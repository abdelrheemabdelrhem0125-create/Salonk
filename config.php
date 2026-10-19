<?php
declare(strict_types=1);

/*
 * إعدادات التطبيق وقاعدة البيانات والجلسة.
 * في الخادم الحقيقي يمكن ضبط متغيرات البيئة:
 * SALON_DB_HOST, SALON_DB_PORT, SALON_DB_NAME, SALON_DB_USER, SALON_DB_PASS
 */

$is_https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
);

return [
    'app_name' => 'منصة صالونك',
    'timezone' => 'Africa/Khartoum',

    'db' => [
        'host' => getenv('SALON_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('SALON_DB_PORT') ?: '3306',
        'name' => getenv('SALON_DB_NAME') ?: 'salon-db',
        'user' => getenv('SALON_DB_USER') ?: 'root',
        'pass' => getenv('SALON_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],

    'session_name' => 'salon_session',
    'session_secure' => $is_https,
];