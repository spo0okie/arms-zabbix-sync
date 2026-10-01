<?php

require_once __DIR__.'/../lib_arrHelper.php';
require_once __DIR__.'/../lib_zabbixApi.php';
require_once __DIR__.'/../lib_inventoryApi.php';
require_once __DIR__.'/../lib_rulesPipeline.php';

/** Заглушка zabbix: поиск отвечает заранее заданными результатами, узлы берутся из кэша */
class searchStubZabbixApi extends zabbixApi {
	public $byMacros=false;		//что вернёт поиск по макросам
	public $byFqdn=null;		//что вернёт поиск по FQDN (null - не нашёл)
	public $byIps=false;		//что вернёт поиск по IP

	public function searchHostByMacros($macros) {return $this->byMacros;}
	public function searchHostFqdn($fqdn=null) {return $this->byFqdn;}
	public function searchHostByIps($ips) {return $this->byIps;}
}

/** Заглушка инвентори: единицы оборудования задаются списком id */
class searchStubInventoryApi extends inventoryApi {
	public $techs=[];

	public function getTech($id) {return $this->techs[$id]??null;}
}

/**
 * Тесты поиска узла zabbix для единицы оборудования: адрес - вещь переезжающая,
 * поэтому найденный по FQDN/IP узел нельзя забирать у другой записи инвентори.
 * Сеть не используется.
 */
class rulesPipelineSearchTest extends miniTestCase {

	/** Узел zabbix с макросами привязки к инвентори */
	protected function zHost($hostid,$class=null,$id=null) {
		$macros=[];
		if ($class!==null) $macros[]=['macro'=>'{$INVENTORY_CLASS}','value'=>$class];
		if ($id!==null) $macros[]=['macro'=>'{$INVENTORY_ID}','value'=>(string)$id];
		return ['hostid'=>$hostid,'host'=>'10.0.0.5','macros'=>$macros];
	}

	/** Единица оборудования из инвентори */
	protected function tech($id,$num,$ip='10.0.0.5',$fqdn='') {
		return ['class'=>'techs','id'=>$id,'num'=>$num,'ip'=>$ip,'fqdn'=>$fqdn];
	}

	/**
	 * Собирает конвейер на заглушках
	 * @param array $zHosts узлы zabbix (hostid => узел)
	 * @param array $techs единицы оборудования инвентори (id => узел)
	 */
	protected function pipeline($zHosts=[],$techs=[]) {
		$zabbix=new searchStubZabbixApi();
		$zabbix->cache['hosts']=$zHosts;

		$inventory=new searchStubInventoryApi();
		$inventory->techs=$techs;

		$pipeLine=new rulesPipeline();
		$pipeLine->zabbixApi=$zabbix;
		$pipeLine->inventoryApi=$inventory;
		return $pipeLine;
	}

	/** Своя привязка по макросам - приоритет, проверка занятости не нужна */
	public function testFoundByMacrosWins() {
		$pipeLine=$this->pipeline(['10501'=>$this->zHost('10501','techs',62)],[62=>['id'=>62]]);
		$pipeLine->zabbixApi->byMacros='10501';
		$pipeLine->zabbixApi->byIps='10501';

		$this->assertSame('10501',$pipeLine->findTechsZabbixHostid($this->tech(62,'МХК-NET-0062')));
	}

	/**
	 * Замена оборудования: найденный по IP узел принадлежит другой единице,
	 * которая ещё есть в инвентори - не забираем, преемник получит свой узел
	 */
	public function testFoundByIpButOwnedByLivingTechIsRejected() {
		$pipeLine=$this->pipeline(
			['10501'=>$this->zHost('10501','techs',62)],	//узел снятого с эксплуатации МХК-NET-0062
			[62=>['id'=>62,'num'=>'МХК-NET-0062']]			//и он ещё числится в инвентори
		);
		$pipeLine->zabbixApi->byIps='10501';

		$this->assertFalse($pipeLine->findTechsZabbixHostid($this->tech(79,'МХК-NET-0079')));
	}

	/** Узел без макросов привязки (заведён в zabbix руками) - подхватываем */
	public function testFoundByIpUnboundHostIsAdopted() {
		$pipeLine=$this->pipeline(['10501'=>$this->zHost('10501')]);
		$pipeLine->zabbixApi->byIps='10501';

		$this->assertSame('10501',$pipeLine->findTechsZabbixHostid($this->tech(79,'МХК-NET-0079')));
	}

	/** Узел ссылается на единицу, которой в инвентори уже нет - осиротел, подбираем */
	public function testFoundByIpOrphanedHostIsAdopted() {
		$pipeLine=$this->pipeline(['10501'=>$this->zHost('10501','techs',62)],[]);	//записи 62 больше нет
		$pipeLine->zabbixApi->byIps='10501';

		$this->assertSame('10501',$pipeLine->findTechsZabbixHostid($this->tech(79,'МХК-NET-0079')));
	}

	/** Узел привязан к компьютеру - это не наш объект */
	public function testFoundByIpBoundToCompsIsRejected() {
		$pipeLine=$this->pipeline(['10501'=>$this->zHost('10501','comps',7)]);
		$pipeLine->zabbixApi->byIps='10501';

		$this->assertFalse($pipeLine->findTechsZabbixHostid($this->tech(79,'МХК-NET-0079')));
	}

	/** Узел ссылается на нас же (например, сменился URL инвентори) - забираем */
	public function testFoundByIpBoundToSelfIsAdopted() {
		$pipeLine=$this->pipeline(['10501'=>$this->zHost('10501','techs',79)],[79=>['id'=>79]]);
		$pipeLine->zabbixApi->byIps='10501';

		$this->assertSame('10501',$pipeLine->findTechsZabbixHostid($this->tech(79,'МХК-NET-0079')));
	}

	/** По FQDN действует то же правило: чужой занятый узел не забираем */
	public function testFoundByFqdnOwnedByLivingTechIsRejected() {
		$pipeLine=$this->pipeline(
			['10501'=>$this->zHost('10501','techs',62)],
			[62=>['id'=>62]]
		);
		$pipeLine->zabbixApi->byFqdn='10501';

		$this->assertFalse($pipeLine->findTechsZabbixHostid($this->tech(79,'МХК-NET-0079','10.0.0.5','sw79.domain.local')));
	}

	/** Не нашли по FQDN - идём искать по IP (searchHostFqdn отдаёт null, а не false) */
	public function testFqdnMissFallsThroughToIp() {
		$pipeLine=$this->pipeline(['10501'=>$this->zHost('10501')]);
		$pipeLine->zabbixApi->byFqdn=null;
		$pipeLine->zabbixApi->byIps='10501';

		$this->assertSame('10501',$pipeLine->findTechsZabbixHostid($this->tech(79,'МХК-NET-0079','10.0.0.5','sw79.domain.local')));
	}

	/** Не нашли нигде - создаём новый узел */
	public function testNotFoundAnywhere() {
		$pipeLine=$this->pipeline();
		$this->assertFalse($pipeLine->findTechsZabbixHostid($this->tech(79,'МХК-NET-0079')));
	}

}
