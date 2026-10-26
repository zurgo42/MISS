<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('privacy', 'view');
$aid = (int)$me['member_id'];
$edit = md_can($me, 'privacy', 'edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    if (!$edit) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
    $act = $_POST['action'] ?? '';
    $m = (int)($_POST['member_id'] ?? 0);
    $err = '';
    $ok = 'Gespeichert.';
    if ($act === 'request_add') {
        $when = md_parse_date($_POST['received'] ?? '');
        [$id, $err] = md_privacy_request_add($m, (string)($_POST['kind'] ?? ''), (string)($_POST['via'] ?? 'other'), $aid, trim((string)($_POST['note'] ?? '')) ?: null, $when ? $when . ' 12:00:00' : null);
        $ok = 'Anfrage erfasst. Die Frist von einem Monat läuft ab Eingang.';
    } elseif ($act === 'request_done' || $act === 'request_refuse') {
        $err = md_privacy_request_handle((int)($_POST['request_id'] ?? 0), $act === 'request_done' ? 'done' : 'refused', (string)($_POST['note'] ?? ''), $aid);
        $ok = 'Anfrage abgeschlossen.';
    } elseif ($act === 'purge') {
        $err = md_privacy_purge($m, $aid, !empty($_POST['early']), trim((string)($_POST['note'] ?? '')) ?: null);
        $ok = 'Profildaten gelöscht (Stufe 1). Der Zugang ist gesperrt.';
    } elseif ($act === 'anonymize') {
        $err = md_privacy_anonymize($m, $aid, trim((string)($_POST['note'] ?? '')) ?: null);
        $ok = 'Mitglied vollständig anonymisiert (Stufe 2).';
    } elseif ($act === 'hold_on' || $act === 'hold_off') {
        $err = md_privacy_set_hold($m, $act === 'hold_on', (string)($_POST['reason'] ?? ''), $aid);
        $ok = $act === 'hold_on' ? 'Löschsperre gesetzt.' : 'Löschsperre aufgehoben.';
    } elseif ($act === 'conf') {
        $pm = (string)($_POST['profile_months'] ?? '');
        $ly = (string)($_POST['ledger_years'] ?? '');
        if (ctype_digit($pm) && ctype_digit($ly) && (int)$pm <= 120 && (int)$ly <= 30) {
            foreach ([['privacy.profile_months', $pm, 'Monate nach dem Austritt bis zur Löschung der Profildaten (Stufe 1)'], ['privacy.ledger_years', $ly, 'Jahre nach Ende des Kalenderjahres der letzten Buchung bis zur Anonymisierung (Stufe 2)']] as [$k, $v, $d]) {
                $old = md_setting($k, '');
                if ($old === $v) continue;
                if (md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', [$k])) md_exec('UPDATE md_settings SET setting_value = ? WHERE setting_key = ?', [$v, $k]);
                else md_exec('INSERT INTO md_settings (setting_key, setting_value, description) VALUES (?,?,?)', [$k, $v, $d]);
                md_log_change($aid, 'settings', "$k: $old", $v, $aid, 'self');
            }
            $ok = 'Fristen gespeichert.';
        } else $err = 'Bitte ganze Zahlen angeben (Monate 0–120, Jahre 0–30).';
    }
    md_flash($err ?: $ok, $err ? 'err' : 'ok');
    header('Location: ' . md_url('admin/privacy.php'));
    exit;
}

$conf = md_privacy_conf();
$today = date('Y-m-d');
md_header('Datenschutz', $me, 'privacy');
md_show_flash();
echo '<h1>Datenschutz</h1><p class="muted">Auskunft, Anfragen und Löschfristen. Die Fristen sind Voreinstellungen und vom Verein (Steuerberater, Datenschutzbeauftragte) zu bestätigen; Näheres in <code>docs/DATENSCHUTZ.md</code>.</p>';

// ---------------------------------------------------------------- Anfragen
$reqs = md_q("SELECT r.*, s.first_name, s.last_name, s.membership_number FROM md_privacy_requests r JOIN svmembers s ON s.member_id = r.member_id
              ORDER BY CASE r.status WHEN 'open' THEN 0 ELSE 1 END, r.received_at DESC LIMIT 200");
echo '<section class="card" id="requests"><h2>Anfragen</h2>';
echo '<table><thead><tr><th>Mitglied</th><th>Art</th><th>Eingang</th><th>Antwort bis</th><th>Status</th><th></th></tr></thead><tbody>';
foreach ($reqs as $r) {
    $due = md_privacy_request_deadline($r);
    $overdue = $r['status'] === 'open' && $due < $today;
    $kind = $r['kind'] === 'access' ? 'Auskunft' : 'Löschung';
    echo '<tr><td><a href="index.php?member=' . (int)$r['member_id'] . '">' . h($r['first_name'] . ' ' . $r['last_name']) . '</a></td><td>' . $kind . '</td><td>' . h(md_date(substr($r['received_at'], 0, 10))) . '<br><span class="muted">' . h(['self' => 'im Dashboard', 'mail' => 'E-Mail', 'letter' => 'Brief', 'phone' => 'Telefon', 'other' => 'sonstig'][$r['received_via']] ?? '') . '</span></td>';
    echo '<td class="' . ($overdue ? 'bad' : '') . '">' . h(md_date($due)) . ($overdue ? ' (überfällig)' : '') . '</td><td>' . ['open' => 'offen', 'done' => 'erledigt', 'refused' => 'abgelehnt'][$r['status']] . ($r['response_note'] ? '<br><span class="muted">' . h($r['response_note']) . '</span>' : '') . '</td><td class="act">';
    if ($r['note']) echo '<span class="muted" title="' . h($r['note']) . '">Hinweis: ' . h(mb_strimwidth($r['note'], 0, 40, '…')) . '</span><br>';
    if ($r['status'] === 'open') {
        echo '<a href="privacy_export.php?member=' . (int)$r['member_id'] . '&amp;format=html" target="_blank">Auskunft ansehen</a> · <a href="privacy_export.php?member=' . (int)$r['member_id'] . '&amp;format=zip">ZIP</a>';
        if ($edit) echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="request_id" value="' . (int)$r['request_id'] . '"><input name="note" placeholder="Antwort / Begründung (geht per Mail ans Mitglied)"><button name="action" value="request_done">erledigt</button> <button class="link" name="action" value="request_refuse">ablehnen</button></form>';
    }
    echo '</td></tr>';
}
if (!$reqs) echo '<tr><td colspan="6" class="muted">Keine Anfragen.</td></tr>';
echo '</tbody></table>';
if ($edit) {
    echo '<details><summary>Anfrage erfassen (z. B. per E-Mail oder Brief eingegangen)</summary><form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="request_add"><div class="addr"><label>Mitglied<select name="member_id">';
    foreach (md_q("SELECT member_id, first_name, last_name FROM svmembers WHERE membership_number NOT LIKE 'DEL-%' OR membership_number IS NULL ORDER BY last_name, first_name") as $m) echo '<option value="' . (int)$m['member_id'] . '">' . h($m['last_name'] . ', ' . $m['first_name']) . '</option>';
    echo '</select></label><label>Art<select name="kind"><option value="access">Auskunft</option><option value="erasure">Löschung</option></select></label><label>Eingegangen über<select name="via"><option value="mail">E-Mail</option><option value="letter">Brief</option><option value="phone">Telefon</option><option value="other">sonstig</option></select></label><label>Eingangsdatum<input name="received" value="' . date('d.m.Y') . '"></label></div><label class="block">Inhalt<input name="note"></label><button>Erfassen</button></form></details>';
}
echo '</section>';

// ---------------------------------------------------------------- Löschfristen
echo '<section class="card" id="retention"><h2>Löschfristen nach Austritt</h2><p class="muted">Stufe 1 löscht nach <strong>' . $conf['profile_months'] . ' Monaten</strong> die Profildaten (Adressen, Kontakt, Fotos, Texte, Gruppen, Profil-Historie) und sperrt den Zugang. '
    . 'Stufe 2 anonymisiert nach <strong>' . $conf['ledger_years'] . ' Jahren</strong> (ab Ende des Kalenderjahres der letzten Buchung, Bestätigung, Funktion oder Zustimmung) den Rest. Gesperrt bei Löschsperre oder offenen Buchungen. Gelöscht wird nur nach dem Austritt.</p>';
$rows = md_q("SELECT s.member_id, s.first_name, s.last_name, s.membership_number FROM svmembers s JOIN md_profiles p ON p.member_id = s.member_id
              WHERE p.status = 'ausgetreten' OR p.purged_at IS NOT NULL OR p.anonymized_at IS NOT NULL ORDER BY p.leave_date, s.last_name");
echo '<table><thead><tr><th>Mitglied</th><th>Austritt</th><th>Stand</th><th>Stufe 1 ab</th><th>Stufe 2 ab</th><th>Hinweise</th><th></th></tr></thead><tbody>';
foreach ($rows as $r) {
    $id = (int)$r['member_id'];
    $st = md_privacy_state($id);
    $anon = $st['stage'] === 2;
    $name = $anon ? 'anonymisiert (#' . $id . ')' : $r['first_name'] . ' ' . $r['last_name'] . ' (' . $r['membership_number'] . ')';
    $d1 = $st['purge_due'];
    $d2 = $st['anon_due'];
    $due1 = $st['stage'] < 1 && $d1 && $d1 <= $today;
    $due2 = $st['stage'] === 1 && $d2 && $d2 <= $today;
    echo '<tr><td>' . ($anon ? h($name) : '<a href="index.php?member=' . $id . '">' . h($name) . '</a>') . '</td><td>' . h(md_date($st['leave_date'])) . '</td><td>' . ['Profil vorhanden', 'Profil gelöscht ' . md_date(substr((string)$st['purged_at'], 0, 10)), 'anonymisiert ' . md_date(substr((string)$st['anonymized_at'], 0, 10))][$st['stage']] . '</td>';
    echo '<td class="' . ($due1 ? 'bad' : '') . '">' . ($st['stage'] >= 1 ? '✓' : h(md_date($d1) ?: '–')) . ($due1 ? ' (erreicht)' : '') . '</td><td class="' . ($due2 ? 'bad' : '') . '">' . ($st['stage'] >= 2 ? '✓' : h(md_date($d2) ?: '–')) . ($due2 ? ' (erreicht)' : '') . '</td>';
    echo '<td>' . ($anon ? '' : h(implode('; ', $st['blockers']))) . '</td><td class="act">';
    if ($edit && !$anon) {
        if ($st['stage'] < 1) {
            $why = md_privacy_can_purge($st, true);
            if ($why === '') echo '<form method="post" onsubmit="return confirm(\'Profildaten dieses Mitglieds jetzt unwiderruflich löschen?\')">' . md_csrf_field() . '<input type="hidden" name="action" value="purge"><input type="hidden" name="member_id" value="' . $id . '">'
                . ($due1 ? '' : '<label class="check"><input type="checkbox" name="early" value="1" required> vor Fristablauf (z. B. auf Antrag)</label> ') . '<input name="note" placeholder="Vermerk"><button>Profildaten löschen</button></form>';
        } else {
            $why = md_privacy_can_anonymize($st);
            if ($why === '') echo '<form method="post" onsubmit="return confirm(\'Dieses Mitglied jetzt unwiderruflich anonymisieren (inkl. Buchungen und Bestätigungen)?\')">' . md_csrf_field() . '<input type="hidden" name="action" value="anonymize"><input type="hidden" name="member_id" value="' . $id . '"><input name="note" placeholder="Vermerk"><button>Anonymisieren</button></form>';
            else echo '<span class="muted">' . h($why) . '</span><br>';
        }
        echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="member_id" value="' . $id . '">'
            . ($st['hold'] ? '<button class="link" name="action" value="hold_off">Löschsperre aufheben</button>' : '<input name="reason" placeholder="Grund der Sperre"><button class="link" name="action" value="hold_on">Löschsperre setzen</button>') . '</form>';
    }
    echo '</td></tr>';
}
if (!$rows) echo '<tr><td colspan="7" class="muted">Keine ausgetretenen Mitglieder.</td></tr>';
echo '</tbody></table></section>';

// ---------------------------------------------------------------- Fristen und Protokoll
if ($edit) {
    echo '<section class="card" id="conf"><h2>Fristen</h2><form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="conf"><div class="addr">'
        . '<label>Stufe 1: Monate nach dem Austritt bis zur Löschung der Profildaten<input name="profile_months" size="4" value="' . $conf['profile_months'] . '"></label>'
        . '<label>Stufe 2: Jahre nach Ende des Kalenderjahres der letzten Buchung bis zur Anonymisierung<input name="ledger_years" size="4" value="' . $conf['ledger_years'] . '"></label></div><button>Speichern</button></form></section>';
}
$log = md_q('SELECT l.*, s.first_name, s.last_name, u.first_name ufn, u.last_name uln, s.membership_number FROM md_privacy_log l LEFT JOIN svmembers s ON s.member_id = l.member_id LEFT JOIN svmembers u ON u.member_id = l.event_by ORDER BY l.event_id DESC LIMIT 100');
echo '<section class="card" id="log"><h2>Protokoll</h2><table><thead><tr><th>Wann</th><th>Mitglied</th><th>Vorgang</th><th>Durch</th><th>Vermerk</th></tr></thead><tbody>';
$ev = ['export' => 'Auskunft/Export', 'request' => 'Anfrage eingegangen', 'handled' => 'Anfrage bearbeitet', 'hold_on' => 'Löschsperre gesetzt', 'hold_off' => 'Löschsperre aufgehoben', 'purged' => 'Profildaten gelöscht (Stufe 1)', 'anonymized' => 'Anonymisiert (Stufe 2)', 'iban_view' => 'IBAN angezeigt'];
foreach ($log as $l) {
    $anon = ($l['membership_number'] ?? '') !== '' && str_starts_with((string)$l['membership_number'], 'DEL-');
    echo '<tr><td>' . h(date('d.m.Y H:i', strtotime($l['event_at']))) . '</td><td>' . h($anon || !$l['first_name'] ? '#' . (int)$l['member_id'] . ' (anonymisiert)' : $l['first_name'] . ' ' . $l['last_name']) . '</td><td>' . h($ev[$l['event']] ?? $l['event']) . '</td><td>' . h(trim($l['ufn'] . ' ' . $l['uln'])) . '</td><td>' . h($l['note']) . '</td></tr>';
}
if (!$log) echo '<tr><td colspan="5" class="muted">Noch keine Vorgänge.</td></tr>';
echo '</tbody></table></section>';
md_footer();
