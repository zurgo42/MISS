<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_login();
$mid = (int)$me['member_id'];
$canView = md_consent_ok($mid) && md_can($me, 'fees', 'view');
$club = md_club();
$conf = md_fee_conf();

if (!empty($_GET['run'])) {
    $rows = md_q("SELECT l.*, s.membership_number FROM md_ledger l JOIN svmembers s ON s.member_id = l.member_id WHERE l.run_id = ? AND l.status <> 'cancelled' ORDER BY s.last_name, s.first_name", [(int)$_GET['run']]);
    if (!$canView) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
} else {
    $ids = array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? $_GET['id'] ?? ''))));
    $rows = [];
    foreach ($ids as $id) {
        $e = md_one('SELECT l.*, s.membership_number FROM md_ledger l JOIN svmembers s ON s.member_id = l.member_id WHERE l.entry_id = ?', [$id]);
        if (!$e || $e['direction'] !== 'to_club' || empty($e['reference'])) continue;
        if ((int)$e['member_id'] !== $mid && !$canView) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
        $rows[] = $e;
    }
}
if (!$rows) { http_response_code(404); exit('Keine Rechnung gefunden.'); }
?><!DOCTYPE html>
<html lang="de"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow"><title>Rechnung</title><style><?= md_doc_css() ?></style></head><body>
<div class="bar"><button onclick="window.print()">Drucken</button><span><?= count($rows) ?> Rechnung(en)</span><a href="<?= h(md_url($canView ? 'admin/fees.php' : 'index.php#finance')) ?>">← zurück</a></div>
<?php foreach ($rows as $e):
    $rcp = md_letter_recipient((int)$e['member_id']);
    if (!$rcp) { echo '<div class="err">Keine vollständige Anschrift: Rechnung ' . h($e['reference']) . ' (Mitgliedsnr. ' . h($e['membership_number']) . ') kann nicht gedruckt werden.</div>'; continue; }
    echo md_invoice_html($e, $club, $conf, $rcp);
endforeach; ?>
</body></html>
