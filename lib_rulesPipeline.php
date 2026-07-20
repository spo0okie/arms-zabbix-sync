<?php
//v1 - поиск узлов inventory в zabbix
//v2 - загрузка всех узлов zabbix сразу (для скорости)
//v3 - филтрация по домену
//v3.1 - поиск аналогичного хоста в других доменах, если нет в искомом
//v4 -

require_once 'lib_zabbixApi.php';

class rulesPipeline {

	public $zabbixApi=null;
	public $inventoryApi=null;
	//статическая ссылка на inventory API для условий-методов (они статические)
	public static $inventory=null;
	public $ruleSets=null;
	public $zabbixTemplates;
	public $zabbixGroups;
	//список узлов (fqdn/num/hostname/id), по которым нужен подробный вывод конвейера
	public $debugHosts=[];

	const macroAny='*';
	const macroNone=false;

	static $inventoryMacros=[
		'${inventory:fqdn}'=>'macroInventoryFqdn',
		'${inventory:hostname}'=>'macroInventoryHostname',
		'${inventory:num}'=>'macroInventoryNum',
		'${inventory:class}'=>'macroInventoryClass',
		'${inventory:id}'=>'macroInventoryId',
		'${inventory:ip}'=>'macroInventoryIp',
		'${inventory:serviceman}'=>'macroInventoryServiceman',
		'${inventory:supportTeam}'=>'macroInventorySupportTeam',
		'${inventory:sandbox}'=>'macroInventorySandbox',
		'${inventory:sandboxId}'=>'macroInventorySandboxId',
		'${inventory:sandboxSuffix}'=>'macroInventorySandboxSuffix',
		'${vmware:uuid}'=>'macroVmwareUuid',
		'${vmware:hostuuid}'=>'macroVmwareHostUuid',
		'${vmware:vcenter}'=>'macroVmwareVcenter',
	];

	public function init($zabbix,$inventory,$rules){
		$this->zabbixApi=$zabbix;
		$this->inventoryApi=$inventory;
		static::$inventory=$inventory;
		$this->ruleSets=$rules;

		$this->zabbixTemplates=[];
		foreach (static::fetchTemplatesNames($rules) as $tpl) {
			$objTpl=arrHelper::getItemByFields($zabbix->cache['templates'],['name'=>$tpl]);
			if (!is_array($objTpl)) die("HALT: cant find template [$tpl]");
			$this->zabbixTemplates[$objTpl['templateid']]=$tpl;
		};
		foreach (static::fetchGroupsNames($rules) as $grp) {
			$obj=arrHelper::getItemByFields($zabbix->cache['groups'],['name'=>$grp]);
			if (!is_array($obj)) die("HALT: cant find group [$grp]");
			$this->zabbixGroups[$obj['groupid']]=$grp;
		};
	}


	//  SIMPLE CHECKS ===============================

	/**
	 * Проверка соответствия FQDN компа из инвентори набору $names
	 * @param $names
	 * @param $iHost
	 * @return boolean
	 */
	public static function conditionFqdn($names,$iHost) {
		if (!is_array($names)) $names=[$names];
        if (array_search(static::macroAny,$names)!==false) {
            //если в качестве условия указано * - значит нужен просто любой не пустой FQDN
            return (boolean)($iHost['fqdn'] ?? '');
        }

        return array_search(strtolower($iHost['fqdn']??'!nofqdn'),$names)!==false;
	}

	/**
	 * Проверка соответствия IP узла инвентори набору $ips
	 * @param $ips
	 * @param $iHost
	 * @return boolean
	 */
	public static function conditionIp($ips,$iHost) {
		if (!is_array($ips)) $ips=[$ips];
		$hostIps=arrHelper::getMultiStringValue($iHost['ip']??'');
		//print_r($hostIps);
		if (array_search(static::macroAny,$ips)!==false) {
			//если в качестве условия указано * - значит нужен просто любой не пустой IP
			//echo count($hostIps)."\n";
			return (boolean)(count($hostIps));
		} else {
			return (boolean)(count(array_intersect($ips,$hostIps)));
		}
	}

