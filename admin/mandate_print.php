<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('sepa', 'view');
$member = (int)($_GET['member'] ?? 0);
$m = md_one('SELECT * FROM svmembers WHERE member_id = ?', [$member]);
if (!$m) { http_response_code(404); exit('Mitglied nicht gefunden.'); }
$club = md_club();
$conf = md_sepa_conf();
$rcp = md_donor($member);
$ref = md_sepa_mandate_ref_next($member);
$p = md_load_profile($member);
$name = trim(trim((string)($p['title'] ?? '') . ' ' . $m['first_name'] . ' ' . $m['last_name']));
?><!DOCTYPE html>
<html lang="de"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow"><title>SEPA-Lastschriftmandat</title><style><?= md_doc_css() ?>.box{border:1px solid #000;padding:2mm 3mm;margin:1mm 0 4mm;min-height:7mm}.lab{font-size:9pt;color:#444}</style></head><body>
<div class="bar"><button onclick="window.print()">Drucken</button><span>Vordruck zum Unterschreiben – nicht gültig, bevor er unterschrieben beim Verein vorliegt</span></div>
<article class="doc">
<p class="sender"><?= h($club['name']) ?> · <?= h($club['street']) ?> · <?= h($club['zip'] . ' ' . $club['city']) ?></p>
<h2>SEPA-Lastschriftmandat</h2>
<p><strong>Zahlungsempfänger:</strong> <?= h($club['name']) ?>, <?= h($club['street']) ?>, <?= h($club['zip'] . ' ' . $club['city']) ?><br>
<strong>Gläubiger-Identifikationsnummer:</strong> <?= h($conf['creditor_id'] ?: '__________________') ?><br>
<strong>Mandatsreferenz:</strong> <?= h($ref) ?> &nbsp; <span class="small">(Mitgliedsnummer <?= h($m['membership_number']) ?>)</span></p>
<p>Ich ermächtige den <?= h($club['name']) ?>, Zahlungen von meinem Konto mittels SEPA-Basislastschrift einzuziehen. Zugleich weise ich mein Kreditinstitut an, die vom <?= h($club['name']) ?> auf mein Konto gezogenen Lastschriften einzulösen.</p>
<p>Einzugsgrund: Mitgliedsbeiträge und sonstige Forderungen aus der Mitgliedschaft. Die Ankündigung des Einzugs (Vorabinformation) erfolgt mit der Rechnung, spätestens <?= (int)$conf['prenotify_days'] ?> Tage vor Fälligkeit.</p>
<p class="small">Hinweis: Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die Erstattung des belasteten Betrages verlangen. Es gelten dabei die mit meinem Kreditinstitut vereinbarten Bedingungen.</p>
<p class="lab">Name des Zahlungspflichtigen (Kontoinhaber)</p><div class="box"><?= h($name) ?></div>
<p class="lab">Anschrift (Straße, PLZ, Ort)</p><div class="box"><?= $rcp ? h(implode(', ', $rcp['lines'])) : '' ?></div>
<p class="lab">IBAN</p><div class="box"></div>
<p class="lab">BIC (bei Konten im Inland optional)</p><div class="box"></div>
<p class="sign"><br><br><span style="display:inline-block;border-top:1px solid #000;width:60mm"></span> &nbsp; <span style="display:inline-block;border-top:1px solid #000;width:80mm"></span><br><span class="small">Ort, Datum &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; Unterschrift des Kontoinhabers</span></p>
</article></body></html>
