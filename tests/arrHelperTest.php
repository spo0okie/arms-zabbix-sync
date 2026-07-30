<?php

require_once __DIR__.'/../lib_arrHelper.php';

class arrHelperStrMatchTest extends miniTestCase {

	public function testExactString() {
		$this->assertTrue(arrHelper::strMatch('Windows Server','Windows Server'));
		$this->assertFalse(arrHelper::strMatch('Windows Server','Windows'));
		$this->assertFalse(arrHelper::strMatch('Windows','Windows Server'));
	}

	public function testSearchArrayAnyItemMatches() {
		$this->assertTrue(arrHelper::strMatch('CentOS',['Ubuntu','CentOS','Debian']));
		$this->assertFalse(arrHelper::strMatch('Astra',['Ubuntu','CentOS','Debian']));
		$this->assertFalse(arrHelper::strMatch('CentOS',[]),'пустой набор поиска не матчится ни с чем');
	}

	public function testValueArrayAnyValueMatches() {
		$this->assertTrue(arrHelper::strMatch(['10.0.0.1','192.168.0.1'],'192.168.0.1'));
		$this->assertFalse(arrHelper::strMatch(['10.0.0.1','192.168.0.1'],'172.16.0.1'));
		$this->assertFalse(arrHelper::strMatch([],'что угодно'),'пустое множество значений не матчится ни с чем');
	}

	public function testValueArrayVsSearchArray() {
		$this->assertTrue(arrHelper::strMatch(['a','b'],['c','b']));
		$this->assertFalse(arrHelper::strMatch(['a','b'],['c','d']));
	}

	public function testRegexSearchItem() {
		$this->assertTrue(arrHelper::strMatch('Ubuntu 22.04','/Ubuntu/'));
		$this->assertTrue(arrHelper::strMatch('srv-web-01','/^srv-/'));
		$this->assertFalse(arrHelper::strMatch('wks-web-01','/^srv-/'));
		$this->assertTrue(arrHelper::strMatch('abcd','/bc/'),'регулярка ищет вхождение, не полное совпадение');
	}

	public function testMixedExactAndRegex() {
		$search=['Windows Server','/^Linux/'];
		$this->assertTrue(arrHelper::strMatch('Windows Server',$search));
		$this->assertTrue(arrHelper::strMatch('Linux Mint',$search));
		$this->assertFalse(arrHelper::strMatch('FreeBSD',$search));
	}

	public function testShortSlashStringsAreLiteral() {
		// длина <=2 — не считается регуляркой, сравнивается буквально
		$this->assertTrue(arrHelper::strMatch('/','/'));
		$this->assertTrue(arrHelper::strMatch('//','//'));
		$this->assertFalse(arrHelper::strMatch('abc','//'));
	}

	public function testSlashOnlyAtOneEndIsLiteral() {
		$this->assertFalse(arrHelper::strMatch('path','/path'),'/path — не регулярка и не равно path');
		$this->assertTrue(arrHelper::strMatch('/path','/path'));
		$this->assertFalse(arrHelper::strMatch('etc','etc/'));
	}

	public function testEmptyStrings() {
		$this->assertTrue(arrHelper::strMatch('',''));
		$this->assertFalse(arrHelper::strMatch('a',''));
	}

	public function testRegexCaseSensitivityAndFlags() {
		$this->assertFalse(arrHelper::strMatch('ubuntu','/Ubuntu/'));
		// флаги после закрывающего / не поддерживаются: строка не оканчивается на /,
		// поэтому сравнивается буквально
		$this->assertFalse(arrHelper::strMatch('ubuntu','/Ubuntu/i'));
	}
}
