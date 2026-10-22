<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_login();
$mid = (int)$me['member_id'];
$consent = md_consent_ok($mid);
$canView = $consent && md_can($me, 'receipts', 'view');
$canEdit = $consent && md_can($me, 'receipts', 'edit');

$items = [];   // [Zeile, Kennzeichnung, Fehlertext]
$isDraft = !empty($_REQUEST['draft']);   // Entwurf: auch per POST (Mitgliederliste bleibt aus der Adresszeile)
if ($isDraft) {
    if (!$canView) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
    $from = md_parse_date($_REQUEST['from'] ?? '');
    $to = md_parse_date($_REQUEST['to'] ?? '');
    $mm = $_REQUEST['members'] ?? '';
    foreach (array_filter(array_map('intval', is_array($mm) ? $mm : explode(',', (string)$mm))) as $m) {
        [$prep, $err] = md_receipt_prepare($m, 'annual', ['from' => $from, 'to' => $to]);
        $name = md_one('SELECT first_name, last_name FROM svmembers WHERE member_id = ?', [$m]);
        $items[] = $prep ? [$prep['receipt'], 'ENTWURF – NICHT GÜLTIG', ''] : [null, '', ($name ? $name['first_name'] . ' ' . $name['last_name'] . ': ' : '') . $err];
    }
} else {
    $ids = array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? $_GET['id'] ?? ''))));
    foreach ($ids as $id) {
        $r = md_one('SELECT * FROM md_receipts WHERE receipt_id = ?', [$id]);
        if (!$r) continue;
        $own = (int)$r['member_id'] === $mid;
        if (!$own && !$canView) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
        $printed = md_one("SELECT 1 FROM md_receipt_events WHERE receipt_id = ? AND event = 'printed' AND note = 'Original'", [$id]);   // nur der Aussteller druckt das Original
        if ($r['status'] === 'void') $mark = 'UNGÜLTIG';
        elseif (!$canView) $mark = 'KOPIE';                          // Mitglied
        elseif ($canEdit && !$printed) $mark = '';                   // erster Ausdruck durch den Aussteller = Original
        else $mark = 'DUPLIKAT';
        md_receipt_event($id, 'printed', $mid, $mark === '' ? 'Original' : ($own && !$canView ? 'Kopie (Mitglied)' : 'Duplikat'));
        $items[] = [$r, $mark, ''];
    }
}
if (!$items) { http_response_code(404); exit('Keine Bestätigung gefunden.'); }
?><!DOCTYPE html>
<html lang="de"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow"><title>Zuwendungsbestätigung</title>
<style>
body { font: 12pt/1.4 "Times New Roman", serif; color: #000; background: #eee; margin: 0; }
.bar { background: #005596; color: #fff; padding: 10px 20px; font: 14px system-ui, sans-serif; display: flex; gap: 16px; align-items: center; }
.bar button { font: inherit; padding: 6px 14px; cursor: pointer; }
.bar a { color: #8ECAF6; }
.zb { position: relative; background: #fff; width: 190mm; min-height: 270mm; margin: 12px auto; padding: 15mm 18mm; box-sizing: border-box; page-break-after: always; }
.zb:last-child { page-break-after: auto; }
.zb h2 { text-align: center; font-size: 15pt; margin: 8mm 0 2mm; }
.small { font-size: 9pt; } .center { text-align: center; } .r { text-align: right; }
.issuer { margin: 0 0 4mm; }
.no { text-align: right; margin: 2mm 0; }
table.box { width: 100%; border-collapse: collapse; margin: 4mm 0; }
table.box td { border: 1px solid #000; padding: 2mm 3mm; vertical-align: top; }
table.box td.lab { width: 38%; font-size: 10pt; background: #f4f4f4; }
table.list { width: 100%; border-collapse: collapse; font-size: 10.5pt; margin: 2mm 0 4mm; }
table.list th, table.list td { border-bottom: 1px solid #999; padding: 1mm 2mm; text-align: left; }
table.list th.r, table.list td.r { text-align: right; }
.sign { margin-top: 12mm; } .line { display: inline-block; border-top: 1px solid #000; width: 90mm; }
.mark { position: absolute; top: 12mm; right: 15mm; border: 2px solid #b00; color: #b00; font: bold 13pt system-ui, sans-serif; padding: 1mm 4mm; transform: rotate(-4deg); }
.err { background: #fff; border: 2px solid #b00; padding: 10px 14px; margin: 12px auto; width: 190mm; box-sizing: border-box; font: 14px system-ui, sans-serif; color: #900; }
@media print { body { background: #fff; } .bar, .err { display: none; } .zb { margin: 0; width: auto; min-height: 0; padding: 0; box-shadow: none; } @page { size: A4; margin: 18mm; } }
</style></head><body>
<div class="bar"><button onclick="window.print()">Drucken</button><span><?= count($items) ?> Bestätigung(en)<?= $isDraft ? ' – Entwurf, nichts wurde ausgestellt' : '' ?></span><a href="<?= h(md_url($canView ? 'admin/receipts.php' : 'index.php#finance')) ?>">← zurück</a></div>
<?php foreach ($items as [$r, $mark, $err]): ?>
<?php if ($err !== ''): ?><div class="err"><?= h($err) ?></div><?php else: echo md_receipt_html($r, $mark); endif; ?>
<?php endforeach; ?>
</body></html>
