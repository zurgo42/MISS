<?php
/**
 * Einrichtung eines Testsystems für externe Tester (eigene MySQL-Datenbank, erfundene Daten, echte Anmeldung mit Passwort).
 * Wird von tools/test_setup.php (Kommandozeile) und setup_test.php (Browser, mit Token) genutzt.
 * Schutz: läuft nur mit MD_TEST_INSTANCE=true und MD_DEMO_MODE=false und bricht ab, sobald svmembers andere Mitglieder als DEMO0001–DEMO0012 enthält.
 */
require_once __DIR__ . '/demo_build.php';
require_once __DIR__ . '/demo_lib.php';

/** Prüft die Voraussetzungen. Gibt null oder die Fehlermeldung zurück. */
function md_ts_guard(bool $reset): ?string
{
    if (!md_is_test_instance()) return "MD_TEST_INSTANCE ist nicht true. Das Testsystem wird nur auf einer eigenen Testdatenbank eingerichtet (config.php).";
    if (MD_DEMO_MODE) return 'MD_DEMO_MODE muss false sein: Testsysteme arbeiten mit echter Anmeldung (Passwörter).';
    if (MD_DB_DRIVER !== 'mysql') return "MD_DB_DRIVER muss 'mysql' sein.";
    if (!preg_match('/^[0-9a-fA-F]{64}$/', defined('MD_ENCRYPTION_KEY') ? (string)MD_ENCRYPTION_KEY : '')) return "MD_ENCRYPTION_KEY fehlt in config.php (Schlüssel erzeugen: php tools/make_key.php).";
    try { md_db(); } catch (Throwable $e) { return 'Keine Datenbankverbindung: ' . $e->getMessage(); }
    if (md_table_exists('svmembers')) {
        $others = md_q("SELECT membership_number FROM svmembers WHERE membership_number NOT LIKE 'DEMO%' OR membership_number IS NULL LIMIT 3");
        if ($others) return 'Abbruch: svmembers enthält Mitglieder, die keine Demo-Figuren sind (z. B. ' . implode(', ', array_column($others, 'membership_number')) . '). Das sieht nach einer echten Datenbank aus – das Testsystem braucht eine eigene, leere Datenbank.';
        if ((int)md_one('SELECT COUNT(*) c FROM svmembers')['c'] > 0 && !$reset) return 'Das Testsystem ist schon eingerichtet. Zum Neuaufbau (löscht alle Testdaten) mit Zurücksetzen starten, nach einem Update „Datenbank aktualisieren“ wählen.';
    }
    return null;
}

function md_ts_random_password(): string
{
    $a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $p = '';
    for ($i = 0; $i < 12; $i++) $p .= $a[random_int(0, strlen($a) - 1)];
    return $p;
}

/** Legt Tabellen an und ergänzt Grunddaten (idempotent). Gibt Meldungen zurück. */
function md_ts_schema(): array
{
    $log = [];
    $pdo = md_db();
    if (!md_table_exists('svmembers')) {
        md_run_sql_file($pdo, dirname(__DIR__) . '/sql/sv_stub.sql');
        $log[] = 'Mitgliedertabelle svmembers (Testversion) angelegt.';
    } elseif (!in_array('password_hash', md_table_columns('svmembers'), true)) {
        $pdo->exec('ALTER TABLE svmembers ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL');
        $log[] = 'svmembers.password_hash ergänzt.';
    }
    md_run_sql_file($pdo, dirname(__DIR__) . '/sql/schema.sql');
    $added = md_migrate_schema();
    $log[] = 'Tabellen angelegt/geprüft' . ($added ? '; Spalten ergänzt: ' . implode(', ', $added) : '') . '.';
    if (!(int)md_one('SELECT COUNT(*) c FROM md_functions')['c']) {
        foreach (MD_DEFAULT_FUNCTIONS as [$code, $name, $lvl]) md_exec('INSERT INTO md_functions (code, name, sv_aktiv_level) VALUES (?,?,?)', [$code, $name, $lvl]);
    }
    if ($n = md_seed_permissions()) $log[] = "Rechtetabelle gefüllt ($n Einträge).";
    if ($n = md_dunning_seed()) $log[] = "Mahnstufen angelegt ($n).";
    return $log;
}

