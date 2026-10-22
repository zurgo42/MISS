<?php
/**
 * test_permissions.php – prüft Rechtetabelle, Zustimmungs-Sperre und Buchungslogik an der Demo-Datenbank.
 * Aufruf: php tools/test_permissions.php   (nach php tools/demo_setup.php bzw. demo_load.php)
 * Räumt seine Testbuchungen am Ende selbst auf; läuft nur mit MD_DEMO_MODE=true.
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
$by = fn(string $nr) => md_one('SELECT * FROM svmembers WHERE membership_number = ?', [$nr]);
$max = $by('DEMO0001'); $erika = $by('DEMO0002'); $lukas = $by('DEMO0003'); $hans = $by('DEMO0007'); $karl = $by('DEMO0010'); $mia = $by('DEMO0011');

echo "-- Rechtetabelle\n";
$check('Erika (FVv): Finanzen bearbeiten', md_perm($erika, 'finance'), 'edit');
$check('Erika: Mitglieder nur ansehen', md_perm($erika, 'members'), 'view');
$check('Erika: kein Import, keine Einstellungen', [md_perm($erika, 'import'), md_perm($erika, 'settings')], ['none', 'none']);
$check('Hans (RL): nur Verzeichnis', [md_perm($hans, 'directory'), md_perm($hans, 'members'), md_perm($hans, 'finance')], ['view', 'none', 'none']);
$check('Karl (FP): Finanzen ansehen, nicht bearbeiten', [md_perm($karl, 'finance'), md_can($karl, 'finance', 'edit')], ['view', false]);
$check('Admin-Flag (Max): immer alles', [md_perm($max, 'settings'), md_perm($max, 'import')], ['edit', 'edit']);
$check('Lukas: abgelaufene PL-Funktion zählt nicht', md_perm($lukas, 'import'), 'none');

md_db()->beginTransaction();
md_exec('INSERT INTO md_member_functions (member_id, function_id, valid_from) VALUES (?,?,?)', [$hans['member_id'], (int)md_one("SELECT function_id FROM md_functions WHERE code='MB'")['function_id'], date('Y-m-d', strtotime('-1 day'))]);
md_exec('INSERT INTO md_member_functions (member_id, function_id, valid_from) VALUES (?,?,?)', [$hans['member_id'], (int)md_one("SELECT function_id FROM md_functions WHERE code='FP'")['function_id'], date('Y-m-d', strtotime('-1 day'))]);
md_perm_cache(true);
$check('mehrere Funktionen: höchste Stufe je Bereich (MB+FP)', [md_perm($hans, 'members'), md_perm($hans, 'finance'), md_perm($hans, 'import')], ['edit', 'view', 'edit']);
md_exec("UPDATE md_permissions SET level = 'none' WHERE subject = 'FP' AND area = 'finance'");
md_perm_cache(true);
$check('Tabelle ist änderbar (FP Finanzen → keine, Hans verliert es)', md_perm($hans, 'finance'), 'none');
md_db()->rollBack();

echo "-- Zustimmung\n";
$check('Max hat zugestimmt', md_consent_ok((int)$max['member_id']), true);
$check('Lukas (alte Fassung): keine Zustimmung', md_consent_ok((int)$lukas['member_id']), false);
$check('Mia (nie zugestimmt): keine Zustimmung', md_consent_ok((int)$mia['member_id']), false);
$vmax = md_visibility_map((int)$max['member_id']);
$check('Ohne Zustimmung sieht man fremde Felder nicht', md_can_see($lukas, (int)$max['member_id'], 'contact.linkedin', $vmax), false);
$check('… die eigenen aber schon', md_can_see($lukas, (int)$lukas['member_id'], 'birthdate', md_visibility_map((int)$lukas['member_id'])), true);
$check('Mit Zustimmung: freigegebenes Feld sichtbar', md_can_see($erika, (int)$max['member_id'], 'contact.linkedin', $vmax), true);
$check('Mit Zustimmung: "Nur ich" bleibt verborgen', md_can_see($erika, (int)$max['member_id'], 'contact.fax', $vmax), false);

echo "-- Beträge\n";
foreach ([['1.234,56', 123456], ['1234.56', 123456], ['12,5 €', 1250], ['-12,50', 1250], ['0', null], ['abc', null], ['1.234', null], ['120', 12000]] as [$in, $want]) $check("Betrag \"$in\"", md_parse_amount($in), $want);

echo "-- Buchungen\n";
$mid = (int)$erika['member_id'];
$cleanup = function () { md_exec("DELETE FROM md_ledger WHERE description LIKE 'TEST-%'"); md_exec("DELETE FROM md_changelog WHERE new_value LIKE '%TEST-%' OR old_value LIKE '%TEST-%'"); };
$cleanup();
[$id, $err] = md_ledger_add(['member_id' => $mid, 'kind' => 'spende', 'amount' => '25,00', 'entry_date' => '01.03.2026', 'description' => 'TEST-Spende', 'status' => 'paid'], (int)$max['member_id']);
$check('Buchung angelegt', [(bool)$id, $err], [true, '']);
$row = md_one('SELECT * FROM md_ledger WHERE entry_id = ?', [$id]);
$check('Spende: Richtung an Verein, bezahlt, Cent', [$row['direction'], $row['status'], $row['amount_cents'], $row['paid_date']], ['to_club', 'paid', 2500, '2026-03-01']);
$check('Ungültiger Betrag wird abgelehnt', md_ledger_add(['member_id' => $mid, 'kind' => 'spende', 'amount' => 'x', 'entry_date' => '01.03.2026', 'description' => 'TEST-x'], 1)[1], 'Betrag ungültig.');
$check('Unbekannte Art wird abgelehnt', md_ledger_add(['member_id' => $mid, 'kind' => 'bar', 'amount' => '5', 'entry_date' => '01.03.2026', 'description' => 'TEST-x'], 1)[1], 'Unbekannte Art.');
[$id2] = md_ledger_add(['member_id' => $mid, 'kind' => 'erstattung', 'amount' => '10', 'entry_date' => '02.03.2026', 'description' => 'TEST-Erstattung'], 1);
$check('Erstattung: Richtung an Mitglied, offen', md_one('SELECT direction, status FROM md_ledger WHERE entry_id = ?', [$id2]), ['direction' => 'to_member', 'status' => 'open']);
$check('Zahlung vermerken', [md_ledger_mark_paid($id2, '05.03.2026', 1), md_one('SELECT status, paid_date FROM md_ledger WHERE entry_id = ?', [$id2])], ['', ['status' => 'paid', 'paid_date' => '2026-03-05']]);
$check('Storno ohne Grund abgelehnt', md_ledger_cancel($id2, '  ', 1), 'Bitte einen Grund für den Storno angeben.');
$check('Storno mit Grund', [md_ledger_cancel($id2, 'doppelt erfasst', 1), md_one('SELECT status, cancel_reason FROM md_ledger WHERE entry_id = ?', [$id2])], ['', ['status' => 'cancelled', 'cancel_reason' => 'doppelt erfasst']]);
$check('Buchungen stehen im Protokoll', (int)md_one("SELECT COUNT(*) c FROM md_changelog WHERE member_id = ? AND field_key = 'ledger' AND new_value LIKE '%TEST-%'", [$mid])['c'] >= 2, true);

echo "-- Buchungsimport\n";
$rows = [
    ['DEMO0002', 'Beitrag', '120,00', '15.01.2026', 'TEST-Beitrag A', 'TB-1', 'bezahlt'],
    ['DEMO0002', 'Beitrag', '120,00', '15.01.2026', 'TEST-Beitrag A', 'TB-1', 'bezahlt'],      // doppelt in Datei
    ['XXX', 'Beitrag', '10,00', '15.01.2026', 'TEST-unbekannt', '', ''],                         // Mitglied fehlt
    ['DEMO0004', 'Gespendet', '10,00', '15.01.2026', 'TEST-art', '', ''],                        // Art unbekannt
    ['DEMO0004', 'Spende', '1.000,00', '16.01.2026', 'TEST-Spende groß', '', 'offen'],
    ['DEMO0004', 'Übungsleiterpauschale', '300', '30.03.2026', 'TEST-ÜL', '', ''],
    ['DEMO0004', 'Spende', 'zehn', '16.01.2026', 'TEST-betrag', '', ''],
];
$map = ['membership_number' => 0, 'kind' => 1, 'amount' => 2, 'entry_date' => 3, 'description' => 4, 'reference' => 5, 'status' => 6];
$plan = md_ledger_import_plan($rows, $map, []);
$check('Plan: gültig/Fehler', array_map(fn($p) => $p['action'], $plan), ['create', 'error', 'error', 'error', 'create', 'create', 'error']);
$check('Plan: Übungsleiter-Alias, Richtung', $plan[5]['data']['kind'], 'uebungsleiter');
$check('Plan: Status offen aus Spalte', $plan[4]['data']['status'], 'open');
[$n, $skip] = md_ledger_import_apply($plan, (int)$max['member_id']);
$check('Import schreibt gültige Zeilen', [$n, $skip], [3, 4]);
$check('Zweiter Plan erkennt Dubletten (Beleg bzw. gleiche Buchung)', array_map(fn($p) => $p['action'], md_ledger_import_plan([$rows[0], $rows[4]], $map, [])), ['error', 'error']);
$check('Standardart ersetzt fehlende Spalte', md_ledger_import_plan([['DEMO0004', '5,00', '01.02.2026', 'TEST-Std']], ['membership_number' => 0, 'amount' => 1, 'entry_date' => 2, 'description' => 3], ['default_kind' => 'spende'])[0]['action'], 'create');
$cleanup();
exit($fail ? 1 : 0);
