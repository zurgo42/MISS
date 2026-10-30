<?php
declare(strict_types=1);

/**
 * vera_sso.php - Durchlässigkeit MISS -> VERA: trägt die Identität der
 * angemeldeten Person beim Klick auf den VERA-Knopf über dem Dashboard
 * (siehe portal.php: md_portal_html()) automatisch mit, auch wenn beide
 * Anwendungen NICHT hinter einem gemeinsamen vorgelagerten SSO
 * (REMOTE_USER) laufen - genau der Fall bei einem eigenständigen
 * Vereins-Deployment ohne Mensa-Infrastruktur (siehe VERA:
 * login_modul_aktiv/demo_modus_aktiv in dessen db_functions.php). Läuft
 * MISS/VERA dagegen hinter einem gemeinsamen SSO, trägt bereits REMOTE_USER
 * die Identität - diese Kopplung bleibt dann einfach ungenutzt, ohne Wirkung
 * (siehe VERA: login.php, Klassenkommentar).
 *
 * Mechanik: MISS legt beim Linkaufbau direkt einen Eintrag in VERAs eigener
 * Magic-Link-Tabelle (mv_login_tokens) an - exakt dasselbe Verfahren, das
 * VERA selbst für seine Anmeldemails nutzt (siehe VERA: login_functions.php,
 * login_token_erzeugen()), nur von MISS aus ausgelöst statt per Mail-Versand.
 * Dieselbe Verbindungslogik wie bei der Wahlserver-Kopplung (siehe wabs.php:
 * md_wabs_transfer_conf()/md_wabs_transfer_db()): entweder dieselbe
 * Datenbank wie MISS selbst (typischer Fall: MISS und VERA teilen sich
 * svmembers, siehe VERA: mitgliederquelle_functions.php), oder eine eigene
 * Verbindung zu VERAs Datenbank.
 *
 * Die übertragene Kennung (MISS: svmembers.membership_number) muss in VERA
 * als Mitgliedsnummer erkannt werden - das ist bereits Voraussetzung der
 * dort gewählten Mitgliederquelle (gemeinsame svmembers-Tabelle,
 * synchronisierte Tabelle oder LDAP) und keine neue Anforderung dieser
 * Kopplung.
 */

/** Aktuelle Verbindungseinstellungen zu VERA (Passwort entschlüsselt, leer wenn nicht gesetzt/entschlüsselbar). */
function md_vera_sso_conf(): array
{
    return [
        'aktiv' => md_setting('vera.sso_aktiv', '0') === '1',
        'same_db' => md_setting('vera.same_db', '0') === '1',
        'host' => md_setting('vera.db_host', ''),
        'port' => md_setting('vera.db_port', '3306'),
        'name' => md_setting('vera.db_name', ''),
        'user' => md_setting('vera.db_user', ''),
        'pass' => (string)(md_decrypt(md_setting('vera.db_pass_enc', '')) ?? ''),
    ];
}

/** Ist die Kopplung tatsächlich einsatzbereit (eingeschaltet UND gleiche Datenbank wie MISS ODER vollständige eigene Angaben)? */
function md_vera_sso_ready(array $conf): bool
{
    return $conf['aktiv'] && ($conf['same_db'] || ($conf['host'] !== '' && $conf['name'] !== '' && $conf['user'] !== ''));
}

/**
 * Speichert die Verbindungseinstellungen zu VERA. Passwort nur bei Eingabe
 * überschrieben (leer = unverändert) - gleiches Vorgehen wie
 * md_wabs_transfer_conf_save(). Gibt im Fehlerfall einen Hinweistext zurück,
 * sonst ''.
 */
