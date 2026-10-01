<?php

/*
 * Чистая логика (без сети) для add_user.php — выдача пользователю ограниченного
 * доступа к мониторингу своих узлов. Вынесено отдельно ради тестов.
 */

class userAccess {

	const PERM_DENY=0;
	const PERM_READ=2;

	public static function nodesGroupName($login) {return "$login nodes";}
	public static function userGroupName($login) {return "$login group";}

	/**
	 * Фамилия и имя из ФИО инвентаризации ("Фамилия Имя Отчество").
	 * Фамилия — первое слово, как в теге serviceman (inventoryApi::fetchUserNames)
	 * @param string $ename
	 * @return array [фамилия, имя]
	 */
	public static function splitEname($ename) {
		$words=preg_split('/\s+/u',trim((string)$ename),-1,PREG_SPLIT_NO_EMPTY);
		return [$words[0]??'',$words[1]??''];
	}

	/**
	 * Гарантирует как минимум чтение на указанные группы узлов.
	 * Чужие права и права выше чтения не трогает.
	 * @param array $current [['id'=>groupid,'permission'=>N],...] как отдает usergroup.get
	 * @param array $groupIds группы, на которые нужно чтение
	 * @param bool $changed
	 * @return array итоговый список прав для usergroup.create/update
	 */
	public static function ensureReadRights(array $current, array $groupIds, &$changed=false) {
		$rights=[];
		foreach ($current as $right)
			$rights[(string)$right['id']]=(int)$right['permission'];

		$changed=false;
		foreach ($groupIds as $id) {
			$id=(string)$id;
			if (!isset($rights[$id]) || $rights[$id]===static::PERM_DENY) {
				$rights[$id]=static::PERM_READ;
				$changed=true;
			}
		}

		$result=[];
		foreach ($rights as $id=>$permission)
			$result[]=['id'=>(string)$id,'permission'=>$permission];
		return $result;
	}

	/**
	 * Добавляет недостающие фильтры по тегам проблем. Существующие не трогает.
	 * Пустой tag = "все теги" (без фильтра) для этой группы узлов.
	 * @param array $current [['groupid'=>..,'tag'=>..,'value'=>..],...]
	 * @param array $required то же самое
	 * @param bool $changed
	 * @return array итоговый список фильтров
	 */
	public static function ensureTagFilters(array $current, array $required, &$changed=false) {
		$normalize=function($filter) {
			return [
				'groupid'=>(string)$filter['groupid'],
				'tag'=>(string)($filter['tag']??''),
				'value'=>(string)($filter['value']??''),
			];
		};

		$result=array_map($normalize,$current);
		$changed=false;
		foreach ($required as $filter) {
			$filter=$normalize($filter);
			if (array_search($filter,$result)===false) {
				$result[]=$filter;
				$changed=true;
			}
		}
		return $result;
	}

	/**
	 * Есть ли в наборах правил правило "узлы с $login в команде -> в группу '$login nodes'"
	 * @param array $ruleSets содержимое rules.priv.php
	 * @param string $login
	 * @return bool
	 */
	public static function hasNodesRule(array $ruleSets, $login) {
		$groupName=static::nodesGroupName($login);
		foreach ($ruleSets as $ruleSet) {
			if (!is_array($ruleSet)) continue;
			foreach ($ruleSet as $key=>$rule) {
				//строковые ключи в наборе — метаданные ('desc'), не правила
				if (!is_int($key) || !is_array($rule)) continue;
				$conditions=$rule[0]??[];
				$actions=$rule[1]??[];
				if (!is_array($conditions) || !is_array($actions)) continue;

				$logins=[];
				foreach ($conditions as $type=>$params)
					if (strtolower($type)==='teamlogins') $logins=(array)$params;

				if (array_search($login,$logins,true)!==false
					&& array_search($groupName,(array)($actions['groups']??[]),true)!==false
				) return true;
			}
		}
		return false;
	}

	/**
	 * Код набора правил для вставки в rules.priv.php
	 */
	public static function nodesRuleSnippet($login,$surname) {
		$groupName=static::nodesGroupName($login);
		return
			"    [[//формируем группы узлов пользователя $surname\n".
			"        ['type'=>['comps','techs'],'teamLogins'=>['$login']],\n".
			"        ['groups'=>['$groupName']],\n".
			"    ]],\n";
	}

	/**
	 * Случайный пароль, проходящий типовую парольную политику zabbix
	 * (строчные, прописные, цифры, спецсимволы)
	 */
	public static function generatePassword($length=20) {
		$sets=['abcdefghijkmnopqrstuvwxyz','ABCDEFGHJKLMNPQRSTUVWXYZ','23456789','!@#%^*-_=+'];
		$all=implode('',$sets);
		$chars=[];
		foreach ($sets as $set) $chars[]=$set[random_int(0,strlen($set)-1)];
		while (count($chars)<$length) $chars[]=$all[random_int(0,strlen($all)-1)];
		for ($i=count($chars)-1; $i>0; $i--) {
			$j=random_int(0,$i);
			[$chars[$i],$chars[$j]]=[$chars[$j],$chars[$i]];
		}
		return implode('',$chars);
	}
}
