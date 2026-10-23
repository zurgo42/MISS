<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('finance', 'view');
$aid = (int)$me['member_id'];
$edit = md_can($me, 'finance', 'edit');

// ---- Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    if (!$edit) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
    $act = $_POST['action'] ?? '';
    if ($act === 'add') {
        [$id, $err] = md_ledger_add($_POST, $aid);
        $msg = $err ?: 'Buchung angelegt.';
        // Option: Spendenquittung gleich ausstellen (nur bezahlte Spenden, Recht "Spendenbescheinigungen bearbeiten")
        if ($id && !empty($_POST['issue_receipt']) && md_can($me, 'receipts', 'edit')) {
            [$rid, $rerr] = md_receipt_issue((int)$_POST['member_id'], 'single', ['entry_id' => $id], $aid);
            if ($rid) { md_flash('Buchung angelegt und Spendenquittung ausgestellt (Original-Ausdruck).'); header('Location: ' . md_url('admin/receipt_print.php?id=' . $rid)); exit; }
            $msg .= ' Die Quittung konnte nicht ausgestellt werden: ' . $rerr;
            $err = $err ?: $rerr;
        }
        md_flash($msg, $err ? 'err' : 'ok');
    } elseif ($act === 'receipt') {
        if (!md_can($me, 'receipts', 'edit')) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
        [$rid, $rerr] = md_receipt_issue((int)($_POST['member_id'] ?? 0), 'single', ['entry_id' => (int)($_POST['entry_id'] ?? 0)], $aid);
        if ($rid) { md_flash('Spendenquittung ausgestellt (Original-Ausdruck).'); header('Location: ' . md_url('admin/receipt_print.php?id=' . $rid)); exit; }
        md_flash($rerr, 'err');
    } elseif ($act === 'paid') {
        $err = md_ledger_mark_paid((int)($_POST['entry_id'] ?? 0), $_POST['paid_date'] ?? '', $aid);
        md_flash($err ?: 'Zahlung vermerkt.', $err ? 'err' : 'ok');
    } elseif ($act === 'cancel') {
        $err = md_ledger_cancel((int)($_POST['entry_id'] ?? 0), $_POST['reason'] ?? '', $aid);
        md_flash($err ?: 'Buchung storniert.', $err ? 'err' : 'ok');
    }
    header('Location: ' . md_url('admin/finance.php?' . http_build_query(array_filter(['q' => $_POST['q'] ?? '', 'kind' => $_POST['fkind'] ?? '', 'status' => $_POST['fstatus'] ?? '', 'year' => $_POST['fyear'] ?? '']))));
    exit;
}

// ---- Filter
$q = trim((string)($_GET['q'] ?? ''));
$fkind = isset(MD_LEDGER_KINDS[$_GET['kind'] ?? '']) ? $_GET['kind'] : '';
$fstatus = in_array($_GET['status'] ?? '', ['open', 'paid', 'cancelled'], true) ? $_GET['status'] : '';
$fyear = ($_GET['year'] ?? date('Y')) === 'all' ? 'all' : (string)(int)($_GET['year'] ?? date('Y'));
$where = ['1=1'];
$par = [];
if ($q !== '') { $where[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.membership_number LIKE ? OR l.reference LIKE ? OR l.description LIKE ?)"; array_push($par, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%"); }
if ($fkind) { $where[] = 'l.kind = ?'; $par[] = $fkind; }
if ($fstatus) { $where[] = 'l.status = ?'; $par[] = $fstatus; }
if ($fyear !== 'all') { $where[] = '(l.entry_date >= ? AND l.entry_date <= ?)'; array_push($par, "$fyear-01-01", "$fyear-12-31"); }
$rows = md_q('SELECT l.*, s.first_name, s.last_name, s.membership_number, rc.receipt_no, rc.status AS receipt_status FROM md_ledger l JOIN svmembers s ON s.member_id = l.member_id
              LEFT JOIN md_receipts rc ON rc.receipt_id = l.receipt_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY l.entry_date DESC, l.entry_id DESC LIMIT 1000', $par);

// ---- CSV-Export der aktuellen Auswahl
if (!empty($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="buchungen-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Mitgliedsnr.', 'Name', 'Art', 'Richtung', 'Betrag', 'Buchungsdatum', 'Fällig', 'Bezahlt am', 'Status', 'Beschreibung', 'Beleg', 'Storno-Grund'], ';', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, [$r['membership_number'], $r['first_name'] . ' ' . $r['last_name'], MD_LEDGER_KINDS[$r['kind']][0] ?? $r['kind'], $r['direction'] === 'to_club' ? 'an Verein' : 'an Mitglied',
            number_format($r['amount_cents'] / 100, 2, ',', ''), md_date($r['entry_date']), md_date($r['due_date']), md_date($r['paid_date']),
            ['open' => 'offen', 'paid' => 'bezahlt', 'cancelled' => 'storniert'][$r['status']], $r['description'], $r['reference'], $r['cancel_reason']], ';', '"', '');
    }
    exit;
}

