<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('sepa', 'view');
$aid = (int)$me['member_id'];
$edit = md_can($me, 'sepa', 'edit');
$id = (int)($_GET['id'] ?? $_POST['batch_id'] ?? 0);
$b = md_one('SELECT * FROM md_sepa_batches WHERE batch_id = ?', [$id]);
if (!$b) { http_response_code(404); exit('Lauf nicht gefunden.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    if (!$edit) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
    $act = $_POST['action'] ?? '';
    $err = '';
    $ok = 'Gespeichert.';
    if ($act === 'submit') { $err = md_sepa_submit($id, $aid, trim((string)($_POST['note'] ?? '')) ?: null); $ok = 'Als bei der Bank eingereicht vermerkt.'; }
    elseif ($act === 'close') { $err = md_sepa_close($id, $aid); $ok = 'Einzug bestätigt: Die Forderungen sind als bezahlt gebucht.'; }
    elseif ($act === 'void') { $err = md_sepa_void($id, (string)($_POST['reason'] ?? ''), $aid); $ok = 'Lauf storniert; die Forderungen sind wieder frei.'; }
    elseif ($act === 'return') {
        $fee = trim((string)($_POST['fee'] ?? '')) === '' ? 0 : (int)md_parse_amount((string)$_POST['fee']);
        $err = md_sepa_return((int)($_POST['item_id'] ?? 0), (string)($_POST['code'] ?? ''), (string)($_POST['text'] ?? ''), (string)($_POST['date'] ?? ''), $fee, !empty($_POST['charge']), $aid);
        $ok = 'Rücklastschrift erfasst: Forderungen sind wieder offen.';
    }
    md_flash($err ?: $ok, $err ? 'err' : 'ok');
    header('Location: ' . md_url('admin/sepa_batch.php?id=' . $id));
    exit;
}

$items = md_q('SELECT i.*, s.first_name, s.last_name FROM md_sepa_items i JOIN svmembers s ON s.member_id = i.member_id WHERE i.batch_id = ? ORDER BY i.item_id', [$id]);
$events = md_q('SELECT e.*, u.first_name, u.last_name FROM md_sepa_events e LEFT JOIN svmembers u ON u.member_id = e.event_by WHERE e.batch_id = ? ORDER BY e.event_id', [$id]);
$cred = json_decode($b['creditor'], true);
$st = ['created' => 'erstellt – Datei herunterladen und bei der Bank einreichen', 'submitted' => 'bei der Bank eingereicht – nach dem Einzugstermin bestätigen', 'closed' => 'Einzug bestätigt', 'void' => 'storniert'];
md_header('Lastschriftlauf ' . $b['batch_no'], $me, 'sepa');
md_show_flash();
echo '<h1>Lastschriftlauf ' . h($b['batch_no']) . '</h1><p><a href="admin/sepa.php#batches">← alle Läufe</a></p>';
echo '<section class="card"><h2>' . h($st[$b['status']] ?? $b['status']) . '</h2><table><tbody>'
    . '<tr><th>Einzugsdatum</th><td>' . h(md_date($b['collection_date'])) . '</td></tr><tr><th>Lastschriften / Summe</th><td>' . (int)$b['items'] . ' / ' . md_money((int)$b['total_cents']) . '</td></tr>'
    . '<tr><th>Gläubiger</th><td>' . h($cred['name']) . ', ' . h(md_iban_mask($cred['iban'])) . ', Gläubiger-ID ' . h($cred['creditor_id']) . '</td></tr><tr><th>Format</th><td>' . h($b['format']) . '</td></tr>'
    . ($b['note'] ? '<tr><th>Vermerk</th><td>' . h($b['note']) . '</td></tr>' : '') . ($b['void_reason'] ? '<tr><th>Storno</th><td>' . h($b['void_reason']) . '</td></tr>' : '') . '</tbody></table>';
if ($edit && $b['status'] !== 'void') {
    echo '<p><a class="btn" href="admin/sepa_download.php?id=' . $id . '"><button type="button">SEPA-Datei (XML) herunterladen</button></a> <span class="muted">Die Datei enthält die IBANs; nach dem Hochladen im Online-Banking löschen. Jeder Download wird protokolliert.</span></p>';
}
if ($edit && $b['status'] === 'created') {
    echo '<form method="post" class="inline">' . md_csrf_field() . '<input type="hidden" name="batch_id" value="' . $id . '"><input type="hidden" name="action" value="submit"><input name="note" placeholder="Vermerk (z. B. Datei am … hochgeladen)"> <button>Als bei der Bank eingereicht vermerken</button></form> ';
    echo '<form method="post" class="inline" onsubmit="return this.reason.value.trim() !== \'\' || (alert(\'Bitte einen Grund angeben.\'), false)">' . md_csrf_field() . '<input type="hidden" name="batch_id" value="' . $id . '"><input type="hidden" name="action" value="void"><input name="reason" placeholder="Storno-Grund"> <button class="link">Lauf stornieren</button></form>';
}
if ($edit && $b['status'] === 'submitted') {
    echo '<form method="post" onsubmit="return confirm(\'Den Einzug bestätigen? Alle Forderungen werden als bezahlt gebucht (Zahltag ' . h(md_date($b['collection_date'])) . '). Rückläufer erfasst du danach einzeln.\')">' . md_csrf_field() . '<input type="hidden" name="batch_id" value="' . $id . '"><input type="hidden" name="action" value="close"><button>Einzug bestätigen (Forderungen als bezahlt buchen)</button></form>';
}
echo '</section>';

echo '<section class="card"><h2>Lastschriften</h2><table><thead><tr><th>Mitglied</th><th>Mandat</th><th>Art</th><th class="r">Betrag</th><th>Verwendungszweck</th><th>Status</th><th></th></tr></thead><tbody>';
foreach ($items as $i) {
    $stt = ['pending' => 'vorgesehen', 'collected' => 'eingezogen', 'returned' => 'zurückgegeben', 'cancelled' => 'storniert'][$i['status']];
    echo '<tr' . ($i['status'] === 'returned' ? ' class="rowbad"' : ($i['status'] === 'cancelled' ? ' class="muted"' : '')) . '><td><a href="index.php?member=' . (int)$i['member_id'] . '#finance">' . h($i['first_name'] . ' ' . $i['last_name']) . '</a></td><td>' . h($i['mandate_ref']) . '</td><td>' . h($i['seq_type']) . '</td><td class="r">' . md_money((int)$i['amount_cents']) . '</td><td>' . h($i['purpose']) . '<br><span class="muted">' . h($i['end_to_end_id']) . '</span></td>'
        . '<td>' . h($stt) . ($i['status'] === 'returned' ? '<br><span class="bad">' . h($i['return_code'] . ' ' . (MD_SEPA_RETURN_CODES[$i['return_code']] ?? '')) . ', ' . h(md_date($i['returned_at'])) . '</span>' . ($i['return_text'] ? '<br><span class="muted">' . h($i['return_text']) . '</span>' : '') : '') . '</td><td class="act">';
    if ($edit && $i['status'] === 'collected') {
        echo '<details><summary>Rücklastschrift</summary><form method="post">' . md_csrf_field() . '<input type="hidden" name="batch_id" value="' . $id . '"><input type="hidden" name="action" value="return"><input type="hidden" name="item_id" value="' . (int)$i['item_id'] . '"><select name="code">';
        foreach (MD_SEPA_RETURN_CODES as $c => $t) echo '<option value="' . $c . '">' . h("$c – $t") . '</option>';
        echo '</select><input name="date" value="' . date('d.m.Y') . '" size="10"><input name="text" placeholder="Vermerk"><input name="fee" size="6" placeholder="Bankgebühr €"><label class="check"><input type="checkbox" name="charge" value="1"> Gebühr dem Mitglied berechnen</label><button>Erfassen</button></form></details>';
    }
    echo '</td></tr>';
}
echo '</tbody></table></section>';
echo '<section class="card"><h2>Protokoll</h2><table><tbody>';
foreach ($events as $e) echo '<tr><td>' . h(date('d.m.Y H:i', strtotime($e['event_at']))) . '</td><td>' . h(['created' => 'erstellt', 'downloaded' => 'Datei heruntergeladen', 'submitted' => 'eingereicht', 'closed' => 'Einzug bestätigt', 'returned' => 'Rücklastschrift', 'void' => 'storniert'][$e['event']] ?? $e['event']) . '</td><td>' . h(trim($e['first_name'] . ' ' . $e['last_name'])) . '</td><td>' . h($e['note']) . '</td></tr>';
echo '</tbody></table></section>';
md_footer();
