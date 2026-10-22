<?php
/**
 * install.php – richtet die Mitgliederverwaltung in einer bestehenden MySQL-Datenbank ein (gemeinsam mit der Sitzungsverwaltung).
 *
 *   php tools/install.php --check     nur prüfen, nichts schreiben
 *   php tools/install.php             md_*-Tabellen anlegen, Standard-Funktionen und Einstellungen eintragen
 *
 * Unter XAMPP/Windows:  C:\xampp\php\php.exe tools\install.php --check
 * Bestehende Tabellen (auch svmembers) werden nie verändert oder gelöscht.
 */
if (PHP_SAPI !== 'cli') exit('nur per Kommandozeile');
$root = dirname(__DIR__);
if (!is_file("$root/config.php")) exit("config.php fehlt – config.example.php kopieren und Datenbankzugang eintragen.\n");
require_once "$root/src/bootstrap.php";
$check = in_array('--check', $argv, true);
$problems = 0;
$say = fn(string $s) => print($s . "\n");

if (MD_DB_DRIVER !== 'mysql') exit("MD_DB_DRIVER ist '" . MD_DB_DRIVER . "'. Dieses Skript ist für MySQL (Demo mit SQLite: tools/demo_setup.php).\n");
try { $pdo = md_db(); } catch (Throwable $e) { exit('Keine Datenbankverbindung: ' . $e->getMessage() . "\n"); }
$say('Datenbank: ' . MD_DB_NAME . ' auf ' . MD_DB_HOST . ($check ? '  [nur Prüfung]' : ''));

// 1. svmembers prüfen
if (!md_one("SHOW TABLES LIKE 'svmembers'")) exit("FEHLER: Tabelle svmembers fehlt. Zuerst die Sitzungsverwaltung einrichten.\n");
$cols = md_sv_columns();
foreach (['member_id', 'membership_number', 'first_name', 'last_name', 'email', 'role'] as $c) if (!in_array($c, $cols, true)) { $say("FEHLER: svmembers.$c fehlt."); $problems++; }
foreach (['aktiv', 'funktion', 'is_admin', 'is_active'] as $c) if (!in_array($c, $cols, true)) $say("Hinweis: svmembers.$c fehlt – der Rechteabgleich bleibt ohne diese Spalte aus.");
$role = md_one("SHOW COLUMNS FROM svmembers LIKE 'role'");
$say('svmembers.role: ' . ($role['Type'] ?? '?'));
if (stripos($role['Type'] ?? '', 'enum') === 0 && stripos($role['Type'], "'Mitglied'") === false) { $say("FEHLER: ENUM von role enthält 'Mitglied' nicht – Import und Rechteabgleich würden scheitern."); $problems++; }
$n = (int)md_one('SELECT COUNT(*) c FROM svmembers')['c'];
$say("svmembers: $n Mitglieder");
if (md_one("SHOW TABLES LIKE 'svressorts'")) $say('Ressortliste svressorts: vorhanden'); else $say('Hinweis: svressorts nicht gefunden – Ressorts können nicht zugeordnet werden.');

// 2. md_-Tabellen
$existing = array_map('current', md_q("SHOW TABLES LIKE 'md\\_%'"));
$say($existing ? 'Vorhandene md_-Tabellen: ' . count($existing) : 'Noch keine md_-Tabellen.');
if ($problems) exit("\n$problems Problem(e) – bitte zuerst beheben.\n");
if ($check) exit("\nPrüfung ohne Befund. Zum Anlegen ohne --check erneut starten.\n");

$sql = (string)file_get_contents("$root/sql/schema.sql");
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$sql = preg_replace('/\s--[^\n]*/', '', $sql);
foreach (array_filter(array_map('trim', preg_split('/;[ \t]*\R/', $sql))) as $stmt) {
    preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $stmt, $m);
    try {
        $pdo->exec($stmt);
        $say('  ok   ' . $m[1]);
    } catch (Throwable $e) {
        // Fremdschlüssel nicht möglich (z. B. andere Engine/Collation in svmembers): ohne Fremdschlüssel anlegen
        $plain = preg_replace('/,?\s*FOREIGN KEY[^\n]*/', '', $stmt);
        try { $pdo->exec($plain); $say('  ok   ' . $m[1] . '  (ohne Fremdschlüssel: ' . substr($e->getMessage(), 0, 80) . ')'); }
        catch (Throwable $e2) { $say('  FEHLER ' . $m[1] . ': ' . $e2->getMessage()); $problems++; }
    }
}
if ($problems) exit("\nFehler beim Anlegen – bitte Meldungen prüfen.\n");

// 3. Schema auf den neuesten Stand bringen (nur Ergänzungen) und Grunddaten
$added = md_migrate_schema();
if ($added) $say('Spalten ergänzt: ' . implode(', ', $added));
if (!(int)md_one('SELECT COUNT(*) c FROM md_functions')['c']) {
    foreach (MD_DEFAULT_FUNCTIONS as [$code, $name, $lvl]) md_exec('INSERT INTO md_functions (code, name, sv_aktiv_level) VALUES (?,?,?)', [$code, $name, $lvl]);
    $say('Funktionen eingetragen: ' . count(MD_DEFAULT_FUNCTIONS));
}
$n = md_seed_permissions();
if ($n) $say("Rechtetabelle mit Voreinstellung gefüllt ($n Einträge, änderbar unter Einstellungen).");
$n = md_dunning_seed();
if ($n) $say("Mahnstufen mit Voreinstellung angelegt ($n, änderbar unter Mahnwesen).");
foreach ([['ueb_freibetrag_cents', '330000', 'Jahresfreibetrag Übungsleiterpauschale in Cent (bitte jährlich prüfen)'],
          ['default_aktiv', '0', 'aktiv-Level für Mitglieder ohne gültige Funktion (nur bei Mitgliedern mit Funktionszuordnung)']] as [$k, $v, $d]) {
    if (!md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', [$k])) md_exec('INSERT INTO md_settings (setting_key, setting_value, description) VALUES (?,?,?)', [$k, $v, $d]);
}
$say("\nFertig. Mitglieder-Dashboard: " . md_url('index.php') . "\nNächster Schritt (nur lokal/Demo): php tools/demo_load.php");
