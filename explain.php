<?php
/*
 * Explain-режим конвейера: "должен ли узел инвентори попадать в мониторинг и в каком виде".
 *
 * В отличие от sync.php работает по ОДНОМУ узлу: не грузит Zabbix и bulk-кэши
 * инвентори, поэтому отвечает быстро и пригоден для вызова из карточки узла ARMS
 * (провайдер интеграции ZabbixSync). В Zabbix и в инвентори ничего не пишет.
 *
 * HTTP:  GET explain.php?class=comps&id=123&token=<$explainToken>
 * CLI:   php explain.php comps 123           (токен не нужен)
 *
 * Ответ — JSON (см. rulesPipeline::explainHost):
 *   verdict  — declined|monitored|add|update-only|skip
 *   errors   — причины отказа из правил
 *   status   — 0/1 итоговый статус узла (1 = мониторинг приостановлен)
 *   sets     — трейс по каждому набору правил (имена/описания если заданы в rules.priv.php)
 *   actions  — итоговые действия (имена шаблонов/групп, теги, прокси...)
 *
 * Токен задается в config.priv.php: $explainToken='<случайная строка>';
 * без настроенного токена HTTP-доступ закрыт (503).
 */

/**
 * @var string $webInventory
 * @var string $inventoryAuth
 * @var string $explainToken
 */

//предупреждения транспорта (404 от file_get_contents и т.п.) не должны засорять JSON
error_reporting(E_ERROR|E_PARSE);

include dirname(__FILE__).'/config.priv.php';
require_once dirname(__FILE__).'/lib_arrHelper.php';
require_once dirname(__FILE__).'/lib_inventoryApi.php';
require_once dirname(__FILE__).'/lib_rulesPipeline.php';

$isCli=php_sapi_name()==='cli';

/**
 * Ответить ошибкой (JSON) и завершиться
 */
function explainFail($httpCode,$message) {
	global $isCli;
	if (!$isCli) http_response_code($httpCode);
	echo json_encode(['error'=>$message],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
	exit(1);
}

if ($isCli) {
	$class=$argv[1]??'';
	$id=$argv[2]??0;
} else {
	header('Content-Type: application/json; charset=utf-8');
	$class=$_GET['class']??'';
	$id=$_GET['id']??0;
	//токен: параметром или заголовком Authorization: Bearer <token>
	$token=$_GET['token']??'';
	if (!strlen($token) && preg_match('/Bearer\s+(.*)$/i',$_SERVER['HTTP_AUTHORIZATION']??'',$m)) $token=trim($m[1]);
	if (!isset($explainToken) || !strlen($explainToken)) explainFail(503,'explain token not configured ($explainToken in config.priv.php)');
	if (!hash_equals($explainToken,(string)$token)) explainFail(403,'invalid token');
}

if (array_search($class,['comps','techs'],true)===false || !(int)$id)
	explainFail(400,'usage: explain.php?class=comps|techs&id=<inventoryId>');

$inventory=new inventoryApi();
$inventory->init($webInventory,$inventoryAuth);

$iHost=$inventory->fetchItem($class,(int)$id);
if (is_null($iHost)) explainFail(404,"$class/$id not found in inventory");

$pipeLine=new rulesPipeline();
//init без Zabbix: имена шаблонов/групп остаются именами, проверка их существования - не задача explain
$pipeLine->init(null,$inventory,require __DIR__.'/rules.priv.php');

echo json_encode(
	$pipeLine->explainHost($iHost),
	JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT
)."\n";