	/**
	 * Проверка наличие на узле инвентори сервиса из набора $services
	 * @param $services
	 * @param $iHost
	 * @return boolean
	 */
	public static function conditionService($services,$iHost) {
		if (!is_array($services)) $services=[$services];
		$hostServices=$iHost['services']??[];
        //убираем архивированные сервисы
        foreach ($hostServices as $i=>$service) {
            if ($service['archived']??false) unset($hostServices[$i]);
        }
		if (array_search(static::macroAny,$services)!==false) {
			//если в качестве условия указано * - значит нужен просто любой сервис
			return (boolean)(count($hostServices));
		} elseif (array_search(static::macroNone,$services)!==false) {
			//если в качестве условия указано FALSE - значит нужно отсутствие любого сервиса
			return !(boolean)(count($hostServices));
		} else {
			$svcNames=arrHelper::getItemsField($hostServices,'name');
			return (boolean)(count(array_intersect($services,$svcNames)));
		}
	}

	/**
	 * Собрать имя сервиса и имена всех его родителей (вверх по цепочке parent_id).
	 * Все сервисы предзагружены в кэш inventory (cacheServices), поэтому подъём по дереву
	 * идёт по данным в памяти без обращений к API.
	 * @param $service array стартовый сервис (как минимум с полями name/parent_id)
	 * @return array список имён [name=>name,...]
	 */
	protected static function serviceAncestorNames($service) {
		$names=[];
		$guard=0;	//защита от зацикливания при битых данных
		while (is_array($service)) {
			if (isset($service['name'])) $names[$service['name']]=$service['name'];
			$parentId=$service['parent_id']??null;
			if (!$parentId || ++$guard>50) break;
			//родительский сервис берём из предзагруженного кэша (getService вернёт его из памяти)
			$service=is_object(static::$inventory)?static::$inventory->getService($parentId):null;
		}
		return $names;
	}

	/**
	 * Проверка наличия на узле инвентори сервиса из набора $services с учётом вложенности:
	 * совпадение засчитывается если узел входит в сервис, который (возможно, через цепочку
	 * родительских сервисов) входит в один из указанных $services.
	 * Например serviceRecursive=>['b'] совпадёт, если узел в сервисе 'd', который входит в 'c',
	 * который входит в 'b'.
	 * @param $services
	 * @param $iHost
	 * @return boolean
	 */
	public static function conditionServiceRecursive($services,$iHost) {
		if (!is_array($services)) $services=[$services];
		$hostServices=$iHost['services']??[];
		//убираем архивированные сервисы
		foreach ($hostServices as $i=>$service) {
			if ($service['archived']??false) unset($hostServices[$i]);
		}
		if (array_search(static::macroAny,$services)!==false) {
			//если в качестве условия указано * - значит нужен просто любой сервис
			return (boolean)(count($hostServices));
		} elseif (array_search(static::macroNone,$services)!==false) {
			//если в качестве условия указано FALSE - значит нужно отсутствие любого сервиса
			return !(boolean)(count($hostServices));
		} else {
			//собираем имена всех сервисов узла вместе с их родительскими сервисами
			$svcNames=[];
			foreach ($hostServices as $service) {
				$svcNames=array_merge($svcNames,static::serviceAncestorNames($service));
			}
			return (boolean)(count(array_intersect($services,$svcNames)));
		}
	}

	/**
	 * Проверка соответствия категории оборудования узла инвентори набору $types
	 * @param $types
	 * @param $iHost
	 * @return boolean
	 */
	public static function conditionTechtype($types,$iHost) {
		if (!is_array($types)) $types=[$types];
		return array_search($iHost['type']['name']??'!notype',$types)!==false;
	}

	/**
	 * Проверка соответствия производителя узла инвентори набору $vendors
	 * @param $vendors
	 * @param $iHost
	 * @return boolean
	 */
	public static function conditionVendor($vendors,$iHost) {
		if (!is_array($vendors)) $vendors=[$vendors];
		return array_search($iHost['manufacturer']['name']??'!novendor',$vendors)!==false;
	}

	/**
	 * Проверка соответствия модели оборудования из инвентори набору $types
	 * @param $models
	 * @param $iHost
	 * @return boolean
	 */
	public static function conditionModel($models,$iHost) {
		if (!is_array($models)) $models=[$models];
		return arrHelper::strMatch($iHost['model']['name']??'!nomodel',$models)!==false;
	}

	/**
	 * Проверка соответствия домена узла инвентори набору $types
	 * @param $types
	 * @param $iHost
	 * @return boolean
	 */
	public static function conditionDomainname($names,$iHost) {
		if (!is_array($names)) $names=[$names];
		return array_search($iHost['domain']['name']??'!nodomain',$names)!==false;
	}

    /**
     * Проверка соответствия состояния узла инвентори набору $states
     * @param $states
     * @param $iHost
     * @return boolean
     */
    public static function conditionState($states,$iHost) {
        if (!is_array($states)) $states=[$states];
        return array_search($iHost['stateName']??'!nostate',$states)!==false;
    }

