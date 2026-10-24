<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('mail', 'view');
$aid = (int)$me['member_id'];
$edit = md_can($me, 'mail', 'edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    if (!$edit) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
    $act = $_POST['action'] ?? '';
    $err = '';
    $ok = 'Gespeichert.';
    if ($act === 'save') {
        $err = md_list_save((int)($_POST['list_id'] ?? 0) ?: null, $_POST);
        $ok = 'Verteiler gespeichert.';
    } elseif ($act === 'send') {
        $lid = (int)($_POST['list_id'] ?? 0);
        [$cid, $n, $err] = md_list_send($lid, (string)($_POST['subject'] ?? ''), (string)($_POST['body'] ?? ''), $aid);
        if ($cid) {
            $sent = 0;
            if (!empty($_POST['now'])) { [$sent, $bad] = md_mail_send_pending(100); }
            $ok = "$n Nachricht(en) vorgemerkt" . (!empty($_POST['now']) ? ", davon $sent sofort gesendet" : '') . ($n > $sent ? '. Den Rest sendest du unter „E-Mail“ (jeweils 100 pro Klick).' : '.');
        } else $_SESSION['md_list_draft'] = ['list' => $lid, 'subject' => $_POST['subject'] ?? '', 'body' => $_POST['body'] ?? ''];
    }
    md_flash($err ?: $ok, $err ? 'err' : 'ok');
    header('Location: ' . md_url('admin/lists.php' . (!empty($_POST['list_id']) ? '?list=' . (int)$_POST['list_id'] : '')));
    exit;
}

$lists = md_lists();
$sel = md_list_get((int)($_GET['list'] ?? 0));
$draft = $_SESSION['md_list_draft'] ?? null;
unset($_SESSION['md_list_draft']);
$groups = md_q('SELECT * FROM md_groups WHERE active = 1 ORDER BY name');
$funcs = md_q('SELECT * FROM md_functions WHERE active = 1 ORDER BY code');
$form = function (?array $l) use ($groups, $funcs, $edit): string {
    $kind = $l['source_kind'] ?? 'group';
    $o = '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="save">' . ($l ? '<input type="hidden" name="list_id" value="' . (int)$l['list_id'] . '">' : '')
        . '<div class="addr"><label>Name<input name="name" value="' . h($l['name'] ?? '') . '" required></label><label>Beschreibung<input name="description" value="' . h($l['description'] ?? '') . '" size="36"></label></div>'
        . '<div class="addr"><label>Empfänger<select name="source_kind">';
    foreach (MD_LIST_SOURCES as $k => $label) $o .= '<option value="' . $k . '"' . ($kind === $k ? ' selected' : '') . '>' . h($label) . '</option>';
    $o .= '</select></label><label>Teilgruppe<select name="group_id"><option value="">–</option>';
    foreach ($groups as $g) $o .= '<option value="' . (int)$g['group_id'] . '"' . ($kind === 'group' && (int)($l['source_ref'] ?? 0) === (int)$g['group_id'] ? ' selected' : '') . '>' . h($g['name']) . '</option>';
    $o .= '</select></label><label>Funktion<select name="function_code"><option value="">–</option>';
    foreach ($funcs as $f) $o .= '<option value="' . h($f['code']) . '"' . ($kind === 'function' && ($l['source_ref'] ?? '') === $f['code'] ? ' selected' : '') . '>' . h($f['code'] . ' – ' . $f['name']) . '</option>';
    return $o . '</select></label></div><label class="check"><input type="checkbox" name="optional" value="1"' . (!$l || $l['optional'] ? ' checked' : '') . '> Mitglieder können den Verteiler abbestellen</label> '
        . '<label class="check"><input type="checkbox" name="active" value="1"' . (!$l || $l['active'] ? ' checked' : '') . '> aktiv</label> ' . ($edit ? '<button>' . ($l ? 'Speichern' : 'Anlegen') . '</button>' : '') . '</form>';
};

