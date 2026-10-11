<?php
require_once __DIR__.'/lib_zabbixApi.php';

/** Генерация и синхронизация простых веб-проверок в существующем шаблоне Zabbix. */
class webChecks {
    // Поля шага, поддерживаемые форматом данных и экспортом из Zabbix.
    const STEP_FIELDS=['name','url','timeout','status_codes','required','follow_redirects','posts','headers','variables','query_fields','retrieve_mode'];
    // Единые умолчания генератора и компактного экспорта.
    const SERVICE_DEFAULTS=['problem'=>5, 'recovery'=>null, 'delay'=>'1m', 'status'=>0, 'priority'=>4];
    const STEP_DEFAULTS=[
        'timeout'=>'20s', 'status_codes'=>'200', 'required'=>'', 'follow_redirects'=>0,
        'retrieve_mode'=>0, 'posts'=>[], 'headers'=>[], 'variables'=>[], 'query_fields'=>[],
    ];
    /** Преобразует PHP-данные в сценарии и триггеры, проверяя их до обращения к API. */
    public static function prepare(array $data): array {
        $template=$data['template']??null;
        if (!is_string($template) || trim($template)==='' || preg_match('/[\/\r\n]/', $template))
            throw new InvalidArgumentException('Некорректное техническое имя шаблона');
        if (!isset($data['services']) || !is_array($data['services']))
            throw new InvalidArgumentException('Нужен массив services');
        $result=[];
        $triggerNames=[];
        foreach ($data['services'] as $service) {
            if (!is_array($service)) throw new InvalidArgumentException('Описание сервиса должно быть массивом');
            $service=array_merge(self::SERVICE_DEFAULTS, $service); // Частичные описания используют общие умолчания.
            $name=$service['name']??null;
            if (!is_string($name) || trim($name)==='' || preg_match('/[\r\n]/', $name))
                throw new InvalidArgumentException('Нужно непустое имя сервиса без перевода строки');
            if (isset($result[$name])) throw new InvalidArgumentException("Повтор имени сервиса: $name");
            // Явное имя влияет только на триггер; ключ web.test.fail всегда использует имя сценария.
            $triggerName=array_key_exists('trigger_name', $service)?$service['trigger_name']:'Недоступен '.$name;
            if (!is_string($triggerName) || trim($triggerName)==='' || preg_match('/[\r\n]/', $triggerName))
                throw new InvalidArgumentException("$name: trigger_name должен быть непустой строкой без перевода строки");
            if (isset($triggerNames[$triggerName])) throw new InvalidArgumentException("Повтор имени триггера: $triggerName");
            $triggerNames[$triggerName]=true;
            $failures=self::positiveCount($service['problem']??5, 'problem');
            $recovery=$service['recovery']??null;
            if ($recovery!==null) $recovery=self::positiveCount($recovery, 'recovery');
            $status=$service['status']??0;
            $priority=$service['priority']??3;
            if (!is_int($status) || !in_array($status, [0,1], true))
                throw new InvalidArgumentException("$name: status должен быть 0 или 1");
            if (!is_int($priority) || $priority<0 || $priority>5)
                throw new InvalidArgumentException("$name: priority должен быть от 0 до 5");
            $steps=$service['steps']??[['name'=>'Проверка', 'url'=>$service['url']??null]];
            if (!is_array($steps) || !$steps) throw new InvalidArgumentException("$name: нужен хотя бы один шаг");
            $preparedSteps=[];
            $stepNames=[];
            foreach (array_values($steps) as $index=>$step) {
                if (!is_array($step)) throw new InvalidArgumentException("$name: шаг должен быть массивом");
                // Ограниченный набор полей; порядковые номера назначаются по порядку массива.
            $unknown=array_diff(array_keys($step), self::STEP_FIELDS);
                if ($unknown) throw new InvalidArgumentException("$name: неизвестные поля шага: ".implode(', ', $unknown));
                $step=array_merge(['name'=>'Шаг '.($index+1)], self::STEP_DEFAULTS, $step); // Снятая настройка возвращается к умолчанию.
                if (!is_string($step['name']) || trim($step['name'])==='' || isset($stepNames[$step['name']]))
                    throw new InvalidArgumentException("$name: имена шагов должны быть непустыми и уникальными");
                if (!isset($step['url']) || !is_string($step['url']) || trim($step['url'])==='')
                    throw new InvalidArgumentException("$name: нужен URL каждого шага");
                $stepNames[$step['name']]=true;
                $step['no']=$index+1;
                $step['timeout']=(string)$step['timeout'];
                $step['status_codes']=(string)$step['status_codes'];
                $preparedSteps[]=$step;
            }
            if (array_key_exists('serviceids', $service)) {
                if (!is_array($service['serviceids'])) throw new InvalidArgumentException("$name: serviceids должен быть массивом ID");
                foreach ($service['serviceids'] as $id) {
                    if ((!is_int($id) && !is_string($id)) || !ctype_digit((string)$id) || (int)$id<1)
                        throw new InvalidArgumentException("$name: serviceids должен содержать положительные целые ID");
                }
            }
            $unknown=array_diff(array_keys($service), ['name','url','steps','problem','recovery','priority','status','delay','serviceids','trigger_name','problem_expression','recovery_expression','trigger_status','trigger_tags']);
            if ($unknown) throw new InvalidArgumentException("$name: неизвестные поля сервиса: ".implode(', ', $unknown));
            $key=self::failureKey($name);
            $item='/'.$template.'/'.$key;
            $result[$name]=[
                'scenario'=>['name'=>$name, 'delay'=>(string)($service['delay']??'1m'), 'status'=>$status, 'steps'=>$preparedSteps],
                'trigger'=>[
                    'description'=>$triggerName,
                    'expression'=>'count('.$item.',#'.$failures.',"ne",0)>'.($failures-1),
                    'recovery_mode'=>$recovery===null?0:1,
                    'recovery_expression'=>$recovery===null?'':'count('.$item.',#'.$recovery.',"eq",0)='.$recovery,
                    'priority'=>$priority, 'status'=>$status,
                ],
            ];
            // Экспорт сохраняет выражения буквально, без потери формата и ручных условий.
            foreach (['problem_expression'=>'expression','recovery_expression'=>'recovery_expression'] as $input=>$field) {
                if (!array_key_exists($input, $service)) continue;
                if (!is_string($service[$input]) || ($input==='problem_expression' && trim($service[$input])===''))
                    throw new InvalidArgumentException("$name: $input должен быть строкой" );
                $result[$name]['trigger'][$field]=$service[$input];
                if ($input==='recovery_expression') $result[$name]['trigger']['recovery_mode']=$service[$input]===''?0:1;
            }
            if (array_key_exists('trigger_status', $service)) {
                if (!in_array($service['trigger_status'], [0,1], true)) throw new InvalidArgumentException("$name: trigger_status должен быть 0 или 1");
                $result[$name]['trigger']['status']=$service['trigger_status'];
            }
            if (array_key_exists('trigger_tags', $service)) {
                if (!is_array($service['trigger_tags'])) throw new InvalidArgumentException("$name: trigger_tags должен быть массивом");
                foreach ($service['trigger_tags'] as $tag) {
                    if (!is_array($tag) || !isset($tag['tag'], $tag['value']) || !is_string($tag['tag']) || !is_string($tag['value']))
                        throw new InvalidArgumentException("$name: trigger_tags должен содержать строковые tag и value");
                }
                $result[$name]['trigger']['tags']=array_values($service['trigger_tags']);
            }
            // Отсутствие serviceids оставляет ручные теги, явный [] очищает теги сопровождения.
            if (array_key_exists('serviceids', $service)) $result[$name]['serviceids']=$service['serviceids'];
        }
        return $result;
    }

