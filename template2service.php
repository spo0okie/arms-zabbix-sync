#!/usr/bin/php
<?php
/**
 * @var $zabbixApiUrl string
 * @var $zabbixAuth string
 * @var $webInventory string
 * @var $inventoryAuth string
 */

$inventoryCache=[];

include dirname(__FILE__).'/config.priv.php';
include dirname(__FILE__).'/lib_inventoryApi.php';
include dirname(__FILE__).'/lib_zabbixApi.php';

$errorsList=[
	'noSite'=>[],
	'noOs'=>[],
	'createdOK'=>[]
];

if ($argc<3) {
	echo "Usage: template2service.php <temaplateName> <serviceID>\n";
	exit(1);
}

$tplName=trim($argv[1]);
$svcID=(int)trim($argv[2]);

echo "Initializing Zabbix API ... ";
	$zabbix=new zabbixApi();
	$zabbix->init($zabbixApiUrl,$zabbixAuth);
echo "complete\n";

echo "Initializin Inventory API ... ";
	$inventory=new inventoryApi();
	$inventory->init($webInventory,$inventoryAuth);
echo "complete\n";

echo "Syncing template '$tplName' with service #$svcID...\n";

$tags=$inventory->getServiceSupportTags($svcID);

//получаем шаблон с тегами
$objTemplate=$zabbix->req('template.get',[
    "output"=>[
        "hostid",
    ],
    'selectTags'=>['tag','value'],
    "filter"=>["host"=>$tplName]
]);

if (is_null($tplId=$objTemplate[0]['templateid']??null)) {
	echo "Template \"$tplName\" not found\n";
	exit(10);
}


//обновляем ответственных в шаблоне
$tplTags=zabbixApi::updateTags($objTemplate[0]['tags']??[],$tags);
echo "Updating '$tplName' tags with ".zabbixApi::printTags($tplTags)."\n";

$objTriggers=$zabbix->req('template.update',[
    'templateid'=>$tplId,
    'tags'=>zabbixApi::updateTags($tplTags,$tags)
]);


//получаем все триггеры шаблона чтобы убрать там теги ответственных (все теги наследовать с шаблона)
$objTriggers=$zabbix->req('trigger.get',[
	'output'=>'extend','templateids'=>$tplId,'templated'=>true,'selectTags'=>['tag','value']]);

if (is_null($objTriggers)) $objTriggers=[];

//убираем оттуда ответственных (наследуются из шаблона)
$removeTags=['serviceman','supportteam'];

//если решили убрать алерты с триггеров то добавлям их в чистку
if (array_search('remove_alert',$argv)) $removeTags[]='alert';

foreach ($objTriggers as $trigger) {
	$trigger['tags']=zabbixApi::removeTags($trigger['tags'],$removeTags);
	echo "Updating '${trigger['description']}' tags with ".zabbixApi::printTags($trigger['tags'])."\n";
	$zabbix->req('trigger.update',[
		'triggerid'=>$trigger['triggerid'],
		'tags'=>$trigger['tags'],
	]);
//	exit(0);
}

?>
