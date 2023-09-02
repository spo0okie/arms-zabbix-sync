#!/usr/bin/php
<?php
/*
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

$dryRun=true;

if (array_search('real',$argv)!==false) $dryRun=false;

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


foreach (array_merge($inventory->getComps(),$inventory->getTechs()) as $item) {
    //имя узла
    $hostName=$item['class']=='comps'?$item['fqdn']:$item['num'];

    //DEBUG
    //if ($hostName!='КЛГ-ВИД-0017') continue;
	//print_r($item['supportTeam']);

    //прогоняем узел через конвейер чтобы понять что с ним делать
	$params=$pipeLine->pipeHost($item);
	//print_r($params); exit;

	//если получили какие-то параметры, то смотрим есть ли actions и нет ли ошибок
	if (!count($params)) {  //пропускаем узлы у которых ничего не нужно делать
		//echo "$hostName - no actions\n";

    } elseif (isset($params['errors'])) {    //пропускаем узлы с ошибками
	    if (!is_array($params['errors'])) $params['errors']=[$params['errors']];
	    //echo "$hostName - ".implode('; ',$params['errors'])."\n";

    } elseif (isset($params['actions'])) {  //узлы где нужно что-то делать и нет ошибок
		$actions=$params['actions'];

		//если этот узел нужно обновлять
		if (array_search('update',$actions)!==false) {

			$hostid=$pipeLine->findZabbixHostid($item);
			//DEBUG
			//$hostid=10182;
            //exit;

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

				} //else echo "$hostName - no create!\n";
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
		} //else echo "$hostName - no update!\n";
	}
}
exit();

?>
