<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_admin();
$aid = (int)$me['member_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'new_version') {
        $label = trim($_POST['version_label'] ?? '');
        $valid = md_parse_date($_POST['valid_from'] ?? '');
        if ($label && $valid && trim($_POST['title'] ?? '') && trim($_POST['body'] ?? '')) {
            md_exec('INSERT INTO md_terms_versions (doc_type, version_label, title, body, valid_from, requires_consent) VALUES (?,?,?,?,?,?)',
                ['nutzungsbedingungen', $label, trim($_POST['title']), trim($_POST['body']), $valid, !empty($_POST['requires_consent']) ? 1 : 0]);
            md_flash('Version angelegt.');
        } else md_flash('Bitte alle Felder ausfüllen (Datum als TT.MM.JJJJ).', 'err');
    } elseif ($act === 'record') {
        $cur = md_current_terms();
        $when = md_parse_date($_POST['date'] ?? '');
        $ch = in_array($_POST['channel'] ?? '', ['paper', 'admin'], true) ? $_POST['channel'] : 'paper';
        $mem = (int)($_POST['member_id'] ?? 0);
        if ($cur && $when && md_one('SELECT 1 FROM svmembers WHERE member_id = ?', [$mem])) {
            md_record_consent($mem, (int)$cur['terms_id'], $ch, $aid, null, trim($_POST['note'] ?? '') ?: null, $when . ' 12:00:00');
            md_flash('Zustimmung erfasst.');
        } else md_flash('Mitglied und Datum prüfen.', 'err');
    }
    header('Location: ' . md_url('admin/terms.php'));
    exit;
}
md_header('Nutzungsbedingungen verwalten', $me, 'terms');
md_show_flash();
echo '<h1>Nutzungsbedingungen</h1><section class="card"><h2>Versionen</h2><table><thead><tr><th>Version</th><th>Titel</th><th>Gültig ab</th><th>Zustimmung nötig</th><th>Zugestimmt</th></tr></thead><tbody>';
$total = (int)md_one('SELECT COUNT(*) c FROM svmembers WHERE is_active = 1')['c'];
foreach (md_q('SELECT * FROM md_terms_versions ORDER BY valid_from DESC') as $v) {
    $n = (int)md_one('SELECT COUNT(DISTINCT member_id) c FROM md_terms_consents WHERE terms_id = ? AND revoked_at IS NULL', [$v['terms_id']])['c'];
    echo '<tr><td><a href="terms.php?id=' . (int)$v['terms_id'] . '">' . h($v['version_label']) . '</a></td><td>' . h($v['title']) . '</td><td>' . h(md_date($v['valid_from'])) . '</td><td>' . ($v['requires_consent'] ? 'ja' : 'nein') . '</td><td>' . $n . ' von ' . $total . '</td></tr>';
}
echo '</tbody></table></section>';
echo '<section class="card"><h2>Neue Version</h2><form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="new_version">
<div class="addr"><label>Version<input name="version_label" placeholder="z. B. 3"></label><label>Gültig ab<input name="valid_from" placeholder="TT.MM.JJJJ"></label><label>Titel<input name="title"></label></div>
<label class="block">Text<textarea name="body" rows="8"></textarea></label><label class="check"><input type="checkbox" name="requires_consent" value="1" checked> Mitglieder müssen neu zustimmen</label> <button>Anlegen</button></form></section>';
$cur = md_current_terms();
echo '<section class="card"><h2>Zustimmung erfassen (schriftlich / im Auftrag)</h2>';
if ($cur) {
    echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="record"><div class="addr"><label>Mitglied<select name="member_id">';
    foreach (md_q('SELECT member_id, first_name, last_name FROM svmembers ORDER BY last_name') as $m) echo '<option value="' . (int)$m['member_id'] . '"' . ((int)($_GET['member'] ?? 0) === (int)$m['member_id'] ? ' selected' : '') . '>' . h($m['last_name'] . ', ' . $m['first_name']) . '</option>';
    echo '</select></label><label>Datum der Zustimmung<input name="date" value="' . date('d.m.Y') . '"></label><label>Weg<select name="channel"><option value="paper">schriftlich</option><option value="admin">mündlich/Mail, durch Verwaltung</option></select></label></div>
<label class="block">Vermerk (z. B. Eingang per Brief, Mailverweis)<input name="note"></label><p class="muted">Gilt für Version ' . h($cur['version_label']) . '.</p><button>Erfassen</button></form>';
} else echo '<p class="muted">Erst eine Version anlegen.</p>';
echo '</section>';
md_footer();
