<?php

/** Генерация и синхронизация простых веб-проверок в существующем шаблоне Zabbix. */
class webChecks {
    /** Преобразует PHP-данные в сценарии и триггеры, проверяя их до обращения к API. */
    public static function prepare(array $data): array {
        $template=$data['template']??null;
        if (!is_string($template) || trim($template)==='' || preg_match('/[\/\r\n]/', $template))
            throw new InvalidArgumentException('Некорректное техническое имя шаблона');
        if (!isset($data['services']) || !is_array($data['services']))
            throw new InvalidArgumentException('Нужен массив services');
        $result=[];
        foreach ($data['services'] as $service) {
            if (!is_array($service)) throw new InvalidArgumentException('Описание сервиса должно быть массивом');
            $name=$service['name']??null;
            if (!is_string($name) || trim($name)==='' || preg_match('/[\r\n]/', $name))
                throw new InvalidArgumentException('Нужно непустое имя сервиса без перевода строки');
            if (isset($result[$name])) throw new InvalidArgumentException("Повтор имени сервиса: $name");
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
                $unknown=array_diff(array_keys($step), ['name','url','timeout','status_codes','required','follow_redirects','posts','headers','variables','query_fields','retrieve_mode']);
                if ($unknown) throw new InvalidArgumentException("$name: неизвестные поля шага: ".implode(', ', $unknown));
                $step=array_merge([
                    'name'=>'Шаг '.($index+1), 'timeout'=>'10s', 'status_codes'=>'200',
                    'required'=>'', 'follow_redirects'=>1, 'retrieve_mode'=>0,
                    'posts'=>'', 'headers'=>[], 'variables'=>[], 'query_fields'=>[],
                ], $step); // Удаление настройки из файла возвращает шаг к её значению по умолчанию.
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
            $unknown=array_diff(array_keys($service), ['name','url','steps','problem','recovery','priority','status','delay']);
            if ($unknown) throw new InvalidArgumentException("$name: неизвестные поля сервиса: ".implode(', ', $unknown));
            // Ключ должен совпадать с автоматически созданным Zabbix: кавычки только при необходимости.
            $parameter=$name;
            if (preg_match('/[",\]]|^\[/', $name)) {
                if (substr($name, -1)==='\\') throw new InvalidArgumentException("$name: имя в кавычках не может заканчиваться обратной косой чертой");
                $parameter='"'.str_replace('"', '\\"', $name).'"';
            }
            $key='web.test.fail['.$parameter.']';
            $item='/'.$template.'/'.$key;
            $result[$name]=[
                'scenario'=>['name'=>$name, 'delay'=>(string)($service['delay']??'1m'), 'status'=>$status, 'steps'=>$preparedSteps],
                'trigger'=>[
                    'description'=>'Недоступен '.$name,
                    'expression'=>'count('.$item.',#'.$failures.',"ne",0)>'.($failures-1),
                    'recovery_mode'=>$recovery===null?0:1,
                    'recovery_expression'=>$recovery===null?'':'count('.$item.',#'.$recovery.',"eq",0)='.$recovery,
                    'priority'=>$priority, 'status'=>$status,
                ],
            ];
        }
        return $result;
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

    /** Строит полный план, затем применяет его: сначала сценарии, затем их триггеры. */
    public static function sync($api, string $template, array $checks, bool $real=false): array {
        $templates=self::request($api, 'template.get', ['output'=>['templateid','host'], 'filter'=>['host'=>$template]]);
        if (count($templates)!==1) throw new RuntimeException("Шаблон не найден однозначно: $template");
        $templateid=$templates[0]['templateid'];
        $scenarios=self::request($api, 'httptest.get', ['output'=>'extend', 'templateids'=>[$templateid], 'selectSteps'=>'extend']);
        $triggers=self::request($api, 'trigger.get', ['output'=>'extend', 'templateids'=>[$templateid], 'expandExpression'=>true]);
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
                $params=$found===null?$check[$kind]:self::diff($check[$kind], $found);
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
