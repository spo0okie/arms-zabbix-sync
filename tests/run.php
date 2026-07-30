<?php

/*
 * Мини test-runner без внешних зависимостей (в проекте нет composer).
 * Запуск: php tests/run.php   (или tests.cmd из корня)
 *
 * Как добавить тесты:
 *  - файл tests/<что-то>Test.php
 *  - в нем класс с именем, оканчивающимся на Test, унаследованный от miniTestCase
 *  - публичные методы test*() с ассертами
 */

class assertionFailed extends Exception {}

abstract class miniTestCase {

	public function assertTrue($actual,$message='') {
		if ($actual!==true) $this->fail('expected TRUE, got '.var_export($actual,true),$message);
	}

	public function assertFalse($actual,$message='') {
		if ($actual!==false) $this->fail('expected FALSE, got '.var_export($actual,true),$message);
	}

	public function assertSame($expected,$actual,$message='') {
		if ($expected!==$actual)
			$this->fail('expected '.var_export($expected,true).', got '.var_export($actual,true),$message);
	}

	protected function fail($details,$message='') {
		throw new assertionFailed(($message?"$message: ":'').$details);
	}
}

$passed=0;
$failures=[];

foreach (glob(__DIR__.'/*Test.php') as $file) {
	$before=get_declared_classes();
	require_once $file;
	$declared=array_diff(get_declared_classes(),$before);

	foreach ($declared as $class) {
		if (substr($class,-4)!=='Test') continue;
		$test=new $class();
		foreach (get_class_methods($class) as $method) {
			if (strpos($method,'test')!==0) continue;
			try {
				$test->$method();
				$passed++;
				echo ".";
			} catch (assertionFailed $e) {
				$failures[]="$class::$method — ".$e->getMessage();
				echo "F";
			} catch (Throwable $e) {
				$failures[]="$class::$method — ERROR: ".$e->getMessage().' @ '.$e->getFile().':'.$e->getLine();
				echo "E";
			}
		}
	}
}

echo "\n\n";
foreach ($failures as $failure) echo "FAIL: $failure\n";
echo $passed." passed, ".count($failures)." failed\n";
exit(count($failures)?1:0);
