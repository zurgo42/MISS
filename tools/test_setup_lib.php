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
    if (!md_is_test_instance()) return "MD_TEST_INSTANCE ist nicht true. Dieses Skript richtet ein Testsystem für externe Tester auf einem Server ein (MD_TEST_INSTANCE=true und MD_DEMO_MODE=false in config.php). Für die lokale Demo (MD_DEMO_MODE=true): php tools/demo_load.php --reset bzw. php tools/demo_setup.php.";
    if (MD_DEMO_MODE) return 'MD_DEMO_MODE muss false sein: Testsysteme arbeiten mit echter Anmeldung (Passwörter).';
    if (MD_DB_DRIVER !== 'mysql') return "MD_DB_DRIVER muss 'mysql' sein.";
    if (!preg_match('/^[0-9a-fA-F]{64}$/', defined('MD_ENCRYPTION_KEY') ? (string)MD_ENCRYPTION_KEY : '')) return "MD_ENCRYPTION_KEY fehlt in config.php (Schlüssel erzeugen: php tools/make_key.php).";
    try { md_db(); } catch (Throwable $e) { return 'Keine Datenbankverbindung: ' . $e->getMessage(); }
    if (md_table_exists('svmembers')) {
        $others = md_q("SELECT membership_number FROM svmembers WHERE membership_number NOT LIKE 'DEMO%' OR membership_number IS NULL LIMIT 3");
        if ($others) return 'Abbruch: svmembers enthält Mitglieder, die keine Demo-Figuren sind (z. B. ' . implode(', ', array_column($others, 'membership_number')) . '). Das sieht nach echten Daten aus – das Testsystem läuft nur in einer Datenbank, die ausschließlich Demo-Daten enthält.';
        if (md_ts_mode() === 'shared' && count(md_ts_idmap()) < 12) return 'Abbruch: In svmembers fehlen Demo-Mitglieder (' . count(md_ts_idmap()) . ' von 12 gefunden). Zuerst die Demo der Sitzungsverwaltung laden.';
        if (md_table_exists('md_profiles') && (int)md_one('SELECT COUNT(*) c FROM md_profiles')['c'] > 0 && !$reset) return 'Das Testsystem ist schon eingerichtet. Zum Neuaufbau (löscht alle Testdaten der Mitgliederverwaltung) mit Zurücksetzen starten, nach einem Update „Datenbank aktualisieren“ wählen.';
    }
    return null;
}

/**
 * 'fresh': noch keine Mitgliedertabelle (wird als Testversion angelegt) · 'own': Testversion von uns angelegt (Marker) ·
 * 'shared': Mitgliedertabelle der Sitzungsverwaltung mit deren Demo-Figuren – sie bleibt unverändert, nur md_-Tabellen kommen dazu.
 */
function md_ts_mode(): string
{
    if (!md_table_exists('svmembers')) return 'fresh';
    if (md_table_exists('md_settings') && (md_one("SELECT setting_value v FROM md_settings WHERE setting_key = 'test.own_sv'")['v'] ?? '') === '1') return 'own';
    return 'shared';
}

function md_ts_idmap(): array
{
    $m = [];
    foreach (md_q("SELECT member_id, membership_number FROM svmembers WHERE membership_number LIKE 'DEMO%'") as $r) $m[(int)substr($r['membership_number'], 4)] = (int)$r['member_id'];
    return $m;
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
        if (md_ts_mode() !== 'own') throw new RuntimeException('In der Mitgliedertabelle der Sitzungsverwaltung fehlt die Spalte password_hash. Sie wird nicht verändert – bitte die Sitzungsverwaltung auf den aktuellen Stand bringen.');
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
    try { return md_ts_schema(); } catch (Throwable $e) { return ['FEHLER: ' . $e->getMessage()]; }
}

