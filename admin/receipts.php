<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('receipts', 'view');
$aid = (int)$me['member_id'];
$edit = md_can($me, 'receipts', 'edit');
$club = md_club();
$problems = md_club_problems($club);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    if (!$edit) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
    $act = $_POST['action'] ?? '';
    $year = (int)($_POST['year'] ?? date('Y') - 1);
    if ($act === 'annual_issue') {
        $ids = [];
        $errs = [];
        foreach (array_filter(array_map('intval', (array)($_POST['members'] ?? []))) as $m) {
            [$rid, $err] = md_receipt_issue($m, 'annual', ['from' => "$year-01-01", 'to' => "$year-12-31"], $aid);
            if ($rid) $ids[] = $rid;
            else { $n = md_one('SELECT first_name, last_name FROM svmembers WHERE member_id = ?', [$m]); $errs[] = ($n ? $n['first_name'] . ' ' . $n['last_name'] . ': ' : '') . $err; }
        }
        if ($errs) md_flash(implode(' | ', $errs), 'err');
        if ($ids) {
            md_flash(count($ids) . ' Bestätigung(en) ausgestellt. Dies ist der Original-Ausdruck; jeder weitere Ausdruck wird als Duplikat gekennzeichnet.');
            header('Location: ' . md_url('admin/receipt_print.php?ids=' . implode(',', $ids)));
            exit;
        }
    } elseif ($act === 'void') {
        $err = md_receipt_void((int)($_POST['receipt_id'] ?? 0), (string)($_POST['reason'] ?? ''), $aid);
        md_flash($err ?: 'Bestätigung für ungültig erklärt. Die Spenden sind wieder frei für eine neue Bestätigung.', $err ? 'err' : 'ok');
    } elseif ($act === 'void_replace') {
        $old = md_one('SELECT * FROM md_receipts WHERE receipt_id = ?', [(int)($_POST['receipt_id'] ?? 0)]);
        if (!$old || $old['status'] !== 'issued') { md_flash('Bestätigung nicht gefunden oder schon ungültig.', 'err'); }
        else {
            $snap = json_decode($old['snapshot'], true);
            $err = md_receipt_void((int)$old['receipt_id'], (string)($_POST['reason'] ?? ''), $aid);
            if ($err) md_flash($err, 'err');
            else {
                $range = $old['kind'] === 'single' ? ['entry_id' => $snap['entries'][0]['entry_id']] : ['from' => $old['period_from'], 'to' => $old['period_to']];
                [$rid, $err2] = md_receipt_issue((int)$old['member_id'], $old['kind'], $range, $aid, (int)$old['receipt_id']);
                if ($rid) { md_flash('Alte Bestätigung für ungültig erklärt, neue ausgestellt (Original-Ausdruck).'); header('Location: ' . md_url('admin/receipt_print.php?id=' . $rid)); exit; }
                md_flash('Die alte Bestätigung ist ungültig, die neue konnte nicht ausgestellt werden: ' . $err2, 'err');
            }
        }
    }
    header('Location: ' . md_url('admin/receipts.php?year=' . $year));
    exit;
}