    /**
     * Проверка соответствия состояния узла инвентори набору $states
     * @param $states
     * @param $iHost
     * @return boolean
     */
    public static function conditionArmState($states,$iHost) {
        if (!is_array($states)) $states=[$states];
        return array_search($iHost['arm']['stateName']??'!nostate',$states)!==false;
    }


    /**
	 * Проверка соответствия класса/типа узла инвентори набору $types
	 * @param $types
	 * @param $iHost
	 * @return boolean
	 */
	public static function conditionType($types,$iHost) {
		if (!is_array($types)) $types=[$types];
		return array_search($iHost['class']??'noclass',$types)!==false;
	}

	/**
	 * Проверка соответствия ОС нужному набору
	 * @param $oses
	 * @param $iHost
	 * @return boolean
	 */
	public static function conditionOs($oses,$iHost) {
		if (!is_array($oses)) $oses=[$oses];
		return preg_match('/('.implode('|',$oses).')/',$iHost['os']??'!no_os_found');
	}

	/**
	 * Проверка превышения возраста ОС (время с последнего обновления)
	 * @param $ages
	 * @param $iHost
	 * @return boolean
	 */
	public static function conditionAgeover($ages,$iHost) {
		if (!is_array($ages)) $ages=[$ages];
		$age=min($ages);
		if (!($iHost['updated_at']??false)) return true; //если обновление не стоит, считаем что не обновлялся никогда
		$hostDate=strtotime($iHost['updated_at']);
		$hostAge=time()-$hostDate;
		//echo "{$iHost['updated_at']} -> $hostDate -> $hostAge\n";
		return $hostAge>$age;
	}

	/**
	 * Проверка соответствия сайта/площадки узла инвентори набору $sites
	 * @param $sites
	 * @param $iHost
	 * @return boolean
	 */
	public static function conditionSite($sites,$iHost) {
		$site=trim($iHost['site']['name']??'!no_site_found');
		//echo "$site\n";
		if (!is_array($sites)) $sites=[$sites];
		return array_search($site,$sites)!==false;
	}

	/**
	 * Проверка заархивирован ли узел (если поля нет - ответ нет независимо от вопроса)
	 * @param $status
	 * @param $iHost
	 * @return bool
	 */
	public static function conditionArchived($status,$iHost) {
		//если поле архивности отсутствует - значит условие не соблюдается, что бы мы не искали
		if (!isset($iHost['archived'])) return false;
		return (boolean)$iHost['archived'] == (boolean)$status;
	}

	/**
	 * Проверка соответствия песочницы ОС из инвентори набору $sandboxes
	 * @param $sandboxes
	 * @param $iHost
	 * @return boolean
	 */
	public static function conditionSandbox($sandboxes,$iHost) {
		$sandbox=trim($iHost['sandbox']['name']??'!none_found');
		if (!is_array($sandboxes)) $sandboxes=[$sandboxes];
		if (array_search(static::macroAny,$sandboxes)!==false) {
			//если в качестве условия указано * - значит нужен просто любая песочница
			return $sandbox!=='!none_found';
		} elseif (array_search(static::macroNone,$sandboxes)!==false) {
			//если в качестве условия указано FALSE - значит нужно отсутствие любой песочницы
			return $sandbox==='!none_found';
		} else {
			return array_search($sandbox,$sandboxes)!==false;
		}
	}

	/**
	 * Проверка наличия внешних узлов
	 * @param $links
	 * @param $iHost
	 * @return boolean
	 */
	public static function conditionExtlink($links,$iHost) {
		$jsonLinks=inventoryApi::externalLinks($iHost);
		if (array_search(static::macroAny,$links)!==false) {
			//если в качестве условия указано * - значит нужен просто любая ссылка
			return count($jsonLinks);
		} elseif (array_search(static::macroNone,$links)!==false) {
			//если в качестве условия указано FALSE - значит нужно отсутствие любой песочницы
			return !count($jsonLinks);
		} else {
			return count(array_intersect(array_keys($jsonLinks),$links));
		}
	}


	/**
	 * Проверка соответствия ФИО члена команды сопровождения из инвентори набору $teammates
	 * @param $teammates
	 * @param $iHost
	 * @return boolean
	 */
	public static function conditionTeamNames($teammates,$iHost) {
		$team=[];
		if (is_array($iHost['responsible']??null)) $team[]=$iHost['responsible']['Ename'];
		if (is_array($iHost['supportTeam'])&&count($iHost['supportTeam'])) {
			foreach ($iHost['supportTeam'] as $mate) $team[]=$mate['Ename'];
		}
		if (!is_array($teammates)) $teammates=[$teammates];
		if (array_search(static::macroAny,$teammates)!==false) {
			//если в качестве условия указано * - значит нужен просто любая песочница
			return count($team);
		} elseif (array_search(static::macroNone,$teammates)!==false) {
			//если в качестве условия указано FALSE - значит нужно отсутствие любой песочницы
			return count($team)===0;
		}
		return count(array_intersect($teammates,$team));
	}

