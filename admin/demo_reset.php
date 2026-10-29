<?php
/**
 * admin/demo_reset.php - setzt die Demo (MD_DEMO_MODE=true) direkt aus dem
 * Admin-Bereich auf einen jungfräulichen Stand zurück, ohne Kommandozeile
 * (siehe tools/demo_lib.php: demo_reset_inplace() für SQLite,
 * demo_reset_inplace_mysql() für MySQL/gemeinsame Datenbank mit der
 * Sitzungsverwaltung). Gedacht für eine Ausprobierphase, die immer wieder
 * von vorn beginnen soll - analog zur entsprechenden Funktion in der
 * Sitzungsverwaltung (KONA).
 *
 * Nur im Demo-Modus erreichbar - auf einer echten Installation (MD_DEMO_MODE=false)
 * meldet diese Seite 404, unabhängig von Rechten.
 */
require_once __DIR__ . '/../src/bootstrap.php';
if (!defined('MD_DEMO_MODE') || !MD_DEMO_MODE) {
    http_response_code(404);
    exit('Nicht gefunden.');
}
$me = md_require_perm('settings', 'edit');
require_once __DIR__ . '/../tools/demo_lib.php';
$mysql = MD_DB_DRIVER === 'mysql';

$log = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    if (($_POST['confirm'] ?? '') !== 'ZURÜCKSETZEN') {
        $err = 'Zum Zurücksetzen bitte ZURÜCKSETZEN eintippen.';
    } else {
        try {
            $log = $mysql ? demo_reset_inplace_mysql((string)($_POST['anchor'] ?? '')) : demo_reset_inplace((string)($_POST['anchor'] ?? ''));
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

md_header('Demo-Daten zurücksetzen', $me, 'demo_reset');
md_show_flash();
echo '<h1>🎭 Demo-Daten zurücksetzen</h1>';
echo '<div class="card"><p><strong>Nur in dieser Demo-Instanz sichtbar</strong> (MD_DEMO_MODE=true). Setzt die Mitglieder-, Finanz-, Wahl- und sonstigen Daten auf den ursprünglichen Demo-Stand zurück - nützlich nach einer Ausprobierphase, wenn wieder ein frisches System gebraucht wird.</p>';
if ($mysql) {
    echo '<p>Diese Installation teilt sich die Datenbank mit der Sitzungsverwaltung (MySQL): <code>svmembers</code>/<code>svressorts</code> sowie die Funktionen- und Rechtetabellen bleiben unangetastet - nur fehlende Demo-Mitglieder werden ergänzt. Alle übrigen Daten (Profile, Finanzen, Wahl und Abstimmung, …) werden geleert und mit den immer gleichen, frei erfundenen Demo-Daten neu befüllt (12 Hauptfiguren Max Mustermann … Rolf Ehemals, wie in der Sitzungsverwaltung).</p>';
} else {
    echo '<p>Dabei werden sämtliche Tabellen geleert und mit den immer gleichen, frei erfundenen Demo-Daten neu befüllt (12 Hauptfiguren Max Mustermann … Rolf Ehemals plus weitere Mitglieder, wie in der Sitzungsverwaltung).</p>';
}
echo '<p>Unwiderruflich: alles, was seit der letzten Einrichtung geändert wurde, ist danach weg.</p></div>';

if ($err) echo '<div class="flash err">' . h($err) . '</div>';
if ($log) {
    echo '<div class="flash ok">Zurückgesetzt.</div><pre style="background:#eef;padding:10px;white-space:pre-wrap">' . h($log) . '</pre><p><a href="login.php">Zur Anmeldung</a></p>';
} else {
    echo '<div class="card"><form method="post" onsubmit="return confirm(\'Wirklich alle Daten löschen und durch den ursprünglichen Demo-Stand ersetzen?\')">' . md_csrf_field()
        . '<label class="block">Bezugstag <span class="muted">(für die erzeugten Datumsangaben, z. B. Alter/Eintrittsdaten; leer = heute)</span><input type="date" name="anchor"></label>'
        . '<label class="block">Zum Bestätigen <code>ZURÜCKSETZEN</code> eintippen<input name="confirm" placeholder="ZURÜCKSETZEN" required></label>'
        . '<button>Jetzt zurücksetzen</button></form></div>';
}
md_footer();
