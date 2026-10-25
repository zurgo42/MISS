<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('mail', 'view');
$aid = (int)$me['member_id'];
$edit = md_can($me, 'mail', 'edit');
$conf = md_mail_conf();
$problems = md_mail_conf_problems($conf);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    if (!$edit) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
    $act = $_POST['action'] ?? '';
    $err = '';
    $ok = 'Erledigt.';
    if ($act === 'conf') {
        foreach (['from_email', 'from_name', 'reply_to'] as $k) {
            $v = trim((string)($_POST['mail'][$k] ?? ''));
            if (in_array($k, ['from_email', 'reply_to'], true) && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { $err = 'Adresse ungültig: ' . $v; break; }
            $old = md_setting('mail.' . $k, '');
            if ($old === $v) continue;
            if (md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', ['mail.' . $k])) md_exec('UPDATE md_settings SET setting_value = ? WHERE setting_key = ?', [$v, 'mail.' . $k]);
            else md_exec('INSERT INTO md_settings (setting_key, setting_value, description) VALUES (?,?,?)', ['mail.' . $k, $v, 'E-Mail: ' . $k]);
            md_log_change($aid, 'settings', "mail.$k: $old", $v, $aid, 'self');
        }
        $ok = 'E-Mail-Einstellungen gespeichert.';
    } elseif ($act === 'send_pending') {
        [$n, $bad, $first] = md_mail_send_pending(100);
        $ok = "$n Nachricht(en) versendet" . ($bad ? ", $bad fehlgeschlagen" : '') . '.';
        if ($bad) $err = $ok . ' ' . $first;
    } elseif ($act === 'send_one') {
        $err = md_mail_send((int)($_POST['mail_id'] ?? 0));
        $ok = 'Versendet.';
    } elseif ($act === 'cancel') {
        $err = md_mail_cancel((int)($_POST['mail_id'] ?? 0));
        $ok = 'Abgebrochen.';
    } elseif ($act === 'test') {
        $to = trim((string)$me['email']);
        $err = md_mail_now($aid, $to, 'Testnachricht – ' . MD_CLUB_NAME, "Das ist eine Testnachricht von elMISS.\nWenn du sie liest, funktioniert der Versand." . md_mail_footer(), 'other', null, $aid);
        $ok = "Testnachricht an $to versendet.";
    } elseif ($act === 'invite_all') {
        $n = $bad = 0;
        foreach (md_login_uninvited() as $m) { if (md_login_send_link((int)$m['member_id'], 'invite', $aid) === '') $n++; else $bad++; }
        $ok = "$n Einladung(en) versendet" . ($bad ? ", $bad fehlgeschlagen" : '') . '.';
        if ($bad) $err = $ok;
    }
    md_flash($err ?: $ok, $err ? 'err' : 'ok');
    header('Location: ' . md_url('admin/mail.php'));
    exit;
}

$filter = in_array($_GET['status'] ?? '', array_keys(MD_MAIL_STATUS), true) ? $_GET['status'] : '';
$rows = md_q('SELECT o.*, s.first_name, s.last_name FROM md_mail_outbox o LEFT JOIN svmembers s ON s.member_id = o.member_id' . ($filter ? ' WHERE o.status = ?' : '') . ' ORDER BY o.mail_id DESC LIMIT 200', $filter ? [$filter] : []);
$counts = [];
foreach (md_q('SELECT status, COUNT(*) c FROM md_mail_outbox GROUP BY status') as $r) $counts[$r['status']] = (int)$r['c'];
$uninvited = md_login_uninvited();

md_header('E-Mail', $me, 'mail');
md_show_flash();
echo '<h1>E-Mail</h1><p class="muted">Ausgang für Rechnungen, Mahnungen und Zugangs-Links. Zustellweg: <strong>' . h(['log' => 'Protokolldatei (Demo, es wird nichts verschickt)', 'mail' => 'PHP mail()', 'smtp' => 'SMTP'][$conf['transport']]) . '</strong> (in config.php).</p>';
if ($problems) {
    echo '<div class="flash err"><strong>Einrichtung unvollständig – es kann nichts versendet werden:</strong><ul>';
    foreach ($problems as $p) echo '<li>' . h($p) . '</li>';
    echo '</ul></div>';
}
if ($edit) {
    echo '<section class="card"><h2>Absender</h2><form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="conf">'
        . '<label class="block">Absenderadresse<input type="email" name="mail[from_email]" value="' . h($conf['from_email']) . '" placeholder="mitglieder@verein.de" size="34"></label>'
        . '<label class="block">Absendername<input name="mail[from_name]" value="' . h(md_setting('mail.from_name', '')) . '" placeholder="' . h($conf['from_name']) . '" size="34"></label>'
        . '<label class="block">Antwortadresse (optional)<input type="email" name="mail[reply_to]" value="' . h($conf['reply_to']) . '" size="34"></label>'
        . '<button>Speichern</button></form>'
        . '<form method="post" style="margin-top:10px">' . md_csrf_field() . '<input type="hidden" name="action" value="test"><button class="link">Testnachricht an mich senden (' . h($me['email']) . ')</button></form></section>';
}
echo '<section class="card"><h2>Zugänge</h2><p>' . count($uninvited) . ' Mitglied(er) mit gültiger Adresse haben noch kein Passwort.</p>';
if ($edit && $uninvited) echo '<form method="post" onsubmit="return confirm(\'' . count($uninvited) . ' Einladungen jetzt senden?\')">' . md_csrf_field() . '<input type="hidden" name="action" value="invite_all"><button>Alle einladen</button></form>';
echo '</section>';

echo '<section class="card"><h2>Ausgang</h2><p>';
foreach (['' => 'alle'] + MD_MAIL_STATUS as $k => $label) echo '<a href="admin/mail.php' . ($k ? '?status=' . $k : '') . '"' . ($filter === $k ? ' style="font-weight:bold"' : '') . '>' . h($label) . ($k ? ' (' . ($counts[$k] ?? 0) . ')' : '') . '</a> · ';
echo '</p>';
if ($edit && ($counts['queued'] ?? 0)) echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="send_pending"><button>' . (int)$counts['queued'] . ' wartende Nachricht(en) jetzt senden</button></form>';
echo '<table><thead><tr><th>Erstellt</th><th>An</th><th>Betreff</th><th>Art</th><th>Status</th><th></th></tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr' . ($r['status'] === 'cancelled' ? ' class="muted"' : '') . '><td>' . h(date('d.m.Y H:i', strtotime($r['created_at']))) . '</td><td>' . h(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) . '<br><span class="muted">' . h($r['to_email']) . '</span></td>'
        . '<td><details><summary>' . h($r['subject']) . '</summary><pre style="white-space:pre-wrap;max-width:60ch">' . h($r['body']) . '</pre></details></td><td>' . h(MD_MAIL_KINDS[$r['kind']] ?? $r['kind']) . ($r['ref'] ? '<br><span class="muted">' . h($r['ref']) . '</span>' : '') . '</td>'
        . '<td>' . h(MD_MAIL_STATUS[$r['status']]) . ($r['sent_at'] ? '<br><span class="muted">' . h(date('d.m.Y H:i', strtotime($r['sent_at']))) . '</span>' : '') . ($r['last_error'] && $r['status'] === 'failed' ? '<br><span class="bad">' . h($r['last_error']) . '</span>' : '') . '</td><td class="act">';
    if ($edit && in_array($r['status'], ['queued', 'failed'], true)) echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="mail_id" value="' . (int)$r['mail_id'] . '"><button class="link" name="action" value="send_one">senden</button> <button class="link" name="action" value="cancel">abbrechen</button></form>';
    echo '</td></tr>';
}
if (!$rows) echo '<tr><td colspan="6" class="muted">Keine Nachrichten.</td></tr>';
echo '</tbody></table></section>';
md_footer();
