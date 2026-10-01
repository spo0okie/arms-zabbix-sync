<?php

require_once __DIR__.'/../lib_inventoryApi.php';

/**
 * Тесты обратной записи в инвентори (external_links: Zabbix.hostid).
 * Сеть не используется — put() подменяется в наследнике, проверяется
 * контракт payload'а и разбор существующих ссылок.
 */
class inventoryApiWritebackTest extends miniTestCase {

	/** Разбор external_links узла: ключ Zabbix.hostid читается */
	public function testExternalLinksParse() {
		$item=['external_links'=>json_encode([
			'VMWare.UUID'=>'abc@vc',
			'Zabbix.hostid'=>'10501',
		])];
		$links=inventoryApi::externalLinks($item);
		$this->assertSame('10501',$links['Zabbix.hostid']);
		$this->assertSame('abc@vc',$links['VMWare.UUID']);
	}

	/** Пустое/битое external_links не роняет разбор */
	public function testExternalLinksEmpty() {
		$this->assertSame([],inventoryApi::externalLinks(['external_links'=>'']));
		$this->assertSame([],inventoryApi::externalLinks([]));
		$this->assertSame([],inventoryApi::externalLinks(['external_links'=>'not-json']));
	}

	/**
	 * setExternalLink шлёт PUT на /api/<class>/<id> и кладёт external_links
	 * как JSON-строку с одним ключом (инвентори домержит поверх остального)
	 */
	public function testSetExternalLinkPayload() {
		$api=new class extends inventoryApi {
			public $lastPath=null;
			public $lastBody=null;
			public function put($path,$body) {
				$this->lastPath=$path;
				$this->lastBody=$body;
				return ['id'=>123]; //инвентори вернул сохранённый объект
			}
		};

		$ok=$api->setExternalLink('comps',123,'Zabbix.hostid','10501');

		$this->assertTrue($ok);
		$this->assertSame('/api/comps/123',$api->lastPath);
		//тело: external_links — строка JSON с единственным ключом
		$this->assertTrue(isset($api->lastBody['external_links']));
		$decoded=json_decode($api->lastBody['external_links'],true);
		$this->assertSame(['Zabbix.hostid'=>'10501'],$decoded);
	}

	/** Неуспех инвентори (нет id в ответе) => setExternalLink=false */
	public function testSetExternalLinkFailure() {
		$api=new class extends inventoryApi {
			public function put($path,$body) { return ['error'=>'nope']; }
		};
		$this->assertFalse($api->setExternalLink('techs',5,'Zabbix.hostid','1'));

		$apiNull=new class extends inventoryApi {
			public function put($path,$body) { return null; } //транспорт упал
		};
		$this->assertFalse($apiNull->setExternalLink('techs',5,'Zabbix.hostid','1'));
	}
}