    /** Формирует ключ автоматически создаваемого элемента данных с синтаксисом кавычек Zabbix. */
    public static function failureKey(string $name): string {
        $parameter=$name;
        if (preg_match('/[",\]]|^\[/', $name)) {
            if (substr($name, -1)==='\\') throw new InvalidArgumentException("$name: имя в кавычках не может заканчиваться обратной косой чертой");
            $parameter='"'.str_replace('"', '\\"', $name).'"';
        }
        return 'web.test.fail['.$parameter.']';
    }

    /** Собирает отдельные значения тегов сопровождения из Inventory для каждого веб-сервиса. */
    public static function enrichSupportTags(array $checks, $inventory): array {
        $cache=[];
        foreach ($checks as $name=>&$check) {
            if (!array_key_exists('serviceids', $check)) continue;
            $tags=['serviceman'=>[], 'supportteam'=>[]];
            foreach ($check['serviceids'] as $id) {
                if (!isset($cache[$id])) {
                    $cache[$id]=$inventory->getServiceSupportTags($id);
                    // Пустой ответ означает недоступный/несуществующий сервис, а не отсутствие ответственных.
                    if (!isset($cache[$id]['serviceman'], $cache[$id]['supportteam']))
                        throw new RuntimeException("$name: не удалось получить сопровождение сервиса Inventory #$id");
                }
                foreach ($tags as $tag=>$values) {
                    $tags[$tag]=array_values(array_unique(array_merge($values, $cache[$id][$tag])));
                }
            }
            $check['supportTags']=$tags;
        }
        unset($check);
        return $checks;
    }

    /** Проверяет число последовательных результатов, используемых в count(). */
    private static function positiveCount($value, string $field): int {
        if (!is_int($value) || $value<1) throw new InvalidArgumentException("$field должен быть положительным целым числом");
        return $value;
    }

