<?php

require_once __DIR__.'/../lib_rulesPipeline.php';
require_once __DIR__.'/../lib_inventoryApi.php';

/**
 * Тесты конвейера правил: трейс traceRuleSet, explain-режим (без Zabbix),
 * вердикты и обратная совместимость со старым (безымянным) форматом rules.
 * Сеть не используется.
 */
class rulesPipelineTraceTest extends miniTestCase {

	protected function windowsHost($extra=[]) {
		return array_merge([
			'class'=>'comps',
			'id'=>42,
			'fqdn'=>'srv1.domain.local',
			'os'=>'Windows Server 2019',
		],$extra);
	}

	/** Старый формат набора (числовые ключи, без desc): первое совпавшее правило побеждает */
	public function testTraceOldFormatFirstMatchWins() {
		$ruleSet=[
			[ ['type'=>'techs'],					['actions'=>['update']] ],
			[ ['type'=>'comps','OS'=>['Windows']],	['actions'=>['update','create']] ],
			[ [],									['errors'=>'unreachable'] ],
		];
		$trace=rulesPipeline::traceRuleSet($ruleSet,$this->windowsHost());
		$this->assertSame(1,$trace['matched']);
		//после совпадения перебор остановлен: проверено только 2 правила
		$this->assertSame(2,count($trace['rules']));
		$this->assertSame(['update','create'],$trace['actions']['actions']);
		//по несовпавшему правилу записано какое условие не прошло
		$this->assertSame('type',$trace['rules'][0]['failedOn']);
		$this->assertFalse($trace['rules'][0]['matched']);
		//desc в старом формате отсутствует
		$this->assertSame(null,$trace['rules'][1]['desc']);
	}

	/** checkRuleSet — прежний контракт: только действия сработавшего правила */
	public function testCheckRuleSetBackCompat() {
		$ruleSet=[
			[ ['type'=>'comps'], ['status'=>0,'actions'=>['update']] ],
		];
		$actions=rulesPipeline::checkRuleSet($ruleSet,$this->windowsHost());
		$this->assertSame([0],$actions['status']);
		$this->assertSame(['update'],$actions['actions']);
		//ничего не совпало — пустой результат
		$this->assertSame([],rulesPipeline::checkRuleSet($ruleSet,['class'=>'techs']));
	}

	/** Совпавшее правило с пустыми действиями не останавливает перебор (как раньше) */
	public function testMatchedEmptyActionsContinues() {
		$ruleSet=[
			[ ['type'=>'comps'],	[] ],
			[ [],					['actions'=>['update']] ],
		];
		$trace=rulesPipeline::traceRuleSet($ruleSet,$this->windowsHost());
		$this->assertSame(1,$trace['matched']);
		$this->assertTrue($trace['rules'][0]['matched']);
		$this->assertSame(['update'],$trace['actions']['actions']);
	}

	/** Строковые ключи внутри набора ('desc') — метаданные, а не правила; desc правила попадает в трейс */
	public function testNamedSetAndRuleDesc() {
		$ruleSet=[
			'desc'=>'Проверка на поддержку модели оборудования/операционки',
			[ ['type'=>'comps','OS'=>['Windows']], ['actions'=>['update','create']], 'desc'=>'Windows серверы' ],
		];
		$trace=rulesPipeline::traceRuleSet($ruleSet,$this->windowsHost());
		$this->assertSame(0,$trace['matched']);
		$this->assertSame('Windows серверы',$trace['rules'][0]['desc']);
	}

	/** Метки наборов/правил: с описаниями и без (старый формат) */
	public function testLabels() {
		$this->assertSame('set#3',rulesPipeline::setLabel(3));
		$this->assertSame('set#3 (Площадки)',rulesPipeline::setLabel(3,['desc'=>'Площадки']));
		$this->assertSame('set[Площадки]',rulesPipeline::setLabel('Площадки'));
		$this->assertSame('rule#5',rulesPipeline::ruleLabel(['index'=>5]));
		$this->assertSame('rule#5 (Коммутаторы H3C)',rulesPipeline::ruleLabel(['index'=>5,'desc'=>'Коммутаторы H3C']));
	}