	/**
	 * Проверка соответствия логина члена команды сопровождения из инвентори набору $teammates
	 * @param $teammates
	 * @param $iHost
	 * @return boolean
	 */
	public static function conditionTeamLogins($teammates,$iHost) {
		$team=[];
		if (is_array($iHost['responsible']??null)) $team[]=$iHost['responsible']['Login'];
		if (is_array($iHost['supportTeam'])&&count($iHost['supportTeam'])) {
			foreach ($iHost['supportTeam'] as $mate) $team[]=$mate['Login'];
		}
		if (!is_array($teammates)) $teammates=[$teammates];
		if (array_search(static::macroAny,$teammates)!==false) {
			//если в качестве условия указано * - значит нужен просто любая песочница
			return count($team);
		} elseif (array_search(static::macroNone,$teammates)!==false) {
			//если в качестве условия указано FALSE - значит нужно отсутствие любой песочницы
			return count($team)===0;
		}
		return count(array_intersect($teammates,$team));
	}

	// ОБХОД наборов правил

	/**
	 * Проверяет соответствия узла условию
	 * @param $type string тип проверки
	 * @param $params mixed параметры проверки
	 * @param $iHost array проверяемый узел
	 * @return boolean
	 */
	public static function checkSingleCondition(string $type, $params, array $iHost): bool
	{
		//OS -> Os, type -> Type, etc...
		$type=ucfirst(strtolower($type));
		$checker='condition'.$type;
		//echo "$type -> ".implode(',',$params)."\n";
		if (!method_exists(__CLASS__,$checker)) {
			die("HALT: no checker [$checker] found in rulesPipeline class: ".print_r([$type=>$params],true));
		}
		return static::$checker($params,$iHost);
	}

	/**
	 * Проверяет выполняются ли все условия $conditions на узле $iHost
	 * @param $conditions
	 * @param $iHost
	 * @return bool
	 */
	public static function checkRuleConditions($conditions,$iHost) {
		//echo implode(':',$conditions);
		foreach ($conditions as $type=>$params) {
			if (!static::checkSingleCondition($type,$params,$iHost)) return false;
		}
		return true;
	}

	public static function checkRule($rule,$iHost) {
		if (!isset($rule[0])) {
			die("HALT: no condition found in rule: ".print_r($rule,true));
		}
		if (!isset($rule[1])) {
			die("HALT: no action found in rule: ".print_r($rule,true));
		}
		//если условия выполняются - возвращаем набор правил, иначе пустой набор
		return (static::checkRuleConditions($rule[0],$iHost))?$rule[1]:[];
	}

	/**
	 * Прогнать хост через один набор правил
	 * @param $ruleSet
	 * @param $iHost
	 * @return array
	 */
	public static function checkRuleSet($ruleSet,$iHost) {
		foreach ($ruleSet as $rule) {
			//возвращаем действия от первого правила которое отработало на узле
			if (count($actions=self::checkRule($rule,$iHost))) return arrHelper::getArrayArrayItems($actions);
		}
		//возвращаем ничего если ничего не совпало
		return [];
	}

	// МАКРОСЫ ========================================

	public static function macroInventoryFqdn($iHost) {
		return mb_strtolower($iHost['fqdn']);
	}

	public static function macroInventoryHostname($iHost) {
		if ($iHost['class']==='comps') return mb_strtolower($iHost['name']);
		if ($iHost['class']==='techs') return mb_strtolower($iHost['hostname']);
		return '';
	}

	public static function macroInventoryNum($iHost) {
		return mb_strtoupper($iHost['num']);
	}

	public static function macroInventoryClass($iHost) {
		return mb_strtolower($iHost['class']);
	}

	public static function macroInventoryId($iHost) {
		return $iHost['id'];
	}

	public static function macroVmwareUuid($iHost) {
		$vmUUID=inventoryApi::externalLinks($iHost)['VMWare.UUID']??'';
		if (!strpos($vmUUID,'@')) return '';
		return explode('@',$vmUUID)[0];
	}

