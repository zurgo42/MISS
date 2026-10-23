<?php
/**
 * test_privacy.php – prüft Auskunft, Anfragen und die zweistufige Löschung an der Demo-Datenbank (Rolf Ehemals ist ausgetreten).
 * Aufruf: php tools/test_privacy.php   (nach php tools/demo_setup.php bzw. demo_load.php). Läuft in einer Transaktion und rollt zurück.
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
$max = $by('DEMO0001'); $hans = $by('DEMO0007'); $rolf = $by('DEMO0012'); $mia = $by('DEMO0011');
md_db()->beginTransaction();
$setSetting = function (string $k, string $v) { if (md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', [$k])) md_exec('UPDATE md_settings SET setting_value = ? WHERE setting_key = ?', [$v, $k]); else md_exec('INSERT INTO md_settings (setting_key, setting_value, description) VALUES (?,?,?)', [$k, $v, 'test']); };
$cnt = fn(string $t, int $m) => (int)md_one("SELECT COUNT(*) c FROM $t WHERE member_id = ?", [$m])['c'];

echo "-- Auskunft\n";
$d = md_privacy_export_data($hans, $max);
$check('Abschnitte vorhanden', array_keys($d), ['meta', 'stammdaten_sitzungsverwaltung', 'profil', 'adressen', 'kontakt', 'fotos', 'sichtbarkeit', 'teilgruppen', 'funktionen', 'lastschriftmandate', 'lastschriften', 'e_mails', 'veranstaltungen', 'veranstaltungen_als_veranstalter', 'buchungen', 'spendenbescheinigungen',
    'zustimmungen_nutzungsbedingungen', 'aenderungsprotokoll', 'datenschutz_anfragen', 'datenschutz_vorgaenge', 'informationen_art_15']);
$check('keine Zugangsdaten im Export', array_filter(array_keys($d['stammdaten_sitzungsverwaltung']), fn($k) => preg_match('/hash|password|token/i', $k)), []);
$check('Hans: 3 Adressen, Kontakte, Buchungen, Gruppen', [count($d['adressen']), count($d['kontakt']) >= 4, count($d['buchungen']) > 3, count($d['teilgruppen'])], [3, true, true, 2]);
$check('Verwaltungsnotizen sind enthalten (Art. 15)', array_key_exists('admin_note', $d['profil']), true);
$check('JSON ist gültig', json_decode(json_encode($d, JSON_UNESCAPED_UNICODE), true) !== null, true);
$html = md_privacy_export_html($d);
$check('HTML enthält Name und Abschnitte', [str_contains($html, 'Lehmann'), str_contains($html, 'Auskunft über die gespeicherten Daten'), str_contains($html, 'Informationen nach Art. 15')], [true, true, true]);
$check('Speicherdauer nennt die eingestellten Fristen', str_contains($d['informationen_art_15']['speicherdauer'], md_privacy_conf()['profile_months'] . ' Monate'), true);

echo "-- Anfragen\n";
[$rid, $err] = md_privacy_request_add($hans, 'erasure', 'mail', $max, 'Testanfrage', '2026-01-10 12:00:00');
$check('Anfrage erfasst', [(bool)$rid, $err], [true, '']);
$check('Frist = ein Monat', md_privacy_request_deadline(md_one('SELECT * FROM md_privacy_requests WHERE request_id = ?', [$rid])), '2026-02-10');
$check('zweite offene Anfrage derselben Art abgelehnt', md_privacy_request_add($hans, 'erasure', 'self', $hans, null)[1], 'Es gibt schon eine offene Anfrage dieser Art.');
$check('Ablehnung ohne Begründung abgelehnt', md_privacy_request_handle($rid, 'refused', ' ', $max), 'Bei einer Ablehnung bitte die Begründung angeben.');
$check('Bearbeiten', [md_privacy_request_handle($rid, 'done', 'Löschung nach Austritt zugesagt', $max), md_one('SELECT status FROM md_privacy_requests WHERE request_id = ?', [$rid])['status']], ['', 'done']);
$check('Vorgänge im Protokoll', (int)md_one("SELECT COUNT(*) c FROM md_privacy_log WHERE member_id = ? AND event IN ('request','handled')", [$hans])['c'], 2);

echo "-- Löschfristen Stufe 1\n";
$setSetting('privacy.profile_months', '0');
$st = md_privacy_state($hans);
$check('Hans (Mitglied) ist gesperrt', md_privacy_can_purge($st, true), 'Mitglied ist noch nicht ausgetreten');
$st = md_privacy_state($rolf);
$check('Rolf: ausgetreten, Frist erreicht, keine Sperre', [$st['stage'], $st['blockers'], $st['purge_due'] <= date('Y-m-d')], [0, [], true]);
md_ledger_add(['member_id' => $rolf, 'kind' => 'beitrag', 'amount' => '10', 'entry_date' => date('d.m.Y'), 'description' => 'TEST-offen'], $max);
$check('offene Buchung sperrt', str_contains(md_privacy_can_purge(md_privacy_state($rolf)), 'offene Buchung'), true);
md_exec("DELETE FROM md_ledger WHERE description = 'TEST-offen'");
md_privacy_set_hold($rolf, true, 'Klärung läuft', $max);
$check('Löschsperre sperrt', str_contains(md_privacy_can_purge(md_privacy_state($rolf)), 'Löschsperre: Klärung läuft'), true);
$check('Sperre ohne Grund abgelehnt', md_privacy_set_hold($rolf, true, ' ', $max), 'Bitte einen Grund für die Löschsperre angeben.');
md_privacy_set_hold($rolf, false, '', $max);
md_exec("INSERT INTO md_photos (member_id, slot, file_name, mime) VALUES (?, 1, 'test_nicht_vorhanden.jpg', 'image/jpeg')", [$rolf]);
md_exec("UPDATE md_profiles SET hobbies = 'Angeln', intro_text = 'Früher im Vorstand' WHERE member_id = ?", [$rolf]);
md_log_change($rolf, 'hobbies', null, 'Angeln', $rolf, 'self');
$before = ['adr' => $cnt('md_addresses', $rolf), 'con' => $cnt('md_contacts', $rolf), 'ledger' => $cnt('md_ledger', $rolf), 'consent' => $cnt('md_terms_consents', $rolf), 'func' => $cnt('md_member_functions', $rolf)];
$check('vor der Löschung vorhanden', [$before['adr'] > 0, $before['con'] > 0, $before['ledger'] > 0, $before['consent'] > 0], [true, true, true, true]);
$check('Stufe 1 ausgeführt', md_privacy_purge($rolf, $max), '');
$check('Adressen, Kontakte, Fotos, Sichtbarkeit, Gruppen gelöscht', [$cnt('md_addresses', $rolf), $cnt('md_contacts', $rolf), $cnt('md_photos', $rolf), $cnt('md_visibility', $rolf), $cnt('md_member_groups', $rolf)], [0, 0, 0, 0, 0]);
$p = md_load_profile($rolf);
$check('Profilfelder geleert, Status und Daten bleiben', [$p['hobbies'], $p['intro_text'], $p['birthdate'], $p['admin_note'], $p['status'], !empty($p['leave_date']), !empty($p['purged_at'])], [null, null, null, null, 'ausgetreten', true, true]);
$check('Profil-Historie gelöscht, Buchungs-/Zustimmungshistorie bleibt', [(int)md_one("SELECT COUNT(*) c FROM md_changelog WHERE member_id = ? AND field_key = 'hobbies'", [$rolf])['c'], $cnt('md_ledger', $rolf) === $before['ledger'], $cnt('md_terms_consents', $rolf) === $before['consent'], $cnt('md_member_functions', $rolf) === $before['func']], [0, true, true, true]);
$sv = md_one('SELECT email, is_active FROM svmembers WHERE member_id = ?', [$rolf]);
$check('Zugang gesperrt, E-Mail entfernt', [$sv['email'], (int)$sv['is_active']], ['geloescht-' . $rolf . '@example.invalid', 0]);
$check('zweite Löschung verhindert', md_privacy_purge($rolf, $max), 'Profildaten sind schon gelöscht.');
$check('Vorgang protokolliert (ohne Klarnamen)', md_one("SELECT event, note FROM md_privacy_log WHERE member_id = ? AND event = 'purged'", [$rolf])['event'], 'purged');

echo "-- Stufe 2 (Anonymisierung)\n";
$st = md_privacy_state($rolf);
$check('Aufbewahrungsfrist läuft noch', str_contains(md_privacy_can_anonymize($st), 'Aufbewahrungsfrist läuft bis'), true);
$setSetting('privacy.ledger_years', '0');
$st = md_privacy_state($rolf);
$check('mit 0 Jahren Frist: erreicht, wenn das letzte Datum vergangen ist', [$st['anon_due'] <= date('Y-m-d'), md_privacy_can_anonymize($st)], [true, '']);
$check('Stufe 2 ausgeführt', md_privacy_anonymize($rolf, $max), '');
$check('Buchungen, Zustimmungen, Funktionen, Anfragen gelöscht', [$cnt('md_ledger', $rolf), $cnt('md_terms_consents', $rolf), $cnt('md_member_functions', $rolf), $cnt('md_changelog', $rolf), $cnt('md_privacy_requests', $rolf)], [0, 0, 0, 0, 0]);
$sv = md_one('SELECT first_name, last_name, email, membership_number, is_active, aktiv, funktion FROM svmembers WHERE member_id = ?', [$rolf]);
$check('Identität anonymisiert', [$sv['first_name'], $sv['last_name'], $sv['membership_number'], (int)$sv['is_active'], (int)$sv['aktiv'], $sv['funktion']], ['Gelöscht', '(anonymisiert)', 'DEL-' . $rolf, 0, 0, null]);
$check('Zustand: Stufe 2, Zeile bleibt', [md_privacy_state($rolf)['stage'], !empty(md_load_profile($rolf)['anonymized_at'])], [2, true]);
$check('nicht noch einmal', md_privacy_can_anonymize(md_privacy_state($rolf)), 'Schon anonymisiert.');
$export = json_encode(md_privacy_export_data($rolf, $max), JSON_UNESCAPED_UNICODE);
$check('Export enthält keine Altdaten mehr', [str_contains($export, 'Rolf'), str_contains($export, 'Ehemals'), str_contains($export, 'Anglerweg')], [false, false, false]);
$check('Protokoll: Anonymisierung nachweisbar ohne Namen', [md_one("SELECT event FROM md_privacy_log WHERE member_id = ? AND event = 'anonymized'", [$rolf])['event'], (int)md_one("SELECT COUNT(*) c FROM md_privacy_log WHERE note LIKE '%Rolf%' OR note LIKE '%Ehemals%'")['c']], ['anonymized', 0]);

echo "-- Rechte\n";
$gf = md_one("SELECT * FROM svmembers WHERE membership_number = 'DEMO0004'");
$hansRow = md_one('SELECT * FROM svmembers WHERE member_id = ?', [$hans]);
md_perm_cache(true);
$check('Hans (RL): kein Zugriff', md_perm($hansRow, 'privacy'), 'none');
md_db()->rollBack();
exit($fail ? 1 : 0);
