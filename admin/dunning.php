<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('fees', 'view');
$aid = (int)$me['member_id'];
$edit = md_can($me, 'fees', 'edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    if (!$edit) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
    $act = $_POST['action'] ?? '';
    $err = '';
    $ok = 'Gespeichert.';
    if ($act === 'levels') {
        foreach (md_dunning_levels() as $lv => $r) {
            $name = trim((string)($_POST['name'][$lv] ?? ''));
            $days = $_POST['days'][$lv] ?? '';
            $dl = $_POST['deadline_days'][$lv] ?? '';
            $fee = trim((string)($_POST['fee'][$lv] ?? '')) === '' || trim((string)$_POST['fee'][$lv]) === '0' ? 0 : md_parse_amount((string)$_POST['fee'][$lv]);
            $body = trim((string)($_POST['body'][$lv] ?? ''));
            if ($name === '' || !ctype_digit((string)$days) || !ctype_digit((string)$dl) || $fee === null || $body === '') { $err = "Stufe $lv: Name, Tage, Frist, Gebühr und Text ausfüllen."; break; }
            md_exec('UPDATE md_dunning_levels SET name = ?, days = ?, deadline_days = ?, fee_cents = ?, body = ? WHERE level = ?', [$name, (int)$days, (int)$dl, $fee, $body, $lv]);
        }
        if (!$err) md_log_change($aid, 'settings', null, 'Mahnstufen geändert', $aid, 'self');
        $ok = 'Mahnstufen gespeichert. Sie gelten für künftige Mahnschreiben.';
    } elseif ($act === 'issue') {
        $cands = md_dunning_candidates();
        $ids = [];
        $errs = [];
        foreach (array_filter(array_map('intval', (array)($_POST['members'] ?? []))) as $m) {
            if (!isset($cands[$m])) continue;
            [$lid, $e] = md_dunning_issue($m, array_map(fn($i) => (int)$i['entry_id'], $cands[$m]['items']), $aid);
            if ($lid) $ids[] = $lid; else $errs[] = $cands[$m]['member']['name'] . ': ' . $e;
        }
        if ($errs) md_flash(implode(' | ', $errs), 'err');
        if ($ids) { md_flash(count($ids) . ' Mahnschreiben ausgestellt; dies ist die Druckansicht.'); header('Location: ' . md_url('admin/dunning_print.php?ids=' . implode(',', $ids))); exit; }
        if (!$errs) $err = 'Kein Mitglied ausgewählt.';
    } elseif ($act === 'void') {
        $err = md_dunning_void((int)($_POST['letter_id'] ?? 0), (string)($_POST['reason'] ?? ''), $aid);
        $ok = 'Mahnschreiben storniert; Mahnstufen und Gebühr sind zurückgesetzt.';
    } elseif ($act === 'defer') {
        $err = md_dunning_defer((int)($_POST['entry_id'] ?? 0), (string)($_POST['until'] ?? ''), (string)($_POST['reason'] ?? ''), $aid);
        $ok = 'Forderung gestundet.';
    } elseif ($act === 'undefer') {
        md_exec('UPDATE md_ledger SET deferred_until = NULL, deferred_reason = NULL WHERE entry_id = ?', [(int)($_POST['entry_id'] ?? 0)]);
        $ok = 'Stundung aufgehoben.';
    }
    if ($err || $act !== 'issue') { md_flash($err ?: $ok, $err ? 'err' : 'ok'); header('Location: ' . md_url('admin/dunning.php')); exit; }
}

$levels = md_dunning_levels();
$today = date('Y-m-d');
md_header('Mahnwesen', $me, 'dunning');
md_show_flash();
echo '<h1>Mahnwesen</h1><p class="muted">Mahnfähig sind offene Forderungen (Beitrag, Eintritt) mit Fälligkeit. Stufe 1 ab den eingestellten Tagen nach Fälligkeit, Stufe 2 und 3 nach der vorherigen Mahnung. '
    . 'Pro Mitglied entsteht ein Schreiben mit allen fälligen Forderungen; Gebühr und Text richten sich nach der höchsten Stufe darin. Gestundete Forderungen werden übersprungen. <a href="admin/fees.php">Beiträge</a> · <a href="admin/payments.php">Zahlungen zuordnen</a></p>';
if (md_fee_conf()['iban'] === '') echo '<div class="flash warn">Es ist keine IBAN hinterlegt: Mahnschreiben enthalten keine Zahlungsangaben (Beiträge → Angaben für Rechnungen).</div>';
if (!$levels) echo '<div class="flash warn">Es sind keine Mahnstufen eingerichtet. Bitte <code>php tools/install.php</code> ausführen.</div>';