	/** Вердикты по итоговым действиям конвейера */
	public function testExplainVerdict() {
		$linked=['external_links'=>json_encode([inventoryApi::ZABBIX_HOSTID_KEY=>'10501'])];
		$unlinked=[];

		//правила явно запретили
		$this->assertSame('declined',rulesPipeline::explainVerdict(['errors'=>['домен не задан'],'actions'=>[]],$unlinked));
		//уже привязан к zabbix и будет обновляться
		$this->assertSame('monitored',rulesPipeline::explainVerdict(['actions'=>['update','create']],$linked));
		$this->assertSame('monitored',rulesPipeline::explainVerdict(['actions'=>['update']],$linked));
		//не привязан, создание разрешено — будет добавлен
		$this->assertSame('add',rulesPipeline::explainVerdict(['actions'=>['update','create']],$unlinked));
		//не привязан, создание запрещено (напр. ageOver снял create) — не добавится
		$this->assertSame('update-only',rulesPipeline::explainVerdict(['actions'=>['update']],$unlinked));
		//конвейер не назначил действий
		$this->assertSame('skip',rulesPipeline::explainVerdict(['actions'=>[]],$unlinked));
	}

	protected function explainPipeline() {
		$rules=[
			'Проверка на поддержку операционки'=>[
				'desc'=>'Какие ОС умеем мониторить',
				[ ['type'=>'comps','OS'=>['Windows']],
					['actions'=>['update','create'],'templates'=>['Windows by Zabbix agent']],
					'desc'=>'Windows серверы' ],
				[ [], ['errors'=>'ОС не поддерживается'] ],
			],
			[ //безымянный набор в старом формате
				[ ['type'=>'comps'], ['name'=>'${inventory:fqdn}','status'=>0,'PSK'=>['wks'=>'SECRET']] ],
			],
		];
		$pipe=new rulesPipeline();
		//explain-режим: без Zabbix
		$pipe->init(null,new inventoryApi(),$rules);
		return $pipe;
	}

	/** explainHost: структура отчета, макросы, имена шаблонов без резолва в ID */
	public function testExplainHostReport() {
		$report=$this->explainPipeline()->explainHost($this->windowsHost());

		$this->assertSame('add',$report['verdict']);
		$this->assertSame([],$report['errors']);
		$this->assertSame(0,$report['status']);
		$this->assertSame('comps',$report['host']['class']);
		$this->assertSame('srv1.domain.local',$report['host']['name']);

		//первый набор: именованный, совпало правило 0 с описанием
		$this->assertSame('Проверка на поддержку операционки',$report['sets'][0]['index']);
		$this->assertSame('Какие ОС умеем мониторить',$report['sets'][0]['desc']);
		$this->assertSame(0,$report['sets'][0]['matched']);
		$this->assertSame('Windows серверы',$report['sets'][0]['rules'][0]['desc']);
		//условия в отчете — читаемой строкой
		$this->assertSame('type=comps, OS=Windows',$report['sets'][0]['rules'][0]['conditions']);

		//второй набор: старый формат, без имени
		$this->assertSame(0,$report['sets'][1]['index']);
		$this->assertSame(null,$report['sets'][1]['desc']);

		//шаблоны остались именами (Zabbix не подключался)
		$this->assertSame(['Windows by Zabbix agent'],$report['actions']['templates']);
		//макрос инвентори подставлен
		$this->assertSame(['srv1.domain.local'],$report['actions']['name']);
		//секреты (PSK) в отчет не попадают
		$this->assertFalse(isset($report['actions']['PSK']));
	}

	/** explainHost: отказ по правилам (errors) */
	public function testExplainHostDeclined() {
		$report=$this->explainPipeline()->explainHost([
			'class'=>'comps','id'=>43,'fqdn'=>'lin1.domain.local','os'=>'Ubuntu 22.04',
		]);
		$this->assertSame('declined',$report['verdict']);
		$this->assertSame(['ОС не поддерживается'],$report['errors']);
		//в трейсе видно на чем срезалось правило Windows
		$this->assertSame('OS',$report['sets'][0]['rules'][0]['failedOn']);
		$this->assertSame(1,$report['sets'][0]['matched']);
	}

	/** explainHost: узел уже привязан к Zabbix через external_links */
	public function testExplainHostMonitored() {
		$report=$this->explainPipeline()->explainHost($this->windowsHost([
			'external_links'=>json_encode([inventoryApi::ZABBIX_HOSTID_KEY=>'10501']),
		]));
		$this->assertSame('monitored',$report['verdict']);
	}
}
