<?php
declare(strict_types=1);

$md_config = __DIR__ . '/../config.php';
if (!is_file($md_config)) {
    http_response_code(500);
    exit('config.php fehlt. Vorlage: config.example.php (Demo: php tools/demo_setup.php)');
}
require_once $md_config;

if (!defined('MD_SV_MODE') || MD_SV_MODE !== 'standalone') {
    http_response_code(503);
    exit('Die Mitgliederverwaltung läuft nur, wenn die Sitzungsverwaltung im Standalone-Betrieb arbeitet (MD_SV_MODE). Im Adapterbetrieb führt die Tabelle "berechtigte".');
}

// Basis-Pfad der Anwendung (z. B. "/Mitgliederverwaltung" unter XAMPP). Kann in config.php mit MD_BASE_URL festgelegt werden.
if (!defined('MD_BASE_URL')) {
    $root = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
    $doc = str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    define('MD_BASE_URL', ($doc !== '' && stripos($root, $doc) === 0) ? rtrim(substr($root, strlen($doc)), '/') : '');
}
function md_url(string $path = ''): string
{
    return MD_BASE_URL . '/' . ltrim($path, '/');
}

// Name der Anwendung (Familie elMISS / elKONA / elVERA …); Tabellen- und Einstellungsnamen bleiben md_*
const MD_APP_NAME = 'MISS';
const MD_APP_TAG = 'Mitglieder-Informationssystem';
const MD_APP_LONG = 'Mitglieder-Informations- und Service-System';

date_default_timezone_set('Europe/Berlin');
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/fields.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/changelog.php';
require_once __DIR__ . '/visibility.php';
require_once __DIR__ . '/profile.php';
require_once __DIR__ . '/rights.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/ledger.php';
require_once __DIR__ . '/receipts.php';
require_once __DIR__ . '/privacy.php';
require_once __DIR__ . '/fees.php';
require_once __DIR__ . '/dunning.php';
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/sepa.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/login.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/lists.php';
require_once __DIR__ . '/apply.php';
require_once __DIR__ . '/datev.php';
require_once __DIR__ . '/org.php';
require_once __DIR__ . '/migrate.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/terms.php';
require_once __DIR__ . '/layout.php';

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
