<?php
//как подключиться к инвентори
$webInventory="https://inventory.domain.local/web";
$inventoryAuth = base64_encode("zabbix_user:zabbix_password");

//урл и токен авторизации в zabbix
$zabbixAuth="inventory-user-token";
$zabbixApiUrl='https://zabbix.domain.local/zabbix/api_jsonrpc.php';

//токен доступа к explain.php (запрос вердикта "попадет ли узел в мониторинг" из ARMS);
//без него HTTP-доступ к explain.php закрыт. Сгенерировать: php -r "echo bin2hex(random_bytes(24));"
$explainToken='';