/** Nach einem Update der Software: Tabellen ergänzen, nichts löschen. */
function md_test_update(): array
{
    if (!md_is_test_instance()) return ['MD_TEST_INSTANCE ist nicht true.'];
    return md_ts_schema();
}

/** Baut das Testsystem komplett neu auf. Gibt [Meldungen, Zugangsdaten] zurück (Zugangsdaten: Name, Nummer, E-Mail, Rolle, Passwort). */
function md_test_setup(bool $reset): array
{
    if ($err = md_ts_guard($reset)) return [[$err], []];
    $pdo = md_db();
    $log = [];
    ob_start();
    try {
        if ($reset) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (array_map('current', md_q("SHOW TABLES LIKE 'md\\_%'")) as $t) $pdo->exec("DROP TABLE `$t`");
            foreach (['svmembers', 'svressorts'] as $t) if (md_table_exists($t)) $pdo->exec("DROP TABLE `$t`");
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            foreach (glob(MD_STORAGE_DIR . '/photos/*') ?: [] as $f) @unlink($f);
            $log[] = 'Alte Testdaten gelöscht.';
        }
        $log = array_merge($log, md_ts_schema());
        $data = demo_build(date('Y-m-d'));
        $pdo->beginTransaction();
        foreach (['svmembers', 'svressorts'] as $t) demo_insert($pdo, $t, $data[$t]);
        foreach (array_diff(DEMO_MD_TABLES, ['md_functions']) as $t) {
            if ($t === 'md_settings') {   // vorhandene Einstellungen (z. B. Mahnstufen) nicht doppelt anlegen
                foreach ($data[$t] as $st) if (!md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', [$st['setting_key']])) demo_insert($pdo, $t, [$st]);
                continue;
            }
            demo_insert($pdo, $t, $data[$t]);
        }
        $pdo->commit();
        demo_photos($pdo, $data['svmembers']);
        demo_seed_sepa(); demo_seed_mail(); demo_seed_events(); demo_seed_lists_apply(); demo_seed_datev();
        demo_issue_receipts();
        md_seed_permissions();
        $log[] = '12 Testfiguren mit Beispieldaten angelegt (Beiträge, Lastschrift-Mandate, Veranstaltungen, Verteiler, Anträge, DATEV-Konten).';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ob_end_clean();
        return [array_merge($log, ['FEHLER: ' . $e->getMessage()]), []];
    }
    ob_end_clean();
    $creds = [];
    foreach (md_q("SELECT * FROM svmembers WHERE membership_number LIKE 'DEMO%' ORDER BY member_id") as $m) {
        $pw = md_ts_random_password();
        if ($e = md_password_set((int)$m['member_id'], $pw)) { $log[] = 'Passwort für ' . $m['membership_number'] . ': ' . $e; continue; }
        $f = md_one('SELECT f.code FROM md_member_functions mf JOIN md_functions f ON f.function_id = mf.function_id WHERE mf.member_id = ? AND (mf.valid_to IS NULL OR mf.valid_to >= ?) ORDER BY f.sv_aktiv_level DESC LIMIT 1', [$m['member_id'], date('Y-m-d')]);
        $creds[] = ['name' => $m['first_name'] . ' ' . $m['last_name'], 'number' => $m['membership_number'], 'email' => $m['email'], 'role' => trim(($m['role'] ?? '') . ($f ? ', ' . $f['code'] : '') . (!empty($m['is_admin']) ? ', Admin' : ''), ', '), 'password' => $pw];
    }
    $log[] = 'Passwörter gesetzt (werden nur jetzt angezeigt).';
    return [$log, $creds];
}