	public static function macroVmwareHostUuid($iHost) {
		$vmUUID=inventoryApi::externalLinks($iHost)['VMWare.hostUUID']??'';
		if (!strpos($vmUUID,'@')) return '';
		return explode('@',$vmUUID)[0];
	}

	public static function macroVmwareVcenter($iHost) {
		$links=inventoryApi::externalLinks($iHost);
		$vmUUID='';
		if (isset($links['VMWare.UUID'])) $vmUUID=$links['VMWare.UUID'];
		if (isset($links['VMWare.hostUUID'])) $vmUUID=$links['VMWare.hostUUID'];
		if (!strpos($vmUUID,'@')) return '';
		return explode('@',$vmUUID)[1];
	}

	public static function macroInventoryIp($iHost) {
		$ip = arrHelper::getMultiStringValue($iHost['ip'])[0]??'';
		//inventory may send the address with a CIDR mask (e.g. 192.168.1.1/24); Zabbix does not accept it
		return explode('/', $ip)[0];
	}

	public static function macroInventoryServiceman($iHost) {
		return inventoryApi::fetchUserNames([$iHost['responsible']??'']);
	}

	public static function macroInventorySupportTeam($iHost) {
		$team=$iHost['supportTeam'];
		if (is_object($responsible=$iHost['responsible']??null)) $team[]=$responsible;
		return inventoryApi::fetchUserNames($team);
	}

	public static function macroInventorySandboxId($iHost) {
		return mb_strtoupper($iHost['sandbox_id']);
	}

	public static function macroInventorySandbox($iHost) {
		return mb_strtoupper($iHost['sandbox']['name']);
	}

	public static function macroInventorySandboxSuffix($iHost) {
		return mb_strtoupper($iHost['sandbox']['suffix']);
	}


	/**
	 * Заменить макросы инвентаризации на реальные значения
	 * @param $value
	 * @param $iHost
	 */
	public function replaceInventoryMacros(&$value,$iHost) {
		//если передали массив а не строку, то рекурсивно обрабатываем элементы массива
		if (is_array($value)) {
			foreach ($value as $key=>$item) {
				$this->replaceInventoryMacros($value[$key],$iHost);
			}
			return;
		}
		foreach (static::$inventoryMacros as $macro => $resolver) {
			if (strpos($value,$macro)!==false) {
				if (!method_exists(__CLASS__,$resolver)) {
					die("HALT: no macro resolver [$resolver] found in rulesPipeline class: ".print_r([$macro=>$resolver],true));
				}
				$replacement=static::$resolver($iHost);
				//echo "$value -> $replacement\n";
				//if (is_string($replacement)) {
				//$value=$replacement;
				$value=str_replace(	$macro,	$replacement,	$value);
				/*} else {
					$value=$replacement;
				}*/
			}
		}
	}

	public function prepareTemplates(&$actions) {

		$templates=arrHelper::getArrayArrayItem($actions,'templates');
		$ofTemplates=arrHelper::getArrayArrayItem($actions,'ofTemplates');

		$removeTemplates=count($ofTemplates)?	array_diff($ofTemplates,$templates):	[];

		//конвертируем имена в ID
		$actions['templateids']=[];
		foreach (array_unique($templates) as $tpl) {
			$actions['templateids'][]=array_search($tpl,$this->zabbixTemplates);
		}

		if (count($removeTemplates)) {
			$actions['remove-templateids']=[];
			foreach (array_unique($removeTemplates) as $tpl) {
				$actions['remove-templateids'][]=array_search($tpl,$this->zabbixTemplates);
			}
		}
	}

	public function prepareGroups(&$actions) {

		$groups=arrHelper::getArrayArrayItem($actions,'groups');
		$ofGroups=arrHelper::getArrayArrayItem($actions,'ofGroups');

		$removeGroups=count($ofGroups)?	array_diff($ofGroups,$groups):	[];

		//конвертируем имена в ID
		$actions['groupids']=[];
		foreach (array_unique($groups) as $grp) {
			$actions['groupids'][]=array_search($grp,$this->zabbixGroups);
		}

		if (count($removeGroups)) {
			$actions['remove-groupids']=[];
			foreach (array_unique($removeGroups) as $grp) {
				$actions['remove-groupids'][]=array_search($grp,$this->zabbixGroups);
			}
		}
	}

