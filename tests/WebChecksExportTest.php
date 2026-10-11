<?php
require_once __DIR__.'/../lib_webChecksExport.php';

/** API-подмена экспортируемого шаблона: разрешает исключительно чтение. */
class webChecksExportFakeApi {
    public $snapshot;
    public $calls=[];

    /** Возвращает снимок шаблона и запрещает любые операции записи. */
    public function req($method, $params, $allowEmpty=false) {
        $this->calls[]=$method;
        if ($method==='template.get') return [['templateid'=>'10', 'host'=>'Service web monitoring']];
        if ($method==='httptest.get') return $this->snapshot['scenarios'];
        if ($method==='trigger.get') return $this->snapshot['triggers'];
        throw new RuntimeException('Неожиданная запись: '.$method);
    }
}

/** Проверки формирования компактного исполняемого PHP data-файла из снимка API. */
class WebChecksExportTest extends miniTestCase {
    /** Реалистичный снимок: строки API, произвольный порядок шагов, разные статусы и ручные теги. */
    private function snapshot(): array {
        $first=[
            'httpstepid'=>'101', 'httptestid'=>'20', 'no'=>'1', 'name'=>'Вход',
            'url'=>'https://example.com/?q={HOST.NAME}', 'timeout'=>'15s', 'status_codes'=>'200,302',
            'required'=>"Вход в 'портал'", 'follow_redirects'=>'0', 'retrieve_mode'=>'2',
            'posts'=>"login=test\npassword={\$PASSWORD}", 'post_type'=>'0',
            'headers'=>[['name'=>'X-Test', 'value'=>'{HOST.NAME}']], 'variables'=>[], 'query_fields'=>[],
        ];
        $second=array_merge($first, ['httpstepid'=>'102', 'no'=>'2', 'name'=>'API', 'url'=>'https://example.com/health', 'posts'=>'', 'headers'=>[]]);
        return [
            'templateid'=>'10',
            'scenarios'=>[['httptestid'=>'20', 'templateid'=>'0', 'name'=>'Портал', 'delay'=>'60s', 'status'=>'1', 'steps'=>[$second,$first]]],
            'triggers'=>[[
                'triggerid'=>'30', 'templateid'=>'0', 'description'=>'Портал недоступен с {HOST.NAME}',
                'expression'=>'count(/Service web monitoring/web.test.fail[Портал],#5,"ne","0") > 4',
                'recovery_mode'=>'1', 'recovery_expression'=>'count(/Service web monitoring/web.test.fail[Портал],#2,"eq",0)=2',
                'priority'=>'4', 'status'=>'0',
                'tags'=>[['tag'=>'serviceman','value'=>'Иванов'], ['tag'=>'supportteam','value'=>'Петров'], ['tag'=>'alert','value'=>'sms']],
                'items'=>[['itemid'=>'40', 'hostid'=>'10', 'key_'=>'web.test.fail[Портал]']],
            ]],
        ];
    }

    /** Экспорт исполняется как data-файл, сохраняя строки, макросы и нестандартные настройки. */
    public function testExportThroughPhpFile() {
        $api=new webChecksExportFakeApi();
        $api->snapshot=$this->snapshot();
        $snapshot=webChecks::readTemplate($api, 'Service web monitoring');
        $data=webChecksExport::data('Service web monitoring', $snapshot);
        $file=tempnam(sys_get_temp_dir(), 'web-checks-');
        try {
            file_put_contents($file, webChecksExport::source($data));
            $loaded=require $file;
            $this->assertSame($data, $loaded);
            $this->assertFalse(isset($loaded['services'][0]['serviceids'])); // Из фамилий нельзя восстановить ID сервисов.
            $this->assertSame(1, $loaded['services'][0]['status']);
            $this->assertSame(0, $loaded['services'][0]['trigger_status']);
            $this->assertFalse(isset($loaded['services'][0]['steps'][0]['httpstepid']));
            $this->assertSame("login=test\npassword={\$PASSWORD}", $loaded['services'][0]['steps'][0]['posts']);
            $this->assertFalse(isset($loaded['services'][0]['steps'][0]['variables']));
            $this->assertSame(3, count($api->calls)); // Только чтение снимка, без запуска генератора.
        } finally { unlink($file); }
    }

