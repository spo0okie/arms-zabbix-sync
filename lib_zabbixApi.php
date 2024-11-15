<?php
//v1 - поиск узлов inventory в zabbix
//v2 - загрузка всех узлов zabbix сразу (для скорости)
//v3 - филтрация по домену
//v3.1 - поиск аналогичного хоста в других доменах, если нет в искомом
//v4 - 


class zabbixApi {

	public $apiUrl=null;
	public $authToken=null;
	public $cache=[];
	public $BasicGroups;
	public $inventory;

	public function setOptions($options) {
		foreach ($options as $option=>$value)
			$this->$option=$value;
	}

	public function init($url,$auth,$options=[]) {
		$this->apiUrl=$url;
		$this->authToken=$auth;
		$this->cacheHosts();
		$this->cacheTemplates();
		$this->cacheGroups();
		$this->setOptions($options);
	}

	function req($method,$params) {

		$request=new stdClass();
		$request->jsonrpc="2.0";
		$request->method=$method;
		$request->params=$params;
		$request->id=(string)rand(0,9999);
		$request->auth=$this->authToken;

		$reqData=json_encode($request,JSON_UNESCAPED_UNICODE);
		//echo(">".$data."\n");
		$reqCurl = curl_init();
		curl_setopt_array($reqCurl, [
			CURLOPT_URL => $this->apiUrl,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER=>['Content-type: application/json'],
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $reqData,
			CURLOPT_SSL_VERIFYPEER => false
		]);
		$respData = curl_exec($reqCurl);
		if(curl_error($reqCurl)) {
			echo curl_error($reqCurl)."\n";
			$respData=null;
		}
		curl_close($reqCurl);

		if (
			$respData &&
			is_array($arrData=json_decode($respData,true)) &&
			isset($arrData['result']) &&
			is_array($arrData['result']) &&
			count($arrData['result'])
		) return $arrData['result'];
		echo "Zabbix API req ERR:\n";
		echo ">".$reqData."\n";
		echo "<".$respData."\n";
		return null;
	}


    function cacheHosts() {
        if (
            isset($this->cache['hosts']) &&
            is_array($this->cache['hosts']) &&
            count($this->cache['hosts'])
        ) return;
        $params=new stdClass();
        $params->selectMacros="extend";
        $params->selectTags="extend";
        $params->selectInterfaces="extend";
        $params->selectHostGroups="extend";
        $params->selectParentTemplates=['name','templateid']; //другого не надо вроде

        $this->cache['hosts']=[];
        foreach ($this->req('host.get',$params) as $host) {
            $this->cache['hosts'][$host['hostid']]=$host;
        }
    }

    function cacheUsers() {
        if (
            isset($this->cache['users']) &&
            is_array($this->cache['users']) &&
            count($this->cache['users'])
        ) return;
        $params=new stdClass();
        $params->output="extend";

        $this->cache['users']=[];
        foreach ($this->req('user.get',$params) as $user) {
            $this->cache['users'][$user['userid']]=$user;
        }
    }

    function cacheActions() {
        if (
            isset($this->cache['actions']) &&
            is_array($this->cache['actions']) &&
            count($this->cache['actions'])
        ) return;
        $params=new stdClass();
        $params->output="extend";

        $this->cache['actions']=[];
        foreach ($this->req('action.get',$params) as $action) {
            $this->cache['actions'][$action['actionid']]=$action;
        }
    }

    function cacheTemplates() {
		if (
			isset($this->cache['templates']) &&
			is_array($this->cache['templates']) &&
			count($this->cache['templates'])
		) return;
		$params=new stdClass();
		$this->cache['templates']=[];
		foreach ($this->req('template.get',$params) as $template) {
			$this->cache['templates'][$template['templateid']]=$template;
		}
	}

	function cacheGroups() {
		if (
			isset($this->cache['groups']) &&
			is_array($this->cache['groups']) &&
			count($this->cache['groups'])
		) return;
		$params=new stdClass();
		$this->cache['groups']=[];
		foreach ($this->req('hostgroup.get',$params) as $group) {
			$this->cache['groups'][$group['groupid']]=$group;
		}
	}


