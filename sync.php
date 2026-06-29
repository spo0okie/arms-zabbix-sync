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

$errorsList=[];

$dryRun=!(array_search('real',$argv)!==false);
$verbose=(array_search('verbose',$argv)!==false);

function verboseMsg($msg) {
	global $verbose;
	if (!$verbose) return;
	echo $msg;
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
echo "complete\n";

$processedItems = [];
$techStacks = [];

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

	// Проверяем уникальность только для оборудования (class == 'techs')
	if ($item['class'] == 'techs') {
		$stackId = $item['model']['name'] . '|' . $item['ip'];

		// Если ключ еще не существует, или его ['num'] больше текущего,
		// то обновляем его текущим
		if (!isset($techStacks[$stackId])) $techStacks[$stackId]=[];
		$techStacks[$stackId][]=['item' => $item, 'params' => $params];

	} else {
		// Для других классов добавляем элемент без проверки уникальности
		$processedItems[] = ['item' => $item, 'params' => $params];
	}
}

// Добавляем уникальные элементы оборудования в итоговый массив
foreach ($techStacks as $stack) {

	//не настоящий стек
	if (count($stack)===1) {
		$processedItems[] = $stack[0];
		continue;
	}

	//настоящий стек, сортируем по имени
	usort($stack, fn($a, $b)=>strcmp($a['item']['num'], $b['item']['num']));
	//мастер тот у кого имя меньше всех
	$master=array_shift($stack);

	echo "Stack found: [{$master['item']['num']}], ".implode(', ', array_map(fn($e) => $e['item']['num'], $stack))."\n";

	$processedItems[] = $master;
	foreach ($stack as $tech) {
		$tech['params']=['actions' => ['update'], 'status' => [1]];   //можем только обновить статус на ВЫКЛ
		$processedItems[]=$tech;
	}

}

// Этап 2: Сверка с Zabbix и выполнение действий
$zabbixProcessed=[];	//для хранения обработанных в zabbix
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
				$zabbix->setHost($diff);
			echo "\n";

		} else {
			if (in_array($hostid,$zabbixProcessed)) {
				echo "$hostName - [already processed] inventory -> zabbix search collision skip\n";
				continue;
			}
			$zabbixProcessed[]=$hostid;
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
