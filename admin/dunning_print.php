<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_login();
$mid = (int)$me['member_id'];
$canView = md_consent_ok($mid) && md_can($me, 'fees', 'view');
$ids = array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? $_GET['id'] ?? ''))));
$letters = [];
foreach ($ids as $id) {
    $l = md_one('SELECT * FROM md_dunning_letters WHERE letter_id = ?', [$id]);
    if (!$l) continue;
    if ((int)$l['member_id'] !== $mid && !$canView) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
    $letters[] = $l;
}
if (!$letters) { http_response_code(404); exit('Kein Mahnschreiben gefunden.'); }
?><!DOCTYPE html>
<html lang="de"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow"><title>Mahnschreiben</title><style><?= md_doc_css() ?></style></head><body>
<div class="bar"><button onclick="window.print()">Drucken</button><span><?= count($letters) ?> Schreiben</span><a href="<?= h(md_url($canView ? 'admin/dunning.php' : 'index.php#finance')) ?>">← zurück</a></div>
<?php foreach ($letters as $l) echo md_dunning_letter_html($l); ?>
</body></html>