	//ищет ID хоста заббикс по FQDN
	function searchHostFqdn($fqdn=null) {
		$this->cacheHosts();

		$fqdn=strtolower($fqdn);

		foreach ($this->cache['hosts'] as $host) {
			if (strtolower($host['host']) == $fqdn) {
				return $host['hostid'];
			}
		}
		return null;
	}

	/**
	 * Возвращает макрос $macro из набора $macros или $default если не нашло
	 * @param array $macros
	 * @param string $macro
	 * @param mixed $default
	 * @return array
	 */
	public static function getMacroFromMacros(array $macros, string $macro, $default=null) {
		foreach ($macros as $item) {
			if ($item['macro']==$macro) return $item;
		}
		return $default;
	}


	/**
	 * Возвращает значение макроса $macro из набора $macros или $default если не нашло
	 * @param array $macros
	 * @param string $macro
	 * @param mixed $default
	 * @return string
	 */
	public static function getMacroValue(array $macros,string $macro,$default=null) {

		if (is_array($found=static::getMacroFromMacros($macros,$macro,null))) {
			return $found['value'];
		}
		return $default;
	}

	/**
	 * Найти хост по совпадению значений макросов
	 * @param $macros
	 * @return mixed
	 */
	public function searchHostByMacros($macros) {
		//echo "Searching HOST: ".json_encode($macros)." ... ";
		foreach ($this->cache['hosts'] as $host) {
			if (!isset($host['macros'])) continue;
			foreach ($macros as $macro => $value) {
				if (static::getMacroValue($host['macros'],$macro)!=$value) {
					continue 2;
				} //else echo "got $macro==$value; ";
			}
			//echo " - {$host['hostid']} FOUND!\n";
			return $host['hostid'];
		}
		return false;
	}

	/**
	 * Найти хост по совпадению IP (можно передать несколько)
	 * @param $macros
	 * @return mixed
	 */
	public function searchHostByIps($ips) {
		if (!is_array($ips)) $ips=[$ips];
		$ips=array_filter($ips,function($value) { return !is_null($value) && trim($value) !== '';});
		if (!count($ips)) return false;
		foreach ($this->cache['hosts'] as $zHost) {
			$host_ips=arrHelper::getItemsField($zHost['interfaces'],'ip');
			$host_ips=array_filter($host_ips,function($value) { return !is_null($value) && trim($value) !== '';});
			//echo "comparing for ".var_export($ips,1).' vs '.var_export($host_ips,1);
			if (count(array_intersect($host_ips,$ips)))
				return $zHost['hostid'];
		}
		return false;
	}

	/**
	 * Получить простой список ID шаблонов
	 * @param array|object $host
	 * @param string $field поле объекта откуда взять templates
	 * @return array
	 */
	public static function getTemplateIds($host,$field='parentTemplates') {
		$ids=[];
		$templates=arrHelper::getField($host,$field,[]);
		foreach ($templates as $template) {
			$ids[]=arrHelper::getField($template,'templateid');
		}
		return $ids;
	}

	public static function hasTemplateIds(array $host,$ids,$field='parentTemplates') {
		return count(array_intersect(static::getTemplateIds($host,$field),$ids))>0;
	}

	/**
	 * Формирует список templates для обновления хоста, если в него нужно добавить template из набора toBe или
	 * убрать template из набора notToBe
	 * если ничего добавлять/убирать не нужно, то вернет $default
	 * @param array $current
	 * @param array $toBe
	 * @param array $notToBe
	 * @param null $default
	 * @return array|null
	 */
	public static function generateTemplatesDiff(array $current, array $toBe, array $notToBe, $default=null) {
		$remove=false; //флаг изменения набора
		//нужно ли убирать?
		foreach ($notToBe as $id) {
			if (array_search($id,$current)!==false) $remove=true;
		}
		if ($remove) $current=array_diff($current,$notToBe);

		$add=false;
		foreach ($toBe as $id) {
			if (array_search($id,$current)===false) $add=true;
		}
		if ($add) $current=array_unique(array_merge($current,$toBe));

		if ($add||$remove) return self::formTemplatesList($current);

		return $default;
	}

