<?php
require_once __DIR__.'/lib_webChecks.php';

/** Экспорт управляемых веб-проверок шаблона в формат web.data.priv.php. */
class webChecksExport {
    /** Преобразует снимок API в данные; неоднозначные и неподдерживаемые проверки отклоняет. */
    public static function data(string $template, array $snapshot): array {
        $data=['template'=>$template, 'services'=>[]];
        foreach ($snapshot['scenarios'] as $scenario) {
            if (!empty($scenario['templateid']))
                throw new RuntimeException($scenario['name'].': унаследованный сценарий нельзя экспортировать для редактирования');
            $name=$scenario['name'];
            $key=webChecks::failureKey($name);
            $found=[];
            foreach ($snapshot['triggers'] as $trigger) {
                foreach ($trigger['items']??[] as $item) {
                    // Привязку определяет ключ элемента данных, а не произвольное имя триггера.
                    if ((string)$item['hostid']===(string)$snapshot['templateid'] && $item['key_']===$key) {
                        $found[]=$trigger;
                        break;
                    }
                }
            }
            if (count($found)!==1) throw new RuntimeException("$name: ожидается один триггер web.test.fail, найдено ".count($found));
            $trigger=$found[0];
            if (!empty($trigger['templateid']) || !in_array((int)$trigger['recovery_mode'], [0,1], true))
                throw new RuntimeException("$name: унаследованный триггер или неподдерживаемый режим восстановления");
            if ((int)$trigger['recovery_mode']===0 && $trigger['recovery_expression']!=='')
                throw new RuntimeException("$name: неактивное выражение восстановления непустое");
            $steps=$scenario['steps'];
            usort($steps, function ($a, $b) { return (int)$a['no']<=>(int)$b['no']; }); // Порядок API произвольный.
            foreach ($steps as $index=>&$step) {
                if ((int)$step['no']!==$index+1) throw new RuntimeException("$name: номера шагов должны идти подряд с 1");
                $step=array_intersect_key($step, array_flip(webChecks::STEP_FIELDS));
                $step=self::withoutDefaults($step, array_merge(['name'=>'Шаг '.($index+1)], webChecks::STEP_DEFAULTS));
            }
            unset($step);
            $service=[
                'name'=>$name, 'trigger_name'=>$trigger['description'],
                'delay'=>$scenario['delay'], 'status'=>(int)$scenario['status'],
                'trigger_status'=>(int)$trigger['status'], 'priority'=>(int)$trigger['priority'],
                'steps'=>$steps,
                'problem_expression'=>$trigger['expression'],
                'recovery_expression'=>$trigger['recovery_expression'],
                'trigger_tags'=>$trigger['tags']??[],
            ];
            $defaultExpression='count(/'.$template.'/'.webChecks::failureKey($name).',#'.webChecks::SERVICE_DEFAULTS['problem'].',"ne",0)>'.(webChecks::SERVICE_DEFAULTS['problem']-1);
            $service=self::withoutDefaults($service, array_merge(webChecks::SERVICE_DEFAULTS, [
                'trigger_name'=>'Недоступен '.$name,
                'trigger_status'=>(int)$scenario['status'], // Без переопределения статус триггера равен статусу сценария.
                'problem_expression'=>$defaultExpression, 'recovery_expression'=>'', 'trigger_tags'=>[],
            ]));
            // Полностью типовой единственный шаг можно записать одним URL.
            if (count($steps)===1 && ($steps[0]['name']??null)==='Проверка' && count($steps[0])===2 && isset($steps[0]['url'])) {
                $service['url']=$steps[0]['url'];
                unset($service['steps']);
            }
            $data['services'][]=$service;
        }
        return $data;
    }

    /** Убирает поля с точными значениями по умолчанию, учитывая числовые строки API. */
    private static function withoutDefaults(array $values, array $defaults): array {
        foreach ($defaults as $field=>$default) {
            if (!array_key_exists($field, $values)) continue;
            $value=$values[$field];
            if ($value===$default || (!is_array($value) && !is_array($default) && $value!==null && $default!==null && (string)$value===(string)$default))
                unset($values[$field]);
        }
        return $values;
    }

    /** Формирует исполняемый PHP-файл без подстановки макросов и экранирования оболочкой. */
    public static function source(array $data): string {
        return "<?php\n/** Экспорт веб-проверок Zabbix; serviceids при необходимости добавьте вручную. */\nreturn ".self::phpValue($data).";\n";
    }

    /** Рекурсивно записывает массивы через [], не изменяя содержимое строковых значений. */
    private static function phpValue($value, int $depth=0): string {
        if (!is_array($value)) return var_export($value, true);
        if (!$value) return '[]';
        $list=array_keys($value)===range(0, count($value)-1);
        $lines=[];
        foreach ($value as $key=>$item) {
            $lines[]=str_repeat('    ', $depth+1).($list?'':var_export($key, true).' => ').self::phpValue($item, $depth+1).',';
        }
        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth).']';
    }
}
