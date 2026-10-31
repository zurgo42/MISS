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
    exit('MISS läuft nur, wenn die Sitzungsverwaltung im Standalone-Betrieb arbeitet (MD_SV_MODE). Im Adapterbetrieb führt die Tabelle "berechtigte".');
}

// Basis-Pfad der Anwendung (z. B. "/MISS" unter XAMPP). Kann in config.php mit MD_BASE_URL festgelegt werden.
if (!defined('MD_BASE_URL')) {
    $root = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
    $base = null;
    // Zuerst aus der aufgerufenen Adresse: endet SCRIPT_NAME mit dem Pfad des Skripts unterhalb von MISS (z. B. /MISS/admin/index.php),
    // ist der Rest der Basispfad. Das stimmt auch bei umbenannten Verzeichnissen, Aliasen und Verknüpfungen auf dem Server.
    if (PHP_SAPI !== 'cli') {
        $sf = str_replace('\\', '/', (string)realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')));
        $sn = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($sf !== '' && $sn !== '' && strpos($sf, $root . '/') === 0) {
            $rel = substr($sf, strlen($root) + 1);
            if ($rel !== '' && substr($sn, -strlen($rel) - 1) === '/' . $rel) $base = rtrim(substr($sn, 0, -strlen($rel) - 1), '/');
        }
    }
    if ($base === null) {
        $doc = str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $base = ($doc !== '' && stripos($root, $doc) === 0) ? rtrim(substr($root, strlen($doc)), '/') : '';
    }
    define('MD_BASE_URL', $base);
}
function md_url(string $path = ''): string
{
    return MD_BASE_URL . '/' . ltrim($path, '/');
}

// Name der Anwendung (Familie MISS / KONA / VERA); Tabellen- und Einstellungsnamen bleiben md_*
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
require_once __DIR__ . '/portal.php';
require_once __DIR__ . '/migrate.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/terms.php';
require_once __DIR__ . '/wabs.php';
require_once __DIR__ . '/layout.php';

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    // Sitzung wie in der Sitzungsverwaltung (KONA) und KAND: Cookie für die ganze Domain, gleiche Laufzeit (MD_SESSION_DAYS, Standard 15 Tage).
    // So bleibt die Anmeldung beim Wechsel zwischen den Anwendungen erhalten und keine räumt die Sitzungen der anderen früher ab.
    $md_days = defined('MD_SESSION_DAYS') ? max(1, (int)MD_SESSION_DAYS) : 15;
    ini_set('session.gc_maxlifetime', (string)($md_days * 86400));
    session_set_cookie_params(['lifetime' => $md_days * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
    session_start();
}
