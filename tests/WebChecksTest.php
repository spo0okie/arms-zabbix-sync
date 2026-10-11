<?php
require_once __DIR__.'/../lib_webChecks.php';

/** Подмена API: фиксирует обращения, не подключаясь к Zabbix. */
class webChecksFakeApi {
    public $calls=[];
    public $scenarios=[];
    public $triggers=[];
    public $failMethod=null;

    /** Возвращает подготовленные выборки или результат записи. */
    public function req($method, $params, $allowEmpty=false) {
        $this->calls[]=['method'=>$method, 'params'=>$params];
        if ($method===$this->failMethod) return null; // Имитируем ошибку API, а не пустую выборку.
        if ($method==='template.get') return [['templateid'=>'10', 'host'=>'Service web monitoring']];
        if ($method==='httptest.get') return $this->scenarios;
        if ($method==='trigger.get') return $this->triggers;
        return ['ids'=>['123']];
    }
}

/** Проверки выражений, идемпотентности, порядка записи и остановки при ошибках API. */
class WebChecksTest extends miniTestCase {
    /** Минимальная конфигурация тестового сервиса. */
    private function data(array $options=[]): array {
        return ['template'=>'Service web monitoring', 'services'=>[array_merge(['name'=>'Сайт', 'url'=>'https://example.com/'], $options)]];
    }

    /** Типовой шаг и выражения повторяют заданные условия проблемы и восстановления. */
    public function testDefaultsAndRecovery() {
        $check=webChecks::prepare($this->data(['recovery'=>2]))['Сайт'];
        $this->assertSame('200', $check['scenario']['steps'][0]['status_codes']);
        $this->assertSame('10s', $check['scenario']['steps'][0]['timeout']);
        $this->assertSame('count(/Service web monitoring/web.test.fail[Сайт],#5,"ne",0)>4', $check['trigger']['expression']);
        $this->assertSame('count(/Service web monitoring/web.test.fail[Сайт],#2,"eq",0)=2', $check['trigger']['recovery_expression']);
        $this->assertSame(1, $check['trigger']['recovery_mode']);
    }

    /** Без специального восстановления используется ложность выражения проблемы. */
    public function testNoRecoveryAndSpecialName() {
        $data=$this->data(['name'=>'Сайт [api], "проверка" c {HOST.NAME}', 'problem'=>1]);
        $check=array_values(webChecks::prepare($data))[0];
        $this->assertSame('count(/Service web monitoring/web.test.fail["Сайт [api], \\"проверка\\" c {HOST.NAME}"],#1,"ne",0)>0', $check['trigger']['expression']);
        $this->assertSame(0, $check['trigger']['recovery_mode']);
        $this->assertSame('', $check['trigger']['recovery_expression']);
    }

    /** Многошаговый сценарий получает последовательные номера и индивидуальные настройки. */
    public function testMultipleSteps() {
        $check=webChecks::prepare($this->data(['steps'=>[
            ['name'=>'Вход', 'url'=>'https://example.com/', 'status_codes'=>'200,302', 'posts'=>'a=b'],
            ['name'=>'API', 'url'=>'https://example.com/api', 'timeout'=>'5s', 'required'=>'OK'],
        ]]))['Сайт'];
        $this->assertSame(2, $check['scenario']['steps'][1]['no']);
        $this->assertSame('5s', $check['scenario']['steps'][1]['timeout']);
        $this->assertSame('a=b', $check['scenario']['steps'][0]['posts']);
    }

    /** Некорректный файл отклоняется целиком до записей. */
    public function testInvalidData() {
        foreach ([['problem'=>0], ['recovery'=>-1], ['steps'=>[]], ['steps'=>[['name'=>'Нет URL']]], ['unknown'=>1]] as $options) {
            try { webChecks::prepare($this->data($options)); }
            catch (InvalidArgumentException $e) { continue; }
            $this->fail('Некорректные данные приняты');
        }
        $data=$this->data();
        $data['services'][]=$data['services'][0];
        try { webChecks::prepare($data); }
        catch (InvalidArgumentException $e) { return; }
        $this->fail('Повтор имени принят');
    }

