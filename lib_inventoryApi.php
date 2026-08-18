<?php
/*
v1		+ поиск узлов inventory в zabbix

*/

require_once 'lib_arrHelper.php';

class inventoryApi {
	public $cache=[];
	public $apiUrl=null;
	public $auth=null;
	public $context=null;

	//ключ external_links в инвентори, под которым хранится hostid zabbix
	//(пишется синхронизацией, читается провайдером интеграции ARMS и explain-режимом)
	const ZABBIX_HOSTID_KEY='Zabbix.hostid';

	//expand-наборы едины для bulk-кэша и точечных запросов,
	//чтобы форма данных узла не зависела от способа загрузки
	const COMPS_EXPAND='responsible,fqdn,domain,site,supportTeam,sandbox,services,arm.stateName';
	const TECHS_EXPAND='responsible,comp,site,supportTeam,stateName,type,model,manufacturer,services,fqdn,itStaff';


	public function init($url,$auth) {
		$this->apiUrl=$url;
		$this->auth=$auth;
		$this->context=stream_context_create([
				"http" => [
				"header" => "Authorization: Basic $auth"
			],
			"ssl"=>[
				"verify_peer"=>false,
				"verify_peer_name"=>false,
			],
		]);
	}

	public function req($path) {
		return file_get_contents($this->apiUrl.$path,false,$this->context);
	}

	/**
	 * PUT в инвентори (для обратной записи данных в узел).
	 * @param string $path путь относительно apiUrl (например /api/comps/123)
	 * @param array $body тело запроса (будет закодировано в JSON)
	 * @return array|null декодированный ответ либо null при ошибке транспорта
	 */
	public function put($path,$body) {
		$context=stream_context_create([
			"http" => [
				"method" => "PUT",
				"header" => "Authorization: Basic {$this->auth}\r\nContent-Type: application/json\r\n",
				"content" => json_encode($body,JSON_UNESCAPED_UNICODE),
				"ignore_errors" => true, //чтобы читать тело и при 4xx/5xx
			],
			"ssl" => [
				"verify_peer" => false,
				"verify_peer_name" => false,
			],
		]);
		$response=@file_get_contents($this->apiUrl.$path,false,$context);
		if ($response===false) return null;
		return json_decode($response,true);
	}

	/**
	 * Записать/обновить внешнюю ссылку узла инвентори (external_links).
	 * Инвентори при сохранении МЕРЖИТ переданный external_links поверх
	 * существующего (ExternalDataModelTrait::externalDataBeforeSave), так
	 * что прочие ключи (VMWare.* и т.п.) не затираются.
	 *
	 * @param string $class класс узла: comps|techs
	 * @param int|string $id id узла в инвентори
	 * @param string $key ключ внешней ссылки (например Zabbix.hostid)
	 * @param string $value значение
	 * @return bool успех (в ответе присутствует сохранённый id)
	 */
	public function setExternalLink($class,$id,$key,$value) {
		$response=$this->put("/api/$class/$id",[
			'external_links' => json_encode([$key=>$value],JSON_UNESCAPED_UNICODE),
		]);
		return is_array($response) && isset($response['id']);
	}

	public function getCache($model,$id,$default=null) {
		return $this->cache[$model][$id]??$default;
	}

	public function setCache($model,$id,$value) {
		if (!isset($this->cache[$model])) $this->cache[$model]=[];
		$this->cache[$model][$id]=$value;
	}

	public function getService($id) {
		if (!is_null($service=$this->getCache('services',$id))) {
			return $service;
		}
		$data=$this->req("/api/services/$id?expand=infrastructureResponsibleName,infrastructureSupportNames,responsibleName,supportNames");
		$obj=json_decode($data,true);
		if (isset($obj['id'])) {
			$this->setCache('services',$id,$obj);
			return $obj;
		}
		return null;
	}

	/**
	 * Собрать данные обо всех сервисах разом (для построения дерева вложенности в памяти).
	 * Кэширует под тем же ключом и с теми же expand, что и getService, поэтому
	 * последующие getService() обслуживаются из памяти без обращения к API.
	 */
	public function cacheServices() {
		$data=$this->req('/api/services/?showArchived=1&per-page=0&expand=infrastructureResponsibleName,infrastructureSupportNames,responsibleName,supportNames');
		$obj=json_decode($data,true);
		if (!is_array($obj)) return;
		foreach ($obj as $service) {
			if (isset($service['id'])) $this->setCache('services',$service['id'],$service);
		}
	}

	/**
	 * Собрать данные о компах
	 * @param $period integer период в днях за который нужны данные
	 * (ОС не обновлявшиеся дольше указанного периода не попадут в кэш)
	 */
	public function cacheComps($period=30) {
		if ($period) {
			$today = new DateTime('today');
			$today->modify('-'.$period.' days');
			$period_limit='&CompsSearch[updated_at]=>'.$today->format('Y-m-d');
		} else $period_limit='';

		$data=$this->req('/api/comps/filter?showArchived=1&per-page=0&expand='.static::COMPS_EXPAND.$period_limit);
		$obj=json_decode($data,true);
		//var_dump($data);
		foreach ($obj as $comp) {
			$comp['class']='comps';
			$this->setCache('comps',$comp['id'],$comp);
		}
	}