$sum = ['claims_open' => 0, 'claims_paid' => 0, 'pay_open' => 0, 'pay_paid' => 0];
foreach ($rows as $r) {
    if ($r['status'] === 'cancelled') continue;
    $k = ($r['direction'] === 'to_club' ? 'claims_' : 'pay_') . ($r['status'] === 'open' ? 'open' : 'paid');
    $sum[$k] += (int)$r['amount_cents'];
}

md_header('Finanzen', $me, 'finance');
md_show_flash();
echo '<h1>Finanzen</h1><p class="muted">Buchungen werden nicht gelöscht, sondern mit Grund storniert. Jede Buchung steht auch im Änderungsprotokoll des Mitglieds.</p>';
echo '<form class="search" method="get"><input name="q" value="' . h($q) . '" placeholder="Name, Nr., Beleg, Text"><select name="kind"><option value="">alle Arten</option>';
foreach (MD_LEDGER_KINDS as $k => [$l]) echo '<option value="' . $k . '"' . ($fkind === $k ? ' selected' : '') . '>' . h($l) . '</option>';
echo '</select><select name="status"><option value="">alle Status</option>';
foreach (['open' => 'offen', 'paid' => 'bezahlt', 'cancelled' => 'storniert'] as $k => $l) echo '<option value="' . $k . '"' . ($fstatus === $k ? ' selected' : '') . '>' . $l . '</option>';
echo '</select><select name="year"><option value="all"' . ($fyear === 'all' ? ' selected' : '') . '>alle Jahre</option>';
for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 6; $y--) echo '<option' . ($fyear === (string)$y ? ' selected' : '') . '>' . $y . '</option>';
echo '</select><button>Filtern</button></form>';
echo '<div class="kpis"><div class="kpi"><span>Offene Forderungen an Mitglieder</span><strong>' . md_money($sum['claims_open']) . '</strong></div><div class="kpi"><span>Eingegangen</span><strong>' . md_money($sum['claims_paid']) . '</strong></div>'
    . '<div class="kpi"><span>Auszahlungen offen</span><strong>' . md_money($sum['pay_open']) . '</strong></div><div class="kpi"><span>Ausgezahlt</span><strong>' . md_money($sum['pay_paid']) . '</strong></div></div>';
echo '<p><a href="admin/finance.php?' . h(http_build_query(['q' => $q, 'kind' => $fkind, 'status' => $fstatus, 'year' => $fyear, 'export' => 1])) . '">Auswahl als CSV exportieren</a>'
    . ($edit ? ' · <a href="admin/finance_import.php">Buchungen aus CSV importieren</a> · <a href="admin/datev.php">DATEV-Import</a>' : '') . '</p>';

if ($edit) {
    echo '<details class="card"><summary><strong>Neue Buchung</strong></summary><form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="add"><div class="addr">';
    echo '<label>Mitglied<select name="member_id">';
    foreach (md_q('SELECT member_id, first_name, last_name, membership_number FROM svmembers ORDER BY last_name, first_name') as $m) echo '<option value="' . (int)$m['member_id'] . '">' . h($m['last_name'] . ', ' . $m['first_name'] . ' (' . $m['membership_number'] . ')') . '</option>';
    echo '</select></label><label>Art<select name="kind">';
    foreach (MD_LEDGER_KINDS as $k => [$l, $dir]) echo '<option value="' . $k . '">' . h($l . ($dir === 'to_club' ? ' (an Verein)' : ' (an Mitglied)')) . '</option>';
    echo '</select></label><label>Betrag in €<input name="amount" placeholder="z. B. 120,00" required></label><label>Buchungsdatum<input name="entry_date" value="' . date('d.m.Y') . '"></label>';
    echo '<label>Fällig am<input name="due_date" placeholder="optional"></label><label>Status<select name="status"><option value="open">offen</option><option value="paid">bezahlt</option></select></label><label>Bezahlt am<input name="paid_date" placeholder="bei „bezahlt“"></label><label>Beleg-/Rechnungsnr.<input name="reference"></label></div>';
    echo '<label class="block">Beschreibung<input name="description" required></label>'
        . (md_can($me, 'receipts', 'edit') ? '<label class="check"><input type="checkbox" name="issue_receipt" value="1"> Spendenquittung ausstellen (nur bei bezahlten Spenden; wird dokumentiert)</label> ' : '')
        . '<button>Buchen</button></form></details>';
}

