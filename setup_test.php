<?php
/**
 * setup_test.php – Einrichter für das Testsystem im Browser (für Webspace ohne Kommandozeile). Siehe docs/PRODUKTIV.md.
 * Funktioniert nur mit MD_TEST_INSTANCE=true und einem geheimen MD_SETUP_TOKEN in config.php. Nach dem Einrichten diese Datei LÖSCHEN.
 */
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/tools/test_setup_lib.php';
header('X-Robots-Tag: noindex');
$tok = defined('MD_SETUP_TOKEN') ? (string)MD_SETUP_TOKEN : '';
$given = (string)($_POST['token'] ?? $_GET['token'] ?? '');
if (strlen($tok) < 20 || !hash_equals($tok, $given)) { http_response_code(404); exit('Nicht gefunden.'); }
// Wer das Token kennt, bekommt eine Erklärung statt einer leeren Seite
$problems = [];
if (!md_is_test_instance()) $problems[] = 'MD_TEST_INSTANCE ist in config.php nicht true. Hinweis: Steht die Konstante dort zweimal (z. B. am Ende von config.example.php und noch einmal weiter unten), gilt die erste; die zweite wird ignoriert.';
if (MD_DEMO_MODE) $problems[] = 'MD_DEMO_MODE ist true; für das Testsystem muss es false sein (Anmeldung mit Passwort).';
if (MD_DB_DRIVER !== 'mysql') $problems[] = "MD_DB_DRIVER muss 'mysql' sein.";
if (!preg_match('/^[0-9a-fA-F]{64}$/', defined('MD_ENCRYPTION_KEY') ? (string)MD_ENCRYPTION_KEY : '')) $problems[] = 'MD_ENCRYPTION_KEY fehlt oder hat nicht genau 64 Hex-Zeichen.';
if ($problems && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !md_is_test_instance())) {
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Testsystem einrichten</title></head><body style="font:15px system-ui,sans-serif;max-width:50rem;margin:2rem auto;padding:0 1rem"><h1>Testsystem einrichten</h1><p>Das Token stimmt, aber die Einrichtung ist noch nicht möglich:</p><ul>';
    foreach ($problems as $p) echo '<li>' . h($p) . '</li>';
    exit('</ul><p>Bitte config.php anpassen und diese Seite neu laden.</p></body></html>');
}
$out = '';
$creds = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    $act = $_POST['action'] ?? '';
    $sync = !empty($_POST['sync_sv']); $ign = !empty($_POST['ignore_others']);
    if ($act === 'setup') [$log, $creds] = md_test_setup(false, $sync, $ign) + [[], []];
    elseif ($act === 'reset') { if (($_POST['confirm'] ?? '') !== 'ZURÜCKSETZEN') $log = ['Zum Zurücksetzen bitte ZURÜCKSETZEN eintippen.']; else [$log, $creds] = md_test_setup(true, $sync, $ign); }
    elseif ($act === 'update') $log = md_test_update();
    elseif ($act === 'delete') $log = [@unlink(__FILE__) ? 'setup_test.php wurde gelöscht.' : 'Löschen nicht möglich – bitte die Datei setup_test.php per FTP entfernen.'];
    $out = implode("\n", $log);
}
echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Testsystem einrichten</title></head><body style="font:15px system-ui,sans-serif;max-width:60rem;margin:2rem auto;padding:0 1rem">';
echo '<h1>Testsystem einrichten</h1><p>Datenbank <strong>' . h(MD_DB_NAME) . '</strong>. Nach dem Einrichten diese Datei löschen.</p>';
if ($out) echo '<pre style="background:#eef;padding:10px;white-space:pre-wrap">' . h($out) . '</pre>';
if ($creds) {
    echo '<h2>Zugangsdaten (nur jetzt sichtbar – bitte notieren)</h2><table border="1" cellpadding="5" style="border-collapse:collapse"><tr><th>Name</th><th>Nr.</th><th>E-Mail</th><th>Rolle</th><th>Passwort</th></tr>';
    foreach ($creds as $c) echo '<tr><td>' . h($c['name']) . '</td><td>' . h($c['number']) . '</td><td>' . h($c['email']) . '</td><td>' . h($c['role']) . '</td><td><code>' . h($c['password']) . '</code></td></tr>';
    echo '</table><p><a href="login.php">Zur Anmeldung</a></p>';
}
$f = fn(string $act, string $label, string $extra = '') => '<form method="post" style="margin:12px 0">' . md_csrf_field() . '<input type="hidden" name="token" value="' . h($given) . '"><input type="hidden" name="action" value="' . $act . '">' . $extra . '<button>' . $label . '</button></form>';
$opts = '<p><label><input type="checkbox" name="sync_sv" value="1"> Rollen und Admin-Kennzeichen der Demo-Figuren in <code>svmembers</code> angleichen (nur bei gemeinsamer Datenbank)</label><br><label><input type="checkbox" name="ignore_others" value="1"> andere Konten in <code>svmembers</code> unberührt lassen (eigene Testkonten)</label></p>';
echo '<h2>Neue Einrichtung</h2><p>Legt die Tabellen von MISS an und füllt sie mit den Demo-Figuren. Ist <code>svmembers</code> schon vorhanden (Demo der Sitzungsverwaltung), bleibt sie unverändert; es werden nur die fehlenden Demo-Mitglieder DEMO0013 ff. ergänzt.</p>'
    . $f('setup', 'Einrichten', $opts)
    . '<h2>Nach einem Software-Update</h2>' . $f('update', 'Datenbank aktualisieren')
    . '<h2>Von vorn beginnen</h2>' . $f('reset', 'Alles löschen und neu aufbauen', '<input name="confirm" placeholder="ZURÜCKSETZEN eintippen"> ' . $opts)
    . '<h2>Fertig?</h2>' . $f('delete', 'Diese Datei jetzt löschen');
echo '</body></html>';