// ---- Mahnlauf
$cands = md_dunning_candidates();
echo '<section class="card" id="run"><h2>Mahnlauf</h2>';
if (!$cands) echo '<p class="muted">Aktuell ist keine Forderung für eine Mahnung fällig.</p>';
else {
    echo '<p><span class="chip ok">' . count($cands) . ' Mitglied(er) mit fälligen Mahnungen</span></p>';
    echo '<form method="post" id="dform" onsubmit="return confirm(\'Mahnschreiben jetzt ausstellen? Mahnstufen und Gebühren werden gebucht.\')">' . md_csrf_field() . '<input type="hidden" name="action" value="issue"><table><thead><tr><th></th><th>Mitglied</th><th>Forderungen</th><th>Schreiben</th><th class="r">Gebühr</th></tr></thead><tbody>';
    foreach ($cands as $m => $g) {
        $okAddr = $g['recipient'] !== null;
        echo '<tr><td><input type="checkbox" name="members[]" value="' . $m . '"' . ($okAddr ? ' checked' : ' disabled') . '></td><td><a href="index.php?member=' . $m . '#finance">' . h($g['member']['name']) . '</a><br><span class="muted">' . h($g['member']['number']) . '</span>' . ($okAddr ? '' : '<br><span class="bad">Anschrift fehlt</span>') . '</td><td>';
        foreach ($g['items'] as $it) {
            $over = (int)floor((strtotime($today) - strtotime($it['due_date'])) / 86400);
            echo h($it['reference'] . ' ' . $it['description']) . ' – ' . md_money((int)$it['amount_cents']) . ', fällig ' . h(md_date($it['due_date'])) . ' (' . $over . ' Tage überfällig) → ' . h($levels[$it['next_level']]['name']);
            if ($edit) echo ' <form class="inline" method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="defer"><input type="hidden" name="entry_id" value="' . (int)$it['entry_id'] . '"><input name="until" size="9" placeholder="Stunden bis"><input name="reason" size="12" placeholder="Grund"><button class="link">stunden</button></form>';
            echo '<br>';
        }
        echo '</td><td>' . h($levels[$g['level']]['name']) . '</td><td class="r">' . ($g['fee_cents'] ? md_money($g['fee_cents']) : '–') . '</td></tr>';
    }
    echo '</tbody></table>';
    if ($edit) echo '<p><button>Ausgewählte Mahnschreiben ausstellen und drucken</button></p>';
    echo '</form>';
}
echo '</section>';

// ---- Stufe erreicht / gestundet
$maxLv = $levels ? max(array_keys($levels)) : 0;
$last = $maxLv ? md_q("SELECT l.*, s.first_name, s.last_name FROM md_ledger l JOIN svmembers s ON s.member_id = l.member_id WHERE l.status = 'open' AND l.direction = 'to_club' AND l.dunning_level >= ? ORDER BY l.last_dunned_at", [$maxLv]) : [];
if ($last) {
    echo '<section class="card" id="last"><h2>Letzte Mahnstufe erreicht (' . count($last) . ')</h2><p class="muted">Hier endet das automatische Mahnwesen. Weitere Schritte (z. B. Verfahren nach der Satzung, Beitragsrückstand, Ausschluss) entscheidet der Vorstand.</p><table><tbody>';
    foreach ($last as $r) echo '<tr><td><a href="index.php?member=' . (int)$r['member_id'] . '#finance">' . h($r['first_name'] . ' ' . $r['last_name']) . '</a></td><td>' . h($r['reference'] . ' ' . $r['description']) . '</td><td class="r">' . md_money((int)$r['amount_cents']) . '</td><td>letzte Mahnung ' . h(md_date($r['last_dunned_at'])) . '</td></tr>';
    echo '</tbody></table></section>';
}
$def = md_q("SELECT l.*, s.first_name, s.last_name FROM md_ledger l JOIN svmembers s ON s.member_id = l.member_id WHERE l.status = 'open' AND l.deferred_until IS NOT NULL AND l.deferred_until >= ? ORDER BY l.deferred_until", [$today]);
if ($def) {
    echo '<section class="card" id="deferred"><h2>Gestundete Forderungen (' . count($def) . ')</h2><table><tbody>';
    foreach ($def as $r) {
        echo '<tr><td>' . h($r['first_name'] . ' ' . $r['last_name']) . '</td><td>' . h($r['reference'] . ' ' . $r['description']) . '</td><td class="r">' . md_money((int)$r['amount_cents']) . '</td><td>bis ' . h(md_date($r['deferred_until'])) . '<br><span class="muted">' . h($r['deferred_reason']) . '</span></td><td>';
        if ($edit) echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="undefer"><input type="hidden" name="entry_id" value="' . (int)$r['entry_id'] . '"><button class="link">aufheben</button></form>';
        echo '</td></tr>';
    }
    echo '</tbody></table></section>';
}