	public function prepareTags(&$actions) {

		$tags=arrHelper::getArrayArrayItem($actions,'tags');

		$removeTags=[];
		$filteredTags=[];

		//вытаскиваем теги типа !unset в отдельный массив,
		//очищенные от unset теги кладем в $filteredTags
		//var_dump($tags);
		foreach ($tags as $tag=>$values) {
			$filteredValues=[];

			//конвертируем значения тега в массив
			if (!is_array($values)) $values=(trim($values))?[$values]:[];

			if (!count($values)) {
				//если передан тег с пустым набором - считаем его как под очистку
				$removeTags[]=$tag;
			} else foreach ($values as $value) {
				//иначе перебираем значения
				if (!$value || $value=='!unset') {
					$removeTags[]=$tag;
				} elseif(strlen($value)) {
					$filteredValues[]=$value;
				}
			}
			//если после чистки значений что-то осталось - кладем в почищенный массив
			if (count($filteredValues)) $filteredTags[$tag]=$filteredValues;
		}

		if (count($filteredTags)) {
			$actions['tags']=$filteredTags;
		} elseif (isset($actions['tags'])) {
			unset($actions['tags']);
		}

		if (count($removeTags))
			$actions['remove-tags']=$removeTags;
	}

	public function prepareActions(&$actions) {
		//убираем из того что запрошено сделать, то что запрошено не делать
		$actions['actions']=array_diff(
			arrHelper::getArrayArrayItem($actions,'actions'),
			arrHelper::getArrayArrayItem($actions,'remove-actions')
		);
	}


