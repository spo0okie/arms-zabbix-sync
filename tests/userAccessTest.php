<?php

require_once __DIR__.'/../lib_userAccess.php';

/**
 * Тесты логики add_user.php: идемпотентное слияние прав и фильтров группы
 * пользователей, поиск правила в rules.priv.php. Сеть не нужна.
 */
class userAccessTest extends miniTestCase {

	public function testSplitEname() {
		$this->assertSame(['Концевич','Михаил'],userAccess::splitEname(' Концевич  Михаил Андреевич '));
		$this->assertSame(['Котов',''],userAccess::splitEname('Котов'));
		$this->assertSame(['',''],userAccess::splitEname(null));
	}

	public function testReadRightsAddedWhenMissing() {
		$rights=userAccess::ensureReadRights([],[10,20],$changed);
		$this->assertTrue($changed);
		$this->assertSame([['id'=>'10','permission'=>2],['id'=>'20','permission'=>2]],$rights);
	}

	public function testReadRightsKeepExistingAndHigher() {
		$current=[['id'=>'10','permission'=>'3'],['id'=>'20','permission'=>'2'],['id'=>'30','permission'=>'2']];
		$rights=userAccess::ensureReadRights($current,[10,20],$changed);
		$this->assertFalse($changed);
		$this->assertSame([['id'=>'10','permission'=>3],['id'=>'20','permission'=>2],['id'=>'30','permission'=>2]],$rights);
	}

	public function testReadRightsOverrideDeny() {
		$rights=userAccess::ensureReadRights([['id'=>'10','permission'=>'0']],[10],$changed);
		$this->assertTrue($changed);
		$this->assertSame([['id'=>'10','permission'=>2]],$rights);
	}

	public function testTagFiltersIdempotent() {
		$required=[
			['groupid'=>'10','tag'=>'','value'=>''],
			['groupid'=>'4','tag'=>'serviceman','value'=>'Концевич'],
		];
		$first=userAccess::ensureTagFilters([],$required,$changed);
		$this->assertTrue($changed);
		$this->assertSame(2,count($first));

		//так фильтры возвращает usergroup.get — повторный прогон ничего не меняет
		$second=userAccess::ensureTagFilters($first,$required,$changed);
		$this->assertFalse($changed);
		$this->assertSame($first,$second);
	}

	public function testTagFiltersKeepForeign() {
		$current=[['groupid'=>'4','tag'=>'serviceman','value'=>'Иванов']];
		$result=userAccess::ensureTagFilters($current,[['groupid'=>'4','tag'=>'serviceman','value'=>'Концевич']],$changed);
		$this->assertTrue($changed);
		$this->assertSame(2,count($result));
	}

	public function testHasNodesRule() {
		$rules=[
			[[['type'=>'comps'],['actions'=>['update']]]],
			'users'=>[
				'desc'=>'группы пользователей',
				[['type'=>['comps','techs'],'teamLogins'=>['kontsevich_ma']],['groups'=>['kontsevich_ma nodes']]],
			],
		];
		$this->assertTrue(userAccess::hasNodesRule($rules,'kontsevich_ma'));
		$this->assertFalse(userAccess::hasNodesRule($rules,'kotov.n'));
	}

	public function testHasNodesRuleScalarsAndCase() {
		$rules=[[[['teamlogins'=>'kotov.n'],['groups'=>'kotov.n nodes']]]];
		$this->assertTrue(userAccess::hasNodesRule($rules,'kotov.n'));
	}

	public function testHasNodesRuleNeedsBothParts() {
		$rules=[[
			[['teamLogins'=>['kotov.n']],['groups'=>['other nodes']]],
			[['teamLogins'=>['other']],['groups'=>['kotov.n nodes']]],
		]];
		$this->assertFalse(userAccess::hasNodesRule($rules,'kotov.n'));
	}

	public function testSnippetIsDetectedRule() {
		$snippet=userAccess::nodesRuleSnippet('kotov.n','Котов');
		$rules=eval('return ['.$snippet.'];');
		$this->assertTrue(userAccess::hasNodesRule($rules,'kotov.n'));
	}

	public function testPasswordComplexity() {
		$password=userAccess::generatePassword();
		$this->assertSame(20,strlen($password));
		$this->assertTrue(
			preg_match('/[a-z]/',$password) && preg_match('/[A-Z]/',$password)
			&& preg_match('/[0-9]/',$password) && preg_match('/[^a-zA-Z0-9]/',$password)
		);
	}
}