	/**
	 * Получить простой список ID групп
	 * @param array|object $host
	 * @param string $field поле объекта откуда взять templates
	 * @return array
	 */
	public static function getGroupIds($host,$field='hostgroups') {
		$ids=[];
		$groups=arrHelper::getField($host,$field,[]);
		foreach ($groups as $group) {
			$ids[]=arrHelper::getField($group,'groupid');
		}
		return $ids;
	}

	/**
	 * Формирует список templates для обновления хоста, если в него нужно добавить template из набора toBe или
	 * убрать template из набора notToBe
	 * если ничего добавлять/убирать не нужно, то вернет $default
	 * @param array $current
	 * @param array $toBe
	 * @param array $notToBe
	 * @param null $default
	 * @return array|null
	 */
	public static function generateGroupsDiff(array $current, array $toBe, array $notToBe, $default=null) {
		$remove=false; //флаг изменения набора
		//нужно ли убирать?
		foreach ($notToBe as $id) {
			if (array_search($id,$current)!==false) $remove=true;
		}
		if ($remove) $current=array_diff($current,$notToBe);

		$add=false;
		foreach ($toBe as $id) {
			if (array_search($id,$current)===false) $add=true;
		}
		if ($add) $current=array_unique(array_merge($current,$toBe));

		if ($add||$remove) return self::formGroupsList($current);

		return $default;
	}

	/**
	 * Формирует список тегов для узла если в его текущем наборе не установлены в нужные значения теги $setValues
	 * или присутствуют не нужные теги $unset. Если все ок - возвращает $default
	 * @param array $current
	 * @param array $setValues
	 * @param array $unset
	 * @param null $default
	 * @return array|null
	 */
	public static function generateTagsDiff(array $current, array $setValues, array $unset, $default=null) {
		$current=static::removeAutoTags($current); //оставляем только ручные теги

		//если нужно что-то убрать сначала убираем
		$isUnset=false;
		foreach ($unset as $tag) {
			//echo "$tag => ".static::getTagValue($current,$tag);
			if (count(static::getTagValues($current,$tag))) {
				$isUnset=true;
				$current=static::removeTag($current,$tag);
			}
		}

		//если нужно что-то установить - устанавливаем
		$isSet=false;
		if (!static::compareTagsValues($current,$setValues)) {
			$isSet=true;
			$current=self::updateTags($current,$setValues);
		}

		if ($isSet||$isUnset) return $current;
		return $default;
	}

	public static function removeAutoTags($tags) {
		$result=[];
		foreach ($tags as $tag) {
			if (isset($tag['automatic'])) {
				if ($tag['automatic'] == 1) continue; //автоматические тэги не нужны
				unset($tag['automatic']);
			}
			$result[]=$tag;
		}
		return $result;
	}

	public static function generateMacrosDiff(array $current, array $setValues, array $unset, &$diff) {
		$current=static::removeAutoTags($current); //оставляем только ручные макросы

		//что нам нужно удалить?
		$removeMacro=[];
		foreach ($unset as $macro) {
			$macro=static::formMacroName($macro);	//правим macro -> {$MACRO}
			if ($item=static::getMacroFromMacros($current,$macro)!==null) {
				$removeMacro[]=$item;
			}
		}

		$createMacro=[];
		$updateMacro=[];
		foreach ($setValues as $macro=>$value) {
			$macro=static::formMacroName($macro);	//правим macro -> {$MACRO}
			if ($value!=static::getMacroValue($current,$macro)) {
				//значения не совпадают, надо разобраться мы создаем или правим макрос?
				if (is_array($item=static::getMacroFromMacros($current,$macro))) {
					$item['value']=$value;
					$updateMacro[]=$item;
				} else {
					$createMacro[]=static::formMacro($macro,$value);
				}
			}
		}
		if (count($removeMacro)) $diff->removeMacro=$removeMacro;
		if (count($createMacro)) $diff->createMacro=$createMacro;
		if (count($updateMacro)) $diff->updateMacro=$updateMacro;
	}

