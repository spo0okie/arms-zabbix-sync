#!/usr/bin/php
<?php
/*
v6.2	+ поддержка условия "поддерживается сотрудником"
v6.1	+ verbose mode
v6		* режим "конвейера/конвертера" узлов инвентори->zabbix
v5.1	+ синхронизация тегов
v5		+ синхронизация/обновление существующих узлов zabbix
v4.3	+ добавлено шифрование при добавлении узлов
		! исправлен порт подключения 10500->10050
v4.2	+ отлажено добавление узлов в Zabbix
v4.1	+ подготовка данных для добавления узла в заббикс (шаблоны, прокси)
v4		+ разделение на ядро, zabbix lib, config
v3.1	+ поиск аналогичного хоста в других доменах, если нет в искомом
v3		+ филтрация по домену
v2		+ загрузка всех узлов zabbix сразу (для скорости)
v1		+ поиск узлов inventory в zabbix
*/

/**
 * @var string $webInventory
 * @var string $webInventoryAuth
 * @var string $zabbixApiUrl
 * @var string $zabbixAuth
 * @var string $inventoryAuth
 */

$inventoryCache=[];

include dirname(__FILE__).'/config.priv.php';
require_once dirname(__FILE__).'/lib_zabbixApi.php';
require_once dirname(__FILE__).'/lib_inventoryApi.php';
require_once dirname(__FILE__).'/lib_arrHelper.php';
require_once dirname(__FILE__).'/lib_rulesPipeline.php';
require_once dirname(__FILE__).'/lib_syncPlanner.php';

$errorsList=[];

$dryRun=!(array_search('real',$argv)!==false);
$verbose=(array_search('verbose',$argv)!==false);

//debug=host1,host2 (или --debug=...) — подробный вывод конвейера только по указанным узлам (fqdn/num/hostname/id)
$debugHosts=[];
foreach ($argv as $arg) {
	$arg=ltrim($arg,'-'); //допускаем префикс --
	if (strpos($arg,'debug=')===0) $debugHosts=explode(',',substr($arg,strlen('debug=')));
}

function verboseMsg($msg) {
	global $verbose;
	if (!$verbose) return;
	echo $msg;
}

//ключ external_links в инвентори, под которым храним hostid zabbix
//(по аналогии с VMWare.UUID); его читает провайдер интеграции ARMS
const INVENTORY_ZABBIX_HOSTID_KEY=inventoryApi::ZABBIX_HOSTID_KEY;

/**
 * Обратная запись: гарантирует, что в external_links узла инвентори
 * записан hostid соответствующего узла zabbix. Пишет только при
 * расхождении (нет ключа или значение изменилось) — чтобы не плодить
 * историю изменений и лишние запросы. В сухом прогоне ничего не пишет.
 *
 * @param inventoryApi $inventory
 * @param array $item узел инвентори (class + id + external_links)
 * @param string|int|null $hostid резолвнутый hostid zabbix
 * @param bool $dryRun
 */
function writebackHostid($inventory,$item,$hostid,$dryRun) {
	if (!$hostid) return;

	$stored=inventoryApi::externalLinks($item)[INVENTORY_ZABBIX_HOSTID_KEY]??null;
	if ((string)$stored===(string)$hostid) return; //уже актуально

	$hostName=$item['class']=='comps'?$item['fqdn']:$item['num'];
	echo "  writeback hostid $hostid -> inventory {$item['class']}/{$item['id']} ($hostName)";
	if ($dryRun) {
		echo " - [dry run] skip\n";
		return;
	}
	$ok=$inventory->setExternalLink($item['class'],$item['id'],INVENTORY_ZABBIX_HOSTID_KEY,(string)$hostid);
	echo $ok?" - OK\n":" - ERROR\n";
}

echo "Initializin Inventory API ... ";
$inventory=new inventoryApi();
$inventory->init($webInventory,$inventoryAuth);
$inventory->cacheComps(360);
$inventory->cacheTechs();
$inventory->cacheServices();
echo "complete\n";

