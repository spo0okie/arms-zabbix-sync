<?php

require_once __DIR__.'/../lib_syncPlanner.php';

/**
 * Тесты планировщика: сборка стеков оборудования и разрешение коллизий,
 * когда несколько узлов инвентори резолвятся в один узел zabbix.
 * Сеть и конфиг не нужны.
 */
class syncPlannerTest extends miniTestCase {

	/** Единица оборудования как её отдаёт инвентори */
	protected function tech($num,$model,$ip,$id=null) {
		return [
			'class'=>'techs',
			'id'=>$id??$num,
			'num'=>$num,
			'ip'=>$ip,
			'model'=>['name'=>$model],
		];
	}

	/** Запись к обработке: узел + вывод конвейера */
	protected function entry($item,$params) {
		return ['item'=>$item,'params'=>$params];
	}

	/** Оборудование в работе: конвейер разрешает создание и обновление */
	protected function monitored() {
		return ['actions'=>['update','create'],'status'=>[0]];
	}

	/** Оборудование на складе: правило ARCHIVED сняло create и погасило мониторинг */
	protected function warehoused() {
		return ['actions'=>['update'],'status'=>[1]];
	}

	/** Параметры, которые планировщик оставляет слейву стека */
	protected function stackSlave() {
		return ['actions'=>['update'],'status'=>[1]];
	}

	/** Инвентарные номера узлов в порядке обработки */
	protected function nums($processedItems) {
		return array_map(function($e) {return $e['item']['num'];},$processedItems);
	}

	// --- статус записи ------------------------------------------------

	/** Статус разворачивается и из массива (после array_merge_recursive), и из скаляра */
	public function testEntryStatus() {
		$this->assertSame(1,syncPlanner::entryStatus(['status'=>[1]]));
		$this->assertSame(1,syncPlanner::entryStatus(['status'=>1]));
		$this->assertSame(0,syncPlanner::entryStatus(['status'=>[0,1]]));	//берём первый, как applyPipelineActions
		//мониторинг не трогаем - узел считаем работающим
		$this->assertSame(0,syncPlanner::entryStatus(['actions'=>['update']]));
		$this->assertFalse(syncPlanner::isDisabling(['actions'=>['update']]));
		$this->assertTrue(syncPlanner::isDisabling(['status'=>[1]]));
	}

	// --- сборка стеков ------------------------------------------------

	/** Настоящий стек: мастер - наименьший номер, остальным только гасим мониторинг */
	public function testRealStackKeepsMasterAndDisablesRest() {
		$log=[];
		$processedItems=syncPlanner::buildProcessedItems([
			$this->entry($this->tech('МХК-NET-0044','C2960','10.0.0.5'),$this->monitored()),
			$this->entry($this->tech('МХК-NET-0012','C2960','10.0.0.5'),$this->monitored()),
			$this->entry($this->tech('МХК-NET-0031','C2960','10.0.0.5'),$this->monitored()),
		],function($msg) use (&$log) {$log[]=$msg;});

		//мастер первым, слейвы следом
		$this->assertSame(['МХК-NET-0012','МХК-NET-0031','МХК-NET-0044'],$this->nums($processedItems));
		//мастер идёт со своими параметрами из конвейера
		$this->assertSame($this->monitored(),$processedItems[0]['params']);
		//слейвам оставлено только выключение
		$this->assertSame($this->stackSlave(),$processedItems[1]['params']);
		$this->assertSame($this->stackSlave(),$processedItems[2]['params']);
		$this->assertSame(1,count($log));
	}

	/**
	 * Замена оборудования той же моделью: складская единица тащит устаревшие
	 * model+ip, но стеком с заменившим её устройством не является - иначе
	 * мастером стал бы склад (номер меньше), а замену бы погасили
	 */
	public function testWarehousedTwinIsNotAStack() {
		$processedItems=syncPlanner::buildProcessedItems([
			$this->entry($this->tech('МХК-NET-0012','C2960','10.0.0.5'),$this->warehoused()),
			$this->entry($this->tech('МХК-NET-0079','C2960','10.0.0.5'),$this->monitored()),
		]);

		$this->assertSame(2,count($processedItems));
		//складская запись не превратилась в мастера и не изменилась
		$this->assertSame('МХК-NET-0012',$processedItems[0]['item']['num']);
		$this->assertSame($this->warehoused(),$processedItems[0]['params']);
		//замена сохранила свои параметры: create на месте, мониторинг включён
		$this->assertSame('МХК-NET-0079',$processedItems[1]['item']['num']);
		$this->assertSame($this->monitored(),$processedItems[1]['params']);
	}

	/** Стек, из которого одну единицу увезли на склад: мастер считается по оставшимся */
	public function testWarehousedMemberDropsOutOfStack() {
		$processedItems=syncPlanner::buildProcessedItems([
			$this->entry($this->tech('МХК-NET-0012','C2960','10.0.0.5'),$this->warehoused()),
			$this->entry($this->tech('МХК-NET-0031','C2960','10.0.0.5'),$this->monitored()),
			$this->entry($this->tech('МХК-NET-0044','C2960','10.0.0.5'),$this->monitored()),
		]);

		//складская запись ушла напрямую, стек собрался из двух рабочих
		$this->assertSame(['МХК-NET-0012','МХК-NET-0031','МХК-NET-0044'],$this->nums($processedItems));
		$this->assertSame($this->warehoused(),$processedItems[0]['params']);
		$this->assertSame($this->monitored(),$processedItems[1]['params']);		//мастер
		$this->assertSame($this->stackSlave(),$processedItems[2]['params']);	//слейв
	}

	/** Оборудование разных моделей и компьютеры в стеки не собираются */
	public function testDifferentModelsAndCompsPassThrough() {
		$comp=['class'=>'comps','id'=>7,'fqdn'=>'srv1.domain.local','num'=>'srv1.domain.local'];
		$processedItems=syncPlanner::buildProcessedItems([
			$this->entry($comp,$this->monitored()),
			$this->entry($this->tech('МХК-NET-0012','C2960','10.0.0.5'),$this->monitored()),
			$this->entry($this->tech('МХК-NET-0079','C9200','10.0.0.5'),$this->monitored()),
		]);

		$this->assertSame(3,count($processedItems));
		foreach ($processedItems as $entry) $this->assertSame($this->monitored(),$entry['params']);
	}

	// --- коллизии за узел zabbix --------------------------------------

	/** Один узел zabbix обслуживает одну запись инвентори: кто занял первым, тот и работает */
	public function testFirstClaimWins() {
		$planner=new syncPlanner();
		$this->assertTrue($planner->claimHost('10501'));
		$this->assertFalse($planner->claimHost('10501'));
		//другой узел не затронут
		$this->assertTrue($planner->claimHost('10502'));
	}

	/**
	 * Узел не передаётся от записи к записи: за ним стоит история наблюдений
	 * конкретного устройства. Замена оборудования - это новый узел zabbix
	 */
	public function testClaimIsNeverHandedOver() {
		$planner=new syncPlanner();
		//складская запись нашла узел по своему макросу с id инвентори
		$this->assertTrue($planner->claimHost('10501'));
		//пришедшая на замену единица нашла тот же узел по IP - не отдаём
		$this->assertFalse($planner->claimHost('10501'));
	}

}