    /** Отсутствие специального Recovery и имена с запятыми экспортируются без изменения ключа. */
    public function testNoRecoveryAndQuotedName() {
        $snapshot=$this->snapshot();
        $snapshot['scenarios'][0]['name']='API, [health]';
        $snapshot['triggers'][0]['items'][0]['key_']='web.test.fail["API, [health]"]';
        $snapshot['triggers'][0]['expression']='count(/Service web monitoring/web.test.fail["API, [health]"],#3,"ne",0)>2';
        $snapshot['triggers'][0]['recovery_mode']='0';
        $snapshot['triggers'][0]['recovery_expression']='';
        $data=webChecksExport::data('Service web monitoring', $snapshot);
        $this->assertFalse(isset($data['services'][0]['recovery_expression']));
        $this->assertSame($snapshot['triggers'][0]['expression'], $data['services'][0]['problem_expression']);
    }

    /** Полностью типовая проверка экспортируется только именем и URL. */
    public function testDefaultValuesAreOmitted() {
        $snapshot=$this->snapshot();
        $snapshot['scenarios'][0]['delay']='1m';
        $snapshot['scenarios'][0]['status']='0';
        $snapshot['scenarios'][0]['steps']=[array_merge(webChecks::STEP_DEFAULTS, [
            'name'=>'Проверка', 'url'=>'https://example.com/', 'no'=>'1', 'follow_redirects'=>'1', 'retrieve_mode'=>'0',
        ])];
        $snapshot['triggers'][0]=array_merge($snapshot['triggers'][0], [
            'description'=>'Недоступен Портал', 'priority'=>'3', 'recovery_mode'=>'0',
            'expression'=>'count(/Service web monitoring/web.test.fail[Портал],#5,"ne",0)>4',
            'recovery_expression'=>'', 'tags'=>[],
        ]);
        $data=webChecksExport::data('Service web monitoring', $snapshot);
        $this->assertSame([['name'=>'Портал', 'url'=>'https://example.com/']], $data['services']);
    }

    /** Короткий синтаксис массивов не портит строки с текстом array() и вложенными данными. */
    public function testShortArraySyntaxAndStringEscaping() {
        $data=['template'=>'Service web monitoring', 'services'=>[['name'=>"array (a) array() [x] ' \\ {HOST.NAME}", 'trigger_tags'=>[]]]];
        $source=webChecksExport::source($data);
        $this->assertTrue(strpos($source, "return [\n")!==false);
        $this->assertTrue(strpos($source, "'trigger_tags' => []")!==false);
        $this->assertFalse(strpos($source, '0 =>')!==false);
        $file=tempnam(sys_get_temp_dir(), 'web-checks-');
        try {
            file_put_contents($file, $source);
            $this->assertSame($data, require $file);
        } finally { unlink($file); }
    }

    /** Неоднозначная пара, отсутствие триггера и наследование не маскируются частичным экспортом. */
    public function testUnsupportedSnapshots() {
        $base=$this->snapshot();
        $missing=$base; $missing['triggers']=[];
        $duplicate=$base; $duplicate['triggers'][]=$base['triggers'][0];
        $inherited=$base; $inherited['scenarios'][0]['templateid']='99';
        $noRecovery=$base; $noRecovery['triggers'][0]['recovery_mode']='2';
        $stepGap=$base; $stepGap['scenarios'][0]['steps'][0]['no']='3';
        foreach ([$missing,$duplicate,$inherited,$noRecovery,$stepGap] as $snapshot) {
            try { webChecksExport::data('Service web monitoring', $snapshot); }
            catch (RuntimeException $e) { continue; }
            $this->fail('Неподдерживаемый снимок принят');
        }
    }
}