echo '<table><thead><tr><th>Datum</th><th>Mitglied</th><th>Art</th><th>Beschreibung</th><th class="r">Betrag</th><th>Status</th>' . ($edit ? '<th></th>' : '') . '</tr></thead><tbody>';
foreach ($rows as $r) {
    $out = $r['direction'] === 'to_club';
    $cx = $r['status'] === 'cancelled';
    $stat = $cx ? 'storniert' . ($r['cancel_reason'] ? ' (' . $r['cancel_reason'] . ')' : '') : ($r['status'] === 'paid' ? 'bezahlt ' . md_date($r['paid_date']) : 'offen' . ($r['due_date'] ? ', fällig ' . md_date($r['due_date']) : ''));
    $overdue = !$cx && $r['status'] === 'open' && $out && $r['due_date'] && $r['due_date'] < date('Y-m-d');
    echo '<tr' . ($cx ? ' class="muted" style="text-decoration:line-through"' : '') . '><td>' . h(md_date($r['entry_date'])) . '</td><td><a href="index.php?member=' . (int)$r['member_id'] . '#finance">' . h($r['first_name'] . ' ' . $r['last_name']) . '</a></td>';
    $refLink = $r['reference'] && $r['direction'] === 'to_club' && in_array($r['kind'], ['beitrag', 'eintritt'], true) ? '<a href="admin/invoice_print.php?id=' . (int)$r['entry_id'] . '" target="_blank" title="Rechnung drucken">' . h($r['reference']) . '</a>' : h($r['reference']);
    echo '<td>' . h(MD_LEDGER_KINDS[$r['kind']][0] ?? $r['kind']) . '</td><td>' . h($r['description']) . ($r['reference'] ? ' <span class="muted">' . $refLink . '</span>' : '') . '</td>';
    echo '<td class="r ' . ($out ? 'neg' : 'pos') . '">' . ($out ? '− ' : '+ ') . md_money((int)$r['amount_cents']) . '</td><td class="' . ($overdue ? 'bad' : '') . '">' . h($stat) . ((int)$r['dunning_level'] > 0 && $r['status'] === 'open' ? '<br><span class="bad">Mahnstufe ' . (int)$r['dunning_level'] . ' (' . h(md_date($r['last_dunned_at'])) . ')</span>' : '') . (!empty($r['deferred_until']) && $r['status'] === 'open' && $r['deferred_until'] >= date('Y-m-d') ? '<br><span class="muted">gestundet bis ' . h(md_date($r['deferred_until'])) . '</span>' : '') . ($r['receipt_id'] ? '<br><a href="admin/receipt_print.php?id=' . (int)$r['receipt_id'] . '" target="_blank" title="Zuwendungsbestätigung">Quittung ' . h($r['receipt_no']) . '</a>' : '') . '</td>';
    if ($edit) {
        echo '<td class="act">';
        if ($r['status'] === 'open') echo '<form class="inline" method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="paid"><input type="hidden" name="entry_id" value="' . (int)$r['entry_id'] . '"><input name="paid_date" size="9" value="' . date('d.m.Y') . '" title="Zahldatum"> <button>bezahlt</button></form> ';
        if ($r['kind'] === 'spende' && $r['status'] === 'paid' && !$r['receipt_id'] && md_can($me, 'receipts', 'edit')) echo '<form class="inline" method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="receipt"><input type="hidden" name="entry_id" value="' . (int)$r['entry_id'] . '"><input type="hidden" name="member_id" value="' . (int)$r['member_id'] . '"><button title="Zuwendungsbestätigung für diese Spende ausstellen">Quittung</button></form> ';
        if (!$cx) echo '<form class="inline" method="post" onsubmit="return this.reason.value.trim() !== \'\' || (alert(\'Bitte einen Grund angeben.\'), false)">' . md_csrf_field() . '<input type="hidden" name="action" value="cancel"><input type="hidden" name="entry_id" value="' . (int)$r['entry_id'] . '"><input name="reason" size="12" placeholder="Storno-Grund"> <button class="link">Storno</button></form>';
        echo '</td>';
    }
    echo '</tr>';
}
if (!$rows) echo '<tr><td colspan="7" class="muted">Keine Buchungen für diese Auswahl.</td></tr>';
echo '</tbody></table>' . (count($rows) >= 1000 ? '<p class="muted">Es werden die ersten 1000 Buchungen angezeigt. Bitte Filter nutzen.</p>' : '');
md_footer();
