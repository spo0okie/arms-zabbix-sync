<?php

require_once __DIR__.'/../lib_arrHelper.php';
require_once __DIR__.'/../lib_zabbixApi.php';
require_once __DIR__.'/../lib_inventoryApi.php';
require_once __DIR__.'/../lib_rulesPipeline.php';

/**
 * Тесты модификаторов макросов (${inventory:num|translit|normalize})
 * и валидации технического имени узла на конвейере.
 * Сеть не используется.
 */
class rulesPipelineModifiersTest extends miniTestCase {

	protected function tech($extra=[]) {
		return array_merge([
			'class'=>'techs',
			'id'=>3788,
			'num'=>'МХК-NET-0079',
			'ip'=>'172.16.2.21',
			'fqdn'=>'',
			'hostname'=>'',
		],$extra);
	}

	/** Голый конвейер: без Zabbix, с одним заданным набором правил */
	protected function pipeline($ruleSets) {
		$pipeLine=new rulesPipeline();
		$pipeLine->ruleSets=$ruleSets;
		return $pipeLine;
	}

	// --- модификаторы --------------------------------------------------

	/** Транслитерация побуквенная, МХК и МСК не сливаются */
	public function testTranslit() {
		$this->assertSame('MHK-NET-0079',rulesPipeline::macroModTranslit('МХК-NET-0079'));
		$this->assertSame('MSK-KOM-0011',rulesPipeline::macroModTranslit('МСК-КОМ-0011'));
		$this->assertSame('CHEL-VFY-0004',rulesPipeline::macroModTranslit('ЧЕЛ-ВФЙ-0004'));
		//латиница и цифры не трогаются
		$this->assertSame('srv1.domain.local',rulesPipeline::macroModTranslit('srv1.domain.local'));
	}

	/** Нормализация: суффиксы песочниц разных форматов дают чистый токен */
	public function testNormalize() {
		$this->assertSame('SAP_2022',rulesPipeline::macroModNormalize('(SAP_2022)'));
		$this->assertSame('CLONE',rulesPipeline::macroModNormalize('_CLONE'));
		$this->assertSame('CLONE2',rulesPipeline::macroModNormalize(' (CLONE2)'));
		$this->assertSame('VCENTERPASS',rulesPipeline::macroModNormalize('_VCENTERPASS'));
	}

	/** Подстановка с модификаторами: значение прогоняется слева направо */
	public function testMacroWithModifiers() {
		$pipeLine=$this->pipeline([]);
		$value='${inventory:num|translit}';
		$pipeLine->replaceInventoryMacros($value,$this->tech());
		$this->assertSame('MHK-NET-0079',$value);
	}

	/** Модификаторы и обычные макросы в одной строке уживаются */
	public function testModifiedAndPlainMacrosMix() {
		$pipeLine=$this->pipeline([]);
		$value='${inventory:num} -> ${inventory:num|translit|normalize}';
		$pipeLine->replaceInventoryMacros($value,$this->tech());
		$this->assertSame('МХК-NET-0079 -> MHK-NET-0079',$value);
	}

	// --- валидация host на конвейере ------------------------------------

	/** Кириллица в host без |translit - внятная ошибка по узлу, а не ошибка API */
	public function testCyrillicHostWithoutTranslitIsCaught() {
		$pipeLine=$this->pipeline([
			[ [[],['host'=>'${inventory:num}','actions'=>['update']]] ],
		]);
		$actions=$pipeLine->pipeHost($this->tech());

		$this->assertTrue(isset($actions['errors']));
		$this->assertSame(1,count($actions['errors']));
		//в ошибке видно и само имя, и чем лечить
		$this->assertTrue(strpos($actions['errors'][0],'МХК-NET-0079')!==false);
		$this->assertTrue(strpos($actions['errors'][0],'|translit')!==false);
	}

	/** С |translit тот же набор правил проходит чисто */
	public function testTranslitHostPassesValidation() {
		$pipeLine=$this->pipeline([
			[ [[],['host'=>'${inventory:num|translit}','name'=>'${inventory:num}','actions'=>['update']]] ],
		]);
		$actions=$pipeLine->pipeHost($this->tech());

		$this->assertFalse(isset($actions['errors']));
		$this->assertSame(['MHK-NET-0079'],$actions['host']);
		//видимое имя осталось кириллическим
		$this->assertSame(['МХК-NET-0079'],$actions['name']);
	}

	/** Скобки песочниц в host тоже ловятся */
	public function testParenthesesHostIsCaught() {
		$pipeLine=$this->pipeline([
			[ [[],['host'=>'hana-erp(SAP_2022)','actions'=>['update']]] ],
		]);
		$actions=$pipeLine->pipeHost($this->tech());

		$this->assertTrue(isset($actions['errors']));
	}

	/** Валидные технические имена ошибок не порождают */
	public function testValidTechNames() {
		$this->assertTrue(rulesPipeline::isValidTechName('srv1.domain.local'));
		$this->assertTrue(rulesPipeline::isValidTechName('MHK-NET-0079'));
		$this->assertTrue(rulesPipeline::isValidTechName('nxlic_CLONE'));
		$this->assertTrue(rulesPipeline::isValidTechName('172.16.2.21'));
		$this->assertFalse(rulesPipeline::isValidTechName('МХК-NET-0079'));
		$this->assertFalse(rulesPipeline::isValidTechName('hana-erp(SAP_2022)'));
		$this->assertFalse(rulesPipeline::isValidTechName(''));
	}

}
