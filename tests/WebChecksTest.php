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

/** Подмена Inventory для проверки объединения ответственных нескольких сервисов. */
class webChecksFakeInventory {
    public $calls=[];
    public $tags=[
        42=>['serviceman'=>['Иванов'], 'supportteam'=>['Петров','Сидоров']],
        43=>['serviceman'=>['Иванов','Петров'], 'supportteam'=>['Сидоров']],
    ];

    /** Возвращает теги сервиса или пустой ответ при ошибке его получения. */
    public function getServiceSupportTags($id): array {
        $this->calls[]=$id;
        return $this->tags[$id]??[];
    }
}

/** Проверки выражений, идемпотентности, порядка записи и остановки при ошибках API. */
class WebChecksTest extends miniTestCase {
    /** Минимальная конфигурация тестового сервиса. */
    private function data(array $options=[]): array {
        return ['template'=>'Service web monitoring', 'services'=>[array_merge(['name'=>'Сайт', 'url'=>'https://example.com/'], $options)]];
    }

    /** Явное имя используется для поиска триггера, сохраняя ключ сценария и его историю. */
    public function testCustomTriggerNameMatching() {
        $default=webChecks::prepare($this->data())['Сайт'];
        $checks=webChecks::prepare($this->data(['trigger_name'=>'Портал недоступен с {HOST.NAME}']));
        $this->assertSame($default['trigger']['expression'], $checks['Сайт']['trigger']['expression']);
        $api=new webChecksFakeApi();
        $api->scenarios=[array_merge($default['scenario'], ['httptestid'=>'20', 'templateid'=>'0'])];
        $api->triggers=[array_merge($checks['Сайт']['trigger'], ['triggerid'=>'30', 'templateid'=>'0', 'priority'=>'4'])];
        ob_start();
        try { $plan=webChecks::sync($api, 'Service web monitoring', $checks); }
        finally { ob_end_clean(); }
        $this->assertSame([['method'=>'trigger.update', 'params'=>['priority'=>3, 'triggerid'=>'30']]], $plan);
    }

