<?php
/** @var $webInventory string адрес Inventory*/
/** @var $PSK_windows string ключ шифрования для Windows серверов */
/** @var $PSK_linux string ключ шифрования для Linux серверов */


$osTemplates=['Linux by Zabbix agent','Windows by Zabbix agent'];
$SNMPv2If=['type'=>'SNMP','details'=>['version'=>2,'bulk'=>1,'community'=>'{$SNMP_COMMUNITY}']];

$OSExclusions=[
	'zabbix.domain.local',		   //этот узел настраиваем вручную
	'oldnode.domain.local',	       //там старая Ubuntu 11, поставить на него Zabbix 6 тот еще квест
];

return
[//наборы правил
	[// OS TYPES =================================================
	//	Раскидывает на узлы шаблоны на основании их операционки
		[//пропускаем ОС которые нормально не настроены в DNS!
			['type' => 'comps',		'domainName' => ['WORKGROUP'],],
			['errors'=>['domain not set!']]
		],
		[//пропускаем ОС которые вручную игнорируются
			['type' => 'comps',		'fqdn' => $OSExclusions,],
			['errors'=>['monitoring manually disabled']]
		],
		[//VCenter servers
			['type' => 'comps',		'OS' => ['VMware Photon'],],
			[
				'actions'=>['update','create'],
				'templates'=>['VMware'], 'ofTemplates'=>$osTemplates,
			]
		],
		[//Linux c сервисами
			['type' => 'comps',		'OS' => ['Linux','Ubuntu','CentOS'], 'service'=>'*',],
			[
				'actions'=>['update','create'],
				'templates'=>['Linux by Zabbix agent'],'ofTemplates'=>$osTemplates,
			]
		],
		[//Windows с сервисами -> значит это сервер независимо от редакции ОС
			['type' => 'comps',		'OS' => ['Windows'], 'service'=>'*'],
			[
				'actions'=>['update','create'],
				'templates'=>['Windows by Zabbix agent'],'ofTemplates'=>$osTemplates,
			]
		],
		[//ставим камеры на мониторинг PING-ом, если у нас есть их IP
			['type'=>'techs',	'techType'=>['Видеорегистратор','Камера видеонабл.'], 'ip'=>'*'],
			[
				'actions'=>['update','create'],
				'templates'=>['Template Status Ping'],
			],
		],
		[//коммутаторы D-Link DES,DGS по шаблону D-Link DES_DGS Switch SNMP, если у нас есть их IP
			['type'=>'techs',	'techType'=>['Коммутатор'], 'vendor'=>['D-Link'], 'model'=>['/^(DES|DGS)/'], 'ip'=>'*'],
			[
				'actions'=>['update','create'],	'templates'=>['D-Link DES_DGS Switch SNMP'],	'interfaces'=>[$SNMPv2If],
			],
		],
		[//ставим коммутаторы Cisco по шаблону Cisco IOS SNMP, если у нас есть их IP
			['type'=>'techs',	'techType'=>['Коммутатор'], 'vendor'=>['Cisco'], 'ip'=>'*'],
			[
				'actions'=>['update','create'],	'templates'=>['Cisco IOS SNMP'],	'interfaces'=>[$SNMPv2If],
			],
		],
		[//пропускаем мониторинг для всех остальных узлов, т.к. хз как их мониторить
			[],['errors'=>'skip monitoring'],
		],
	],

	[ //SERVICES =============================================
		[//узлы с KES мониторим дополнительным шаблоном
			['type'=>'comps', 'service'=>'Kaspersky Security Center'],
			['templates'=>'Template Kaspersky Security Center KSC KES',	'interfaces'=>[$SNMPv2If],]
		]
	],

	[ //SITES =================================================
	//	Дополнительно накидывает шаблоны площадки и раскидывает по проксям
        [
            ['site'=>'Московский офис'],        //Узлы москвы
            [
                'templates'=>'Москва макросы',	'ofTemplates'=>['Москва макросы','Питер макросы'],  //накидываем шаблон Мск и скидываем остальные
                'groups'=>'Москва', 'ofGroups'=>['Москва','Питер'], //кладем в группу Мск и выкидываем из остальных
                'proxy'=>91511	//мониторим через московский прокси
            ]
        ],
        [
            ['site'=>'Офис в СПБ'],        //Узлы москвы
            [
                'templates'=>'Питер макросы',	'ofTemplates'=>['Москва макросы','Питер макросы'],  //накидываем шаблон Спб и скидываем остальные
                'groups'=>'Питер', 'ofGroups'=>['Москва','Питер'], //кладем в группу Спб и выкидываем из остальных
                'proxy'=>10085	//мониторим через питерский прокси
            ]
        ],
        [
			[],//если все правила выше прошли и не было ни одного совпадения, значит не можем назначить площадку. пропустим такое пока
			['errors'=>['unknown site']]
		]
	],

	[//SERVICE TAGS =================================================
		[//ставим ответственных/поддержку за узел
			['type'=>['comps','techs']],
			['tags'=>[
				'node-service'=>'${inventory:serviceman}',
				'node-support'=>'${inventory:supportTeam}',
			]],
		]
	],

    [//OLD OS =======================================================
		[//запрещаем создание в заббиксе узлов, которые не обновлялись в инвентори больше 15 дней
			['type'=>'comps','ageOver'=>15*24*3600],
			['remove-actions'=>['create']],
		],
	],

	[// ARCHIVED =================================================
		[//включаем мониторинг оборудования в работе
			['type'=>'techs','state'=>['ОК','Замеч']],
			['status'=>0,'actions'=>['update']],
		],
		[//включаем не архивированные ОС
			['type'=>'comps','archived'=>false],
			['status'=>0,'actions'=>['update']],
		],
		[//остальное - мониторинг приостанавливаем, добавление в мониторинг (для отсутствующих) отменяем
			[],
			['status'=>1,'remove-actions'=>['create']],
		]
	],

    [// NAMES =====================================================
		[//компьютеры именуем и адресуем по FQDN
			['type'=>'comps'],['name'=>'${inventory:fqdn}', 'host'=>'${inventory:fqdn}',],
		],
		[//оборудование именуем по инвентарному номеру, адресуем по IP
			['type'=>'techs'],['name'=>'${inventory:num}', 'host'=>'${inventory:ip}',],
		],
	],

	[// MACROS =====================================================
		[//всем узлам прописываем с чем они синхронизированы
			[],['macros'=>['inventory_url'=>$webInventory,'inventory_class'=>'${inventory:class}','inventory_id'=>'${inventory:id}'],]
		],
	],

	[//PSK Keys
		[//Linux
			['type' => 'comps',	'OS' => ['Linux','Ubuntu','CentOS'],],
			['PSK'=>['linux'=>$PSK_linux],],
		],
		[//Windows
			['type' => 'comps',	'OS' => ['Windows']],
			['PSK'=>['server'=>$PSK_windows]]
		],
	],

];
