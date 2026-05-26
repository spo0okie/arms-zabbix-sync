<?php
/**
 * Zabbix LLD discovery preprocessor
 *
 * Reads a JSON array of raw discovery items, applies macro defaults,
 * and enriches items that have a "serviceids" key with responsible/
 * support-team data pulled from the Inventory API.
 *
 * Usage:
 *   php lld.php < items.json
 *   php lld.php items.json
 *
 * Output: {"data":[{"{#DOMAIN}":…, "{#SERVICEMAN}":…, …}]}
 */

require_once __DIR__ . '/config.priv.php';
require_once __DIR__ . '/lib_inventoryApi.php';

// --- input -------------------------------------------------------------------

$items = require __DIR__.'/ssl.data.priv.php';
if (!is_array($items)) {
    fwrite(STDERR, "lld.php: invalid input data\n");
    exit(1);
}

// --- process -----------------------------------------------------------------

$inventory = new inventoryApi();
$inventory->init($webInventory, $inventoryAuth);

$result = [];
foreach ($items as $item) {

    // defaults
    if (!array_key_exists('{#NAME}', $item))
        $item['{#NAME}'] = 'SSL ' . ($item['{#DOMAIN}'] ?? '');
    if (!array_key_exists('{#SERVER}', $item))
        $item['{#SERVER}'] = '';
    if (!array_key_exists('{#PORT}', $item))
        $item['{#PORT}'] = 443;
    if (!array_key_exists('{#TYPE}', $item))
        $item['{#TYPE}'] = 'web';

    // inventory
    if (!empty($item['serviceids']) && is_array($item['serviceids'])) {
        $serviceman  = [];
        $supportteam = [];
        foreach ($item['serviceids'] as $svcId) {
            $tags = $inventory->getServiceSupportTags($svcId);
            foreach ($tags['serviceman']  ?? [] as $name) $serviceman[$name]  = true;
            foreach ($tags['supportteam'] ?? [] as $name) $supportteam[$name] = true;
        }

        if (count($serviceman))  $item['{#SERVICEMAN}']  = implode(',', array_keys($serviceman));
        if (count($supportteam)) $item['{#SUPPORTTEAM}'] = implode(',', array_keys($supportteam));
        unset($item['serviceids']);
    }

    $result[] = $item;
}

// --- output ------------------------------------------------------------------

echo json_encode(['data' => $result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