    /** Пустое имя и совпадение явного имени с типовым именем другого сервиса запрещены. */
    public function testInvalidOrDuplicateTriggerName() {
        foreach (['', '   ', null, 42, "Имя\nтриггера"] as $name) {
            try { webChecks::prepare($this->data(['trigger_name'=>$name])); }
            catch (InvalidArgumentException $e) { continue; }
            $this->fail('Некорректное имя триггера принято');
        }
        $data=$this->data(['trigger_name'=>'Недоступен Портал']);
        $data['services'][]=['name'=>'Портал', 'url'=>'https://example.org/'];
        try { webChecks::prepare($data); }
        catch (InvalidArgumentException $e) { return; }
        $this->fail('Повтор имени триггера принят');
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

    /** Несколько сервисов объединяются без повторов, каждый ID запрашивается один раз. */
    public function testSupportTagCreation() {
        $inventory=new webChecksFakeInventory();
        $data=$this->data(['serviceids'=>[42,43,42]]);
        $data['services'][]=['name'=>'Второй сайт', 'url'=>'https://example.org/', 'serviceids'=>[43]];
        $checks=webChecks::enrichSupportTags(webChecks::prepare($data), $inventory);
        $this->assertSame([42,43], $inventory->calls);
        $this->assertSame(['serviceman'=>['Иванов','Петров'], 'supportteam'=>['Петров','Сидоров']], $checks['Сайт']['supportTags']);
        $api=new webChecksFakeApi();
        ob_start();
        try { $plan=webChecks::sync($api, 'Service web monitoring', $checks); }
        finally { ob_end_clean(); }
        $this->assertFalse(isset($plan[0]['params']['tags'])); // Теги нужны на триггере, не на сценарии.
        $this->assertSame([
            ['tag'=>'serviceman', 'value'=>'Иванов'], ['tag'=>'serviceman', 'value'=>'Петров'],
            ['tag'=>'supportteam', 'value'=>'Петров'], ['tag'=>'supportteam', 'value'=>'Сидоров'],
        ], $plan[1]['params']['tags']);
    }

    /** Обновление убирает старые фамилии и сохраняет остальные теги; порядок тегов не влияет. */
    public function testSupportTagUpdateAndRepeat() {
        $checks=webChecks::enrichSupportTags(webChecks::prepare($this->data(['serviceids'=>[42]])), new webChecksFakeInventory());
        $api=new webChecksFakeApi();
        $api->scenarios=[array_merge($checks['Сайт']['scenario'], ['httptestid'=>'20', 'templateid'=>'0'])];
        $api->triggers=[array_merge($checks['Сайт']['trigger'], ['triggerid'=>'30', 'templateid'=>'0', 'tags'=>[
            ['tag'=>'serviceman', 'value'=>'Старый'], ['tag'=>'supportteam', 'value'=>'Старый'],
            ['tag'=>'alert', 'value'=>'sms'],
        ]])];
        ob_start();
        try {
            $plan=webChecks::sync($api, 'Service web monitoring', $checks, true);
            $this->assertSame(1, count($plan));
            $tags=$plan[0]['params']['tags'];
            $this->assertSame(4, count($tags));
            $this->assertTrue(in_array(['tag'=>'alert', 'value'=>'sms'], $tags, true));
            $this->assertFalse(in_array(['tag'=>'serviceman', 'value'=>'Старый'], $tags, true));
            $this->assertSame('trigger.update', $api->calls[3]['method']);
            $api->triggers[0]['tags']=array_reverse($tags);
            $this->assertSame([], webChecks::sync($api, 'Service web monitoring', $checks, true));
            $this->assertSame(['tag','value'], $api->calls[2]['params']['selectTags']);
        } finally { ob_end_clean(); }
    }

    /** Отсутствие поля сохраняет теги, явный пустой список удаляет только сопровождение. */
    public function testEmptyAndMissingServiceIds() {
        $checks=webChecks::prepare($this->data());
        $api=new webChecksFakeApi();
        $api->scenarios=[array_merge($checks['Сайт']['scenario'], ['httptestid'=>'20', 'templateid'=>'0'])];
        $api->triggers=[array_merge($checks['Сайт']['trigger'], ['triggerid'=>'30', 'templateid'=>'0', 'tags'=>[
            ['tag'=>'serviceman', 'value'=>'Иванов'], ['tag'=>'supportteam', 'value'=>'Петров'], ['tag'=>'alert', 'value'=>'sms'],
        ]])];
        $inventory=new webChecksFakeInventory();
        ob_start();
        try {
            $this->assertSame([], webChecks::sync($api, 'Service web monitoring', $checks));
            $empty=webChecks::enrichSupportTags(webChecks::prepare($this->data(['serviceids'=>[]])), $inventory);
            $plan=webChecks::sync($api, 'Service web monitoring', $empty);
            $this->assertSame([['tag'=>'alert', 'value'=>'sms']], $plan[0]['params']['tags']);
            $this->assertSame([], $inventory->calls);
        } finally { ob_end_clean(); }
    }

    /** Ошибочные ID отвергаются при подготовке; ошибка Inventory не превращается в очистку тегов. */
    public function testInvalidServiceIdsAndInventoryFailure() {
        foreach ([null, '42', [0], [-1], ['abc'], [true], [1.5]] as $ids) {
            try { webChecks::prepare($this->data(['serviceids'=>$ids])); }
            catch (InvalidArgumentException $e) { continue; }
            $this->fail('Некорректные serviceids приняты');
        }
        $checks=webChecks::prepare($this->data(['serviceids'=>['99']]));
        try { webChecks::enrichSupportTags($checks, new webChecksFakeInventory()); }
        catch (RuntimeException $e) { return; }
        $this->fail('Ошибка Inventory проигнорирована');
    }
}