	/**
	 * Собрать данные об оборудовании
	 */
	public function cacheTechs() {

		$data=$this->req('/api/techs/?showArchived=1&per-page=0&expand='.static::TECHS_EXPAND);
		$obj=json_decode($data,true);
		foreach ($obj as $tech) {
			$tech['class']='techs';
			//если своего адреса нет, но есть привязанная основная ОС с адресом, то используем его
			// 2026-07-30 отключено, потому что выгода от такого fallback не понятна,
			// но при этом поведение становится неочевидным, т.к. в WEB-UI такого fallback нет.
			/*if (!strlen($tech['ip']??'') && is_array($tech['comp']??null) && strlen($tech['comp']['ip']??'')) {
				$tech['ip']=$tech['comp']['ip'];
			}*/
			$this->setCache('techs',$tech['id'],$tech);
		}
	}

	/**
	 * Точечно получить один узел из инвентори без bulk-кэша (для explain-режима).
	 * Использует те же expand, что и bulk-методы, поэтому форма данных совпадает.
	 * @param string $class comps|techs
	 * @param int|string $id
	 * @return array|null узел (с проставленным 'class') либо null если не найден/ошибка
	 */
	public function fetchItem($class,$id) {
		$expand=$class==='comps'?static::COMPS_EXPAND:static::TECHS_EXPAND;
		$data=@$this->req('/api/'.$class.'/'.(int)$id.'?expand='.$expand);
		$obj=is_string($data)?json_decode($data,true):null;
		if (!is_array($obj)||!isset($obj['id'])) return null;
		$obj['class']=$class;
		$this->setCache($class,$obj['id'],$obj);
		return $obj;
	}

	public function fetchComp($id) {return $this->fetchItem('comps',$id);}
	public function fetchTech($id) {return $this->fetchItem('techs',$id);}

	public function getComps() {return $this->cache['comps']??[];}
	public function getComp($id) {return $this->cache['comps'][$id]??null;}
	public function getTechs() {return $this->cache['techs']??[];}
	public function getTech($id) {return $this->cache['techs'][$id]??null;}

	public function searchFqdnCompId($fqdn) {
		$fqdn=strtolower($fqdn);

		foreach ($this->getComps() as $item) {
			if (strtolower($item['fqdn']) == $fqdn) return $item['id'];
		}

		return null;
	}

	/**
	 * найти в кэше объект типа $type, мультистрочное (множественное) поле $field которого содержит $needle
	 * @param $type
	 * @param $field
	 * @param $needle
	 * @return mixed|null
	 */
	public function findByMultiString($type,$field,$needle){
		foreach ($this->cache[$type]??[] as $item) {
			$strings=explode("\n",$item[$field]??'');
			if (array_search($needle,$strings)!==false) return $item;
		}
		return null;
	}

	/**
	 * Найти объект типа $type по $ip
	 * @param $type
	 * @param $ip
	 * @return mixed|null
	 */
	public function findByIp($type,$ip) {
		if (is_array($ip)) {
			foreach ($ip as $item) {
				if (!is_null($obj=$this->findByIp($type,$item))) return $obj;
			}
		}
		return $this->findByMultiString($type,'ip',$ip);

	}

	public function getCompByIp($ip) { return $this->findByIp('comps',$ip); }
	public function getTechByIp($ip) { return $this->findByIp('techs',$ip); }

	public static function cutFirstWords($string) {
		$result=[];
		foreach(explode(',',$string) as $token) {
			$result[]=explode(' ',$token)[0];
		}
		return $result;
	}

	/**
	 * Вытащить уникальные имена из пользователей (убрав лишних)
	 * @param $users
	 * @param array $exclude
	 * @return string
	 */
	public static function fetchUserNames($users,$exclude=[]) {
		$names=[];
		$excludeNames=arrHelper::getItemsField($exclude,'Ename');
		foreach (arrHelper::getItemsField($users,'Ename') as $name) {
			if (array_search($name,$excludeNames)===false)
				$names[]=explode(' ',$name)[0];
		}
		return implode(', ',array_unique($names));
	}


	/**
	 * Возвращает пары значение-ключ для сопровождения сервиса
	 * @param $id
	 * @return array|array[]
	 */
	public function getServiceSupportTags($id) {
		if (is_null($service=$this->getService($id))) return [];

		$serviceMan=[];
		$supportTeam=[];
		foreach (static::cutFirstWords($service['infrastructureResponsibleName']) as $name)
			$serviceMan[$name]=$name;

		foreach (static::cutFirstWords($service['responsibleName']) as $name)
			$serviceMan[$name]=$name;

		foreach (static::cutFirstWords($service['infrastructureSupportNames']) as $name)
			$supportTeam[$name]=$name;

		foreach (static::cutFirstWords($service['supportNames']) as $name)
			$supportTeam[$name]=$name;

		return [
			'serviceman'=>array_values($serviceMan),
			'supportteam'=>array_values($supportTeam)
		];
	}

	/**
	 * Возвращает ассоциативный массив внешних ссылок
	 * @param $iHost
	 * @return array
	 */
	public static function externalLinks($iHost) {
		$hostLinks=trim($iHost['external_links']??'[]');
		if (!strlen($hostLinks)) $hostLinks = '[]'; //ну это на случай что там каким-то образом пусто сохранено
		$jsonLinks=json_decode($hostLinks,true);
		if (!is_array($jsonLinks)) $jsonLinks=[];
		return $jsonLinks;
	}
}


?>