    /** Возвращает изменения только управляемых полей; числовые строки API равны числам PHP. */
    public static function diff(array $desired, array $current): array {
        $diff=[];
        foreach ($desired as $field=>$value) {
            if ($field==='tags' && isset($current[$field])) {
                // API может вернуть те же теги в другом порядке; это не изменение.
                sort($value);
                sort($current[$field]);
            }
            if (!array_key_exists($field, $current) || !self::matches($value, $current[$field])) $diff[$field]=$value;
        }
        return $diff;
    }

    /** Сравнивает вложенные поля, игнорируя служебные ID, возвращённые API. */
    private static function matches($desired, $current): bool {
        if (!is_array($desired)) return !is_array($current) && (string)$desired===(string)$current;
        if (!is_array($current)) return false;
        if (!$desired) return !$current;
        if (array_keys($desired)===range(0, count($desired)-1)) {
            if (count($desired)!==count($current)) return false;
            $current=array_values($current);
        }
        foreach ($desired as $key=>$value) {
            if (!array_key_exists($key, $current) || !self::matches($value, $current[$key])) return false;
        }
        return true;
    }

    /** Выполняет запрос с явной остановкой при ошибке, включая разрешённые пустые выборки. */
    private static function request($api, string $method, array $params): array {
        $result=$api->req($method, $params, true);
        if (!is_array($result)) throw new RuntimeException("Ошибка Zabbix API: $method");
        return $result;
    }

    /** Читает один шаблон со сценариями, шагами, триггерами и их ключами элементов данных. */
    public static function readTemplate($api, string $template): array {
        $templates=self::request($api, 'template.get', ['output'=>['templateid','host'], 'filter'=>['host'=>$template]]);
        if (count($templates)!==1) throw new RuntimeException("Шаблон не найден однозначно: $template");
        $templateid=$templates[0]['templateid'];
        $scenarios=self::request($api, 'httptest.get', ['output'=>'extend', 'templateids'=>[$templateid], 'selectSteps'=>'extend']);
        $triggers=self::request($api, 'trigger.get', ['output'=>'extend', 'templateids'=>[$templateid], 'expandExpression'=>true, 'selectTags'=>['tag','value'], 'selectItems'=>['hostid','key_']]);
        return ['templateid'=>$templateid, 'scenarios'=>$scenarios, 'triggers'=>$triggers];
    }

    /** Строит полный план, затем применяет его: сначала сценарии, затем их триггеры. */
    public static function sync($api, string $template, array $checks, bool $real=false): array {
        $snapshot=self::readTemplate($api, $template);
        $templateid=$snapshot['templateid'];
        $scenarios=$snapshot['scenarios'];
        $triggers=$snapshot['triggers'];
        $plan=[];
        foreach ($checks as $name=>$check) {
            foreach (['scenario','trigger'] as $kind) {
                $scenario=$kind==='scenario';
                $field=$scenario?'name':'description';
                $idField=$scenario?'httptestid':'triggerid';
                $method=$scenario?'httptest':'trigger';
                $found=null;
                foreach ($scenario?$scenarios:$triggers as $current) {
                    if ($current[$field]!==$check[$kind][$field]) continue;
                    // Наследованный объект нельзя редактировать в дочернем шаблоне.
                    if (!empty($current['templateid'])) throw new RuntimeException("$name: $kind унаследован из другого шаблона");
                    if ($found!==null) throw new RuntimeException("$name: несколько объектов $kind с одинаковым именем");
                    $found=$current;
                }
                if ($found!==null && $scenario) {
                    usort($found['steps'], function ($a, $b) { return (int)$a['no']<=>(int)$b['no']; }); // API не гарантирует порядок шагов.
                }
                $desired=$check[$kind];
                if (!$scenario && array_key_exists('serviceids', $check)) {
                    if (!isset($check['supportTags'])) throw new RuntimeException("$name: теги Inventory не подготовлены");
                    // Заменяем только сопровождение; alert и остальные ручные теги сохраняются.
                    $desired['tags']=zabbixApi::updateTags($desired['tags']??$found['tags']??[], $check['supportTags']);
                }
                $params=$found===null?$desired:self::diff($desired, $found);
                if (!$params) continue;
                if ($found!==null) $params[$idField]=$found[$idField];
                elseif ($scenario) $params['hostid']=$templateid;
                $plan[]=['method'=>$method.($found===null?'.create':'.update'), 'params'=>$params];
            }
        }
        foreach ($plan as $operation) {
            echo $operation['method'].' '.json_encode($operation['params'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
        }
        if ($real) {
            // Веб-сценарий создаёт web.test.fail; триггер можно создать только после него.
            foreach (['httptest','trigger'] as $prefix) {
                foreach ($plan as $operation) {
                    if (strpos($operation['method'], $prefix.'.')===0)
                        self::request($api, $operation['method'], $operation['params']);
                }
            }
        }
        return $plan;
    }
}
