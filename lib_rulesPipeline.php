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
	public $ruleSets=null;
	public $zabbixTemplates;
	public $zabbixGroups;

    const macroAny='*';
    const macroNone=false;

	static $inventoryMacros=[
		'${inventory:fqdn}'=>'macroInventoryFqdn',
		'${inventory:num}'=>'macroInventoryNum',
		'${inventory:class}'=>'macroInventoryClass',
		'${inventory:id}'=>'macroInventoryId',
		'${inventory:ip}'=>'macroInventoryIp',
        '${inventory:serviceman}'=>'macroInventoryServiceman',
        '${inventory:supportTeam}'=>'macroInventorySupportTeam',
        '${vmware:uuid}'=>'macroVmwareUuid',
        '${vmware:hostuuid}'=>'macroVmwareHostUuid',
        '${vmware:vcenter}'=>'macroVmwareVcenter',
	];

	public function init($zabbix,$inventory,$rules){
		$this->zabbixApi=$zabbix;
		$this->inventoryApi=$inventory;
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
		$hostServices=$iHost['services']??[];;
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
		return arrHelper::getMultiStringValue($iHost['ip'])[0]??'';
	}

	public static function macroInventoryServiceman($iHost) {
		return inventoryApi::fetchUserNames([$iHost['responsible']]);
	}

	public static function macroInventorySupportTeam($iHost) {
		//print_r($iHost['supportTeam']);
		return inventoryApi::fetchUserNames(
		    array_merge($iHost['supportTeam']),
            [$iHost['responsible']]
        );
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
		$actions=[];
		foreach ($this->ruleSets as $ruleSet) {
			//добавляем результаты от каждого набора правил из конвейера
			$actions=array_merge_recursive($actions,static::checkRuleSet($ruleSet,$iHost));
		}
		$this->prepareActions($actions);
		$this->replaceInventoryMacros($actions,$iHost);
		$this->prepareTemplates($actions);
		$this->prepareGroups($actions);
		$this->prepareTags($actions);
		//print_r($actions);
		return $actions;
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

        if ($inventoryClass=zabbixApi::getMacroValue($zHost['macros'],'{$INVENTORY_CLASS}')) {
            //мы нашли какой то узел привязанный к инвентори но к другому классу устройств
            if ($inventoryClass!=='comps') return false;    // - считай нашли не то
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
