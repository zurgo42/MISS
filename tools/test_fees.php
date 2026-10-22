<?php
/**
 * test_fees.php – prüft Beitragsgruppen, Beitragslauf, Rechnungen, Mahnwesen und Zahlungszuordnung an der Demo-Datenbank.
 * Aufruf: php tools/test_fees.php   (nach php tools/demo_setup.php bzw. demo_load.php). Läuft in einer Transaktion und rollt zurück.
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
$max = $by('DEMO0001'); $lukas = $by('DEMO0003'); $sabine = $by('DEMO0006'); $petra = $by('DEMO0009'); $mia = $by('DEMO0011'); $hans = $by('DEMO0007');
$Y = (int)date('Y'); $N = $Y + 1;

echo "-- Zeiträume\n";
$check('Jahr', md_fee_period("$N"), ['rhythm' => 'yearly', 'start' => "$N-01-01", 'end' => "$N-12-31", 'months' => 12, 'label' => "$N"]);
$check('Halbjahr 2', [md_fee_period("$N-H2")['start'], md_fee_period("$N-H2")['end'], md_fee_period("$N-H2")['months']], ["$N-07-01", "$N-12-31", 6]);
$check('Quartal 1 bis 4', array_map(fn($q) => md_fee_period("$N-Q$q")['end'], [1, 2, 3, 4]), ["$N-03-31", "$N-06-30", "$N-09-30", "$N-12-31"]);
$check('Monat (Februar)', [md_fee_period("$N-02")['end'], md_fee_period("$N-02")['label']], [date('Y-m-t', strtotime("$N-02-01")), "Februar $N"]);
$check('ungültiger Zeitraum', [md_fee_period('2027-13'), md_fee_period('abc'), md_fee_period('2027-Q5')], [null, null, null]);
$p = md_fee_period("$N");
$check('Monate: Eintritt Mitte April zählt ab April', md_fee_months($p, "$N-04-15", null), 9);
$check('Monate: Austritt Ende Juni zählt bis Juni', md_fee_months($p, null, "$N-06-30"), 6);
$check('Monate: Austritt davor = 0', md_fee_months($p, null, ($N - 1) . '-12-31'), 0);

echo "-- Beitragslauf Vorschau\n";
$plan = md_fee_run_plan((string)$N);
$by_ = fn($mid) => array_values(array_filter($plan, fn($r) => $r['member_id'] === $mid))[0];
$create = array_filter($plan, fn($r) => $r['action'] === 'create');
$check('Demo: 11 Rechnungen, Summe 1.110,00 €', [count($create), array_sum(array_column($create, 'cents'))], [11, 111000]);
$check('Abweichender Betrag (Sabine 90 €)', [$by_($sabine)['cents'], str_contains($by_($sabine)['note'], 'abweichender Betrag')], [9000, true]);
$check('Prozent-Ermäßigung (Petra 50 %)', [$by_($petra)['cents'], str_contains($by_($petra)['note'], '50 % Ermäßigung')], [6000, true]);
$check('Ermäßigte Gruppe (Mia 60 €)', $by_($mia)['cents'], 6000);
$rolf = $by('DEMO0012');
$check('Ausgetretener wird übersprungen', $by_($rolf)['action'], 'skip');
$check('laufendes Jahr: schon berechnet, nichts doppelt', count(array_filter(md_fee_run_plan((string)$Y), fn($r) => $r['action'] === 'create')), 0);
$check('anderer Rhythmus: Quartalslauf berechnet niemanden (alle jährlich)', count(array_filter(md_fee_run_plan("$N-Q1"), fn($r) => $r['action'] === 'create')), 0);

md_db()->beginTransaction();
echo "-- Gruppen und Zuordnung\n";
$check('Gruppe ohne Betrag abgelehnt', md_fee_group_save(null, ['name' => 'X', 'amount' => 'abc']), 'Betrag ungültig (z. B. 120,00; 0 für beitragsfrei).');
$check('Doppelter Name abgelehnt', md_fee_group_save(null, ['name' => 'vollmitglied', 'amount' => '10']), 'Es gibt schon eine Gruppe mit diesem Namen.');
$check('Gruppe anlegen (beitragsfrei 0)', md_fee_group_save(null, ['name' => 'TEST-Frei', 'amount' => '0', 'active' => 1]), '');
$gFull = (int)md_one("SELECT group_id FROM md_fee_groups WHERE name = 'Vollmitglied'")['group_id'];
$gErm = (int)md_one("SELECT group_id FROM md_fee_groups WHERE name = 'Ermäßigt'")['group_id'];
$check('Abweichung ohne Grund abgelehnt', md_fee_assign($hans, $gFull, ['override' => '50', 'valid_from' => "01.01.$N"], $max), 'Für eine Abweichung ist ein Grund erforderlich (Nachvollziehbarkeit).');
$check('Betrag und Prozent zugleich abgelehnt', md_fee_assign($hans, $gFull, ['override' => '50', 'discount' => '10', 'reason' => 'x', 'valid_from' => "01.01.$N"], $max), 'Entweder abweichender Betrag oder Prozent-Ermäßigung, nicht beides.');
$check('Zuordnung mit Grund', md_fee_assign($hans, $gErm, ['valid_from' => "01.01.$N", 'reason' => 'Rentner', 'discount' => '50', 'approved_by' => 'Vorstand'], $max), '');
$old = md_one('SELECT valid_to FROM md_member_fees WHERE member_id = ? AND valid_from < ? ORDER BY valid_from DESC LIMIT 1', [$hans, "$N-01-01"]);
$check('bisherige Zuordnung endet am Vortag', $old['valid_to'], ($N - 1) . '-12-31');
$a = md_fee_assignment($hans, "$N-01-01", "$N-12-31");
$check('Zeitraum nutzt die neue Zuordnung', [$a['group_name'], (int)$a['discount_percent']], ['Ermäßigt', 50]);
$check('Protokoll im Mitgliedskonto', (int)md_one("SELECT COUNT(*) c FROM md_changelog WHERE member_id = ? AND field_key = 'fee'", [$hans])['c'], 1);
$plan = md_fee_run_plan((string)$N);
$check('Hans: 50 % von 60 € = 30 €', array_values(array_filter($plan, fn($r) => $r['member_id'] === $hans))[0]['cents'], 3000);
// anteilig: neues Mitglied, Eintritt 15. April
md_exec("INSERT INTO svmembers (membership_number, first_name, last_name, email, role) VALUES ('TEST9001', 'Neu', 'Eintritt', 'neu.test@example.invalid', 'Mitglied')");
$neu = (int)md_db()->lastInsertId();
md_ensure_profile($neu);
md_exec("UPDATE md_profiles SET join_date = ?, status = 'aktiv' WHERE member_id = ?", ["$N-04-15", $neu]);
md_fee_assign($neu, $gFull, ['valid_from' => "$N-04-15"], $max);
$row = array_values(array_filter(md_fee_run_plan((string)$N), fn($r) => $r['member_id'] === $neu))[0];
$check('anteilig: Eintritt 15.04. → 9/12 von 120 € = 90 €', [$row['months'], $row['cents'], $row['action']], [9, 9000, 'create']);
md_exec("DELETE FROM md_member_fees WHERE member_id = ?", [$neu]);
$check('ohne Beitragsgruppe: Warnung, keine Rechnung', array_values(array_filter(md_fee_run_plan((string)$N), fn($r) => $r['member_id'] === $neu))[0]['action'], 'warn');
md_exec("DELETE FROM md_profiles WHERE member_id = ?", [$neu]); md_exec("DELETE FROM svmembers WHERE member_id = ?", [$neu]);

echo "-- Beitragslauf durchführen\n";
[$rid, $err] = md_fee_run_apply((string)$N, '30.01.' . $N, $max, 'Test');
$check('Lauf angelegt', [(bool)$rid, $err], [true, '']);
$run = md_one('SELECT * FROM md_fee_runs WHERE run_id = ?', [$rid]);
$check('Lauf dokumentiert (Anzahl, Summe, Fälligkeit)', [(int)$run['entries'], (int)$run['total_cents'], $run['due_date']], [11, 102000, "$N-01-30"]);   // Hans jetzt 30 € statt 120 €
$ents = md_q("SELECT * FROM md_ledger WHERE run_id = ? ORDER BY entry_id", [$rid]);
$check('11 Forderungen, offen, mit Zeitraum und Fälligkeit', [count($ents), array_unique(array_column($ents, 'status')), array_unique(array_column($ents, 'fee_period')), array_unique(array_column($ents, 'due_date'))], [11, ['open'], ["$N"], ["$N-01-30"]]);
$refs = array_column($ents, 'reference');
$check('Rechnungsnummern fortlaufend R<Jahr>-0001…', [$refs[0], $refs[10]], [sprintf('R%d-0001', $Y), sprintf('R%d-0011', $Y)]);
$check('Nummern eindeutig', count(array_unique($refs)), 11);
$check('zweiter Lauf desselben Zeitraums: nichts mehr', md_fee_run_apply((string)$N, '30.01.' . $N, $max)[1], 'Nichts zu berechnen.');
$check('Protokoll je Mitglied', (int)md_one("SELECT COUNT(*) c FROM md_changelog WHERE member_id = ? AND field_key = 'ledger' AND new_value LIKE ?", [$max, "%{$refs[0]}%"])['c'] >= 0, true);
$e0 = md_one('SELECT l.*, s.membership_number FROM md_ledger l JOIN svmembers s ON s.member_id = l.member_id WHERE l.entry_id = ?', [$ents[0]['entry_id']]);
$conf = md_fee_conf(); $conf['iban'] = 'DE02 1234 5678 9012 3456 78';
$html = md_invoice_html($e0, md_club(), $conf, md_letter_recipient((int)$e0['member_id']) ?? ['name' => 'Test', 'lines' => ['x']]);
$check('Rechnung enthält Nummer, Betrag, IBAN, Zahlungsziel', [str_contains($html, $e0['reference']), str_contains($html, md_money((int)$e0['amount_cents'])), str_contains($html, 'DE02 1234'), str_contains($html, md_date($e0['due_date']))], [true, true, true, true]);

echo "-- Mahnwesen\n";
$levels = md_dunning_levels();
$check('drei Stufen eingerichtet', array_map(fn($l) => $l['name'], array_values($levels)), ['Zahlungserinnerung', '1. Mahnung', '2. Mahnung']);
$today = date('Y-m-d');
$cands = md_dunning_candidates($today);
$check('Lukas (45 Tage überfällig) ist mahnfähig, Mia (noch nicht fällig) nicht', [isset($cands[$lukas]), isset($cands[$mia])], [true, false]);
$check('Lukas: Stufe 1 (Zahlungserinnerung), keine Gebühr', [$cands[$lukas]['level'], $cands[$lukas]['fee_cents'], count($cands[$lukas]['items'])], [1, 0, 1]);
$itemId = (int)$cands[$lukas]['items'][0]['entry_id'];
$check('Stundung ohne Grund abgelehnt', md_dunning_defer($itemId, date('d.m.Y', strtotime('+10 days')), '', $max), 'Bitte einen Grund für die Stundung angeben.');
$check('Stundung', md_dunning_defer($itemId, date('d.m.Y', strtotime('+10 days')), 'Ratenzahlung vereinbart', $max), '');
$check('gestundet: nicht mahnfähig', isset(md_dunning_candidates($today)[$lukas]), false);
$check('nach Ablauf der Stundung wieder mahnfähig', isset(md_dunning_candidates(date('Y-m-d', strtotime('+11 days')))[$lukas]), true);
md_exec('UPDATE md_ledger SET deferred_until = NULL, deferred_reason = NULL WHERE entry_id = ?', [$itemId]);
[$l1, $e1] = md_dunning_issue($lukas, [$itemId], $max, $today);
$L1 = md_one('SELECT * FROM md_dunning_letters WHERE letter_id = ?', [$l1]);
$check('Schreiben Stufe 1 ausgestellt', [(bool)$l1, $e1, (int)$L1['level'], (int)$L1['fee_cents'], $L1['deadline']], [true, '', 1, 0, date('Y-m-d', strtotime('+14 days'))]);
$check('Schreibennummer M<Jahr>-0001', $L1['letter_no'], sprintf('M%d-0001', $Y));
$it = md_one('SELECT dunning_level, last_dunned_at FROM md_ledger WHERE entry_id = ?', [$itemId]);
$check('Mahnstufe an der Forderung', [(int)$it['dunning_level'], $it['last_dunned_at']], [1, $today]);
$check('sofort nicht noch einmal mahnfähig', isset(md_dunning_candidates($today)[$lukas]), false);
$html = md_dunning_letter_html($L1);
$check('Schreiben: Anrede, Betrag, Frist, Nummer', [str_contains($html, 'Sehr geehrter Herr Beispiel'), str_contains($html, '120,00'), str_contains($html, md_date($L1['deadline'])), str_contains($html, $L1['letter_no'])], [true, true, true, true]);
$d2 = date('Y-m-d', strtotime('+22 days'));
$c2 = md_dunning_candidates($d2);
$check('Stufe 2 erst 21 Tage nach der Erinnerung', [isset(md_dunning_candidates(date('Y-m-d', strtotime('+20 days')))[$lukas]), $c2[$lukas]['level'], $c2[$lukas]['fee_cents']], [false, 2, 500]);
[$l2, $e2] = md_dunning_issue($lukas, [$itemId], $max, $d2);
$L2 = md_one('SELECT * FROM md_dunning_letters WHERE letter_id = ?', [$l2]);
$check('1. Mahnung: Gebühr 5 €, Gesamt 125 €', [(int)$L2['level'], (int)$L2['fee_cents'], (int)$L2['total_cents']], [2, 500, 12500]);
$fee = md_one("SELECT * FROM md_ledger WHERE member_id = ? AND kind = 'gebuehr' AND status = 'open'", [$lukas]);
$check('Mahngebühr als Forderung gebucht', [(int)$fee['amount_cents'], $fee['reference'], $fee['source']], [500, $L2['letter_no'], 'dunning']);
$d3 = date('Y-m-d', strtotime('+44 days'));
[$l3] = md_dunning_issue($lukas, [$itemId], $max, $d3);
$L3 = md_one('SELECT * FROM md_dunning_letters WHERE letter_id = ?', [$l3]);
$check('2. Mahnung: Gebühr 10 €, enthält frühere Gebühr: 120 + 5 + 10 = 135 €', [(int)$L3['level'], (int)$L3['total_cents']], [3, 13500]);
$check('nach der letzten Stufe keine weitere Mahnung dieser Forderung', in_array($itemId, array_map(fn($i) => (int)$i['entry_id'], md_dunning_candidates(date('Y-m-d', strtotime('+200 days')))[$lukas]['items'] ?? [])), false);
$check('Storno der 2. Mahnung: Gebühr storniert, Stufe zurück', [md_dunning_void($l3, 'Test', $max), (int)md_one('SELECT dunning_level FROM md_ledger WHERE entry_id = ?', [$itemId])['dunning_level']], ['', 2]);
$check('Storno ohne Grund abgelehnt', md_dunning_void($l2, ' ', $max), 'Bitte einen Grund angeben.');
$check('Storno 1. Mahnung: Stufe 1, Gebühr storniert', [md_dunning_void($l2, 'Test', $max), (int)md_one('SELECT dunning_level FROM md_ledger WHERE entry_id = ?', [$itemId])['dunning_level'], (int)md_one("SELECT COUNT(*) c FROM md_ledger WHERE member_id = ? AND kind = 'gebuehr' AND status = 'open'", [$lukas])['c']], ['', 1, 0]);
$check('Mahnung ohne Anschrift (Mia) wird abgelehnt', md_dunning_issue($mia, [(int)md_one("SELECT entry_id FROM md_ledger WHERE member_id = ? AND status = 'open' AND kind = 'beitrag' LIMIT 1", [$mia])['entry_id']], $max, date('Y-m-d', strtotime('+100 days')))[1], 'Für dieses Mitglied fehlt eine vollständige Anschrift (Straße, PLZ, Ort).');

echo "-- Zahlungszuordnung\n";
$claim = md_one("SELECT * FROM md_ledger WHERE member_id = ? AND status = 'open' AND kind = 'beitrag' AND fee_period = ?", [$lukas, (string)$Y]);
$ref = $claim['reference'];
$refMia = md_one("SELECT reference FROM md_ledger WHERE member_id = ? AND status = 'open' AND kind = 'beitrag' AND fee_period = ?", [$mia, (string)$Y])['reference'];
$rows = [
    ['10.03.2026', '120,00', "Mitgliedsbeitrag $ref Beispiel", 'Lukas Beispiel'],
    ['11.03.2026', '99,00', "Beitrag " . str_replace('-', ' ', $refMia) . " falscher Betrag", 'X'],
    ['11.03.2026', '120,00', "Beitrag " . $ref . '9 mitten in längerer Nummer', 'X2'],
    ['12.03.2026', '-50,00', 'Überweisung Miete', 'Y'],
    ['13.03.2026', '5,00', 'Spende ohne Bezug', 'Z'],
    ['14.03.2026', 'abc', 'kaputt', ''],
];
$map = ['date' => 0, 'amount' => 1, 'purpose' => 2, 'payer' => 3];
$pp = md_payment_plan($rows, $map);
$check('Zeilen: sicher, Betrag weicht ab, Ausgang, nicht zugeordnet, Fehler', array_column($pp, 'status'), ['sure', 'diff', 'none', 'debit', 'none', 'error']);
$check('Nummer mit Leerzeichen statt Bindestrich wird erkannt, Betrag abweichend', [$pp[1]['status'], str_contains($pp[1]['note'], 'weicht')], ['diff', true]);
$check('Treffer mitten in längerer Nummer zählt nicht', $pp[2]['status'], 'none');
[$n, $perr] = md_payment_apply($pp, [2], $max);
$check('Zahlung vermerkt', [$n, $perr, md_one('SELECT status, paid_date FROM md_ledger WHERE entry_id = ?', [$claim['entry_id']])], [1, [], ['status' => 'paid', 'paid_date' => '2026-03-10']]);
$check('Zweiter Import derselben Zahlung: nicht mehr zuordenbar', md_payment_plan([$rows[0]], $map)[0]['status'], 'none');
$check('Spaltenvorschlag für Kontoauszug', md_payment_guess_mapping(['Buchungstag', 'Auftraggeber/Zahlungsempfänger', 'Verwendungszweck', 'Betrag']), ['date' => 0, 'amount' => 3, 'purpose' => 2]);

echo "-- Rechte\n";
$erika = md_one("SELECT * FROM svmembers WHERE membership_number = 'DEMO0002'");
$karl = md_one("SELECT * FROM svmembers WHERE membership_number = 'DEMO0010'");
$hansRow = md_one('SELECT * FROM svmembers WHERE member_id = ?', [$hans]);
md_perm_cache(true);
$check('Erika (FVv): Beiträge bearbeiten', md_perm($erika, 'fees'), 'edit');
$check('Karl (FP): ansehen', md_perm($karl, 'fees'), 'view');
$check('Hans (RL): keine', md_perm($hansRow, 'fees'), 'none');
md_db()->rollBack();
exit($fail ? 1 : 0);
