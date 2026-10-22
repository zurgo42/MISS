<?php
declare(strict_types=1);

$md_config = __DIR__ . '/../config.php';
if (!is_file($md_config)) {
    http_response_code(500);
    exit('config.php fehlt. Vorlage: config.example.php (Demo: php tools/demo_setup.php)');
}
require_once $md_config;

date_default_timezone_set('Europe/Berlin');
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/fields.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/changelog.php';
require_once __DIR__ . '/visibility.php';
require_once __DIR__ . '/profile.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/terms.php';
require_once __DIR__ . '/layout.php';

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
