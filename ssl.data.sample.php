<?php
/**
 * Список SSL/web-эндпоинтов для LLD "Domain Discovery"
 * Скопируйте этот файл как ssl.data.priv.php и заполните своими данными.
 *
 * Обязательные поля:
 *   {#DOMAIN}  — FQDN или IP сертификата
 *
 * Необязательные поля (заполняются по умолчанию в lld.php):
 *   {#NAME}    — отображаемое имя (default: "SSL {#DOMAIN}")
 *   {#SERVER}  — SNI/hostname для проверки (default: "")
 *   {#PORT}    — порт (default: 443)
 *   {#TYPE}    — тип проверки: web | smtp | imap | ... (default: "web")
 *
 * Опциональные поля:
 *   serviceids — массив ID сервисов в Inventory; если задан, добавляет
 *                {#SERVICEMAN} и {#SUPPORTTEAM} из инвентаризации
 */
return [

    ['{#DOMAIN}' => 'example.com'],

    ['{#DOMAIN}' => 'rabbitmq.main.local', '{#PORT}' => 15671],

    ['{#DOMAIN}' => 'mail.example.com', '{#PORT}' => 465, '{#TYPE}' => 'smtp',
     '{#NAME}'   => 'SMTP mail.example.com'],

    ['{#DOMAIN}' => 'app.example.com', 'serviceids' => [42]],

];
