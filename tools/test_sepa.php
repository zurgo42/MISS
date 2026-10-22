<?php
/**
 * test_sepa.php – prüft SEPA-Lastschrift (Mandate, Läufe, XML, Rückgaben) an der Demo-Datenbank.
 * Aufruf: php tools/test_sepa.php   (nach demo_setup.php bzw. demo_load.php). Läuft in einer Transaktion und rollt zurück.
 * XML-Prüfung gegen die offiziellen Schemata, falls tools/xsd/pain.008.001.02.xsd und pain.008.001.08.xsd liegen (nicht im Repo).
 */
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__) . '/src/bootstrap.php';
if (!MD_DEMO_MODE) exit("nur mit Demo-Daten\n");
if (md_crypt_key() === null) exit("MD_ENCRYPTION_KEY fehlt (php tools/demo_setup.php trägt in der Demo einen ein)\n");
$fail = 0;
$check = function (string $name, $got, $want) use (&$fail) {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("%s %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $ok ? '' : ' – erwartet ' . json_encode($want, JSON_UNESCAPED_UNICODE) . ', war ' . json_encode($got, JSON_UNESCAPED_UNICODE));
};
$by = fn(string $nr) => (int)md_one('SELECT member_id FROM svmembers WHERE membership_number = ?', [$nr])['member_id'];
$max = $by('DEMO0001'); $erika = $by('DEMO0002'); $lukas = $by('DEMO0003'); $sabine = $by('DEMO0006'); $hans = $by('DEMO0007'); $anna = $by('DEMO0008'); $mia = $by('DEMO0011'); $karl = $by('DEMO0010');

echo "-- Prüfungen\n";
$check('IBAN gültig (Leerzeichen, Kleinbuchstaben)', md_iban_valid(md_iban_normalize('de89 3704 0044 0532 0130 00')), true);
$check('IBAN falsche Prüfziffer', md_iban_valid('DE89370400440532013001'), false);
$check('IBAN zu kurz / Müll', [md_iban_valid('DE89'), md_iban_valid('1234'), md_iban_valid('')], [false, false, false]);
$check('BIC 8 und 11 Stellen', [md_bic_valid('COBADEFFXXX'), md_bic_valid('GENODEF1S04'), md_bic_valid('COBADEFF')], [true, true, true]);
$check('BIC ungültig', [md_bic_valid('COBA'), md_bic_valid('1234DEFF')], [false, false]);
$check('Gläubiger-ID Beispiel gültig', md_sepa_creditor_id_valid('DE98ZZZ09999999999'), true);
$check('Gläubiger-ID Prüfziffer falsch', md_sepa_creditor_id_valid('DE99ZZZ09999999999'), false);
$check('SEPA-Text: Umlaute und Sonderzeichen', md_sepa_text('Müller & Söhne – Beitrag Ärger ß', 140), 'Mueller + Soehne - Beitrag Aerger ss');
$check('SEPA-Text gekürzt', strlen(md_sepa_text(str_repeat('a', 200), 140)), 140);
$check('IBAN-Maske', md_iban_mask('DE89370400440532013000'), 'DE89 … 3000');

echo "-- TARGET2-Tage\n";
$check('Samstag/Sonntag kein Bankarbeitstag', [md_is_target_day('2027-03-06'), md_is_target_day('2027-03-07')], [false, false]);
$check('Karfreitag und Ostermontag 2027', [md_is_target_day('2027-03-26'), md_is_target_day('2027-03-29')], [false, false]);
$check('1. Mai, 25./26. Dezember, 1. Januar', [md_is_target_day('2026-05-01'), md_is_target_day('2026-12-25'), md_is_target_day('2026-12-26'), md_is_target_day('2027-01-01')], [false, false, false, false]);
$check('3. Oktober ist TARGET-Tag (nur Feiertag in Deutschland)', md_is_target_day('2025-10-03'), true);
$check('2 Tage Vorlauf über Wochenende (Do → Mo)', md_target_add('2026-10-22', 2), '2026-10-26');
$check('nächster Tag ab Samstag', md_target_next('2026-10-24'), '2026-10-26');

echo "-- Verschlüsselung\n";
$enc = md_encrypt('DE89370400440532013000');
$check('Roundtrip', md_decrypt($enc), 'DE89370400440532013000');
$check('Klartext nicht im Blob', str_contains($enc, 'DE89'), false);
$check('zwei Verschlüsselungen unterschiedlich (IV)', $enc !== md_encrypt('DE89370400440532013000'), true);
$check('manipulierter Blob nicht lesbar', md_decrypt(substr($enc, 0, -4) . 'AAAA'), null);

md_db()->beginTransaction();
md_perm_cache(true);
$conf = md_sepa_conf();
$check('Demo-Einstellungen vollständig', md_sepa_conf_problems($conf), []);

echo "-- Mandate\n";
$sign = date('d.m.Y', strtotime('-1 month'));
$check('ungültige IBAN', md_sepa_mandate_add($karl, ['iban' => 'DE00', 'signed_on' => $sign, 'debtor_name' => 'K'], $max)[1], 'IBAN ungültig.');
$check('Datum in der Zukunft', md_sepa_mandate_add($karl, ['iban' => 'DE89370400440532013000', 'signed_on' => date('d.m.Y', strtotime('+3 days')), 'debtor_name' => 'K'], $max)[1], 'Unterschriftsdatum ungültig (TT.MM.JJJJ, nicht in der Zukunft).');
$check('Kontoinhaber fehlt', md_sepa_mandate_add($karl, ['iban' => 'DE89370400440532013000', 'signed_on' => $sign, 'debtor_name' => ''], $max)[1], 'Kontoinhaber fehlt.');
[$m1, $e1] = md_sepa_mandate_add($karl, ['iban' => 'de89 3704 0044 0532 0130 00', 'signed_on' => $sign, 'debtor_name' => 'Karl Prüfer', 'bic' => 'COBADEFFXXX'], $max);
$check('Mandat angelegt', [(bool)$m1, $e1], [true, '']);
$mr = md_one('SELECT * FROM md_sepa_mandates WHERE mandate_id = ?', [$m1]);
$check('Referenz aus Mitgliedsnummer', $mr['mandate_ref'], 'M-DEMO0010-01');
$check('IBAN nur verschlüsselt, letzte 4 Stellen offen', [str_contains($mr['iban_enc'], '3704'), $mr['iban_last4']], [false, '3000']);
$check('Doppelte Referenz abgelehnt', md_sepa_mandate_add($erika, ['iban' => 'DE89370400440532013000', 'signed_on' => $sign, 'debtor_name' => 'E', 'mandate_ref' => 'M-DEMO0010-01'], $max)[1], 'Mandatsreferenz fehlt oder ist schon vergeben.');
[$m2] = md_sepa_mandate_add($karl, ['iban' => 'DE02500105170137075030', 'signed_on' => $sign, 'debtor_name' => 'Karl Prüfer'], $max);
$check('Neues Mandat ersetzt das alte', [md_one('SELECT status FROM md_sepa_mandates WHERE mandate_id = ?', [$m1])['status'], md_one('SELECT status FROM md_sepa_mandates WHERE mandate_id = ?', [$m2])['status'], md_one('SELECT mandate_ref FROM md_sepa_mandates WHERE mandate_id = ?', [$m2])['mandate_ref']], ['revoked', 'active', 'M-DEMO0010-02']);
$check('Widerrufenes nicht reaktivierbar', md_sepa_mandate_set_status((int)$m1, 'active', '', $max), 'Ein widerrufenes Mandat kann nicht wieder aktiviert werden; bitte ein neues Mandat anlegen.');
$check('Aussetzen braucht Grund', md_sepa_mandate_set_status((int)$m2, 'suspended', '', $max), 'Bitte einen Grund angeben.');
$check('Mandat im Protokoll', (int)md_one("SELECT COUNT(*) c FROM md_changelog WHERE member_id = ? AND field_key = 'sepa'", [$karl])['c'] >= 2, true);

echo "-- Vorschau\n";
$today = date('Y-m-d');
$coll = md_target_next(date('Y-m-d', strtotime('+25 days')));
// Sabine: abgelaufenes Mandat, Anna: ausgesetzt, Hans: widerrufen, Max: kein Mandat – je eine offene Forderung
$mk = function (int $m, int $cents, string $ref, ?string $entry = null) use ($today): int {
    md_exec("INSERT INTO md_ledger (member_id, kind, direction, amount_cents, entry_date, due_date, status, description, reference, created_at, source) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
        [$m, 'beitrag', 'to_club', $cents, $entry ?? date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('+10 days')), 'open', 'Test-Beitrag', $ref, md_now(), 'test']);
    return (int)md_db()->lastInsertId();
};
foreach ([$sabine, $anna, $hans, $max] as $i => $m) $mk($m, 5000, 'TST-' . $i);
$cand = md_sepa_candidates($coll);
$check('Mia, Lukas, Sabine, Anna, Hans haben Mandate; Max nicht', [isset($cand[$mia]), isset($cand[$lukas]), isset($cand[$sabine]), isset($cand[$anna]), isset($cand[$hans]), isset($cand[$max])], [true, true, true, true, true, false]);
$check('Lukas: zwei Forderungen zusammen, FRST', [count($cand[$lukas]['entries']), $cand[$lukas]['cents'], $cand[$lukas]['seq'], $cand[$lukas]['ok']], [2, 13500, 'FRST', true]);
$check('Mia ok', $cand[$mia]['ok'], true);
$check('Sabine: Mandat verfallen', [$cand[$sabine]['ok'], str_contains($cand[$sabine]['problems'][0], 'verfallen')], [false, true]);
$check('Anna: ausgesetzt', [$cand[$anna]['ok'], $cand[$anna]['problems'][0]], [false, 'Mandat ausgesetzt']);
$check('Hans: widerrufen', [$cand[$hans]['ok'], $cand[$hans]['problems'][0]], [false, 'Mandat widerrufen']);
$mk($erika, 3000, 'TST-S', date('Y-m-d'));
md_exec("UPDATE md_ledger SET due_date = ? WHERE reference = 'TST-S'", [date('Y-m-d')]);
$soon = date('Y-m-d', strtotime('+3 days'));
$check('Vorabankündigung: Rechnung von heute, Einzug in 3 Tagen zu kurz', md_sepa_candidates($soon)[$erika]['short_notice'] ?? null, true);
$check('… nicht einziehbar ohne Ausnahme', md_sepa_candidates($soon)[$erika]['ok'] ?? null, false);
$check('… mit Ausnahme (allowShort) ok', md_sepa_candidates($soon, true)[$erika]['ok'] ?? null, true);
md_exec("DELETE FROM md_ledger WHERE reference = 'TST-S'");
// gestundet: Mia
md_exec("UPDATE md_ledger SET deferred_until = ? WHERE member_id = ? AND kind = 'beitrag' AND status = 'open'", [date('Y-m-d', strtotime('+40 days')), $mia]);
$check('gestundete Forderung nicht im Vorschlag', isset(md_sepa_candidates($coll)[$mia]), false);
md_exec("UPDATE md_ledger SET deferred_until = NULL WHERE member_id = ?", [$mia]);

