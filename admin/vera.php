<?php
require_once __DIR__ . '/../src/bootstrap.php';

/**
 * admin/vera.php - Stimmberechtigung für VERA (Versammlungsorganisation),
 * siehe src/vera.php für die Herleitung und docs/VERA.md für die
 * Zusammenhänge mit der Produktfamilie.
 */

$me = md_require_perm('members', 'view');
$aid = (int)$me['member_id'];
$edit = md_can($me, 'members', 'edit');
$back = 'admin/vera.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    if (!$edit) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
    $act = $_POST['action'] ?? '';
    $err = '';
    $ok = 'Gespeichert.';
    if ($act === 'settings') {
        $alter = trim((string)($_POST['mindestalter'] ?? ''));
        $stichtag = trim((string)($_POST['stichtag'] ?? ''));
        if ($alter !== '' && (!ctype_digit($alter) || (int)$alter > 120)) {
            $err = 'Mindestalter: ganze Zahl 0–120, oder leer lassen.';
        } elseif ($stichtag !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $stichtag)) {
            $err = 'Stichtag: Datum oder leer lassen.';
        } else {
            foreach (['vera.mindestalter' => $alter, 'vera.stichtag' => $stichtag] as $k => $v) {
                $old = md_setting($k, '');
                if ($old === $v) continue;
                if (md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', [$k])) md_exec('UPDATE md_settings SET setting_value = ? WHERE setting_key = ?', [$v, $k]);
                else md_exec('INSERT INTO md_settings (setting_key, setting_value, description) VALUES (?,?,?)', [$k, $v, 'VERA-Stimmberechtigung (siehe admin/vera.php)']);
                md_log_change($aid, 'settings', "$k: $old", $v, $aid, 'self');
            }
            $ok = 'Einstellungen gespeichert.';
        }
    } elseif ($act === 'compute') {
        $n = vera_status_alle_berechnen($aid, 'admin');
        $ok = $n > 0 ? "$n Mitglied(er) aktualisiert." : 'Keine Änderungen - alle Werte waren schon aktuell.';
    } elseif ($act === 'override') {
        $mid = (int)($_POST['member_id'] ?? 0);
        $wert = trim((string)($_POST['wert'] ?? ''));
        try {
            vera_override_setzen($mid, $wert === '' ? null : $wert, $aid);
            $ok = $wert === '' ? 'Übersteuerung aufgehoben, automatisch neu berechnet.' : 'Übersteuerung gespeichert.';
        } catch (InvalidArgumentException $e) {
            $err = $e->getMessage();
        }
    } else {
        $err = 'Unbekannte Aktion.';
    }
    md_flash($err ?: $ok, $err ? 'err' : 'ok');
    header('Location: ' . md_url($back));
    exit;
}

$stichtag = vera_stichtag();
$mindestalter = vera_mindestalter();

md_header('Stimmberechtigung (VERA)', $me, 'vera');
md_show_flash();
echo '<h1>Stimmberechtigung für VERA</h1>';
echo '<p class="muted">VERA (Versammlungsorganisation) liest den Status aus <code>svmembers.status</code>, um am Einlass der Mitgliederversammlung zu entscheiden, wer eine Stimmkarte bekommt. Diese Seite berechnet ihn aus dem Mitgliedsstatus (links in der Mitgliederliste), dem Geburtsdatum und offenen Beitragsforderungen. Siehe <code>docs/VERA.md</code> für Details.</p>';

echo '<details open><summary><h2 style="display:inline;">Einstellungen</h2></summary>';
echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="settings">';
echo '<div class="field"><label>Stichtag (Alter und Beitragszahlung werden zu diesem Tag geprüft - leer = heute)</label><input type="date" name="stichtag" value="' . h($stichtag) . '"' . ($edit ? '' : ' disabled') . '></div>';
echo '<div class="field"><label>Mindestalter für Stimmrecht (leer = keine Altersgrenze, jedes aktive Mitglied ist stimmberechtigt)</label><input type="number" min="0" max="120" name="mindestalter" value="' . h((string)($mindestalter ?? '')) . '"' . ($edit ? '' : ' disabled') . '></div>';
if ($edit) echo '<button type="submit">Speichern</button>';
echo '</form></details>';

echo '<details open style="margin-top:16px;"><summary><h2 style="display:inline;">Jetzt berechnen</h2></summary>';
echo '<p class="muted">Berechnet den Status für alle Mitglieder OHNE manuelle Übersteuerung (letzte Spalte unten) neu und schreibt ihn nach <code>svmembers.status</code>. Läuft nicht automatisch im Hintergrund - bitte passend zum Stichtag oben anstoßen (z. B. den Tag vor der Versammlung).</p>';
if ($edit) {
    echo '<form method="post" onsubmit="return confirm(\'Stimmberechtigung jetzt für alle Mitglieder ohne manuelle Übersteuerung neu berechnen?\');">' . md_csrf_field() . '<input type="hidden" name="action" value="compute"><button type="submit">Jetzt berechnen</button></form>';
}
echo '</details>';

$rows = md_q("SELECT s.member_id, s.membership_number, s.first_name, s.last_name, s.status AS vera_status, p.status AS status, p.birthdate, p.vera_override
              FROM svmembers s LEFT JOIN md_profiles p ON p.member_id = s.member_id ORDER BY s.last_name, s.first_name");
echo '<h2 style="margin-top:24px;">Mitglieder (' . count($rows) . ')</h2>';
echo '<table><thead><tr><th>Nr.</th><th>Name</th><th>MISS-Status</th><th>Alter</th><th>Beitrag</th><th>Würde berechnet</th><th>Gespeichert (svmembers.status)</th><th>Manuelle Übersteuerung</th></tr></thead><tbody>';
foreach ($rows as $r) {
    $mid = (int)$r['member_id'];
    $alter = vera_alter_am_stichtag($r['birthdate'], $stichtag);
    $ueberfaellig = vera_beitrag_ueberfaellig($mid, $stichtag);
    $berechnet = vera_status_berechnen($mid, $r);
    $abweichend = $r['vera_status'] !== $berechnet && $r['vera_override'] === null;
    echo '<tr><td>' . h($r['membership_number']) . '</td><td>' . h($r['first_name'] . ' ' . $r['last_name']) . '</td>';
    echo '<td>' . h(MD_STATUS[$r['status'] ?? 'aktiv'] ?? ($r['status'] ?? '–')) . '</td>';
    echo '<td>' . ($alter === null ? '–' : $alter) . '</td>';
    echo '<td>' . ($ueberfaellig ? '<span class="chip bad">überfällig</span>' : '<span class="chip ok">ok</span>') . '</td>';
    echo '<td>' . h($berechnet) . '</td>';
    echo '<td>' . h($r['vera_status'] ?? '–') . ($abweichend ? ' <span class="chip warn">weicht ab - „Jetzt berechnen“ aktualisiert</span>' : '') . '</td>';
    echo '<td>';
    if ($edit) {
        echo '<form method="post" style="display:flex; gap:4px;">' . md_csrf_field() . '<input type="hidden" name="action" value="override"><input type="hidden" name="member_id" value="' . $mid . '">';
        echo '<select name="wert" onchange="this.form.submit()"><option value="">(automatisch)</option>';
        foreach (VERA_STATUS_WERTE as $w) echo '<option value="' . h($w) . '"' . ($r['vera_override'] === $w ? ' selected' : '') . '>' . h($w) . '</option>';
        echo '</select></form>';
    } else {
        echo h($r['vera_override'] ?? '–');
    }
    echo '</td></tr>';
}
echo '</tbody></table>';
md_footer();