	/**
	 * Сформировать DIFF по интерфейсам
	 * @param array $current	текущие
	 * @param array $hosts		адреса узла (по умолчанию все параметры array, но мы не умеем работать с несколькими адресами и кушаем только первый)
	 * @param array $ifTemplates шаблоны интерфейсов
	 * @param $diff
	 */
	public static function generateInterfacesDiff(array $current, array $hosts, array $ifTemplates, &$diff) {
		//если шаблоны интерфейсов не указаны - значит 1 заббикс ифейс
		if (!count($ifTemplates)) $ifTemplates=[['type'=>'agent']];

		//делаем strtolower Дла всех ДНС текущих интерфейсов (для единообразия при поиске)
		foreach ($current as $i=>$v)
			arrHelper::updField($current[$i],'dns',mb_strtolower(arrHelper::getField($current[$i],'dns','')));

		//по умолчанию все параметры array, но мы не умеем работать с несколькими адресами и кушаем только первый
		$host=reset($hosts);


		$mustBe=[];
		foreach ($ifTemplates as $ifTemplate) {
			//собираем интерфейс на базе шаблона
			$byTemplate=static::formInterface($host,$ifTemplate);
			//ищем текущий такого же типа как созданный
			$sameType=arrHelper::getItemIdByFields($current,['type'=>arrHelper::getField($byTemplate,'type')]);
			//если нашли
			if ($sameType!==false) {
				$byTemplate->interfaceid=arrHelper::getField($current[$sameType],'interfaceid');
			}
			$mustBe[]=$byTemplate;
		}

		static::setDefaultInterface($mustBe);

		$allOk=true;
		foreach ($mustBe as $item) if (static::getInterfaceId($current,$item)===false) $allOk=false;

		if (!$allOk) $diff->interfaces=$mustBe;
	}


	public function getHosts() {
		return $this->cache['hosts']??[];
	}
	public function getHost($id) {
		return $this->getHosts()[$id]??null;
	}

	//ищет хосты заббикс из кэша с совпадающим hostname
	function searchHostnames($hostname) {
		$this->cacheHosts();

		$hostname = strtolower($hostname);

		$result=[];
		foreach ($this->cache['hosts'] as $host) {
			$fqdn = strtolower($host['host']);
			if ($fqdn == $hostname || substr($fqdn,0,strlen($hostname)+1) == $hostname.'.') {
				$result[]=$host;
			}
		}
		return $result;
	}

	//ищет хосты заббикс с совпадающим hostname, но с FQDN отсутствующим в инвентори
	function searchNonInventoryHostnames($hostname) {

		global $zabbix;
		$arrData = $zabbix->searchHostnames($hostname);
		$result=[];

		foreach ($arrData as $host)
			if (!$this->inventory->searchFqdnCompId($host['host']))
				$result[]=$host;

		return $result;
	}

	//формирует список темплейтов для привязки объекта к этим темплейтам
	static function formTemplatesList($ids) {
		$templates=[];
		foreach($ids as $id) {
			$template=new stdClass();
			$template->templateid=(string)$id;
			$templates[]=$template;
		}
		return $templates;
	}

	static function formGroupsList($ids) {
		$groups=[];
		foreach($ids as $id) {
			$group=new stdClass();
			$group->groupid=(string)$id;
			$groups[]=$group;
		}
		return $groups;
	}

	/**
	 * Одному интерфейсу ставит признак основного остальным нет
	 * @param $interfaces
	 */
	static function setDefaultInterface(&$interfaces) {
	    //ищем нет ли уже назначенного основного
        $assigned=arrHelper::getItemByFields($interfaces,['main'=>1]);

        if ($assigned===null){ //если нет
            //находим наименьший тип интерфейса, его будем назначать по умолчанию
            $mainType=min(arrHelper::getItemsField($interfaces,'type'));
            $mainIsSet=false;
        } else { //выбираем его тип как по умолчанию
            $mainType=$assigned->type;
        }

		foreach ($interfaces as $key=>$interface) {
			//если еще не назначен и тип нужный, то по умолчанию
			if (!$mainIsSet && arrHelper::getField($interface,'type')==$mainType) {
				$interfaces[$key]->main=1;
				$mainIsSet=true;
			} else {
				$interfaces[$key]->main=0;
			}
		}
	}