echo "-- Lauf erstellen\n";
$sat = date('d.m.Y', strtotime('next saturday'));
$check('Wochenende abgelehnt', md_sepa_batch_create($sat, [$lukas], $max)[1], 'Das Einzugsdatum muss ein Bankarbeitstag (TARGET2) sein.');
$tooEarly = md_target_next(date('Y-m-d', strtotime('+1 day')));
$err = md_sepa_batch_create(md_date($tooEarly), [$lukas], $max)[1];
$check('zu früh abgelehnt', str_starts_with($err, 'Das Einzugsdatum liegt zu früh'), true);
$check('Datum ungültig', md_sepa_batch_create('morgen', [$lukas], $max)[1], 'Einzugsdatum ungültig (TT.MM.JJJJ).');
$check('nichts gewählt / nicht einziehbar (Sabine)', md_sepa_batch_create(md_date($coll), [$sabine], $max)[1], 'Keine einziehbare Lastschrift ausgewählt.');
[$bid, $err] = md_sepa_batch_create(md_date($coll), [$lukas, $mia, $sabine], $max);
$check('Lauf erstellt', [(bool)$bid, $err], [true, '']);
$b = md_one('SELECT * FROM md_sepa_batches WHERE batch_id = ?', [$bid]);
$check('Laufnummer, Status, Summe', [$b['batch_no'], $b['status'], (int)$b['items'], (int)$b['total_cents']], ['L' . date('Y') . '-0001', 'created', 2, 13500 + 6000]);
$items = md_q('SELECT * FROM md_sepa_items WHERE batch_id = ? ORDER BY item_id', [$bid]);
$check('Sabine nicht im Lauf', in_array($sabine, array_map(fn($i) => (int)$i['member_id'], $items), true), false);
$check('Posten sind FRST, Mandatsdaten als Momentaufnahme', [$items[0]['seq_type'], $items[0]['mandate_ref'] !== '', str_starts_with($items[0]['iban_enc'], 'v1:')], ['FRST', true, true]);
$check('Forderungen mit Posten verknüpft', (int)md_one('SELECT COUNT(*) c FROM md_ledger WHERE sepa_item_id IS NOT NULL')['c'], 3);
$check('Mahnwesen ignoriert Lastschriftforderungen', in_array($lukas, array_map(fn($c) => (int)($c['member_id'] ?? 0), md_dunning_candidates()), true), false);
$check('zweiter Lauf: nichts mehr für Lukas/Mia', isset(md_sepa_candidates($coll)[$lukas]) || isset(md_sepa_candidates($coll)[$mia]), false);
$check('Karl (Mandat, keine Forderung) nicht im Vorschlag', isset(md_sepa_candidates($coll)[$karl]), false);