$year = (int)($_GET['year'] ?? date('Y') - 1);
$from = "$year-01-01";
$to = "$year-12-31";
$open = md_q("SELECT l.member_id, s.first_name, s.last_name, COUNT(*) n, SUM(l.amount_cents) total FROM md_ledger l JOIN svmembers s ON s.member_id = l.member_id
              WHERE l.kind = 'spende' AND l.status = 'paid' AND l.receipt_id IS NULL AND l.paid_date >= ? AND l.paid_date <= ?
              GROUP BY l.member_id, s.first_name, s.last_name ORDER BY s.last_name, s.first_name", [$from, $to]);
$done = md_one("SELECT COUNT(*) n, COALESCE(SUM(amount_cents), 0) total FROM md_ledger WHERE kind = 'spende' AND status = 'paid' AND receipt_id IS NOT NULL AND paid_date >= ? AND paid_date <= ?", [$from, $to]);

md_header('Spendenbescheinigungen', $me, 'receipts');
md_show_flash();
echo '<h1>Spendenbescheinigungen</h1><p class="muted">Bescheinigt werden bezahlte Geldspenden (Tag der Zuwendung = Zahltag). Jede Bestätigung ist nummeriert, dokumentiert und wird nie gelöscht; Fehler werden mit Grund für ungültig erklärt. '
    . 'Nicht enthalten: Aufwandsspenden (Verzicht auf Erstattung), Sachspenden, Mitgliedsbeiträge.</p>';
if ($problems) {
    echo '<div class="flash err"><strong>Vereinsangaben unvollständig – es können keine Bestätigungen ausgestellt werden:</strong><ul>';
    foreach ($problems as $p) echo '<li>' . h($p) . '</li>';
    echo '</ul>' . (md_can($me, 'settings', 'edit') ? '<a href="admin/settings.php#club">Einstellungen → Verein</a>' : 'Bitte die Verwaltung bitten, die Angaben unter Einstellungen → Verein zu ergänzen.') . '</div>';
}

// ---- Jahresbescheinigung
echo '<section class="card" id="annual"><h2>Jahresbescheinigung für ' . $year . '</h2>';
echo '<form class="search" method="get"><select name="year">';
for ($y = (int)date('Y'); $y >= (int)date('Y') - 6; $y--) echo '<option' . ($y === $year ? ' selected' : '') . '>' . $y . '</option>';
echo '</select><button>Jahr wählen</button></form>';
echo '<p>' . ($open ? count($open) . ' Mitglied(er) mit bezahlten, noch nicht bescheinigten Spenden im Jahr ' . $year . '.' : 'Keine offenen Spenden ohne Bestätigung im Jahr ' . $year . '.')
    . ($done['n'] ? ' <span class="muted">Schon bescheinigt (einzeln oder früher): ' . (int)$done['n'] . ' Spende(n), ' . md_money((int)$done['total']) . '.</span>' : '') . '</p>';
if ($open) {
    echo '<form method="post" id="annualform" onsubmit="return confirm(\'Bestätigungen jetzt ausstellen? Das wird dokumentiert und lässt sich nur mit Grund rückgängig machen.\')">' . md_csrf_field() . '<input type="hidden" name="action" value="annual_issue"><input type="hidden" name="year" value="' . $year . '">';
    echo '<table><thead><tr><th></th><th>Mitglied</th><th class="r">Spenden</th><th class="r">Summe</th><th>Anschrift</th></tr></thead><tbody>';
    foreach ($open as $r) {
        $ok = md_donor((int)$r['member_id']) !== null;
        echo '<tr><td><input type="checkbox" name="members[]" value="' . (int)$r['member_id'] . '"' . ($ok ? ' checked' : ' disabled') . '></td><td><a href="index.php?member=' . (int)$r['member_id'] . '#addresses">' . h($r['first_name'] . ' ' . $r['last_name']) . '</a></td>'
            . '<td class="r">' . (int)$r['n'] . '</td><td class="r">' . md_money((int)$r['total']) . '</td><td' . ($ok ? '' : ' class="bad"') . '>' . ($ok ? 'vollständig' : 'fehlt (Straße, PLZ, Ort)') . '</td></tr>';
    }
    echo '</tbody></table><p>';
    echo '<button type="submit" formaction="admin/receipt_print.php" formmethod="post" formtarget="_blank" name="draft" value="1" onclick="this.form.onsubmit=null">Entwurf ansehen (nichts wird ausgestellt)</button> ';
    echo '<input type="hidden" name="from" value="' . $from . '"><input type="hidden" name="to" value="' . $to . '">';
    if ($edit && !$problems) echo '<button>Ausgewählte ausstellen und drucken</button>';
    echo '</p></form>';
}
echo '</section>';

// ---- Ausgestellte Bestätigungen
$q = trim((string)($_GET['q'] ?? ''));
$fstatus = in_array($_GET['status'] ?? '', ['issued', 'void'], true) ? $_GET['status'] : '';
$where = ['1=1'];
$par = [];
if ($q !== '') { $where[] = '(s.first_name LIKE ? OR s.last_name LIKE ? OR r.receipt_no LIKE ?)'; array_push($par, "%$q%", "%$q%", "%$q%"); }
if ($fstatus) { $where[] = 'r.status = ?'; $par[] = $fstatus; }
$where[] = '(r.period_to >= ? AND r.period_from <= ?)';
array_push($par, $from, $to);
$rows = md_q('SELECT r.*, s.first_name, s.last_name, u.first_name ufn, u.last_name uln,
              (SELECT COUNT(*) FROM md_receipt_events e WHERE e.receipt_id = r.receipt_id AND e.event = \'printed\') prints
              FROM md_receipts r JOIN svmembers s ON s.member_id = r.member_id LEFT JOIN svmembers u ON u.member_id = r.issued_by
              WHERE ' . implode(' AND ', $where) . ' ORDER BY r.receipt_no DESC LIMIT 500', $par);
echo '<section class="card" id="issued"><h2>Ausgestellte Bestätigungen (Zuwendungen ' . $year . ')</h2>';
echo '<form class="search" method="get"><input type="hidden" name="year" value="' . $year . '"><input name="q" value="' . h($q) . '" placeholder="Name oder Nummer"><select name="status"><option value="">alle</option><option value="issued"' . ($fstatus === 'issued' ? ' selected' : '') . '>gültig</option><option value="void"' . ($fstatus === 'void' ? ' selected' : '') . '>ungültig</option></select><button>Filtern</button></form>';
echo '<table><thead><tr><th>Nr.</th><th>Mitglied</th><th>Art</th><th>Zeitraum</th><th class="r">Betrag</th><th>Ausgestellt</th><th>Ausdrucke</th><th>Status</th><th></th></tr></thead><tbody>';
foreach ($rows as $r) {
    $void = $r['status'] === 'void';
    echo '<tr' . ($void ? ' class="muted"' : '') . '><td><a href="admin/receipt_print.php?id=' . (int)$r['receipt_id'] . '" target="_blank">' . h($r['receipt_no']) . '</a></td><td><a href="index.php?member=' . (int)$r['member_id'] . '#finance">' . h($r['first_name'] . ' ' . $r['last_name']) . '</a></td>'
        . '<td>' . ($r['kind'] === 'annual' ? 'Sammelbestätigung' : 'Einzelbestätigung') . '</td><td>' . h(md_date($r['period_from'])) . ($r['period_from'] !== $r['period_to'] ? ' – ' . h(md_date($r['period_to'])) : '') . '</td>'
        . '<td class="r">' . md_money((int)$r['total_cents']) . '</td><td>' . h(date('d.m.Y', strtotime($r['issued_at']))) . '<br><span class="muted">' . h(trim($r['ufn'] . ' ' . $r['uln'])) . '</span></td><td class="r">' . (int)$r['prints'] . '</td>'
        . '<td>' . ($void ? 'ungültig<br><span class="muted">' . h($r['void_reason']) . '</span>' : 'gültig') . '</td><td class="act">';
    if ($edit && !$void) {
        echo '<form method="post" onsubmit="return this.reason.value.trim() !== \'\' || (alert(\'Bitte einen Grund angeben.\'), false)">' . md_csrf_field() . '<input type="hidden" name="year" value="' . $year . '"><input type="hidden" name="receipt_id" value="' . (int)$r['receipt_id'] . '">'
            . '<input name="reason" placeholder="Grund"><button name="action" value="void_replace" title="Alte Bestätigung ungültig erklären und sofort eine neue ausstellen">ersetzen</button> <button class="link" name="action" value="void">nur ungültig</button></form>';
    }
    echo '</td></tr>';
}
if (!$rows) echo '<tr><td colspan="9" class="muted">Keine Bestätigungen für ' . $year . '.</td></tr>';
echo '</tbody></table></section>';
md_footer();
