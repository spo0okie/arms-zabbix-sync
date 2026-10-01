<?php
/**
 * Ограниченный доступ пользователя к мониторингу своих узлов и сервисов.
 *
 * Использование: php add_user.php <login> [фамилия]
 *
 *  1. группа узлов "<login> nodes"
 *  2. группа пользователей "<login> group":
 *       чтение на "<login> nodes" и "Zabbix servers";
 *       фильтр проблем: "<login> nodes" — все теги, "Zabbix servers" — serviceman=<фамилия>
 *  3. пользователь <login> с ролью User, включенный в "<login> group"
 *  4. проверка правила в rules.priv.php, наполняющего "<login> nodes" (если нет — печатает код для вставки)
 *
 * Фамилия нужна для фильтра по тегу serviceman. Если не указана — берется из уже
 * существующего пользователя zabbix.
 * Скрипт идемпотентный: выполненные шаги пропускаются, повторный прогон ничего не меняет.
 *
 * @var $zabbixApiUrl string
 * @var $zabbixAuth string
 */

include dirname(__FILE__).'/config.priv.php';
require_once dirname(__FILE__).'/lib_zabbixApi.php';
require_once dirname(__FILE__).'/lib_arrHelper.php';
require_once dirname(__FILE__).'/lib_userAccess.php';

const ZABBIX_SERVERS_GROUP='Zabbix servers';
const USER_ROLE='User';
const SERVICEMAN_TAG='serviceman';

if ($argc<2) {
	echo "Usage: add_user.php <login> [surname]\n";
	exit(1);
}

$login=trim($argv[1]);
$surname=trim($argv[2]??'');
if (!strlen($login)) die("HALT: empty login\n");

$zabbix=new zabbixApi();
//init() не зовем — он тянет в кэш все узлы/шаблоны, нам они не нужны
$zabbix->apiUrl=$zabbixApiUrl;
$zabbix->authToken=$zabbixAuth;

function zGet($method,$params) {
	global $zabbix;
	$result=$zabbix->req($method,$params,true);
	if (!is_array($result)) die("HALT: $method failed\n");
	return $result;
}

function zSet($method,$params) {
	global $zabbix;
	$result=$zabbix->req($method,$params);
	if (!is_array($result)) die("HALT: $method failed\n");
	return $result;
}

function findHostGroup($name) {
	return zGet('hostgroup.get',['output'=>['groupid','name'],'filter'=>['name'=>$name]])[0]??null;
}

// ПОЛЬЗОВАТЕЛЬ (ищем заранее: из него берем фамилию) ===========================
$user=zGet('user.get',[
	'output'=>['userid','username','name','surname','roleid'],
	'selectUsrgrps'=>['usrgrpid','name'],
	'filter'=>['username'=>$login],
])[0]??null;

if (!strlen($surname)) $surname=trim($user['surname']??'');
if (!strlen($surname))
	die("HALT: surname unknown — user $login is not in zabbix or has empty surname. Pass it: add_user.php $login <surname>\n");

echo "User: $login ($surname)\n";

// ГРУППЫ УЗЛОВ ================================================================
$nodesGroupName=userAccess::nodesGroupName($login);
if ($nodesGroup=findHostGroup($nodesGroupName)) {
	$nodesGroupId=$nodesGroup['groupid'];
	echo "[skip] host group \"$nodesGroupName\" exists\n";
} else {
	$nodesGroupId=zSet('hostgroup.create',['name'=>$nodesGroupName])['groupids'][0];
	echo "[done] host group \"$nodesGroupName\" created\n";
}

if (!$serversGroup=findHostGroup(ZABBIX_SERVERS_GROUP))
	die("HALT: host group \"".ZABBIX_SERVERS_GROUP."\" not found\n");
$serversGroupId=$serversGroup['groupid'];

// ГРУППА ПОЛЬЗОВАТЕЛЕЙ ========================================================
$userGroupName=userAccess::userGroupName($login);
$userGroup=zGet('usergroup.get',[
	'output'=>['usrgrpid','name'],
	'selectHostGroupRights'=>'extend',
	'selectTagFilters'=>'extend',
	'filter'=>['name'=>$userGroupName],
])[0]??null;

$requiredTagFilters=[
	['groupid'=>$nodesGroupId,'tag'=>'','value'=>''],	//свои узлы — все проблемы
	['groupid'=>$serversGroupId,'tag'=>SERVICEMAN_TAG,'value'=>$surname],	//узлы zabbix — только проблемы своих сервисов
];

$rights=userAccess::ensureReadRights($userGroup['hostgroup_rights']??[],[$nodesGroupId,$serversGroupId],$rightsChanged);
$tagFilters=userAccess::ensureTagFilters($userGroup['tag_filters']??[],$requiredTagFilters,$filtersChanged);

if (!$userGroup) {
	$userGroupId=zSet('usergroup.create',[
		'name'=>$userGroupName,
		'hostgroup_rights'=>$rights,
		'tag_filters'=>$tagFilters,
	])['usrgrpids'][0];
	echo "[done] user group \"$userGroupName\" created\n";
} else {
	$userGroupId=$userGroup['usrgrpid'];
	if ($rightsChanged || $filtersChanged) {
		$update=['usrgrpid'=>$userGroupId];
		if ($rightsChanged) $update['hostgroup_rights']=$rights;
		if ($filtersChanged) $update['tag_filters']=$tagFilters;
		zSet('usergroup.update',$update);
		echo "[done] user group \"$userGroupName\" updated:"
			.($rightsChanged?' host permissions':'')
			.($filtersChanged?' problem tag filters':'')."\n";
	} else
		echo "[skip] user group \"$userGroupName\" is up to date\n";
}

// ПОЛЬЗОВАТЕЛЬ ================================================================
if (!$user) {
	$role=zGet('role.get',['output'=>['roleid','name'],'filter'=>['name'=>USER_ROLE]])[0]??null;
	if (!$role) die("HALT: role \"".USER_ROLE."\" not found\n");
	$password=userAccess::generatePassword();
	zSet('user.create',[
		'username'=>$login,
		'surname'=>$surname,
		'roleid'=>$role['roleid'],
		'passwd'=>$password,
		'usrgrps'=>[['usrgrpid'=>$userGroupId]],
	]);
	echo "[done] user $login created (role ".USER_ROLE.", group \"$userGroupName\")\n";
	echo "       initial password (only needed for internal auth): $password\n";
} else {
	$groupIds=arrHelper::getItemsField($user['usrgrps'],'usrgrpid');
	if (array_search($userGroupId,$groupIds)===false) {
		$usrgrps=[];
		foreach (array_merge($groupIds,[$userGroupId]) as $id) $usrgrps[]=['usrgrpid'=>$id];
		zSet('user.update',['userid'=>$user['userid'],'usrgrps'=>$usrgrps]);
		echo "[done] user $login added to group \"$userGroupName\"\n";
	} else
		echo "[skip] user $login exists and is in group \"$userGroupName\"\n";
}

// ПРАВИЛО СИНХРОНИЗАЦИИ =======================================================
$rulesFile=__DIR__.'/rules.priv.php';
$ruleSets=file_exists($rulesFile)?require $rulesFile:[];
if (is_array($ruleSets) && userAccess::hasNodesRule($ruleSets,$login)) {
	echo "[skip] rules.priv.php already fills \"$nodesGroupName\"\n";
} else {
	echo "[TODO] rules.priv.php has no rule filling \"$nodesGroupName\". Add this rule set (USERS GROUPS section):\n\n";
	echo userAccess::nodesRuleSnippet($login,$surname);
	echo "\n";
}
