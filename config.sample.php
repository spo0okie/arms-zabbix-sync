<?php
//как подключиться к инвентори
$webInventory="https://inventory.domain.local/web";
$inventoryAuth = base64_encode("zabbix_user:zabbix_password");

//урл и токен авторизации в zabbix
$zabbixAuth="inventory-user-token";
$zabbixApiUrl='https://zabbix.domain.local/zabbix/api_jsonrpc.php';