/** Baut das Testsystem komplett neu auf. Gibt [Meldungen, Zugangsdaten] zurück (Zugangsdaten: Name, Nummer, E-Mail, Rolle, Passwort). */
function md_test_setup(bool $reset, bool $syncSv = false): array
{
    if ($err = md_ts_guard($reset)) return [[$err], []];
    $pdo = md_db();
    $log = [];
    $mode = md_ts_mode();
    ob_start();
    try {
        if ($reset && $mode !== 'fresh') {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (array_map('current', md_q("SHOW TABLES LIKE 'md\\_%'")) as $t) $pdo->exec("DROP TABLE `$t`");
            if ($mode === 'own') foreach (['svmembers', 'svressorts'] as $t) if (md_table_exists($t)) $pdo->exec("DROP TABLE `$t`");
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            foreach (glob(MD_STORAGE_DIR . '/photos/*') ?: [] as $f) @unlink($f);
            $log[] = $mode === 'own' ? 'Alte Testdaten gelöscht.' : 'Alte Testdaten der Mitgliederverwaltung gelöscht (die Tabellen der Sitzungsverwaltung bleiben unverändert).';
            $mode = md_ts_mode();
        }
        $log = array_merge($log, md_ts_schema());
        $shared = $mode === 'shared';
        if (!$shared && !md_one("SELECT 1 FROM md_settings WHERE setting_key = 'test.own_sv'")) md_exec("INSERT INTO md_settings (setting_key, setting_value, description) VALUES ('test.own_sv', '1', 'Testsystem hat die Mitgliedertabelle selbst angelegt')");
        $idmap = $shared ? md_ts_idmap() : [];
        $data = demo_build(date('Y-m-d'));
        if ($shared) demo_ensure_extra_members($data['svmembers'], $idmap);
        if ($shared) {   // Ressorts der vorhandenen Ressortliste über den Namen zuordnen
            $real = [];
            try { foreach (md_q('SELECT ID, Ressort FROM svressorts') as $r) $real[mb_strtolower($r['Ressort'])] = (int)$r['ID']; } catch (Throwable $e) {}
            $demoRes = array_column($data['svressorts'], 'Ressort', 'ID');
            foreach ($data['md_member_functions'] as &$mf) $mf['ressort_id'] = $mf['ressort_id'] ? ($real[mb_strtolower($demoRes[$mf['ressort_id']] ?? '')] ?? null) : null;
            unset($mf);
        }
        $pdo->beginTransaction();
        if (!$shared) foreach (['svmembers', 'svressorts'] as $t) demo_insert($pdo, $t, $data[$t]);
        foreach (array_diff(DEMO_MD_TABLES, ['md_functions']) as $t) {
            if ($t === 'md_settings') {   // vorhandene Einstellungen (z. B. Mahnstufen) nicht doppelt anlegen
                foreach ($data[$t] as $st) if (!md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', [$st['setting_key']])) demo_insert($pdo, $t, [$st]);
                continue;
            }
            demo_insert($pdo, $t, $data[$t], $idmap);
        }
        $pdo->commit();
        demo_photos($pdo, $data['svmembers'], $idmap);
        demo_seed_sepa($idmap); demo_seed_mail($idmap); demo_seed_events($idmap); demo_seed_lists_apply($idmap); demo_seed_datev($idmap); demo_seed_org();
        demo_issue_receipts($idmap);
        if ($syncSv && $shared) demo_sync_sv($data['svmembers'], $idmap);
        md_seed_permissions();
        $log[] = '12 Testfiguren mit Beispieldaten angelegt (Beiträge, Lastschrift-Mandate, Veranstaltungen, Verteiler, Anträge, DATEV-Konten).' . ($shared ? ' Die Mitgliedertabelle der Sitzungsverwaltung wurde nicht verändert.' : '');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ob_end_clean();
        return [array_merge($log, ['FEHLER: ' . $e->getMessage()]), []];
    }
    ob_end_clean();
    $creds = [];
    foreach (md_q("SELECT * FROM svmembers WHERE membership_number LIKE 'DEMO%' ORDER BY member_id") as $m) {
        $pw = null;
        if (empty($m['password_hash'])) {   // vorhandene Passwörter (z. B. aus der Demo der Sitzungsverwaltung) bleiben unberührt
            $pw = md_ts_random_password();
            if ($e = md_password_set((int)$m['member_id'], $pw)) { $log[] = 'Passwort für ' . $m['membership_number'] . ': ' . $e; continue; }
        }
        $f = md_one('SELECT f.code FROM md_member_functions mf JOIN md_functions f ON f.function_id = mf.function_id WHERE mf.member_id = ? AND (mf.valid_to IS NULL OR mf.valid_to >= ?) ORDER BY f.sv_aktiv_level DESC LIMIT 1', [$m['member_id'], date('Y-m-d')]);
        $creds[] = ['name' => $m['first_name'] . ' ' . $m['last_name'], 'number' => $m['membership_number'], 'email' => $m['email'], 'role' => trim(($m['role'] ?? '') . ($f ? ', ' . $f['code'] : '') . (!empty($m['is_admin']) ? ', Admin' : ''), ', '), 'password' => $pw ?? '(vorhanden – wie in der Sitzungsverwaltung)'];
    }
    $log[] = 'Passwörter: nur für Figuren ohne Passwort neu gesetzt (werden nur jetzt angezeigt).';
    return [$log, $creds];
}