    /** Пробный запуск выполняет только чтения, реальный создаёт сценарии перед триггерами. */
    public function testDryRunAndCreateOrder() {
        $checks=webChecks::prepare($this->data());
        $api=new webChecksFakeApi();
        ob_start();
        try { $plan=webChecks::sync($api, 'Service web monitoring', $checks); }
        finally { ob_end_clean(); }
        $this->assertSame(2, count($plan));
        $this->assertSame(3, count($api->calls));
        ob_start();
        try { webChecks::sync($api, 'Service web monitoring', $checks, true); }
        finally { ob_end_clean(); }
        $this->assertSame('httptest.create', $api->calls[6]['method']);
        $this->assertSame('trigger.create', $api->calls[7]['method']);
        $this->assertSame('10', $api->calls[6]['params']['hostid']);
    }

    /** Повторный запуск не пишет; снятие Recovery и смена URL обновляют существующие ID. */
    public function testUpdateAndRepeat() {
        $checks=webChecks::prepare($this->data(['recovery'=>2]));
        $api=new webChecksFakeApi();
        $api->scenarios=[array_merge($checks['Сайт']['scenario'], ['httptestid'=>'20', 'templateid'=>'0'])];
        $api->scenarios[0]['steps'][0]['httpstepid']='21'; // Служебное поле не вызывает diff.
        $api->triggers=[array_merge($checks['Сайт']['trigger'], ['triggerid'=>'30', 'templateid'=>'0'])];
        ob_start();
        try {
            $this->assertSame([], webChecks::sync($api, 'Service web monitoring', $checks, true));
            $changed=webChecks::prepare($this->data(['url'=>'https://example.com/new']));
            $plan=webChecks::sync($api, 'Service web monitoring', $changed);
        } finally { ob_end_clean(); }
        $this->assertSame('20', $plan[0]['params']['httptestid']);
        $this->assertSame('30', $plan[1]['params']['triggerid']);
        $this->assertSame(0, $plan[1]['params']['recovery_mode']);
        $this->assertSame('', $plan[1]['params']['recovery_expression']);
    }

    /** Снятие POST/проверки текста и очистка заголовков не остаются незамеченными. */
    public function testResetStepOptions() {
        $before=webChecks::prepare($this->data(['steps'=>[['url'=>'https://example.com/', 'required'=>'OK', 'headers'=>[['name'=>'X-Test', 'value'=>'1']]]]]));
        $after=webChecks::prepare($this->data(['steps'=>[['url'=>'https://example.com/']]]));
        $diff=webChecks::diff($after['Сайт']['scenario'], $before['Сайт']['scenario']);
        $this->assertSame('', $diff['steps'][0]['required']);
        $this->assertSame([], $diff['steps'][0]['headers']);
    }

    /** Ошибка создания сценария не допускает создания триггера. */
    public function testApiFailureStopsWrites() {
        $api=new webChecksFakeApi();
        $api->failMethod='httptest.create';
        ob_start();
        try {
            try { webChecks::sync($api, 'Service web monitoring', webChecks::prepare($this->data()), true); }
            catch (RuntimeException $e) {
                $this->assertSame('httptest.create', $api->calls[count($api->calls)-1]['method']);
                return;
            }
            $this->fail('Ошибка API проигнорирована');
        } finally { ob_end_clean(); }
    }

    /** Неоднозначные и наследованные объекты отклоняются до первой записи. */
    public function testConflictingObjects() {
        $checks=webChecks::prepare($this->data());
        foreach ([
            [array_merge($checks['Сайт']['scenario'], ['httptestid'=>'20', 'templateid'=>'99'])],
            [array_merge($checks['Сайт']['scenario'], ['httptestid'=>'20', 'templateid'=>'0']), array_merge($checks['Сайт']['scenario'], ['httptestid'=>'21', 'templateid'=>'0'])],
        ] as $scenarios) {
            $api=new webChecksFakeApi();
            $api->scenarios=$scenarios;
            try { webChecks::sync($api, 'Service web monitoring', $checks, true); }
            catch (RuntimeException $e) {
                $this->assertSame(3, count($api->calls));
                continue;
            }
            $this->fail('Конфликт не обнаружен');
        }
    }
}
