#!/usr/bin/php
<?php
/*
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
 * @var $webInventory string
 * @var $webInventoryAuth string
 * @var $zabbixApiUrl string
 * @var $zabbixAuth string
 * @var $inventoryAuth string
 */

$inventoryCache=[];

include dirname(__FILE__).'/config.priv.php';
require_once dirname(__FILE__).'/lib_zabbixApi.php';
require_once dirname(__FILE__).'/lib_inventoryApi.php';
require_once dirname(__FILE__).'/lib_arrHelper.php';
require_once dirname(__FILE__).'/lib_rulesPipeline.php';

$errorsList=[
	'noSite'=>[],
	'noOs'=>[],
	'createdOK'=>[]
];

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
$inventory->cacheComps();
$inventory->cacheTechs();
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

//обходим оборудование и ОС
foreach (array_merge($inventory->getComps(),$inventory->getTechs()) as $item) {
    //имя узла
    $hostName=$item['class']=='comps'?$item['fqdn']:$item['num'];

    //прогоняем узел через конвейер чтобы понять что с ним делать
	$params=$pipeLine->pipeHost($item);

	//если получили какие-то параметры, то смотрим есть ли actions и нет ли ошибок
	if (!count($params)) {  //пропускаем узлы у которых ничего не нужно делать
		verboseMsg("$hostName - no pipeline output\n");
    } elseif (isset($params['errors'])) {    //пропускаем узлы с ошибками
		if (!is_array($params['errors'])) $params['errors']=[$params['errors']];
		verboseMsg("$hostName - ".implode('; ',$params['errors'])."\n");
    } elseif (isset($params['actions'])) {  //узлы где нужно что-то делать и нет ошибок
		$actions=$params['actions'];

		//если этот узел нужно обновлять
		if (array_search('update',$actions)!==false) {

			$hostid=$pipeLine->findZabbixHostid($item);

            //Узел есть в заббикс?
            if (!$hostid) {
				//не нашли в заббиксе, а нужно ли создавать?
				if (array_search('create',$actions)!==false) {
					$diff=$zabbix->applyPipelineActions([],$params,true);
                    if (count(get_object_vars($diff))) {
                        //print_r($params); exit;
                        echo  'CREATE '.$hostName .': ';

                        if (strlen($changes=$pipeLine->printDiff($diff,[]))) echo $changes;

                        if ($dryRun)
                            echo "- [dry run] skip";
                        else
                            $zabbix->setHost($diff);
                        echo "\n";
                        //exit;
                    }

				} else verboseMsg("$hostName - no create!\n");
			} else {
				$zHost=$zabbix->getHost($hostid);
				//print_r($zHost); //exit;
				$diff=$zabbix->applyPipelineActions($zHost,$params);
				if (count(get_object_vars($diff))) {
					echo  'UPDATE '. $hostName .': ';

					if (strlen($changes=$pipeLine->printDiff($diff,$zHost))) echo $changes;

					$diff->hostid=$zHost['hostid'];
					if ($dryRun)
						echo "- [dry run] skip";
					else
    					$zabbix->setHost($diff);
					echo "\n";
					//exit;
                }
			}
		} else verboseMsg("$hostName - no update!\n");
	} else verboseMsg("$hostName - no actions!\n");
}
echo "script done.\n";
exit();

?>