	/**
	 * Собирает интерфейс на базе адреса и шаблона
	 * @param $addr
	 * @param $ifTemplate
	 * @return stdClass
	 */
	static function formInterface($addr,$ifTemplate) {
		$iface=(object)$ifTemplate;

		//тип интерфейса (https://www.zabbix.com/documentation/current/en/manual/api/reference/hostinterface/object)

		switch (mb_strtolower(trim($iface->type))) {
			case 'agent':
				$iface->type=1;
				$iface->port=$iface->port??"10050";	//default port 10500
				break;
			case 'snmp':
				$iface->type=2;
				$iface->port=$iface->port??"161";	//default port 161
				break;
			case 'ipmi': $iface->type=3; break;
			case 'jmx': $iface->type=4; break;
		}

		if (!isset($ifTemplate['ip']) && !isset($ifTemplate['dns'])) {
            if (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\z/',$addr)) {
                //IP mode
                $iface->ip=$addr;		//IP addr
                $iface->dns="";			//DNS addr
            } else {
                //DNS mode
                $iface->ip="";					//IP addr
                $iface->dns=strtolower($addr);	//DNS addr
            }
        }
        $iface->useip=strlen($iface->ip)?1:0;	//use DNS

        return $iface;
	}

	/**
	 * Найти индекс интерфейса в куче
	 * @param $ifaces
	 * @param $iface
	 * @return bool|int
	 */
	static function getInterfaceId($ifaces,$iface) {
		//echo "searching ". static::printInterface($iface);
		//var_export($iface);
		//var_export($ifaces);
		//var_export(arrHelper::getItemIdByFields($ifaces,$iface));
		return arrHelper::getItemIdByFields($ifaces,$iface);
	}

	static function printInterface($iface) {
		$types=['unknown','Agent','SNMP','IPMI','JMX'];

		$addr=arrHelper::getField($iface,'useip')?
			$addr=arrHelper::getField($iface,'ip'):
			arrHelper::getField($iface,'dns');
		//if (!$addr) $addr=arrHelper::getField($iface,'ip');
		$type=arrHelper::getField($iface,'type',0);
		$description=$type?$types[$type]:$type;
		return $description.':'.$addr;
	}

	static function formMacroName($name) {
		$name=mb_strtoupper($name);
		if (mb_substr($name,0,2)!='{$')
			$name='{$'.$name.'}';
		return $name;
	}

	static function formMacro($var,$val,$descr=null) {
		$macro=new stdClass();
		$macro->macro=static::formMacroName($var);
		$macro->value=(string)$val;
		if ($descr)
			$macro->description=$descr;
		return $macro;
	}

	/**
	 * Вытащить таг из кучи тагов
	 * @param $tags
	 * @param $tag
	 * @return mixed
	 */
	static function getTagFromTags($tags,$tag) {
		return arrHelper::getItemsByFields($tags,['tag'=>$tag]);
	}

	/**
	 * Вернуть значения тега из кучи
	 * @param $tags
	 * @param $tag
	 * @return mixed
	 */
	static function getTagValues($tags,$tag) {
		$filtered=static::getTagFromTags($tags,$tag);
		return arrHelper::getItemsField($filtered,'value');
	}

	/**
	 * Проверяет что тег в куче имеет нужные значения
	 * @param $tags
	 * @param $tag
	 * @param $values
	 * @return bool
	 */
	static function compareTagValues($tags,$tag,$values) {
		//проверяемые значения
		if (!is_array($values)) $values=[$values];
		//реальные значения
		$realValues=static::getTagValues($tags,$tag);
		//недостающие значения
		$missing=array_diff($values,$realValues);
		return count($missing)==0;
	}

	/**
	 * Проверяет что все переданные теги есть в куче с нужными значениями
	 * @param $tags
	 * @param $search
	 * @return bool
	 */
	static function compareTagsValues($tags,$search) {
		foreach ($search as $tag=>$values) {
			if (!is_array($values)) $values=[$values];
			if (!static::compareTagValues($tags,$tag,$values)) return false;
		}
		return true;
	}