function md_vera_sso_conf_save(bool $aktiv, bool $sameDb, string $host, string $port, string $name, string $user, string $pass): string
{
    if ($aktiv && $sameDb && MD_DB_DRIVER !== 'mysql') return '„Gleiche Datenbank wie MISS“ ist nur möglich, wenn MISS selbst mit MySQL läuft.';
    if ($aktiv && !$sameDb && ($host === '' || $name === '' || $user === '')) return 'Bitte Datenbankserver, Datenbankname und Benutzer angeben (oder „gleiche Datenbank wie MISS“ ankreuzen).';
    if ($sameDb) { $host = ''; $name = ''; $user = ''; $pass = ''; }
    foreach ([
        ['vera.sso_aktiv', $aktiv ? '1' : '0', 'Mitgliedsnummer beim Wechsel zu VERA automatisch übertragen'],
        ['vera.same_db', $sameDb ? '1' : '0', 'VERA ist dieselbe Datenbank wie MISS selbst'],
        ['vera.db_host', $host, 'VERA-Datenbank: Host'],
        ['vera.db_port', $port !== '' ? $port : '3306', 'VERA-Datenbank: Port'],
        ['vera.db_name', $name, 'VERA-Datenbank: Datenbankname'],
        ['vera.db_user', $user, 'VERA-Datenbank: Benutzer'],
    ] as [$k, $v, $d]) {
        if (md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', [$k])) md_exec('UPDATE md_settings SET setting_value = ? WHERE setting_key = ?', [$v, $k]);
        else md_exec('INSERT INTO md_settings (setting_key, setting_value, description) VALUES (?,?,?)', [$k, $v, $d]);
    }
    if (!$sameDb && trim($pass) !== '') {
        $enc = md_encrypt($pass);
        if ($enc === null) return 'Das Passwort kann nicht verschlüsselt werden: MD_ENCRYPTION_KEY fehlt in config.php (php tools/make_key.php).';
        if (md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', ['vera.db_pass_enc'])) md_exec('UPDATE md_settings SET setting_value = ? WHERE setting_key = ?', [$enc, 'vera.db_pass_enc']);
        else md_exec('INSERT INTO md_settings (setting_key, setting_value, description) VALUES (?,?,?)', ['vera.db_pass_enc', $enc, 'VERA-Datenbank: Passwort (verschlüsselt)']);
    } elseif ($sameDb && md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', ['vera.db_pass_enc'])) {
        md_exec('UPDATE md_settings SET setting_value = ? WHERE setting_key = ?', ['', 'vera.db_pass_enc']);
    }
    return '';
}

/** Öffnet die Verbindung zur VERA-Datenbank (siehe md_wabs_transfer_db() für dieselbe Logik). */
function md_vera_db(array $conf): PDO
{
    if ($conf['same_db']) {
        if (MD_DB_DRIVER !== 'mysql') throw new RuntimeException('„Gleiche Datenbank wie MISS“ ist nur mit MySQL möglich.');
        return md_db();
    }
    $pdo = new PDO('mysql:host=' . $conf['host'] . ';port=' . ((int)$conf['port'] ?: 3306) . ';dbname=' . $conf['name'] . ';charset=utf8mb4', $conf['user'], $conf['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

/**
 * Legt in VERAs eigener Tabelle mv_login_tokens einen neuen, 15 Minuten
 * gültigen Anmelde-Token für $mnr an - exakt dasselbe Format/dieselbe
 * Gültigkeit wie VERAs eigene login_token_erzeugen() (siehe dort), nur von
 * hier aus statt per Mail-Versand ausgelöst. CREATE TABLE IF NOT EXISTS mit
 * demselben Schema wie dort, falls VERA die Tabelle (z. B. mangels bisher
 * genutztem Magic-Link-Login) noch nicht angelegt hat. Gibt den rohen
 * Token zurück, oder null bei einem Verbindungs-/Schreibfehler (Aufrufer
 * baut den Link dann ohne Token, wie vor dieser Kopplung).
 */
function md_vera_login_token_erzeugen(array $conf, string $mnr): ?string
{
    try {
        $pdo = md_vera_db($conf);
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS mv_login_tokens (
                ID INT AUTO_INCREMENT PRIMARY KEY,
                MNr VARCHAR(12), TokenHash VARCHAR(64), Erstellt DATETIME, Ablauf DATETIME, Benutzt TINYINT(1) DEFAULT 0,
                INDEX idx_tokenhash (TokenHash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO mv_login_tokens (MNr, TokenHash, Erstellt, Ablauf) VALUES (?, ?, NOW(), NOW() + INTERVAL 15 MINUTE)')
            ->execute([$mnr, hash('sha256', $token)]);
        return $token;
    } catch (Throwable $e) {
        error_log('md vera_sso: Token konnte nicht angelegt werden: ' . $e->getMessage());
        return null;
    }
}

/**
 * Baut die tatsächlich aufzurufende VERA-Adresse für diese Person: mit
 * automatischem Anmelde-Token, wenn die Kopplung eingerichtet ist und eine
 * Mitgliedsnummer vorliegt, sonst unverändert die eingetragene
 * Basis-Adresse (Verhalten wie vor dieser Kopplung).
 */
function md_vera_portal_url(string $baseUrl, string $mnr): string
{
    if ($baseUrl === '' || $mnr === '') return $baseUrl;
    $conf = md_vera_sso_conf();
    if (!md_vera_sso_ready($conf)) return $baseUrl;
    $token = md_vera_login_token_erzeugen($conf, $mnr);
    if ($token === null) return $baseUrl;
    return rtrim($baseUrl, '/') . '/login.php?token=' . $token;
}