md_header('Verteiler', $me, 'lists');
md_show_flash();
echo '<h1>E-Mail-Verteiler</h1><p class="muted">Ein Verteiler ist eine Teilgruppe, eine Funktion oder die Gesamtheit der Mitglieder. Die Empfänger ergeben sich laufend aus den Mitgliedsdaten. Jede Nachricht geht einzeln an jeden Empfänger. Gesendet wird über den <a href="admin/mail.php">E-Mail-Ausgang</a>.</p>';
echo '<section class="card"><h2>Verteiler</h2><table><thead><tr><th>Name</th><th>Empfänger aus</th><th class="r">Empfänger</th><th>Abbestellbar</th><th></th></tr></thead><tbody>';
foreach ($lists as $l) {
    $r = md_list_recipients($l);
    echo '<tr' . ($l['active'] ? '' : ' class="muted"') . '><td><a href="admin/lists.php?list=' . (int)$l['list_id'] . '">' . h($l['name']) . '</a><br><span class="muted">' . h($l['description']) . '</span></td><td>' . h(md_list_source_label($l)) . '</td>'
        . '<td class="r">' . count($r['send']) . ($r['no_mail'] || $r['opted_out'] ? '<br><span class="muted">' . ($r['no_mail'] ? $r['no_mail'] . ' ohne Adresse' : '') . ($r['no_mail'] && $r['opted_out'] ? ', ' : '') . ($r['opted_out'] ? $r['opted_out'] . ' abbestellt' : '') . '</span>' : '') . '</td><td>' . ($l['optional'] ? 'ja' : 'nein (verbindlich)') . '</td><td><a href="admin/lists.php?list=' . (int)$l['list_id'] . '#send">schreiben</a></td></tr>';
}
if (!$lists) echo '<tr><td colspan="5" class="muted">Noch keine Verteiler.</td></tr>';
echo '</tbody></table></section>';
if ($sel) {
    $r = md_list_recipients($sel);
    echo '<section class="card" id="send"><h2>Nachricht an „' . h($sel['name']) . '“</h2>';
    if (!$sel['active']) echo '<p class="muted">Der Verteiler ist nicht aktiv.</p>';
    elseif ($edit) {
        echo '<p>' . count($r['send']) . ' Empfänger (' . h(implode(', ', array_map(fn($m) => $m['first_name'] . ' ' . $m['last_name'], array_slice($r['send'], 0, 8)))) . (count($r['send']) > 8 ? ' …' : '') . ').</p>'
            . '<form method="post" onsubmit="return confirm(\'Nachricht an ' . count($r['send']) . ' Empfänger vormerken?\')">' . md_csrf_field() . '<input type="hidden" name="action" value="send"><input type="hidden" name="list_id" value="' . (int)$sel['list_id'] . '">'
            . '<label class="block">Betreff<input name="subject" value="' . h($draft['subject'] ?? '') . '" maxlength="200" required style="width:100%"></label>'
            . '<label class="block">Text <span class="muted">(Platzhalter: {anrede} {vorname} {name}; Fußzeile mit Abmeldelink wird automatisch angefügt)</span><textarea name="body" rows="10" required style="width:100%">' . h($draft['body'] ?? '') . '</textarea></label>'
            . '<label class="check"><input type="checkbox" name="now" value="1" checked> gleich senden (sonst nur im Ausgang vormerken)</label> <button>Senden</button></form>';
    }
    echo '</section>';
}
if ($edit) echo '<section class="card"><h2>' . ($sel ? 'Verteiler bearbeiten' : 'Neuer Verteiler') . '</h2>' . $form($sel) . ($sel ? '<p><a href="admin/lists.php">Neuen Verteiler anlegen</a></p>' : '') . '</section>';
$hist = md_q("SELECT c.*, l.name lname, e.title etitle, s.first_name, s.last_name FROM md_mail_campaigns c LEFT JOIN md_mail_lists l ON l.list_id = c.list_id LEFT JOIN md_events e ON e.event_id = c.event_id LEFT JOIN svmembers s ON s.member_id = c.created_by ORDER BY c.campaign_id DESC LIMIT 30");
echo '<section class="card"><h2>Bisherige Nachrichten</h2><table><thead><tr><th>Wann</th><th>An</th><th>Betreff</th><th class="r">Empfänger</th><th>Von</th></tr></thead><tbody>';
foreach ($hist as $c) echo '<tr><td>' . h(date('d.m.Y H:i', strtotime($c['created_at']))) . '</td><td>' . h($c['lname'] ?: ('Veranstaltung: ' . $c['etitle'])) . '</td><td><details><summary>' . h($c['subject']) . '</summary><pre style="white-space:pre-wrap;max-width:60ch">' . h($c['body']) . '</pre></details></td><td class="r">' . (int)$c['recipients'] . '</td><td>' . h($c['first_name'] . ' ' . $c['last_name']) . '</td></tr>';
if (!$hist) echo '<tr><td colspan="5" class="muted">Noch keine.</td></tr>';
echo '</tbody></table></section>';
md_footer();