	//создать массив тегов $tag со значениями $values
	static function createTags($tag,$values) {
		if (!is_array($values)) $values=[$values];
		$tags=[];
		foreach ($values as $val) {
			$tags[]=['tag'=>$tag,'value'=>$val];
		}
		return $tags;
	}

	//удалить из массива $tags все текги $tagName
	static function removeTag($tags, $tagName) {
		foreach ($tags as $i=>$tag) {
			if (($tag['tag']??null) == $tagName) unset($tags[$i]);
		}
		return $tags;
	}

	/**
	 * Удалить из кучи тегов все теги с именами из массива $removeList
	 * @param $tags
	 * @param $removeList
	 * @return mixed
	 */
	static function removeTags($tags,$removeList) {
		foreach ($removeList as $tag) {
			$tags=static::removeTag($tags,$tag);
		}
		return $tags;
	}

	//заменяет в тегах $tags все теги переданные в $upfate на новые значения
	static function updateTags($tags,$update) {
		foreach ($update as $tag=>$values) {
			$tags=array_merge(
				static::removeTag($tags,$tag),
				static::createTags($tag,$values)
			);
		}
		return $tags;
	}

	static function printTags($tags) {
		$render=[];
		//var_dump($tags);
		foreach ($tags as $tag) {
			$render[]=$tag['tag'].'=>['.$tag['value'].']';
		}
		return implode('; ',$render);
	}


	/**
	 * @param $zHost
	 * @param $iHost
	 * @param $diff
	 * @return stdClass
	 */
	public function applyPipelineActions(
		$zHost,
		$actions,
		$new=false,
		$diff=null
	) {
		if (!is_object($diff)) $diff = new stdClass();

		/* Host - single value */
		if (isset($actions['host'])){
			$host=reset($actions['host']);
			if (($zHost['host']??'')!=$host) {
				$diff->host=$host;
			}
		}

		/* ipmi_username - single value */
		if (isset($actions['ipmi_username'])){
			$ipmi_username=reset($actions['ipmi_username']);
			if (($zHost['ipmi_username']??'')!=$ipmi_username) {
				$diff->ipmi_username=$ipmi_username;
			}
		}

		/* ipmi_password - single value */
		if (isset($actions['ipmi_password'])){
			$ipmi_password=reset($actions['ipmi_password']);
			if (($zHost['ipmi_password']??'')!=$ipmi_password) {
				$diff->ipmi_password=$ipmi_password;
			}
		}

		/* Host(name) - single value */
		if (isset($actions['name'])){
			$name=reset($actions['name']);
			if (($zHost['name']??'')!=$name || arrHelper::getField($diff,'host')) { //если хост проставили, то надо и имя, иначе сбрасывается на хост
				$diff->name=$name;
			}
		}

		/* Proxy ID - single value */
		if (isset($actions['proxy'])) {
			$zProxy=$zHost['proxy_hostid']??0;
			$aProxy=reset($actions['proxy']);
			if ($zProxy!=$aProxy) {
				$diff->proxy_hostid=$aProxy?$aProxy:null;
			}
		}

		/* Status - single value */
		if (isset($actions['status'])) {
			$status=(int)(reset($actions['status']));
			if ((int)($zHost['status']??null) != $status) {
				$diff->status=$status;
			}
		}

		/* Groups - multiple value */
		if (isset($actions['groupids'])) {
			$diffGrp=static::generateGroupsDiff(
				static::getGroupIds($zHost),
				$actions['groupids']??[],
				$actions['remove-groupids']??[]
			);
			if ($diffGrp) $diff->groups=$diffGrp;
		}

		/* Templates - multiple value */
		if (isset($actions['templateids']) || isset($actions['remove-templateids'])) {
			$diffTpl=static::generateTemplatesDiff(
				static::getTemplateIds($zHost),
				$actions['templateids']??[],
				$actions['remove-templateids']??[]
			);
			if ($diffTpl) $diff->templates=$diffTpl;
		}

		/* TAGS - multiple value */
		if (isset($actions['tags']) || isset($actions['remove-tags'])) {
			$diffTags=static::generateTagsDiff(
				$zHost['tags']??[],
				$actions['tags']??[],
				$actions['remove-tags']??[]
			);
			if (!is_null($diffTags)) $diff->tags=$diffTags;
		}

		/* MACROS - multiple value */
		if (isset($actions['macros']) || isset($actions['remove-macros'])) {
			static::generateMacrosDiff(
				$zHost['macros']??[],
				$actions['macros']??[],
				$actions['remove-macros']??[],
				$diff
			);
		}

		/* INTERFACES - multiple values */
		if (isset($actions['host']) || isset($actions['interfaces'])) {
			static::generateInterfacesDiff(
				$zHost['interfaces']??[],
				$actions['host'],
				$actions['interfaces']??[],
				$diff
			);
		}


		//обрабатываем пареметры которые применяем только на новый хост
		if ($new) {
			/* PSK - single value */
			if (isset($actions['PSK'])) {
				$identity='';
				$PSK='';
				foreach ($actions['PSK'] as $key=>$value) {
					$identity=$key;
					$PSK=$value;
				}
				static::diffSimpleField($zHost,'tls_accept',2,$diff);
				static::diffSimpleField($zHost,'tls_connect',2,$diff);
				static::diffSimpleField($zHost,'tls_psk_identity',$identity,$diff);
				static::diffSimpleField($zHost,'tls_psk',$PSK,$diff);
			}
		}

		return $diff;
		//exit();
	}




