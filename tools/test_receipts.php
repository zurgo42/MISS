<?php
/**
 * test_receipts.php – prüft Spendenbescheinigungen an der Demo-Datenbank (Einzel-/Sammelbestätigung, Storno, Vereinsangaben).
 * Aufruf: php tools/test_receipts.php   (nach php tools/demo_setup.php bzw. demo_load.php). Läuft in einer Transaktion und rollt zurück.
 */
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__) . '/src/bootstrap.php';
if (!MD_DEMO_MODE) exit("nur mit Demo-Daten\n");
$fail = 0;
$check = function (string $name, $got, $want) use (&$fail) {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("%s %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $ok ? '' : ' – erwartet ' . json_encode($want) . ', war ' . json_encode($got));
};
$by = fn(string $nr) => (int)md_one('SELECT member_id FROM svmembers WHERE membership_number = ?', [$nr])['member_id'];
$max = $by('DEMO0001'); $erika = $by('DEMO0002'); $julia = $by('DEMO0004'); $mia = $by('DEMO0011');
$Y = (int)date('Y'); $py = $Y - 1;

echo "-- Betrag in Buchstaben\n";
foreach ([[12050, 'einhundertzwanzig Euro und fünfzig Cent'], [100, 'ein Euro'], [2100, 'einundzwanzig Euro'], [123456, 'eintausendzweihundertvierunddreißig Euro und sechsundfünfzig Cent'], [101, 'ein Euro und ein Cent']] as [$c, $w]) $check("$c Cent", md_amount_in_words($c), $w);

echo "-- Vereinsangaben\n";
$club = md_club();
$check('Demo-Angaben vollständig', md_club_problems($club), []);
$old = $club; $old['exemption_date'] = date('d.m.Y', strtotime('-4 years'));
$check('Feststellung älter als 3 Jahre: gesperrt', count(md_club_problems($old)) === 1 && str_contains(md_club_problems($old)[0], 'älter als 3 Jahre'), true);
$f = $old; $f['exemption_kind'] = 'freistellung'; $f['exemption_period'] = '2021 bis 2023';
$check('Freistellung 4 Jahre alt: ok (5-Jahres-Grenze)', md_club_problems($f), []);
$f['exemption_date'] = date('d.m.Y', strtotime('-6 years'));
$check('Freistellung 6 Jahre alt: gesperrt', count(md_club_problems($f)), 1);
$f['exemption_date'] = date('d.m.Y'); $f['exemption_period'] = '';
$check('Freistellung ohne Veranlagungszeitraum: gesperrt', md_club_problems($f), ['Veranlagungszeitraum fehlt (bei Freistellungsbescheid)']);
$m = $club; $m['tax_number'] = '';
$check('Steuernummer fehlt', md_club_problems($m), ['Steuernummer fehlt']);

md_db()->beginTransaction();
echo "-- Einzelbestätigung\n";
$existing = md_one("SELECT COUNT(*) c FROM md_receipts WHERE receipt_no LIKE '$Y-%'")['c'];
$spende = md_ledger_add(['member_id' => $erika, 'kind' => 'spende', 'amount' => '75,50', 'entry_date' => "01.03.$Y", 'description' => 'TEST-Spende', 'status' => 'paid', 'paid_date' => "02.03.$Y"], $max)[0];
[$rid, $err] = md_receipt_issue($erika, 'single', ['entry_id' => $spende], $max);
$r = md_one('SELECT * FROM md_receipts WHERE receipt_id = ?', [$rid]);
$check('ausgestellt', [(bool)$rid, $err], [true, '']);
$check('Nummer Jahr-laufend', $r['receipt_no'], sprintf('%d-%04d', $Y, $existing + 1));
$check('Betrag und Tag der Zuwendung', [$r['total_cents'], $r['period_from'], $r['kind']], [7550, "$Y-03-02", 'single']);
$check('Spende ist verknüpft', (int)md_one('SELECT receipt_id FROM md_ledger WHERE entry_id = ?', [$spende])['receipt_id'], $rid);
$check('doppelt bescheinigen verhindert', md_receipt_issue($erika, 'single', ['entry_id' => $spende], $max)[1], 'Für diese Spende gibt es schon eine Bestätigung.');
$open = md_ledger_add(['member_id' => $erika, 'kind' => 'spende', 'amount' => '10', 'entry_date' => "01.03.$Y", 'description' => 'TEST-offen'], $max)[0];
$check('offene Spende nicht bescheinigbar', md_receipt_issue($erika, 'single', ['entry_id' => $open], $max)[1], 'Nur bezahlte Spenden können bescheinigt werden.');
$beitrag = md_ledger_add(['member_id' => $erika, 'kind' => 'beitrag', 'amount' => '10', 'entry_date' => "01.03.$Y", 'description' => 'TEST-Beitrag', 'status' => 'paid'], $max)[0];
$check('Mitgliedsbeitrag nicht bescheinigbar', md_receipt_issue($erika, 'single', ['entry_id' => $beitrag], $max)[1], 'Nur bezahlte Spenden können bescheinigt werden.');
$html = md_receipt_html($r);
foreach (['Bestätigung über Geldzuwendungen', 'fünfundsiebzig Euro und fünfzig Cent', 'Erika Musterfrau', 'nach § 60a AO', 'Verzicht auf Erstattung', 'Nein', '§ 10b Abs. 4 EStG', $r['receipt_no']] as $needle) $check("Text enthält „{$needle}“", str_contains($html, $needle), true);
$check('Original ohne Stempel', str_contains($html, 'class="mark"'), false);
$check('Duplikat gestempelt', str_contains(md_receipt_html($r, 'DUPLIKAT'), 'DUPLIKAT'), true);

$variant = function (array $change) use ($r) { $sn = json_decode($r['snapshot'], true); $sn['club'] = array_merge($sn['club'], $change); return strip_tags(md_receipt_html(['snapshot' => json_encode($sn)] + $r)); };
$t1 = $variant(['exemption_kind' => 'freistellung', 'exemption_period' => '2022 bis 2024', 'tax_office' => 'Musterstadt']);
$check('Freistellung: Wortlaut, Finanzamt nur einmal, Zweck ohne Doppelung', [str_contains($t1, 'Wir sind wegen Förderung der Bildung und Erziehung (§ 52 Abs. 2 Nr. 7 AO) nach dem Freistellungsbescheid'), str_contains($t1, 'des Finanzamtes Musterstadt, StNr.'), str_contains($t1, 'Finanzamt Finanzamt'), str_contains($t1, 'nur zur Förderung der Bildung')], [true, true, false, true]);
$t2 = $variant([]);
$check('Feststellung: "vom Finanzamt Musterstadt" ohne Doppelung', [str_contains($t2, 'vom Finanzamt Musterstadt, StNr.'), str_contains($t2, 'Finanzamt Finanzamt'), str_contains($t2, 'Wir fördern nach unserer Satzung: Förderung der Bildung')], [true, false, true]);
$bad = $club; $bad['purposes'] = 'Bildung';
$check('Zweck ohne "Förderung" wird beanstandet', count(md_club_problems($bad)), 1);

echo "-- Sammelbestätigung (Vorjahr)\n";
[$prep, $perr] = md_receipt_prepare($max, 'annual', ['from' => "$py-01-01", 'to' => "$py-12-31"]);
$check('Entwurf Max: 2 Spenden, 250,00 €', [count($prep['entries']), $prep['total']], [2, 25000]);
$check('Entwurf schreibt nichts', (int)md_one('SELECT COUNT(*) c FROM md_ledger WHERE member_id = ? AND receipt_id IS NOT NULL AND paid_date LIKE ?', [$max, "$py-%"])['c'], 0);
[$a1, $e1] = md_receipt_issue($max, 'annual', ['from' => "$py-01-01", 'to' => "$py-12-31"], $max);
$ra = md_one('SELECT * FROM md_receipts WHERE receipt_id = ?', [$a1]);
$check('Sammelbestätigung ausgestellt', [(bool)$a1, $e1, $ra['kind'], $ra['total_cents']], [true, '', 'annual', 25000]);
$snap = json_decode($ra['snapshot'], true);
$check('Anlage listet beide Zuwendungen', count($snap['entries']), 2);
$check('Anschrift in der Momentaufnahme', $snap['donor']['lines'][0], 'Gartenweg 4');
$htmlA = md_receipt_html($ra);
$check('Sammelbestätigung: Titel, Zeitraum, Anlage', [str_contains($htmlA, 'Sammelbestätigung über Geldzuwendungen'), str_contains($htmlA, "01.01.$py bis 31.12.$py"), str_contains($htmlA, 'Aufstellung der einzelnen Zuwendungen'), str_contains($htmlA, 'zweihundertfünfzig Euro')], [true, true, true, true]);
$check('zweiter Lauf: nichts mehr offen', md_receipt_issue($max, 'annual', ['from' => "$py-01-01", 'to' => "$py-12-31"], $max)[1], 'Keine bezahlten, noch nicht bescheinigten Spenden im Zeitraum.');
$check('Anschrift unvollständig (Mia) verhindert Ausstellung', md_receipt_issue($mia, 'annual', ['from' => "$py-01-01", 'to' => "$py-12-31"], $max)[1], 'Für dieses Mitglied fehlt eine vollständige Anschrift (Straße, PLZ, Ort).');
[$pe] = md_receipt_prepare($erika, 'annual', ['from' => "$Y-01-01", 'to' => "$Y-12-31"]);
$check('bereits einzeln bescheinigte Spende zählt nicht in der Sammelbestätigung', [count($pe['entries']), $pe['total']], [1, 5000]);

echo "-- Ungültig erklären und ersetzen\n";
$check('ohne Grund abgelehnt', md_receipt_void($a1, '  ', $max), 'Bitte einen Grund angeben.');
$check('mit Grund', md_receipt_void($a1, 'falsche Anschrift', $max), '');
$check('Status ungültig, Spenden frei', [md_one('SELECT status FROM md_receipts WHERE receipt_id = ?', [$a1])['status'], (int)md_one('SELECT COUNT(*) c FROM md_ledger WHERE receipt_id = ?', [$a1])['c']], ['void', 0]);
$check('ungültige hat Stempel im Druck', str_contains(md_receipt_html(md_one('SELECT * FROM md_receipts WHERE receipt_id = ?', [$a1]), 'UNGÜLTIG'), 'UNGÜLTIG'), true);
[$a2, $e2] = md_receipt_issue($max, 'annual', ['from' => "$py-01-01", 'to' => "$py-12-31"], $max, $a1);
$check('Ersatz ausgestellt, verweist auf die ungültige', [(bool)$a2, (int)md_one('SELECT replaces_id FROM md_receipts WHERE receipt_id = ?', [$a2])['replaces_id']], [true, $a1]);
$ev = array_column(md_q('SELECT event FROM md_receipt_events WHERE receipt_id IN (?, ?) ORDER BY event_id', [$a1, $a2]), 'event');
$check('Ereignisse dokumentiert (ausgestellt, ungültig, ausgestellt)', $ev, ['issued', 'voided', 'issued']);
$check('Protokoll im Mitgliederkonto', (int)md_one("SELECT COUNT(*) c FROM md_changelog WHERE member_id = ? AND field_key = 'receipt'", [$max])['c'] >= 3, true);

echo "-- Rechte\n";
$erikaRow = md_one('SELECT * FROM svmembers WHERE member_id = ?', [$erika]);
$karlRow = md_one("SELECT * FROM svmembers WHERE membership_number = 'DEMO0010'");
$hansRow = md_one("SELECT * FROM svmembers WHERE membership_number = 'DEMO0007'");
$check('Erika (FVv): bescheinigen', md_perm($erikaRow, 'receipts'), 'edit');
$check('Karl (FP): nur ansehen', md_perm($karlRow, 'receipts'), 'view');
$check('Hans (RL): keine', md_perm($hansRow, 'receipts'), 'none');
md_db()->rollBack();
exit($fail ? 1 : 0);
