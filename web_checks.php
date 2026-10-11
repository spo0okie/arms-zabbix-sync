<?php
/** CLI: php web_checks.php [web.data.priv.php] [real]; без real — только план изменений. */
require_once __DIR__.'/lib_zabbixApi.php';
require_once __DIR__.'/lib_webChecks.php';

try {
    $file=__DIR__.'/web.data.priv.php';
    $real=false;
    $hasFile=false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg==='real') $real=true;
        elseif (!$hasFile) { $file=$arg; $hasFile=true; }
        else throw new InvalidArgumentException('Использование: php web_checks.php [файл данных] [real]');
    }
    if (!is_file($file)) throw new RuntimeException("Нет файла данных: $file (см. web.data.sample.php)");
    $data=require $file;
    if (!is_array($data)) throw new InvalidArgumentException('Файл данных должен возвращать массив');
    $checks=webChecks::prepare($data);
    if (!is_file(__DIR__.'/config.priv.php')) throw new RuntimeException('Нет config.priv.php');
    require __DIR__.'/config.priv.php';
    $api=new zabbixApi();
    // Для работы с одним шаблоном не нужен полный кэш узлов и Inventory.
    $api->apiUrl=$zabbixApiUrl;
    $api->authToken=$zabbixAuth;
    echo $real?"Применение изменений\n":"Пробный запуск: изменения не применяются\n";
    $plan=webChecks::sync($api, $data['template'], $checks, $real);
    echo count($plan)?"Операций: ".count($plan)."\n":"Изменений нет\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'HALT: '.$e->getMessage()."\n");
    exit(1);
}
