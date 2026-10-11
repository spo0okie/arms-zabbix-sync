<?php
/**
 * Скопируйте в web.data.priv.php. template — техническое имя существующего шаблона.
 * name — имя сценария; триггер называется «Недоступен <name>».
 * problem — число последовательных ошибок (по умолчанию 5).
 * recovery — число последовательных успехов; null/отсутствие — восстановление по Problem.
 * delay — интервал сценария (1m), priority — важность триггера (3), status — 0/1.
 * Если steps отсутствует: один запрос на url, HTTP 200, таймаут 10s.
 * Явные шаги выполняются в порядке массива; timeout/status_codes имеют те же умолчания.
 */
return [
    'template'=>'Service web monitoring',
    'services'=>[
        [
            'name'=>'Сайт staging.domain.local:8443 c {HOST.NAME}',
            'url'=>'https://staging.domain.local:8443/',
            'problem'=>5,
            'recovery'=>2,
        ],
        [
            'name'=>'Портал',
            'url'=>'https://portal.example.com/',
            'problem'=>3, // Восстановление — когда условие проблемы перестанет выполняться.
        ],
        [
            'name'=>'Приложение',
            'problem'=>2,
            'recovery'=>1,
            'delay'=>'30s',
            'steps'=>[
                ['name'=>'Главная', 'url'=>'https://app.example.com/', 'required'=>'Вход'],
                ['name'=>'Здоровье', 'url'=>'https://app.example.com/health', 'required'=>'OK', 'timeout'=>'5s'],
            ],
        ],
    ],
];
