<?php
/**
 * test_datev.php – prüft den DATEV-Import (Format EXTF/Buchungsstapel) an der Demo-Datenbank.
 * Aufruf: php tools/test_datev.php   (nach demo_setup.php bzw. demo_load.php). Läuft in einer Transaktion und rollt zurück.
 */
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__) . '/src/bootstrap.php';
if (!MD_DEMO_MODE) exit("nur mit Demo-Daten\n");
$fail = 0;
$check = function (string $name, $got, $want) use (&$fail) {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("%s %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $ok ? '' : ' – erwartet ' . json_encode($want, JSON_UNESCAPED_UNICODE) . ', war ' . json_encode($got, JSON_UNESCAPED_UNICODE));
};
$by = fn(string $nr) => (int)md_one('SELECT member_id FROM svmembers WHERE membership_number = ?', [$nr])['member_id'];
$max = $by('DEMO0001'); $erika = $by('DEMO0002'); $lukas = $by('DEMO0003'); $hans = $by('DEMO0007'); $mia = $by('DEMO0011');
$tmp = sys_get_temp_dir() . '/md_datev_' . getmypid();
$write = function (array $lines, string $name = 'a') use ($tmp): string {
    $f = "$tmp-$name.csv";
    file_put_contents($f, implode("\r\n", $lines) . "\r\n");
    return $f;
};
$Y = (int)date('Y');
$head = fn($from = null, $to = null) => '"EXTF";510;21;"Buchungsstapel";7;20260115093000000;;"RE";"";"";29098;55003;' . ($from ?? "$Y" . '0101') . ';4;' . ($from ?? "$Y" . '0101') . ';' . ($to ?? "$Y" . '1231') . ';"Test";"SO";1;0;0;"EUR"';
$cols = 'Umsatz (ohne Soll/Haben-Kz);Soll/Haben-Kennzeichen;WKZ Umsatz;Kurs;Basis-Umsatz;WKZ Basis-Umsatz;Konto;Gegenkonto (ohne BU-Schlüssel);BU-Schlüssel;Belegdatum;Belegfeld 1;Belegfeld 2;Skonto;Buchungstext';

echo "-- Datei lesen\n";
foreach (['Keine' => 'nur Text', 'Kat' => '"EXTF";510;16;"Debitoren/Kreditoren"'] as $n => $first) {
    try { md_datev_parse($write([$first], $n)); $res = 'kein Fehler'; } catch (RuntimeException $e) { $res = str_contains($e->getMessage(), 'EXTF') || str_contains($e->getMessage(), 'Formatkategorie') ? 'Fehler' : $e->getMessage(); }
    $check("ungültige Datei ($n) abgelehnt", $res, 'Fehler');
}
$open = md_one("SELECT l.* FROM md_ledger l WHERE l.member_id = ? AND l.status = 'open' AND l.kind = 'beitrag'", [$lukas]);
$miaOpen = md_one("SELECT l.* FROM md_ledger l WHERE l.member_id = ? AND l.status = 'open' AND l.kind = 'beitrag'", [$mia]);
$lines = [$head(), $cols,
    '120,00;"H";"EUR";;;;10003;1200;;1510;"' . $open['reference'] . '";;;"Zahlung ' . $open['reference'] . '"',                 // 3: Zahlung mit Rechnungsnummer (Lukas)
    '60,00;"S";"EUR";;;;1200;10011;;1610;"";;;"Eingang Mitgliedsbeitrag"',                                                         // 4: Soll Bank / Gegenkonto Debitor Mia (Seitenumkehr), Betrag = offene Forderung
    '50,00;"H";"EUR";;;;10002;8200;;1710;"";;;"Spende Sommerfest"',                                                                // 5: Spende (Regel 8200)
    '125,00;"S";"EUR";;;;70007;1200;;1810;"K1";;;"Erstattung Kursmaterial"',                                                        // 6: Zahlung an Hans (Kreditor)
    '75,00;"H";"EUR";;;;19999;1200;;1810;"";;;"Zahlung unbekanntes Konto"',                                                        // 7: unbekanntes Konto
    '40,00;"H";"EUR";;;;19998;1200;;1810;"' . $open['reference'] . '";;;"Zahlung ' . $open['reference'] . ' ohne Personenkonto"',   // 8: Rechnungsnummer verrät das Mitglied (aber Betrag weicht ab)
    '1200,00;"S";"EUR";;;;4100;1200;;1810;"";;;"Sachkonto ohne Mitglied"',                                                         // 9: kein Personenkonto
    '12,00;"H";"USD";;;;10002;1200;;1810;"";;;"Dollar"',                                                                           // 10: Währung
    '0,00;"H";"EUR";;;;10002;1200;;1810;"";;;"Null"',                                                                              // 11: Betrag
    '20,00;"X";"EUR";;;;10002;1200;;1810;"";;;"Kennzeichen"',                                                                      // 12
    '20,00;"H";"EUR";;;;10002;1200;;3002;"";;;"Datum 30.02."',                                                                      // 13
    '300,00;"S";"EUR";;;;10001;8100;;1910;"RX1";;;"Rechnung aus DATEV"',                                                          // 14: Forderung (Soll auf Debitor)
];
$f = $write($lines);
$p = md_datev_parse($f);
$check('Kopfzeile: Mandant, Zeitraum, Kontolänge', [$p['meta']['client'], $p['meta']['from'], $p['meta']['to'], $p['meta']['account_length']], ['55003', "$Y-01-01", "$Y-12-31", 4]);
$check('Zeilen gelesen', count($p['rows']), 12);
$r = $p['rows'];
$check('Betrag, Datum (TTMM + Jahr der Datei), Konten', [$r[0]['cents'], $r[0]['date'], $r[0]['konto'], $r[0]['gegen'], $r[0]['beleg']], [12000, "$Y-10-15", '10003', '1200', $open['reference']]);
$check('Fehlerzeilen erkannt', [$r[6]['error'], $r[7]['error'], $r[8]['error'], $r[9]['error'], $r[10]['error'], $r[11]['error']], ['', 'Währung USD wird nicht unterstützt', 'Betrag ungültig', 'Soll/Haben-Kennzeichen fehlt', 'Belegdatum ungültig', '']);
$check('Prüfsummen eindeutig', count(array_unique(array_column($r, 'fingerprint'))), 12);
$dupLines = [$head(), $cols, '10,00;"H";"EUR";;;;10002;1200;;1510;"";;;"gleich"', '10,00;"H";"EUR";;;;10002;1200;;1510;"";;;"gleich"'];
$pd = md_datev_parse($write($dupLines, 'dup'));
$check('zwei gleiche Zeilen haben verschiedene Prüfsummen', $pd['rows'][0]['fingerprint'] !== $pd['rows'][1]['fingerprint'], true);
$check('Jahreswechsel: Dezember/Januar im richtigen Jahr', (function () use ($write, $head, $cols) {
    $pj = md_datev_parse($write([$head('20251101', '20260131'), $cols, '1,00;"H";"EUR";;;;1;2;;2012;"";;;"d"', '1,00;"H";"EUR";;;;1;2;;1501;"";;;"j"'], 'jw'));
    return [$pj['rows'][0]['date'], $pj['rows'][1]['date']];
})(), ['2025-12-20', '2026-01-15']);
$noHead = md_datev_parse($write([$head(), '5,00;"H";"EUR";;;;10002;1200;;1510;"";;;"ohne Überschriften"'], 'nh'));
$check('ohne Spaltenzeile: Standardreihenfolge', [$noHead['rows'][0]['cents'], $noHead['rows'][0]['text']], [500, 'ohne Überschriften']);

md_db()->beginTransaction();
md_perm_cache(true);
echo "-- Vorschau\n";
$opts = ['claims' => false, 'in_kind' => 'beitrag', 'out_kind' => 'erstattung'];
$plan = md_datev_plan($p, $opts);
$by_ = fn($row) => array_values(array_filter($plan, fn($x) => $x['row'] === $row))[0];
$a = $by_(3);
$check('Zahlung mit Rechnungsnummer: sicher, bezahlt Forderung', [$a['action'], $a['level'], $a['member_id'], $a['entry_ids']], ['pay', 'sure', $lukas, [(int)$open['entry_id']]]);
$a = $by_(4);
$check('Seitenumkehr (Gegenkonto = Personenkonto): Vorschlag über gleichen Betrag', [$a['action'], $a['level'], $a['member_id'], $a['entry_ids']], ['pay', 'suggest', $mia, [(int)$miaOpen['entry_id']]]);
$a = $by_(5);
$check('Spende über Kontenregel 8200', [$a['action'], $a['kind'], $a['level'], $a['member_id']], ['new_paid', 'spende', 'suggest', $erika]);
$a = $by_(6);
$check('Zahlung an Kreditor: Auszahlung Erstattung', [$a['action'], $a['kind'], $a['member_id']], ['new_out', 'erstattung', $hans]);
$a = $by_(7);
$check('unbekanntes Konto: Mitglied zuordnen', [$a['action'], $a['level'], $a['member_id']], ['skip', 'manual', null]);
$p8 = md_datev_parse($write([$head(), $cols, '40,00;"H";"EUR";;;;19998;1200;;1810;"' . $open['reference'] . '";;;"Zahlung ' . $open['reference'] . ' ohne Personenkonto"'], 'p8'));
$a = md_datev_plan($p8, $opts)[0];
$check('Rechnungsnummer ohne Personenkonto, Betrag weicht ab: nicht automatisch', [$a['action'], $a['level'], str_contains($a['note'], 'weicht ab')], ['skip', 'manual', true]);
$p9 = md_datev_parse($write([$head(), $cols, '120,00;"H";"EUR";;;;19998;1200;;1810;"' . $open['reference'] . '";;;"Zahlung"'], 'p9'));
$a = md_datev_plan($p9, $opts)[0];
$check('Rechnungsnummer ohne Personenkonto, Betrag passt: Vorschlag mit Mitglied', [$a['action'], $a['level'], $a['member_id']], ['pay', 'suggest', $lukas]);
$check('Fehlerzeilen nicht übernommen', array_values(array_unique(array_map(fn($x) => $x['level'], array_filter($plan, fn($x) => in_array($x['row'], [10, 11, 12, 13], true))))), ['skip']);
$check('Forderung aus DATEV: ohne Option nicht übernommen', [$by_(14)['action'], str_contains($by_(14)['note'], 'Rechnungen kommen aus der Mitgliederverwaltung')], ['skip', true]);
$check('… mit Option als offene Forderung', (function () use ($p, $opts, $by_) { $pl = md_datev_plan($p, array_merge($opts, ['claims' => true])); $x = array_values(array_filter($pl, fn($y) => $y['row'] === 14))[0]; return [$x['action'], $x['kind'], $x['member_id'] ?? null]; })(), ['new_open', 'beitrag', $max]);
$check('manuelle Zuordnung (Zeile 7 → Hans) wird zur Buchung', (function () use ($p, $opts, $hans) { $pl = md_datev_plan($p, $opts, [7 => $hans]); $x = array_values(array_filter($pl, fn($y) => $y['row'] === 7))[0]; return [$x['action'], $x['member_id']]; })(), ['new_paid', $hans]);

echo "-- Übernehmen\n";
$before = (int)md_one('SELECT COUNT(*) c FROM md_ledger')['c'];
[$paid, $new, $errs, $iid] = md_datev_apply($p, $plan, [3, 4, 5, 6, 7, 8], [], 'test.csv', $max);
$check('Ergebnis (Zeilen 7 und 8 sind nicht übernehmbar)', [$paid, $new, $errs], [2, 2, []]);
$check('Rechnung Lukas bezahlt am Belegdatum', array_values(md_one('SELECT status, paid_date FROM md_ledger WHERE entry_id = ?', [$open['entry_id']])), ['paid', "$Y-10-15"]);
$check('Rechnung Mia bezahlt', md_one('SELECT status FROM md_ledger WHERE entry_id = ?', [$miaOpen['entry_id']])['status'], 'paid');
$sp = md_one("SELECT * FROM md_ledger WHERE member_id = ? AND source = 'datev' AND kind = 'spende'", [$erika]);
$check('Spende als bezahlte Buchung', [$sp['status'], (int)$sp['amount_cents'], $sp['direction'], $sp['paid_date']], ['paid', 5000, 'to_club', "$Y-10-17"]);
$er = md_one("SELECT * FROM md_ledger WHERE member_id = ? AND source = 'datev' AND kind = 'erstattung'", [$hans]);
$check('Erstattung an Mitglied', [$er['direction'], (int)$er['amount_cents'], $er['status'], $er['reference']], ['to_member', 12500, 'paid', 'K1']);
$check('Buchungen insgesamt +2', (int)md_one('SELECT COUNT(*) c FROM md_ledger')['c'] - $before, 2);
$check('Protokoll im Mitgliedskonto', (int)md_one("SELECT COUNT(*) c FROM md_changelog WHERE member_id = ? AND field_key = 'ledger'", [$erika])['c'] >= 1, true);
$check('Importprotokoll', (function ($i) { return [(int)$i['paid_count'], (int)$i['created_count'], $i['file_name']]; })(md_one('SELECT * FROM md_datev_imports WHERE import_id = ?', [$iid])), [2, 2, 'test.csv']);
$plan2 = md_datev_plan($p, $opts);
$check('zweiter Import derselben Datei: alles schon übernommen', [array_count_values(array_map(fn($x) => $x['level'], array_filter($plan2, fn($x) => in_array($x['row'], [3, 4, 5, 6], true))))], [['dup' => 4]]);
[$p2, $n2] = md_datev_apply($p, $plan2, [3, 4, 5, 6], [], 'test.csv', $max);
$check('… nichts doppelt gebucht', [$p2, $n2], [0, 0]);

echo "-- Zuordnen und Merken\n";
$plan3 = md_datev_plan($p, $opts, [7 => $hans]);
[$p3, $n3, $e3] = md_datev_apply($p, $plan3, [7], [7], 'test.csv', $max);
$check('manuell zugeordnete Zeile gebucht', [$p3, $n3, $e3], [0, 1, []]);
$check('Konto 19999 gemerkt', md_datev_account_of('19999')['member_id'] ?? null, $hans);
$check('gemerktes Konto hat Art Debitor', md_datev_account_of('19999')['type'], 'debitor');
echo "-- Personenkonten\n";
$check('Konto ungültig', md_datev_account_save('ABC', $max), 'Kontonummer ungültig.');
$check('Mitglied unbekannt', md_datev_account_save('12345', 999999), 'Mitglied nicht gefunden.');
[$bn, $be] = md_datev_accounts_bulk("20001;DEMO0001\n20002;GIBTSNICHT\n\n20003;DEMO0003");
$check('Liste einfügen', [$bn, count($be)], [2, 1]);
$check('Regel anlegen / ungültig', [md_datev_kind_rule_save('9000', '9100', 'gebuehr'), md_datev_kind_rule_save('9100', '9000', 'gebuehr'), md_datev_kind_rule_save('9000', '9100', 'unsinn')], ['', 'Kontonummern ungültig (von ≤ bis).', 'Buchungsart unbekannt.']);
$check('Regel greift', md_datev_kind_for('9050', 'beitrag'), 'gebuehr');
echo "-- Lastschrift-Forderungen werden nicht angefasst\n";
md_exec("INSERT INTO md_ledger (member_id, kind, direction, amount_cents, entry_date, due_date, status, description, reference, created_at, source, sepa_item_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)", [$max, 'beitrag', 'to_club', 7700, date('Y-m-d'), date('Y-m-d'), 'open', 'SEPA-Test', 'RSEPA-1', md_now(), 'test', 99999]);
$ps = md_datev_parse($write([$head(), $cols, '77,00;"H";"EUR";;;;10001;1200;;1510;"RSEPA-1";;;"Zahlung RSEPA-1"'], 'sepa'));
$a = md_datev_plan($ps, $opts)[0];
$check('Forderung im Lastschriftlauf wird nicht per DATEV bezahlt', [$a['action'], $a['entry_ids']], ['new_paid', []]);

md_db()->rollBack();
foreach (glob("$tmp-*.csv") as $x) unlink($x);
echo $fail ? "\n$fail Fehler\n" : "\nalles ok\n";
exit($fail ? 1 : 0);