	/**
	 * Прогнать хост через конвейер и собрать в кучку все действия над ним по результату всех наборов правил
	 * @param $iHost
	 */
	public function pipeHost($iHost) {
		$debug=$this->isDebugHost($iHost);
		if ($debug) {
			echo "\n===== PIPELINE DEBUG: ".$this->hostLabel($iHost)." =====\n";
			echo "----- поля инвентаризации, влияющие на теги сопровождения -----\n";
			echo "responsible: ".json_encode($iHost['responsible']??null,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
			echo "supportTeam: ".json_encode($iHost['supportTeam']??null,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
			echo "  \${inventory:serviceman}   -> '".static::macroInventoryServiceman($iHost)."'\n";
			echo "  \${inventory:supportTeam}  -> '".static::macroInventorySupportTeam($iHost)."'\n";
		}
		$actions=[];
		foreach ($this->ruleSets as $setIndex=>$ruleSet) {
			//добавляем результаты от каждого набора правил из конвейера
			$setActions=$debug
				? static::debugRuleSet($ruleSet,$iHost,$setIndex)
				: static::checkRuleSet($ruleSet,$iHost);
			$actions=array_merge_recursive($actions,$setActions);
		}
		$this->prepareActions($actions);
		$this->replaceInventoryMacros($actions,$iHost);
		$this->prepareTemplates($actions);
		$this->prepareGroups($actions);
		$this->prepareTags($actions);
		//print_r($actions);
		if ($debug) {
			echo "----- итоговые actions после подстановки макросов -----\n";
			echo json_encode($actions,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
			echo "===== END DEBUG: ".$this->hostLabel($iHost)." =====\n\n";
		}
		return $actions;
	}

	/**
	 * Задать список целевых узлов для отладки (fqdn/num/hostname/id)
	 * @param array $hosts
	 */
	public function setDebugHosts($hosts) {
		$this->debugHosts=array_values(array_filter(array_map('trim',$hosts),'strlen'));
	}

	/**
	 * Метка узла для вывода (как в sync.php: comps->fqdn, techs->num)
	 */
	public function hostLabel($iHost) {
		return (($iHost['class']??'')==='comps') ? ($iHost['fqdn']??'?') : ($iHost['num']??'?');
	}

	/**
	 * Входит ли узел в список отлаживаемых. Сверяем по fqdn/num/hostname/id без учета регистра.
	 */
	public function isDebugHost($iHost) {
		if (!count($this->debugHosts)) return false;
		$candidates=array_map('mb_strtolower',array_filter([
			$iHost['fqdn']??'',
			$iHost['num']??'',
			$iHost['hostname']??'',
			(string)($iHost['id']??''),
		],'strlen'));
		foreach ($this->debugHosts as $target) {
			if (in_array(mb_strtolower($target),$candidates,true)) return true;
		}
		return false;
	}

	/**
	 * Отладочный прогон одного набора правил: печатает по каждому правилу,
	 * совпало оно или нет (и на каком условии отвалилось), возвращает действия
	 * первого сработавшего правила — как checkRuleSet.
	 */
	public static function debugRuleSet($ruleSet,$iHost,$setIndex) {
		foreach ($ruleSet as $ruleIndex=>$rule) {
			$conditions=$rule[0]??[];
			$condStr=static::debugConditionsStr($conditions);
			$failedOn=null;
			foreach ($conditions as $type=>$params) {
				if (!static::checkSingleCondition($type,$params,$iHost)) { $failedOn=$type; break; }
			}
			if ($failedOn===null) {
				echo "  set#$setIndex rule#$ruleIndex MATCH [$condStr]\n";
				echo "      actions: ".json_encode($rule[1]??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
				return arrHelper::getArrayArrayItems($rule[1]);
			}
			echo "  set#$setIndex rule#$ruleIndex skip  [$condStr] (не прошло условие '$failedOn')\n";
		}
		echo "  set#$setIndex — ни одно правило не совпало\n";
		return [];
	}

	/**
	 * Компактно отрисовать условия правила для отладочного вывода
	 */
	public static function debugConditionsStr($conditions) {
		if (!count($conditions)) return '<пусто/по-умолчанию>';
		$parts=[];
		foreach ($conditions as $type=>$params) {
			$val=is_array($params)
				? implode('|',array_map(fn($v)=>is_scalar($v)?$v:json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$params))
				: $params;
			$parts[]="$type=$val";
		}
		return implode(', ',$parts);
	}

	public static function fetchTemplatesNames($ruleSets) {
		$names=[];
		foreach ($ruleSets as $ruleSet) {
			foreach ($ruleSet as $rule) {
				if (!isset($rule[1])) continue;
				$actions=$rule[1];
				$names=array_unique(array_merge(
					$names,
					arrHelper::getArrayArrayItem($actions,'templates'),
					arrHelper::getArrayArrayItem($actions,'ofTemplates')
				));
			}
		}
		return $names;
	}

	public static function fetchGroupsNames($ruleSets) {
		$names=[];
		foreach ($ruleSets as $ruleSet) {
			foreach ($ruleSet as $rule) {
				if (!isset($rule[1])) continue;
				$actions=$rule[1];
				$names=array_unique(array_merge(
					$names,
					arrHelper::getArrayArrayItem($actions,'groups'),
					arrHelper::getArrayArrayItem($actions,'ofGroups')
				));
			}
		}
		return $names;
	}

	public function printDiffTemplates($from,$to) {
		$removed=array_diff($from,$to);
		$added=array_diff($to,$from);
		$output=[];
		foreach ($removed as $templateId) $output[]='-'.$this->zabbixTemplates[$templateId];
		foreach ($added as $templateId) $output[]='+'.$this->zabbixTemplates[$templateId];
		return (implode(',',$output));
	}

	public function printDiffGroups($from,$to) {
		$removed=array_diff($from,$to);
		$added=array_diff($to,$from);
		$output=[];
		foreach ($removed as $groupId) $output[]='-'.($this->zabbixGroups[$groupId] ?? "#$groupId");
		foreach ($added as $groupId) $output[]='+'.(  $this->zabbixGroups[$groupId] ?? "#$groupId");
		return (implode(',',$output));
	}

	public function printDiffMacros($values) {
		$output=[];
		foreach ($values as $macro)
			$output[]=arrHelper::getField($macro,'macro').'->'.arrHelper::getField($macro,'value');
		return (implode(',',$output));
	}

	public function printDiffInterfaces($from,$to) {
		//var_dump($from);
		//var_dump($to);
		$output=[];
		foreach ($from as $if) if (zabbixApi::getInterfaceId($to,$if)===false) $output[]='-'.zabbixApi::printInterface($if);
		foreach ($to as $if) if (zabbixApi::getInterfaceId($from,$if)===false) $output[]='+'.zabbixApi::printInterface($if);
		return (implode(',',$output));
	}

	/**
	 * Выводит изменения которые внесены в объект $diff по сравнению с исходным узлом $zHost
	 * @param $diff
	 * @param $zHost
	 * @return string
	 */
	public function printDiff($diff,$zHost)
	{
		$output='';
		$changedProperties = is_object($diff) ? get_object_vars($diff) : [];
		if (count($changedProperties)) {
			foreach ($changedProperties as $property => $value) {
				switch ($property) {
					case 'tags':
						$output.=strtoupper($property).': ' . zabbixApi::printTags($value) . '; ';
						break;
					case 'interfaces':
						$output.='Ifaces: ' . static::printDiffInterfaces($zHost[$property]??[],$value) . '; ';
						break;
					case 'removeMacro':
						$output.=strtoupper($property).': ' . implode(',',$value) . '; ';
						break;
					case 'updateMacro':
					case 'createMacro':
						$output.= static::printDiffMacros($value) . '; ';
						break;
					case 'host':
					case 'name':
					case 'status':
					case 'proxyid':
						$output.=strtoupper($property).': ' . ($zHost[$property] ?? 'unset') . '->' . $value . '; ';
						break;
					case 'tls_psk': break;
					case 'tls_psk_identity':
						$output.='PSK ->' . $value . '; ';
						break;
					case 'templates':
						$output.=strtoupper($property) . ': ' . $this->printDiffTemplates(
								zabbixApi::getTemplateIds($zHost),
								zabbixApi::getTemplateIds($diff,'templates')
							) . '; ';
						break;
					case 'groups':
						$output.=strtoupper($property) . ': ' . $this->printDiffGroups(
								zabbixApi::getGroupIds($zHost),
								zabbixApi::getGroupIds($diff,'groups')
							) . '; ';
						break;
					default:
						$output.=strtoupper($property) . '; ';

				}
			}
		}
		return $output;
	}

	/**
	 * Ищет комп в заббиксе
	 * @param $iHost
	 * @return mixed
	 */
	public function findCompsZabbixHostid($iHost) {
		//ищем его в заббикс по inventoryId
		if (($hostId = $this->zabbixApi->searchHostByMacros([
			'{$INVENTORY_ID}' => $iHost['id'],
			'{$INVENTORY_CLASS}' => 'comps',
			'{$INVENTORY_URL}' => $this->inventoryApi->apiUrl,
		]))!==false) {
			return $hostId;
		}

		$hostId=$this->zabbixApi->searchHostFqdn($iHost['fqdn']);

		if ($hostId===false) return false;

		//обработка на случай дублей FQDN изза песочниц
		//мы можем тут получить hostId клона машины, которая была найдена не по inventory_id, а по FQDN
		//если у этого узла есть inventory_id, и он указывает не на тот хост который мы сейчас обрабатываем,
		//а на другой, который нам тоже надо обработать - считай мы не нашли нужный узел в Zabbix. Потому что тот,
		//который мы нашли - это тоже нужный нам, мы не можем его перепривязать

		$zHost=$this->zabbixApi->getHost($hostId);

		if ($inventoryClass=zabbixApi::getMacroValue($zHost['macros']??[],'{$INVENTORY_CLASS}')) {
			//мы нашли какой то узел привязанный к инвентори но к другому классу устройств
			if ($inventoryClass!=='comps') return false;	// - считай нашли не то
			//класс comps

			//есть ссылка на ID компа?
			if ($inventoryId=zabbixApi::getMacroValue($zHost['macros'],'{$INVENTORY_ID}')) {
				//если ссылаемся на искомый хост - успех
				if ( (int)$iHost['id']===(int)$inventoryId) return $hostId;

				//если комп на который ссылается забикс не найден в инвентори - то норм. считай нашли.
				if (!($iHost2=$this->inventoryApi->getComp($inventoryId))) return $hostId;

				//на этом этапе у нас найден в заббиксе объект, который ссылается на другой КОМП в инвентори и он там есть
				//такое отдавать нельзя - будет драка между узлами инвентори за этот заббикс узел
				return false;
			}
		}
		return $hostId;
	}

	/**
	 * Ищет оборудование в заббиксе
	 * @param $iHost
	 * @return mixed
	 */
	public function findTechsZabbixHostid($iHost) {
		//ищем его в заббикс
		if (($hostId = $this->zabbixApi->searchHostByMacros([
			'{$INVENTORY_ID}' => $iHost['id'],
			'{$INVENTORY_CLASS}' => 'techs',
			'{$INVENTORY_URL}' => $this->inventoryApi->apiUrl,
		]))!==false) {
			//echo "found ".$iHost['num']." by ID \n";
			return $hostId;
		}

        if ($iHost['fqdn']) {
            $hostId=$this->zabbixApi->searchHostFqdn($iHost['fqdn']);
            if ($hostId!==false) {
                //echo "found ".$iHost['num']." by FQDN \n";
                return $hostId;
            }
        }

        return $this->zabbixApi->searchHostByIps(arrHelper::getMultiStringValue($iHost['ip']));
    }

	/**
	 * Ищет узел в заббикс соответствующий узлу в инвентори
	 * @param $iHost
	 * @return mixed
	 */
	public function findZabbixHostid($iHost) {
		$class=ucfirst($iHost['class']);
		//для каждого класса объектов свой метод поиска
		$searcher='find'.$class.'ZabbixHostid';
		if (!method_exists($this,$searcher)) {
			die("HALT: no searcher [$searcher] found in rulesPipeline class");
		} //else echo "using $searcher\n";
		return $this->$searcher($iHost);
	}

}
