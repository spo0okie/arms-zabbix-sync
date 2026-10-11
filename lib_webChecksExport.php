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
            $data['services'][]=$service;
        }
        webChecks::prepare($data); // Проверяем совместимость до записи файла.
        return $data;
    }

    /** Формирует исполняемый PHP-файл без подстановки макросов и экранирования оболочкой. */
    public static function source(array $data): string {
        return "<?php\n/** Экспорт веб-проверок Zabbix; serviceids при необходимости добавьте вручную. */\nreturn ".var_export($data, true).";\n";
    }
}