// ---- ausgestellte Schreiben
$letters = md_q('SELECT d.*, s.first_name, s.last_name, u.first_name ufn, u.last_name uln FROM md_dunning_letters d JOIN svmembers s ON s.member_id = d.member_id LEFT JOIN svmembers u ON u.member_id = d.issued_by ORDER BY d.letter_id DESC LIMIT 100');
echo '<section class="card" id="letters"><h2>Ausgestellte Mahnschreiben</h2><table><thead><tr><th>Nr.</th><th>Mitglied</th><th>Stufe</th><th class="r">Betrag</th><th>Frist</th><th>Ausgestellt</th><th>Status</th><th></th></tr></thead><tbody>';
foreach ($letters as $l) {
    $void = $l['status'] === 'void';
    $s = json_decode($l['snapshot'], true);
    echo '<tr' . ($void ? ' class="muted"' : '') . '><td><a href="admin/dunning_print.php?id=' . (int)$l['letter_id'] . '" target="_blank">' . h($l['letter_no']) . '</a></td><td>' . h($l['first_name'] . ' ' . $l['last_name']) . '</td><td>' . h($s['level_name'] ?? $l['level']) . '</td><td class="r">' . md_money((int)$l['total_cents']) . '</td><td>' . h(md_date($l['deadline'])) . '</td><td>' . h(date('d.m.Y', strtotime($l['issued_at']))) . '<br><span class="muted">' . h(trim($l['ufn'] . ' ' . $l['uln'])) . '</span></td><td>' . ($void ? 'storniert<br><span class="muted">' . h($l['void_reason']) . '</span>' : 'gültig') . '</td><td class="act">';
    if ($edit && !$void) echo '<form method="post" onsubmit="return this.reason.value.trim() !== \'\' || (alert(\'Bitte einen Grund angeben.\'), false)">' . md_csrf_field() . '<input type="hidden" name="action" value="void"><input type="hidden" name="letter_id" value="' . (int)$l['letter_id'] . '"><input name="reason" placeholder="Storno-Grund"><button class="link">stornieren</button></form>';
    echo '</td></tr>';
}
if (!$letters) echo '<tr><td colspan="8" class="muted">Noch keine Mahnschreiben.</td></tr>';
echo '</tbody></table></section>';

// ---- Stufen
if ($levels) {
    echo '<section class="card" id="levels"><h2>Mahnstufen</h2><p class="muted">Platzhalter im Text: <code>{anrede}</code> <code>{name}</code> <code>{total}</code> <code>{fee}</code> <code>{deadline}</code>. Mahngebühren sind nur zulässig, wenn die Satzung oder Beitragsordnung sie vorsieht (bitte prüfen) und müssen angemessen sein.</p>';
    if ($edit) echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="levels">';
    foreach ($levels as $lv => $r) {
        echo '<fieldset><legend>Stufe ' . $lv . '</legend><div class="addr"><label>Name<input name="name[' . $lv . ']" value="' . h($r['name']) . '"' . ($edit ? '' : ' disabled') . '></label>'
            . '<label>' . ($lv === 1 ? 'Tage nach Fälligkeit' : 'Tage nach der vorherigen Mahnung') . '<input name="days[' . $lv . ']" size="4" value="' . (int)$r['days'] . '"' . ($edit ? '' : ' disabled') . '></label>'
            . '<label>Zahlungsfrist in Tagen<input name="deadline_days[' . $lv . ']" size="4" value="' . (int)$r['deadline_days'] . '"' . ($edit ? '' : ' disabled') . '></label>'
            . '<label>Mahngebühr in €<input name="fee[' . $lv . ']" size="6" value="' . h(number_format($r['fee_cents'] / 100, 2, ',', '')) . '"' . ($edit ? '' : ' disabled') . '></label></div>'
            . '<label class="block">Text<textarea name="body[' . $lv . ']" rows="5"' . ($edit ? '' : ' disabled') . '>' . h($r['body']) . '</textarea></label></fieldset>';
    }
    if ($edit) echo '<button>Mahnstufen speichern</button></form>';
    echo '</section>';
}
md_footer();
