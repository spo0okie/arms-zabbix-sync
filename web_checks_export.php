<?php
/** CLI: php web_checks_export.php <техническое имя шаблона> [файл данных]; только чтение Zabbix. */
require_once __DIR__.'/lib_webChecksExport.php';

try {
    if ($argc<2 || $argc>3) throw new InvalidArgumentException('Использование: php web_checks_export.php <template> [web.data.priv.php]');
    $template=$argv[1];
    $file=$argv[2]??__DIR__.'/web.data.priv.php';
    if (file_exists($file)) throw new RuntimeException("Файл уже существует: $file. Укажите другой путь");
    if (!is_file(__DIR__.'/config.priv.php')) throw new RuntimeException('Нет config.priv.php');
    require __DIR__.'/config.priv.php';
    $api=new zabbixApi();
    $api->apiUrl=$zabbixApiUrl;
    $api->authToken=$zabbixAuth;
    $snapshot=webChecks::readTemplate($api, $template);
    $data=webChecksExport::data($template, $snapshot);
    // Проверяем обратный проход тем же механизмом DIFF, прежде чем публиковать экспорт.
    ob_start();
    try { $plan=webChecks::sync($api, $template, webChecks::prepare($data)); }
    finally { ob_end_clean(); }
    if ($plan) throw new RuntimeException('Обратная проверка обнаружила изменения: '.json_encode($plan, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $source=webChecksExport::source($data);
    $handle=fopen($file, 'x'); // Эксклюзивное создание не перезаписывает существующие данные.
    if ($handle===false) throw new RuntimeException("Не удалось создать файл: $file");
    try {
        if (fwrite($handle, $source)!==strlen($source)) throw new RuntimeException("Не удалось полностью записать файл: $file");
    } finally { fclose($handle); }
    echo 'Экспортировано проверок: '.count($data['services'])."\nФайл: $file\nDIFF: 0 изменений\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'HALT: '.$e->getMessage()."\n");
    exit(1);
}
