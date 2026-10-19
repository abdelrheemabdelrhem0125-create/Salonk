<?php
declare(strict_types=1);

$app_config = require dirname(__DIR__) . '/config/config.php';

date_default_timezone_set($app_config['timezone']);

ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');

session_name($app_config['session_name']);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $app_config['session_secure'],
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/services/SalonService.php';
require_once __DIR__ . '/view.php';

security_headers(!defined('API_REQUEST'));