echo "Loading Zabbix hosts ... ";
	$zabbix=new zabbixApi();
	$zabbix->init($zabbixApiUrl,$zabbixAuth,[
		'inventory'=>$inventory
	]);
echo "complete\n";

//print_r($zabbix->cache['hosts']); exit;

echo "Loading Pipeline ... ";
	$pipeLine=new rulesPipeline();
	$pipeLine->init($zabbix,$inventory,require __DIR__.'/rules.priv.php');
	if (count($debugHosts)) $pipeLine->setDebugHosts($debugHosts);
echo "complete\n";

$pipedItems = [];

// Проходим по всем элементам из inventory
foreach (array_merge($inventory->getComps(), $inventory->getTechs()) as $item) {
	$hostName = $item['class'] == 'comps' ? $item['fqdn'] : $item['num'];
	$params = $pipeLine->pipeHost($item);

	// Пропускаем элементы без параметров
	if (!count($params)) {
		verboseMsg("$hostName - no pipeline output\n");
		continue;
	}

	// или с ошибками
	if (isset($params['errors'])) {
		verboseMsg("$hostName - " . implode('; ', (array)$params['errors']) . "\n");
		continue;
	}

	// или с которыми ничего не надо делать
	if (!isset($params['actions'])) {
		verboseMsg("$hostName - no actions!\n");
		continue;
	}

	$pipedItems[] = ['item' => $item, 'params' => $params];
}

// Схлопываем стеки оборудования: мониторим только мастера, остальных гасим
$processedItems = syncPlanner::buildProcessedItems($pipedItems, function($msg) {echo $msg;});

// Этап 2: Сверка с Zabbix и выполнение действий
$planner=new syncPlanner();	//кто из узлов инвентори за какой узел zabbix отвечает
foreach ($processedItems as $entry) {
	$item = $entry['item'];
	$params = $entry['params'];
	$hostName = $item['class'] == 'comps' ? $item['fqdn'] : $item['num'];
	$actions = $params['actions'] ?? [];

	//если этот узел нужно обновлять
	if (in_array('update', $actions) || in_array('create', $actions)) {
		$hostid = $pipeLine->findZabbixHostid($item);

		//Узел есть в заббикс?
		if (!$hostid ) {

			//не нашли в заббиксе, а нужно ли создавать?
			if (!in_array('create', $actions)) {
				verboseMsg("$hostName - no create!\n"); continue;
			}

			$diff = $zabbix->applyPipelineActions([], $params, true);

			//есть что создавать
			if (!count(get_object_vars($diff))) {
				verboseMsg("$hostName - nothing to create!\n"); continue;
			}

			echo 'CREATE ' . $hostName . ': '.$pipeLine->printDiff($diff, []);
			if ($dryRun)
				echo "- [dry run] skip";
			else
				$hostid=$zabbix->setHost($diff); //новый hostid для обратной записи
			echo "\n";

			//записываем hostid созданного узла обратно в инвентори
			writebackHostid($inventory,$item,$hostid,$dryRun);

		} else {
			//узел уже занят другой записью инвентори - обслуживаем только первую
			if (!$planner->claimHost($hostid)) {
				echo "$hostName - [already processed] inventory -> zabbix search collision skip\n";
				continue;
			}

			//узел найден в zabbix — независимо от того, есть ли изменения,
			//убеждаемся что hostid записан в инвентори (первичное связывание
			//найденных по FQDN/IP узлов, у которых ещё нет ключа)
			writebackHostid($inventory,$item,$hostid,$dryRun);

			$zHost = $zabbix->getHost($hostid);
			$diff = $zabbix->applyPipelineActions($zHost, $params);

			if (!count(get_object_vars($diff))) {
				verboseMsg("$hostName - no changes\n"); continue;
			}

			echo 'UPDATE ' . $hostName . ': '. $pipeLine->printDiff($diff, $zHost);
			$diff->hostid = $zHost['hostid'];
			if ($dryRun)
				echo "- [dry run] skip";
			else
				$zabbix->setHost($diff);
			echo "\n";
		}
	}
}


echo "script done.\n";
exit();

?>