	/**
	 * Если в zHost поле $field не имеет значение $value, то записываем обновление этого поля в $diff
	 * @param $zHost
	 * @param $field
	 * @param $value
	 * @param $diff
	 */
	public static function diffSimpleField($zHost,$field,$value,&$diff) {
		if ($zHost[$field]??null!=$value) $diff->$field=$value;
	}


	public function setMacro($macro) {
		$first=is_array($macro)?$macro[0]:$macro;
		$method=arrHelper::getField($first,'hostmacroid')?'update':'create';
		$response=$this->req('usermacro.'.$method,$macro);
		if (isset($response['hostmacroids']))
			echo " - macro $method OK (".implode(',',$response['hostmacroids']).")";
		else
			echo " - macro $method ERROR";

	}

	public function setHost($diff) {
		$createMacro=arrHelper::getField($diff,'createMacro',[]); if (count($createMacro)) unset($diff->createMacro);
		$updateMacro=arrHelper::getField($diff,'updateMacro',[]); if (count($updateMacro)) unset($diff->updateMacro);
		$deleteMacro=arrHelper::getField($diff,'deleteMacro',[]); if (count($deleteMacro)) unset($diff->deleteMacro);


		$method=($diff->hostid??false)?'update':'create';
		$response=$this->req('host.'.$method,$diff);

		if (isset($response['hostids'])) {
			echo " - host $method OK (" . implode(',', $response['hostids']) . ")";
			//после успешного обновления/создания узла переходим к макросам.
			//для новых макросов нам нужен hpstid узла
			$hostid=arrHelper::getField($diff,'hostid',$response['hostids'][0]);

			//прописываем его всем новым макросам
			foreach ($createMacro as $i=>$v)arrHelper::setField($createMacro[$i],'hostid',$hostid);
			if (count($createMacro))  $this->setMacro($createMacro);

			//у обновляемых наоборот убираем
			foreach ($updateMacro as $i=>$v)arrHelper::delField($updateMacro[$i],'hostid');
			if (count($updateMacro))  $this->setMacro($updateMacro);
			//if (count($updateMacro))  $this->setMacro($updateMacro);
		} else
			echo " - host $method ERROR";

	}

    public function searchUserByLogin($login) {
        $this->cacheUsers();

        return arrHelper::getItemsByFields($this->cache['users'],['username'=>$login]);
    }

    public function searchActionByName($name) {
        $this->cacheActions();

        return arrHelper::getItemsByFields($this->cache['actions'],['name'=>$name]);
    }

}
