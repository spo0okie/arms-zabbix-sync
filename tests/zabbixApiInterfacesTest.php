<?php

require_once __DIR__.'/../lib_arrHelper.php';
require_once __DIR__.'/../lib_zabbixApi.php';

/**
 * Тесты разделения технического имени узла (host) и адреса подключения (address).
 * Сеть не используется: applyPipelineActions ничего не запрашивает, только считает дифф.
 */
class zabbixApiInterfacesTest extends miniTestCase {

	/** Шаблон SNMP-интерфейса из rules.priv.php */
	protected function snmpIf($extra=[]) {
		return array_merge(['type'=>'SNMP','details'=>['version'=>2,'bulk'=>1,'community'=>'{$SNMP_COMMUNITY}']],$extra);
	}

	/** Считает дифф для нового узла (в zabbix его ещё нет) */
	protected function newHostDiff($actions) {
		$zabbix=new zabbixApi();
		return $zabbix->applyPipelineActions([],$actions,true);
	}

	/**
	 * Оборудование: имя - инвентарный номер, адрес - IP.
	 * Техническое имя в интерфейс больше не протекает
	 */
	public function testAddressFeedsInterfaceNotHost() {
		$diff=$this->newHostDiff([
			'host'=>['MHK-NET-0079'],		//конвейер уже применил |translit
			'name'=>['МХК-NET-0079'],
			'address'=>['10.0.0.5'],
			'interfaces'=>[$this->snmpIf()],
		]);

		//техническое имя - инвентарный номер, как задано
		//(транслитерация - явными модификаторами в правилах, не здесь)
		$this->assertSame('MHK-NET-0079',$diff->host);
		$this->assertSame('МХК-NET-0079',$diff->name);
		//а в интерфейсе - IP, а не номер
		$this->assertSame(1,count($diff->interfaces));
		$iface=$diff->interfaces[0];
		$this->assertSame('10.0.0.5',$iface->ip);
		$this->assertSame('',$iface->dns);
		$this->assertSame(1,$iface->useip);
		$this->assertSame(2,$iface->type);		//SNMP
	}

	/**
	 * Обратная совместимость: без address адрес берётся из host.
	 * У comps техническое имя и адрес - одно и то же (FQDN), правила не меняются
	 */
	public function testAddressFallsBackToHost() {
		$diff=$this->newHostDiff([
			'host'=>['srv1.domain.local'],
			'name'=>['srv1.domain.local'],
		]);

		$this->assertSame('srv1.domain.local',$diff->host);
		//интерфейс по умолчанию - agent, адрес не IP, значит DNS-режим
		$iface=$diff->interfaces[0];
		$this->assertSame('',$iface->ip);
		$this->assertSame('srv1.domain.local',$iface->dns);
		$this->assertSame(0,$iface->useip);
		$this->assertSame(1,$iface->type);		//agent
	}

	/** Адрес, заданный прямо в шаблоне интерфейса, приоритетнее и host, и address */
	public function testInterfaceTemplateAddressWins() {
		$diff=$this->newHostDiff([
			'host'=>['MHK-NET-0079'],
			'address'=>['10.0.0.5'],
			'interfaces'=>[$this->snmpIf(['ip'=>'192.168.1.1','dns'=>''])],
		]);

		$this->assertSame('192.168.1.1',$diff->interfaces[0]->ip);
		$this->assertSame(1,$diff->interfaces[0]->useip);
	}

	/** Набор правил с interfaces, но без host/address, больше не роняет расчёт диффа */
	public function testInterfacesWithoutHostDoesNotCrash() {
		$diff=$this->newHostDiff([
			'interfaces'=>[$this->snmpIf(['ip'=>'10.0.0.5','dns'=>''])],
		]);

		$this->assertSame('10.0.0.5',$diff->interfaces[0]->ip);
		$this->assertFalse(isset($diff->host));
	}

	/** Смена адреса у существующего узла не трогает его техническое имя */
	public function testAddressChangeKeepsHostName() {
		$zabbix=new zabbixApi();
		$zHost=[
			'host'=>'MHK-NET-0079',
			'name'=>'МХК-NET-0079',
			'interfaces'=>[['interfaceid'=>'5','type'=>2,'ip'=>'10.0.0.5','dns'=>'','useip'=>1,'port'=>'161',
				'details'=>['version'=>2,'bulk'=>1,'community'=>'{$SNMP_COMMUNITY}']]],
		];
		$diff=$zabbix->applyPipelineActions($zHost,[
			'host'=>['MHK-NET-0079'],
			'name'=>['МХК-NET-0079'],
			'address'=>['10.0.0.9'],			//устройство переехало
			'interfaces'=>[$this->snmpIf()],
		]);

		//имя не изменилось - в диффе его нет
		$this->assertFalse(isset($diff->host));
		//интерфейс переписан на новый адрес, с сохранением interfaceid
		$this->assertSame('10.0.0.9',$diff->interfaces[0]->ip);
		$this->assertSame('5',$diff->interfaces[0]->interfaceid);
	}


}