echo "-- XML\n";
$xsdDir = __DIR__ . '/xsd';
foreach (['pain.008.001.08', 'pain.008.001.02'] as $fmt) {
    md_exec("UPDATE md_sepa_batches SET format = ? WHERE batch_id = ?", [$fmt, $bid]);
    $xml = md_sepa_xml((int)$bid);
    $dom = new DOMDocument();
    $check("$fmt wohlgeformt", @$dom->loadXML($xml), true);
    $x = new DOMXPath($dom);
    $x->registerNamespace('p', "urn:iso:std:iso:20022:tech:xsd:$fmt");
    $check("$fmt Kopfsumme/Anzahl", [$x->evaluate('string(//p:GrpHdr/p:NbOfTxs)'), $x->evaluate('string(//p:GrpHdr/p:CtrlSum)')], ['2', '195.00']);
    $check("$fmt Gruppen FRST", [$x->evaluate('count(//p:PmtInf)'), $x->evaluate('string(//p:PmtInf/p:PmtTpInf/p:SeqTp)'), $x->evaluate('string(//p:PmtInf/p:ReqdColltnDt)')], [1.0, 'FRST', $coll]);
    $check("$fmt Gläubiger-ID", $x->evaluate('string(//p:CdtrSchmeId//p:Othr/p:Id)'), 'DE98ZZZ09999999999');
    $check("$fmt Mandatsangaben", [$x->evaluate('count(//p:MndtRltdInf/p:MndtId)'), $x->evaluate('count(//p:MndtRltdInf/p:DtOfSgntr)')], [2.0, 2.0]);
    if (is_file("$xsdDir/$fmt.xsd")) {
        libxml_use_internal_errors(true);
        $ok = $dom->schemaValidate("$xsdDir/$fmt.xsd");
        $check("$fmt gegen XSD gültig", $ok, true);
        if (!$ok) foreach (array_slice(libxml_get_errors(), 0, 3) as $le) echo '     ' . trim($le->message) . " (Zeile {$le->line})\n";
    } else echo "skip $fmt gegen XSD (tools/xsd/$fmt.xsd fehlt)\n";
}
$check('Bearbeiten der Posten: keine Klartext-IBAN in der Datenbank', (int)md_one("SELECT COUNT(*) c FROM md_sepa_items WHERE iban_enc LIKE '%DE02%' OR iban_enc LIKE '%DE91%'")['c'], 0);

