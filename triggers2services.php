#!/usr/bin/php
<?php
/**
 * @var $zabbixApiUrl string
 * @var $zabbixAuth string
 * @var $webInventory string
 * @var $inventoryAuth string
 */


include dirname(__FILE__).'/config.priv.php';
require_once dirname(__FILE__).'/lib_zabbixApi.php';
require_once dirname(__FILE__).'/lib_inventoryApi.php';
require_once dirname(__FILE__).'/lib_arrHelper.php';


if ($argc<4) {
	echo "Usage: triggers2Services.php <temaplateName> <triggerName> <serviceId>\n";
	exit(1);
}

$tplName=trim($argv[1]);
$triggerName=trim($argv[2]);
$svcID=(int)trim($argv[3]);

echo "Initializing Zabbix API ... ";
    $zabbix=new zabbixApi();
    $zabbix->init($zabbixApiUrl,$zabbixAuth,[]);
echo "complete\n";

//var_dump($zabbix->cache['templates']);
$objTemplate=arrHelper::getItemByFields($zabbix->cache['templates'],['name'=>$tplName]);
if (empty($objTemplate)) die ("Template '$tplName' not found");

$tplId=$objTemplate['templateid'];

echo "Template '$tplName' ID: $tplId\n";

echo "Initializin Inventory API ... ";
	$inventory=new inventoryApi();
	$inventory->init($webInventory,$inventoryAuth);
echo "complete\n";

$tags=$inventory->getServiceSupportTags($svcID);

echo "Syncing template '$tplName' trigger '$triggerName' with service#$svcID...\n";

$objTrigger=$zabbix->req('trigger.get',[
    'output'=>'extend',
    'templateids'=>$tplId,
    'templated'=>true,
    'filter'=>[
        'description'=>$triggerName,
    ],
    'selectTags'=>['tag','value']
]);

if (empty($objTrigger)) die ("Template '$tplName' trigger '$triggerName' not found");
if (count($objTrigger)>1) die ("Template '$tplName' trigger '$triggerName' too much found");

foreach ($objTrigger as $trigger) {
    $triggerTags=zabbixApi::updateTags($trigger['tags']??[],$tags);
    $trigger['tags']=$triggerTags;
    echo "Updating '${trigger['description']}' tags with ".zabbixApi::printTags($trigger['tags'])."\n";
    $zabbix->req('trigger.update',[
        'triggerid'=>$trigger['triggerid'],
        'tags'=>$trigger['tags'],
    ]);
//	exit(0);
}






$tags=$inventory->getServiceSupportTags($svcID);

//получаем шаблон с тегами
$objTemplate=$zabbix->req('template.get',[
    "output"=>[
        "hostid",
        'selectTags'=>['tag','value'],
    ],
    "filter"=>["host"=>$tplName]
]);

if (is_null($tplId=$objTemplate[0]['templateid']??null)) exit(10);


//обновляем ответственных в шаблоне
$tplTags=zabbixApi::updateTags($objTemplate[0]['tags']??[],$tags);
echo "Updating '$tplName' tags with ".zabbixApi::printTags($tplTags)."\n";
$objTriggers=$zabbix->req('template.update',[
    'templateid'=>$tplId,
    'tags'=>zabbixApi::updateTags($tplTags,$tags)
]);


//получаем все триггеры шаблона

?>
