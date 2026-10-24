<?php
/**
 * test_events.php – prüft Veranstaltungen: Anlegen, Anmelden, Warteliste, Teilnehmerliste mit Sichtbarkeit, Gebühren, Absage.
 * Aufruf: php tools/test_events.php   (nach demo_setup.php bzw. demo_load.php). Läuft in einer Transaktion und rollt zurück.
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
$max = $by('DEMO0001'); $erika = $by('DEMO0002'); $lukas = $by('DEMO0003'); $julia = $by('DEMO0004'); $thomas = $by('DEMO0005'); $sabine = $by('DEMO0006'); $hans = $by('DEMO0007'); $karl = $by('DEMO0010');
$mailDir = MD_STORAGE_DIR . '/mail';
$before = glob("$mailDir/*.eml") ?: [];
$in = fn(int $d, string $t = '12:00') => date('d.m.Y', strtotime("+$d days")) . " $t";

echo "-- Datum\n";
$check('Eingabe TT.MM.JJJJ HH:MM', md_event_parse_dt('05.03.2027 18:30'), '2027-03-05 18:30:00');
$check('Eingabe datetime-local', md_event_parse_dt('2027-03-05T18:30'), '2027-03-05 18:30:00');
$check('ungültig', [md_event_parse_dt('31.02.2027 10:00'), md_event_parse_dt('05.03.2027'), md_event_parse_dt('05.03.2027 25:00'), md_event_parse_dt('')], [null, null, null, null]);

md_db()->beginTransaction();
md_perm_cache(true);
echo "-- Anlegen\n";
$ok = ['title' => 'Test-Treffen', 'starts_at' => $in(20), 'capacity' => '2', 'price' => '10,00', 'location' => 'Raum 1'];
$check('Titel fehlt', md_event_save(null, array_merge($ok, ['title' => ' ']), $karl)[1], 'Titel fehlt oder ist zu lang (höchstens 150 Zeichen).');
$check('Beginn in der Vergangenheit', md_event_save(null, array_merge($ok, ['starts_at' => '01.01.2020 10:00']), $karl)[1], 'Der Beginn muss in der Zukunft liegen.');
$check('Ende vor Beginn', md_event_save(null, array_merge($ok, ['ends_at' => $in(19)]), $karl)[1], 'Das Ende liegt vor dem Beginn.');
$check('Anmeldeschluss nach Beginn', md_event_save(null, array_merge($ok, ['reg_deadline' => $in(21)]), $karl)[1], 'Der Anmeldeschluss liegt nach dem Beginn.');
$check('Teilnehmerzahl ungültig', md_event_save(null, array_merge($ok, ['capacity' => '0']), $karl)[1], 'Teilnehmerzahl: ganze Zahl ab 1 oder leer für unbegrenzt.');
$check('Gebühr ungültig', md_event_save(null, array_merge($ok, ['price' => 'viel']), $karl)[1], 'Teilnahmegebühr ungültig (z. B. 12,50; leer für kostenlos).');
[$ev, $e] = md_event_save(null, $ok, $karl);
$check('angelegt (jedes Mitglied)', [(bool)$ev, $e], [true, '']);
$E = md_event_get((int)$ev);
$check('Veranstalter, Status, Gebühr', [(int)$E['organizer_id'], $E['status'], (int)$E['price_cents'], (int)$E['capacity']], [$karl, 'open', 1000, 2]);
$check('Veranstalter verwaltet, Fremder nicht', [md_event_can_manage($E, md_one('SELECT * FROM svmembers WHERE member_id = ?', [$karl])), md_event_can_manage($E, md_one('SELECT * FROM svmembers WHERE member_id = ?', [$hans]))], [true, false]);
$check('Verwaltung (Recht events) verwaltet fremde Veranstaltungen', md_event_can_manage($E, md_one('SELECT * FROM svmembers WHERE member_id = ?', [$julia])), true);

echo "-- Anmelden\n";
$check('Anmeldung A (sichtbar)', md_event_register((int)$ev, $max, true, 'bringe Kuchen', $max), ['registered', '']);
$check('doppelt abgelehnt', md_event_register((int)$ev, $max, true, null, $max)[1], 'Du bist schon angemeldet.');
$check('Anmeldung B (unsichtbar)', md_event_register((int)$ev, $julia, false, null, $julia), ['registered', '']);
$check('voll: Warteliste', md_event_register((int)$ev, $thomas, true, null, $thomas), ['waitlist', '']);
$check('Warteliste Nr. 2', md_event_register((int)$ev, $sabine, false, null, $sabine), ['waitlist', '']);
$check('Plätze', [md_event_counts((int)$ev), md_event_free($E)], [['registered' => 2, 'waitlist' => 2], 0]);
$claim = md_one("SELECT * FROM md_ledger WHERE member_id = ? AND source = 'event'", [$max]);
$check('Forderung für Angemeldeten (Art Eintritt, offen, 10 €)', [$claim['kind'], $claim['status'], (int)$claim['amount_cents'], str_starts_with($claim['reference'], 'EV' . $ev . '-')], ['eintritt', 'open', 1000, true]);
$check('keine Forderung auf der Warteliste', (int)md_one("SELECT COUNT(*) c FROM md_ledger WHERE member_id = ? AND source = 'event'", [$thomas])['c'], 0);
$check('Lastschrift zieht Veranstaltungsgebühren nicht ein', isset(md_sepa_candidates(date('Y-m-d', strtotime('+60 days')))[$max]) && count(array_filter(md_sepa_candidates(date('Y-m-d', strtotime('+60 days')))[$max]['entries'], fn($x) => $x['source'] === 'event')) > 0, false);

echo "-- Teilnehmerliste\n";
$names = fn($pl) => array_map(fn($r) => (int)$r['member_id'], $pl['rows']);
$full = md_event_participants((int)$ev, $karl, true);
$check('Veranstalter sieht alle (4 inkl. Warteliste)', [count($full['rows']), $full['rows'][0]['status']], [4, 'registered']);
$pj = md_event_participants((int)$ev, $julia, false);
$check('Julia (unsichtbar) sieht Max und sich selbst', [$names($pj), $pj['hidden']], [[$max, $julia], 0]);
$pm = md_event_participants((int)$ev, $max, false);
$check('Max sieht sich, aber nicht die unsichtbare Julia', [$names($pm), $pm['hidden']], [[$max], 1]);
$pt = md_event_participants((int)$ev, $thomas, false);
$check('Warteliste sieht die Liste auch, aber keine anderen Wartenden', [$names($pt), $pt['hidden']], [[$max, $thomas], 1]);
$pf = md_event_participants((int)$ev, $hans, false);
$check('Nicht Angemeldete sehen keine Namen, nur die Zahl', [$pf['rows'], $pf['hidden'], $pf['restricted'] ?? false], [[], 2, true]);
$check('Fremdangaben (Anmerkung, Nr.) nicht in der Mitgliederansicht', array_key_exists('note', $pj['rows'][0]) || array_key_exists('membership_number', $pj['rows'][0]), false);
$check('Sichtbarkeit ändern', [md_event_set_show((int)$ev, $julia, true, $julia), $names(md_event_participants((int)$ev, $max, false))], ['', [$max, $julia]]);
$check('… und zurück', [md_event_set_show((int)$ev, $julia, false, $julia), md_event_participants((int)$ev, $max, false)['hidden']], ['', 1]);
$check('Sichtbarkeit ohne Anmeldung', md_event_set_show((int)$ev, $hans, true, $hans), 'Keine Anmeldung gefunden.');

echo "-- Abmelden und Nachrücken\n";
$check('Max meldet sich ab', md_event_unregister((int)$ev, $max, $max), '');
$check('Forderung storniert', md_one('SELECT status FROM md_ledger WHERE entry_id = ?', [$claim['entry_id']])['status'], 'cancelled');
$check('Thomas rückt nach (Warteliste Nr. 1)', [md_one('SELECT status FROM md_event_regs WHERE event_id = ? AND member_id = ?', [$ev, $thomas])['status'], md_one('SELECT status FROM md_event_regs WHERE event_id = ? AND member_id = ?', [$ev, $sabine])['status']], ['registered', 'waitlist']);
$check('… mit Forderung', (int)md_one("SELECT COUNT(*) c FROM md_ledger WHERE member_id = ? AND source = 'event' AND status = 'open'", [$thomas])['c'], 1);
$mail = md_one("SELECT * FROM md_mail_outbox WHERE ref = ? AND member_id = ?", ['EV' . $ev, $thomas]);
$check('Nachrücker per E-Mail benachrichtigt', [$mail['status'] ?? null, str_contains($mail['subject'] ?? '', 'Platz frei')], ['sent', true]);
$check('erneute Abmeldung', md_event_unregister((int)$ev, $max, $max), 'Keine Anmeldung gefunden.');
$check('erneut anmelden möglich (Warteliste, da voll)', md_event_register((int)$ev, $max, false, null, $max)[0], 'waitlist');
md_exec("UPDATE md_ledger SET status = 'paid', paid_date = ? WHERE member_id = ? AND source = 'event' AND status = 'open'", [date('Y-m-d'), $thomas]);
$check('Abmeldung nach Bezahlung: Buchung bleibt', [md_event_unregister((int)$ev, $thomas, $thomas), (int)md_one("SELECT COUNT(*) c FROM md_ledger WHERE member_id = ? AND source = 'event' AND status = 'paid'", [$thomas])['c']], ['', 1]);
$check('Protokoll weist auf Erstattung hin', (int)md_one("SELECT COUNT(*) c FROM md_changelog WHERE member_id = ? AND new_value LIKE '%Erstattung%'", [$thomas])['c'], 1);

echo "-- Ändern\n";
$check('Gebühr ändern bei Anmeldungen abgelehnt', md_event_save((int)$ev, array_merge($ok, ['price' => '20,00']), $karl)[1], 'Die Teilnahmegebühr lässt sich nicht mehr ändern, weil es schon Anmeldungen gibt.');
$check('Plätze unter Angemeldete abgelehnt', str_starts_with(md_event_save((int)$ev, array_merge($ok, ['capacity' => '1']), $karl)[1], 'Es sind schon'), true);
$check('mehr Plätze lässt Warteliste nachrücken', [md_event_save((int)$ev, array_merge($ok, ['capacity' => '5']), $karl)[1], md_one('SELECT status FROM md_event_regs WHERE event_id = ? AND member_id = ?', [$ev, $sabine])['status']], ['', 'registered']);

echo "-- Anmeldeschluss und Beginn\n";
[$ev2] = md_event_save(null, ['title' => 'Mit Schluss', 'starts_at' => $in(5), 'reg_deadline' => $in(2)], $karl);
$check('vor Anmeldeschluss möglich', md_event_register((int)$ev2, $hans, false, null, $hans)[0], 'registered');
$check('nach Anmeldeschluss abgelehnt', md_event_register((int)$ev2, $sabine, false, null, $sabine, date('Y-m-d H:i:s', strtotime('+3 days')))[1], 'Der Anmeldeschluss ist vorbei.');
$check('nach Beginn abgelehnt', md_event_register((int)$ev2, $sabine, false, null, $sabine, date('Y-m-d H:i:s', strtotime('+6 days')))[1], 'Die Veranstaltung hat schon begonnen.');
$check('Abmelden nach Beginn abgelehnt', md_event_unregister((int)$ev2, $hans, $hans, date('Y-m-d H:i:s', strtotime('+6 days'))), 'Die Veranstaltung hat schon begonnen.');
$check('kostenlos: keine Forderung', (int)md_one("SELECT COUNT(*) c FROM md_ledger WHERE member_id = ? AND source = 'event'", [$hans])['c'], 0);

echo "-- Absage\n";
$check('Absage ohne Grund', md_event_cancel((int)$ev, ' ', $karl), 'Bitte einen Grund angeben.');
$openBefore = (int)md_one("SELECT COUNT(*) c FROM md_ledger WHERE source = 'event' AND status = 'open' AND reference LIKE ?", ['EV' . $ev . '-%'])['c'];
$check('Absage', md_event_cancel((int)$ev, 'Referent erkrankt', $karl), '');
$check('Status abgesagt, offene Forderungen storniert', [md_event_get((int)$ev)['status'], (int)md_one("SELECT COUNT(*) c FROM md_ledger WHERE source = 'event' AND status = 'open' AND reference LIKE ?", ['EV' . $ev . '-%'])['c'], $openBefore > 0], ['cancelled', 0, true]);
$check('Angemeldete benachrichtigt', (int)md_one("SELECT COUNT(*) c FROM md_mail_outbox WHERE subject LIKE 'Abgesagt:%' AND ref = ?", ['EV' . $ev])['c'] >= 2, true);
$check('Anmeldung bei abgesagter abgelehnt', md_event_register((int)$ev, $hans, false, null, $hans)[1], 'Die Veranstaltung ist abgesagt.');
$check('abgesagte nicht änderbar', md_event_save((int)$ev, $ok, $karl)[1], 'Eine abgesagte Veranstaltung kann nicht mehr geändert werden.');

echo "-- Datenschutz\n";
$exp = md_privacy_export_data($julia, $julia);
$check('Auskunft enthält Anmeldungen', array_map(fn($x) => $x['veranstaltung'], $exp['veranstaltungen']) !== [], true);
$expK = md_privacy_export_data($karl, $karl);
$check('… und eigene Veranstaltungen', count($expK['veranstaltungen_als_veranstalter']) >= 2, true);
$check('Austritt Stufe 1 löscht Anmeldungen', [md_privacy_purge($by('DEMO0012'), $max, true), (int)md_one('SELECT COUNT(*) c FROM md_event_regs WHERE member_id = ?', [$by('DEMO0012')])['c']], ['', 0]);
$demo = md_one("SELECT event_id FROM md_events WHERE title = 'Sommerfest im Vereinsgarten'");
$check('Demo: Sommerfest ausgebucht mit Warteliste', $demo ? [md_event_free(md_event_get((int)$demo['event_id'])), md_event_counts((int)$demo['event_id'])['waitlist']] : null, [0, 1]);

md_db()->rollBack();
foreach (array_diff(glob("$mailDir/*.eml") ?: [], $before) as $f) unlink($f);
echo $fail ? "\n$fail Fehler\n" : "\nalles ok\n";
exit($fail ? 1 : 0);