echo "-- Ablauf\n";
$check('Bestätigen vor Einreichen abgelehnt', md_sepa_close((int)$bid, $max), 'Nur ein eingereichter Lauf kann als eingezogen bestätigt werden.');
$check('Einreichen', md_sepa_submit((int)$bid, $max), '');
$check('Stornieren nach Einreichen abgelehnt', md_sepa_void((int)$bid, 'x', $max), 'Nur ein noch nicht eingereichter Lauf kann storniert werden.');
$check('Mandat während offenem Einzug nicht verwendbar', md_sepa_mandate_usable(md_sepa_active_mandate($lukas), $coll)[0], false);
$check('Einzug bestätigen', md_sepa_close((int)$bid, $max), '');
$lp = md_one("SELECT status, paid_date FROM md_ledger WHERE member_id = ? AND sepa_item_id IS NOT NULL LIMIT 1", [$lukas]);
$check('Forderungen bezahlt am Einzugsdatum', [$lp['status'], $lp['paid_date']], ['paid', $coll]);
$check('Lauf geschlossen', md_one('SELECT status FROM md_sepa_batches WHERE batch_id = ?', [$bid])['status'], 'closed');
$lm = md_sepa_active_mandate($lukas);
$check('Folgeeinzug jetzt RCUR', md_sepa_seq((int)$lm['mandate_id']), 'RCUR');

