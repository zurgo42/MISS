<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('members', 'view');
$aid = (int)$me['member_id'];
$edit = md_can($me, 'members', 'edit');
md_apply_purge();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    if (!$edit) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
    $act = $_POST['action'] ?? '';
    $err = '';
    $ok = 'Gespeichert.';
    if ($act === 'conf') {
        foreach (['enabled' => !empty($_POST['apply']['enabled']) ? '1' : '0', 'notify_email' => trim((string)($_POST['apply']['notify_email'] ?? '')), 'privacy_url' => trim((string)($_POST['apply']['privacy_url'] ?? '')),
                  'statute_url' => trim((string)($_POST['apply']['statute_url'] ?? '')), 'intro_text' => trim((string)($_POST['apply']['intro_text'] ?? '')), 'keep_days' => trim((string)($_POST['apply']['keep_days'] ?? '90'))] as $k => $v) {
            if ($k === 'notify_email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { $err = 'Benachrichtigungsadresse ungültig.'; break; }
            if (in_array($k, ['privacy_url', 'statute_url'], true) && $v !== '' && !preg_match('~^https?://~i', $v)) { $err = 'Adressen müssen mit http:// oder https:// beginnen.'; break; }
            if ($k === 'keep_days' && (!ctype_digit($v) || (int)$v < 7 || (int)$v > 730)) { $err = 'Aufbewahrung: 7 bis 730 Tage.'; break; }
            $old = md_setting("apply.$k", '');
            if ($old === $v) continue;
            if (md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', ["apply.$k"])) md_exec('UPDATE md_settings SET setting_value = ? WHERE setting_key = ?', [$v, "apply.$k"]);
            else md_exec('INSERT INTO md_settings (setting_key, setting_value, description) VALUES (?,?,?)', ["apply.$k", $v, 'Online-Anmeldung: ' . $k]);
            md_log_change($aid, 'settings', "apply.$k: " . mb_substr($old, 0, 40), mb_substr($v, 0, 40), $aid, 'self');
        }
        $ok = 'Einstellungen gespeichert.';
    } elseif ($act === 'approve') {
        [$mid, $err, $note] = md_apply_approve((int)($_POST['app_id'] ?? 0), ['join_date' => $_POST['join_date'] ?? '', 'fee_group_id' => $_POST['fee_group_id'] ?? 0, 'group_ids' => $_POST['group_ids'] ?? []], $aid);
        if ($mid) { md_flash('Aufgenommen. Das Mitglied hat eine Einladung zum Festlegen des Passworts erhalten.' . ($note ? ' ' . $note : ''), $note ? 'warn' : 'ok'); header('Location: ' . md_url('index.php?member=' . $mid)); exit; }
    } elseif ($act === 'reject') {
        $err = md_apply_reject((int)($_POST['app_id'] ?? 0), (string)($_POST['reason'] ?? ''), $aid);
        $ok = 'Antrag abgelehnt; der Antragsteller wurde per E-Mail informiert.';
    }
    md_flash($err ?: $ok, $err ? 'err' : 'ok');
    header('Location: ' . md_url('admin/applications.php'));
    exit;
}

$new = md_q("SELECT * FROM md_applications WHERE status = 'new' ORDER BY confirmed_at");
$other = md_q("SELECT a.*, s.first_name dfn, s.last_name dln FROM md_applications a LEFT JOIN svmembers s ON s.member_id = a.decided_by WHERE a.status <> 'new' ORDER BY a.app_id DESC LIMIT 50");
$fg = md_fee_groups(true);
$groups = md_q('SELECT * FROM md_groups WHERE active = 1 ORDER BY name');
md_header('Mitglied werden', $me, 'apply');
md_show_flash();
echo '<h1>Mitglied werden – Anträge</h1><p class="muted">Interessierte füllen <a href="apply.php" target="_blank">apply.php</a> aus und bestätigen ihre E-Mail-Adresse. Hier entscheidet der Vorstand. Bei Aufnahme entsteht das Mitglied samt Anschrift und Beitragsgruppe und bekommt eine Einladung zum Festlegen des Passworts.</p>';
if (!md_apply_enabled()) echo '<div class="flash warn">Die Anmeldeseite „Mitglied werden“ ist <strong>nicht freigeschaltet</strong> (unten bei den Einstellungen aktivieren).</div>';
echo '<section class="card"><h2>Neue Anträge (' . count($new) . ')</h2>';
foreach ($new as $a) {
    $pref = $a['fee_group_id'] ? md_one('SELECT name FROM md_fee_groups WHERE group_id = ?', [$a['fee_group_id']]) : null;
    echo '<fieldset><legend>' . h($a['first_name'] . ' ' . $a['last_name']) . ' · bestätigt ' . h(date('d.m.Y H:i', strtotime($a['confirmed_at']))) . '</legend><p>' . h(trim($a['salutation'] . ' ' . $a['first_name'] . ' ' . $a['last_name'])) . ' · ' . h($a['email']) . ($a['phone'] ? ' · ' . h($a['phone']) : '') . '<br>'
        . h($a['street'] . ', ' . $a['zip'] . ' ' . $a['city']) . ($a['birthdate'] ? ' · geboren ' . h(md_date($a['birthdate'])) : '') . '<br>Gewünschte Mitgliedsart: <strong>' . h($pref['name'] ?? 'keine Angabe') . '</strong>' . ($a['message'] ? '<br>Nachricht: „' . h($a['message']) . '“' : '') . '</p>';
    if ($edit) {
        echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="app_id" value="' . (int)$a['app_id'] . '"><input type="hidden" name="action" value="approve"><div class="addr"><label>Eintritt<input name="join_date" size="10" value="' . h(date('d.m.Y')) . '"></label>'
            . '<label>Beitragsgruppe<select name="fee_group_id"><option value="">keine (später zuordnen)</option>';
        foreach ($fg as $g) echo '<option value="' . (int)$g['group_id'] . '"' . ((int)$a['fee_group_id'] === (int)$g['group_id'] ? ' selected' : '') . '>' . h($g['name'] . ' (' . md_money((int)$g['amount_cents']) . ')') . '</option>';
        echo '</select></label></div>';
        if ($groups) { echo '<p>Teilgruppen: '; foreach ($groups as $g) echo '<label class="check"><input type="checkbox" name="group_ids[]" value="' . (int)$g['group_id'] . '"> ' . h($g['name']) . '</label> '; echo '</p>'; }
        echo '<button>Aufnehmen und einladen</button></form>'
            . '<form method="post" style="margin-top:6px">' . md_csrf_field() . '<input type="hidden" name="app_id" value="' . (int)$a['app_id'] . '"><input type="hidden" name="action" value="reject"><input name="reason" placeholder="Begründung (optional, geht an den Antragsteller)" size="50"> <button class="link">ablehnen</button></form>';
    }
    echo '</fieldset>';
}
if (!$new) echo '<p class="muted">Keine offenen Anträge.</p>';
echo '</section>';
echo '<section class="card"><h2>Bisherige Anträge</h2><table><thead><tr><th>Eingang</th><th>Name</th><th>Status</th><th>Entschieden</th></tr></thead><tbody>';
foreach ($other as $a) echo '<tr><td>' . h(date('d.m.Y', strtotime($a['created_at']))) . '</td><td>' . h($a['first_name'] . ' ' . $a['last_name']) . '</td><td>' . h(MD_APPLY_STATUS[$a['status']] ?? $a['status']) . ($a['decision_note'] ? '<br><span class="muted">' . h($a['decision_note']) . '</span>' : '') . '</td><td>'
    . ($a['decided_at'] ? h(date('d.m.Y', strtotime($a['decided_at']))) . ' <span class="muted">' . h(trim($a['dfn'] . ' ' . $a['dln'])) . '</span>' : '') . ($a['member_id'] ? ' · <a href="index.php?member=' . (int)$a['member_id'] . '">Mitglied</a>' : '') . '</td></tr>';
if (!$other) echo '<tr><td colspan="4" class="muted">Noch keine.</td></tr>';
echo '</tbody></table><p class="muted">Unbestätigte Anträge werden nach 6 Wochen, entschiedene nach ' . (int)max(7, (int)md_setting('apply.keep_days', '90')) . ' Tagen gelöscht.</p></section>';
if ($edit) {
    $g = fn($k, $d = '') => h(md_setting("apply.$k", $d));
    echo '<section class="card"><h2>Einstellungen</h2><form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="conf">'
        . '<label class="block"><input type="checkbox" name="apply[enabled]" value="1"' . (md_apply_enabled() ? ' checked' : '') . '> Seite „Mitglied werden“ freischalten (<code>apply.php</code>)</label>'
        . '<label class="block">Einleitungstext auf der Anmeldeseite<textarea name="apply[intro_text]" rows="3" style="width:100%">' . $g('intro_text') . '</textarea></label>'
        . '<div class="addr"><label>Adresse der Datenschutzhinweise<input name="apply[privacy_url]" value="' . $g('privacy_url') . '" size="36" placeholder="https://…"></label><label>Adresse der Satzung<input name="apply[statute_url]" value="' . $g('statute_url') . '" size="36" placeholder="https://…"></label></div>'
        . '<div class="addr"><label>Benachrichtigung bei neuem Antrag an<input type="email" name="apply[notify_email]" value="' . $g('notify_email') . '" size="30"></label><label>Entschiedene Anträge löschen nach (Tage)<input name="apply[keep_days]" size="4" value="' . $g('keep_days', '90') . '"></label></div>'
        . '<button>Speichern</button> <span class="muted">Die wählbaren Mitgliedsarten legst du unter Administration → Mitgliedsarten und Beiträge fest („online wählbar“).</span></form></section>';
}
md_footer();
