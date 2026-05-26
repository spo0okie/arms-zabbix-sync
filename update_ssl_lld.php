#!/usr/bin/php
<?php
/**
 * Обновляет JS-скрипт LLD "Domain Discovery" в шаблоне "SSL Check"
 * данными из ssl.data.priv.php (через lld.php).
 *
 * Usage:
 *   php update_ssl_lld.php          # dry run — покажет скрипт, не обновит
 *   php update_ssl_lld.php real     # применит изменения в Zabbix
 */

require_once __DIR__ . '/config.priv.php';
require_once __DIR__ . '/lib_zabbixApi.php';

const TPL_NAME = 'SSL Check';
const LLD_NAME = 'Domain Discovery';

$dryRun = !in_array('real', $argv);

// --- 1. Получаем обработанные данные через lld.php --------------------------

echo "Running lld.php ... ";
$lldJson = shell_exec('php ' . escapeshellarg(__DIR__ . '/lld.php') . ' 2>&1');
$lldData  = $lldJson ? json_decode($lldJson, true) : null;

if (!is_array($lldData) || !isset($lldData['data'])) {
    echo "FAILED\n$lldJson\n";
    exit(1);
}
$itemCount = count($lldData['data']);
echo "OK ($itemCount items)\n";

// --- 2. Формируем JS-скрипт -------------------------------------------------

$dataJson  = json_encode($lldData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$jsScript  = "return JSON.stringify($dataJson);";

if ($dryRun) {
    $preview = strlen($jsScript) > 800
        ? substr($jsScript, 0, 800) . "\n...(truncated, total " . strlen($jsScript) . " bytes)"
        : $jsScript;
    echo "--- JS SCRIPT PREVIEW ---\n$preview\n--- END PREVIEW ---\n";
}

// --- 3. Подключаемся к Zabbix -----------------------------------------------

echo "Connecting to Zabbix API ... ";
$zabbix = new zabbixApi();
$zabbix->apiUrl    = $zabbixApiUrl;
$zabbix->authToken = $zabbixAuth;
echo "OK\n";

// --- 4. Ищем шаблон ---------------------------------------------------------

$templates = $zabbix->req('template.get', [
    'output' => ['templateid', 'host'],
    'filter' => ['host' => TPL_NAME],
]);
if (!$templates || !($tplId = $templates[0]['templateid'] ?? null)) {
    echo "ERROR: template \"" . TPL_NAME . "\" not found\n";
    exit(10);
}
echo "Template \"" . TPL_NAME . "\": templateid=$tplId\n";

// --- 5. Ищем LLD-правило ----------------------------------------------------

$rules = $zabbix->req('discoveryrule.get', [
    'output'      => ['itemid', 'name', 'type'],
    'templateids' => $tplId,
    'filter'      => ['name' => LLD_NAME],
]);
if (!$rules || !($ruleId = $rules[0]['itemid'] ?? null)) {
    echo "ERROR: discovery rule \"" . LLD_NAME . "\" not found in \"" . TPL_NAME . "\"\n";
    exit(11);
}
echo "Discovery rule \"" . LLD_NAME . "\": itemid=$ruleId, type=" . ($rules[0]['type'] ?? '?') . "\n";

// --- 6. Обновляем -----------------------------------------------------------

if ($dryRun) {
    echo "DRY RUN — передайте 'real' для применения изменений\n";
    exit(0);
}

$result = $zabbix->req('discoveryrule.update', [
    'itemid' => $ruleId,
    'params' => $jsScript,
]);

if ($result) {
    echo "Discovery rule updated OK\n";
} else {
    echo "ERROR: discoveryrule.update failed\n";
    exit(12);
}