echo "-- Rückgabe\n";
$it = (int)$items[0]['item_id'];
$mem0 = (int)$items[0]['member_id'];
$check('ungültiger Code', md_sepa_return($it, 'XXXX', '', null, 0, false, $max), 'Rückgabegrund wählen.');
$check('Rückgabe AM04 (keine Deckung) mit Gebühr', md_sepa_return($it, 'AM04', 'Konto nicht gedeckt', md_date($coll), 350, true, $max), '');
$check('Forderungen wieder offen, ohne Posten', (int)md_one("SELECT COUNT(*) c FROM md_ledger WHERE member_id = ? AND status = 'open' AND sepa_item_id IS NULL AND kind IN ('beitrag','eintritt')", [$mem0])['c'] >= 1, true);
$check('AM04 setzt Mandat nicht aus', md_sepa_active_mandate($mem0) !== null, true);
$check('Gebühr als Forderung', (int)md_one("SELECT amount_cents c FROM md_ledger WHERE member_id = ? AND kind = 'gebuehr' AND status = 'open'", [$mem0])['c'], 350);
$check('doppelte Rückgabe abgelehnt', md_sepa_return($it, 'AM04', '', null, 0, false, $max), 'Nur eingezogene Lastschriften können zurückgegeben werden.');
$it2 = (int)$items[1]['item_id'];
$check('Rückgabe MD06 (Widerspruch)', md_sepa_return($it2, 'MD06', '', null, 0, false, $max), '');
$mem1 = (int)$items[1]['member_id'];
$check('MD06 setzt Mandat aus', md_one("SELECT status FROM md_sepa_mandates WHERE member_id = ? ORDER BY mandate_id DESC LIMIT 1", [$mem1])['status'], 'suspended');
$check('Folgeposten wieder im Vorschlag (Lukas, AM04)', isset(md_sepa_candidates(md_target_next(date('Y-m-d', strtotime('+60 days'))))[$mem0]) || $mem0 === $mia, true);

echo "-- Stornieren\n";
$mk($erika, 4000, 'TST-E', date('Y-m-d', strtotime('-30 days')));
[$b2] = md_sepa_batch_create(md_date($coll), [$erika], $max);
$check('zweiter Lauf Nummer', md_one('SELECT batch_no FROM md_sepa_batches WHERE batch_id = ?', [$b2])['batch_no'], 'L' . date('Y') . '-0002');
$check('Stornieren ohne Grund abgelehnt', md_sepa_void((int)$b2, ' ', $max), 'Bitte einen Grund angeben.');
$check('Stornieren', md_sepa_void((int)$b2, 'Test', $max), '');
$check('Forderung wieder frei', (int)md_one("SELECT COUNT(*) c FROM md_ledger WHERE member_id = ? AND reference = 'TST-E' AND sepa_item_id IS NULL AND status = 'open'", [$erika])['c'], 1);
$check('XML eines stornierten Laufs nicht möglich', (function () use ($b2) { try { md_sepa_xml((int)$b2); return 'ok'; } catch (Throwable $e) { return 'fehler'; } })(), 'fehler');

echo "-- Rechnungstext / Datenschutz\n";
$di = md_sepa_debit_info($erika, date('Y-m-d', strtotime('+10 days')));
$check('Rechnungsinfo mit Maske, ohne volle IBAN', [$di['mandate_ref'] ?? null, isset($di['iban_mask']) && !str_contains($di['iban_mask'], '0532')], ['M-DEMO0002-01', true]);
$check('keine Info ohne Mandat', md_sepa_debit_info($max, date('Y-m-d')), null);
$check('keine Info bei widerrufenem Mandat', md_sepa_debit_info($hans, date('Y-m-d')), null);
$exp = md_privacy_export_data($erika, $erika);
$check('Auskunft enthält Mandat mit IBAN', [count($exp['lastschriftmandate']), $exp['lastschriftmandate'][0]['iban']], [1, 'DE89370400440532013000']);
$check('Auskunft: ausgesetzte Mitglieder haben ihre Lastschriften', is_array($exp['lastschriften']), true);
$rolf = $by('DEMO0012');
md_sepa_mandate_add($rolf, ['iban' => 'DE89370400440532013000', 'signed_on' => $sign, 'debtor_name' => 'Rolf Ehemals'], $max);
$check('Austritt: Stufe 1 widerruft aktives Mandat', [md_privacy_purge($rolf, $max, true), md_one("SELECT status FROM md_sepa_mandates WHERE member_id = ?", [$rolf])['status']], ['', 'revoked']);

md_db()->rollBack();
echo $fail ? "\n$fail Fehler\n" : "\nalles ok\n";
exit($fail ? 1 : 0);
