<?php
/**
 * test_lists.php – prüft E-Mail-Verteiler, Nachrichten an Veranstaltungs-Angemeldete und die Online-Anmeldung neuer Mitglieder.
 * Aufruf: php tools/test_lists.php   (nach demo_setup.php bzw. demo_load.php). Läuft in einer Transaktion und rollt zurück.
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
$max = $by('DEMO0001'); $erika = $by('DEMO0002'); $lukas = $by('DEMO0003'); $julia = $by('DEMO0004'); $thomas = $by('DEMO0005'); $hans = $by('DEMO0007'); $karl = $by('DEMO0010'); $rolf = $by('DEMO0012');
$mailDir = MD_STORAGE_DIR . '/mail';
$before = glob("$mailDir/*.eml") ?: [];
$members0 = (int)md_one('SELECT COUNT(*) c FROM svmembers')['c'];
$actor = fn(int $id) => md_one('SELECT * FROM svmembers WHERE member_id = ?', [$id]);

md_db()->beginTransaction();
md_perm_cache(true);
echo "-- Verteiler anlegen\n";
$g = md_one('SELECT group_id FROM md_groups ORDER BY group_id LIMIT 1');
$check('Name fehlt', md_list_save(null, ['name' => ' ']), 'Name fehlt oder ist zu lang.');
$check('Teilgruppe fehlt', md_list_save(null, ['name' => 'T', 'source_kind' => 'group', 'group_id' => 99999]), 'Bitte eine Teilgruppe wählen.');
$check('Funktion unbekannt', md_list_save(null, ['name' => 'T', 'source_kind' => 'function', 'function_code' => 'XXX']), 'Bitte eine Funktion wählen.');
$check('anlegen (Teilgruppe)', md_list_save(null, ['name' => 'TEST-Gruppe', 'source_kind' => 'group', 'group_id' => $g['group_id'], 'optional' => 1, 'active' => 1]), '');
$check('Doppelter Name', md_list_save(null, ['name' => 'test-gruppe', 'source_kind' => 'all']), 'Es gibt schon einen Verteiler mit diesem Namen.');
$L = md_one("SELECT * FROM md_mail_lists WHERE name = 'TEST-Gruppe'");
$check('Quelle lesbar', str_starts_with(md_list_source_label($L), 'Teilgruppe '), true);

echo "-- Empfänger\n";
$gm = array_map('intval', array_column(md_q('SELECT member_id FROM md_member_groups WHERE group_id = ?', [$g['group_id']]), 'member_id'));
$ids = fn($r) => array_map(fn($m) => (int)$m['member_id'], $r['send']);
$r0 = md_list_recipients($L);
$check('Empfänger = Mitglieder der Teilgruppe mit Adresse', array_diff($ids($r0), $gm), []);
if (!$gm) { echo "FAIL Demo-Teilgruppe hat keine Mitglieder\n"; $fail++; }
$one = $gm[0];
md_exec("UPDATE svmembers SET email = 'x@platzhalter.invalid' WHERE member_id = ?", [$one]);
$r1 = md_list_recipients($L);
$check('ohne gültige Adresse: nicht im Verteiler, gezählt', [in_array($one, $ids($r1), true), $r1['no_mail']], [false, 1]);
md_exec("UPDATE svmembers SET email = ? WHERE member_id = ?", ['m' . $one . '@example.org', $one]);
$check('Abbestellen', md_list_set_optout($one, (int)$L['list_id'], true, $one), '');
$r2 = md_list_recipients($L);
$check('abbestellt: nicht im Verteiler, gezählt', [in_array($one, $ids($r2), true), $r2['opted_out']], [false, 1]);
$check('Protokoll', (int)md_one("SELECT COUNT(*) c FROM md_changelog WHERE member_id = ? AND field_key = 'mail' AND new_value = 'abbestellt'", [$one])['c'], 1);
$check('wieder abonnieren', [md_list_set_optout($one, (int)$L['list_id'], false, $one), in_array($one, $ids(md_list_recipients($L)), true)], ['', true]);
md_exec("UPDATE svmembers SET is_active = 0 WHERE member_id = ?", [$gm[0]]);
$check('gesperrter Zugang ausgeschlossen', in_array($gm[0], $ids(md_list_recipients($L)), true), false);
md_exec("UPDATE svmembers SET is_active = 1 WHERE member_id = ?", [$gm[0]]);
md_exec("UPDATE md_profiles SET status = 'ausgetreten' WHERE member_id = ?", [$gm[0]]);
$check('Ausgetretene ausgeschlossen', in_array($gm[0], $ids(md_list_recipients($L)), true), false);
md_exec("UPDATE md_profiles SET status = 'aktiv' WHERE member_id = ?", [$gm[0]]);
md_exec("UPDATE md_member_groups SET until = ? WHERE member_id = ? AND group_id = ?", [date('Y-m-d', strtotime('-1 day')), $gm[0], $g['group_id']]);
$check('beendete Gruppenzugehörigkeit ausgeschlossen', in_array($gm[0], $ids(md_list_recipients($L)), true), false);
md_exec("UPDATE md_member_groups SET until = NULL WHERE member_id = ? AND group_id = ?", [$gm[0], $g['group_id']]);
md_list_save(null, ['name' => 'TEST-Funktion', 'source_kind' => 'function', 'function_code' => 'Vo', 'optional' => 0, 'active' => 1]);
$LF = md_one("SELECT * FROM md_mail_lists WHERE name = 'TEST-Funktion'");
$vo = array_map('intval', array_column(md_q("SELECT mf.member_id FROM md_member_functions mf JOIN md_functions f ON f.function_id = mf.function_id WHERE f.code = 'Vo' AND (mf.valid_to IS NULL OR mf.valid_to >= ?)", [date('Y-m-d')]), 'member_id'));
$check('Funktions-Verteiler = aktuelle Funktionsinhaber', array_values(array_diff($ids(md_list_recipients($LF)), $vo)), []);
$check('verbindlicher Verteiler nicht abbestellbar', md_list_set_optout($vo[0] ?? $max, (int)$LF['list_id'], true, $max), 'Dieser Verteiler ist verbindlich und kann nicht abbestellt werden.');
md_list_save(null, ['name' => 'TEST-Alle', 'source_kind' => 'all', 'optional' => 1, 'active' => 1]);
$LA = md_one("SELECT * FROM md_mail_lists WHERE name = 'TEST-Alle'");
$all = $ids(md_list_recipients($LA));
$check('Alle: ohne Ausgetretene (Rolf)', [in_array($rolf, $all, true), in_array($max, $all, true)], [false, true]);

echo "-- Nachricht senden\n";
$check('ohne Betreff', md_list_send((int)$L['list_id'], '', 'Text', $max)[2], 'Betreff und Text dürfen nicht leer sein.');
$check('unbekannter Verteiler', md_list_send(99999, 'x', 'y', $max)[2], 'Verteiler nicht gefunden oder nicht aktiv.');
[$cid, $n, $e] = md_list_send((int)$L['list_id'], "Sommer-Info\r\nBcc: spam@x.de", "{anrede},\nam Samstag ist Treffen.", $max);
$exp = count($ids(md_list_recipients($L)));
$check('Nachricht vorgemerkt', [(bool)$cid, $n, $e], [true, $exp, '']);
$mails = md_q("SELECT * FROM md_mail_outbox WHERE ref = ?", ['K' . $cid]);
$check('eine Mail je Empfänger, Status wartet', [count($mails), array_unique(array_column($mails, 'status'))], [$exp, ['queued']]);
$check('Betreff ohne Einschleusung', $mails[0]['subject'], 'Sommer-Info Bcc: spam@x.de');
$check('persönliche Anrede ersetzt', str_contains($mails[0]['body'], '{anrede}'), false);
$check('Fußzeile mit Abmeldelink', [str_contains($mails[0]['body'], 'unsubscribe.php?m='), str_contains($mails[0]['unsub_url'], 'unsubscribe.php?m=')], [true, true]);
$check('jede Mail nur ein Empfänger', count(array_unique(array_column($mails, 'to_email'))), count($mails));
[$h] = md_mail_build($mails[0], md_mail_conf());
$check('Kopfzeile List-Unsubscribe', count(array_filter($h, fn($x) => str_starts_with($x, 'List-Unsubscribe: <http'))), 1);
$check('doppeltes Absenden verhindert', md_list_send((int)$L['list_id'], "Sommer-Info\r\nBcc: spam@x.de", "{anrede},\nam Samstag ist Treffen.", $max)[2], 'Diese Nachricht wurde vor Kurzem schon an den Verteiler gesendet.');
[$c2, , ] = md_list_send((int)$LF['list_id'], 'Vorstand intern', 'Sitzung', $max);
$m2 = md_one("SELECT * FROM md_mail_outbox WHERE ref = ?", ['K' . $c2]);
$check('verbindlicher Verteiler: kein Abmeldelink', $m2 ? [$m2['unsub_url'], str_contains($m2['body'], 'unsubscribe')] : null, [null, false]);
[$ok1, $bad1] = md_mail_send_pending(500);
$check('Versand aus dem Ausgang', $bad1, 0);
$check('Kampagne protokolliert', (int)md_one('SELECT recipients FROM md_mail_campaigns WHERE campaign_id = ?', [$cid])['recipients'], $exp);

echo "-- Abmeldelink\n";
$t = md_unsub_token($one, (int)$L['list_id']);
$check('Token passt nur zu Mitglied und Verteiler', [hash_equals($t, md_unsub_token($one, (int)$L['list_id'])), $t === md_unsub_token($one, (int)$L['list_id'] + 1), $t === md_unsub_token($one + 1, (int)$L['list_id'])], [true, false, false]);
$check('Verteiler des Mitglieds mit Status', (function () use ($one, $L) { foreach (md_lists_of_member($one) as $l) if ((int)$l['list_id'] === (int)$L['list_id']) return $l['opted_out']; return null; })(), false);
md_list_set_optout($one, (int)$L['list_id'], true, $one);
$check('… abbestellt wird weiter angezeigt (zum Wiederanmelden)', (function () use ($one, $L) { foreach (md_lists_of_member($one) as $l) if ((int)$l['list_id'] === (int)$L['list_id']) return $l['opted_out']; return null; })(), true);

echo "-- Nachricht an Angemeldete\n";
[$ev] = md_event_save(null, ['title' => 'Verteiler-Test', 'starts_at' => date('d.m.Y', strtotime('+10 days')) . ' 18:00'], $karl);
md_event_register((int)$ev, $hans, false, null, $hans);
md_event_register((int)$ev, $erika, true, null, $erika);
$K = $actor($karl);
$check('Fremde dürfen nicht', md_event_message((int)$ev, 'x', 'y', false, $actor($hans))[1], 'Nur der Veranstalter darf Nachrichten senden.');
$check('ohne Text', md_event_message((int)$ev, '', 'y', false, $K)[1], 'Betreff und Text dürfen nicht leer sein.');
[$cnt, $er] = md_event_message((int)$ev, 'Treffpunkt', "{vorname}, wir treffen uns am Eingang.", false, $K);
$check('Nachricht an 2 Angemeldete gesendet', [$cnt, $er], [2, '']);
$em = md_q("SELECT * FROM md_mail_outbox WHERE subject = 'Treffpunkt' ORDER BY mail_id");
$check('sofort versendet, Antwort an Veranstalter', [array_unique(array_column($em, 'status')), array_unique(array_column($em, 'reply_to'))], [['sent'], [md_mail_address_of($karl)]]);
$check('Kopfzeile Reply-To', count(array_filter(md_mail_build($em[0], md_mail_conf())[0], fn($x) => str_starts_with($x, 'Reply-To: '))), 1);
md_event_message((int)$ev, 'Zwei', 'Text', false, $K); md_event_message((int)$ev, 'Drei', 'Text', false, $K);
$check('höchstens 3 Nachrichten je Tag', md_event_message((int)$ev, 'Vier', 'Text', false, $K)[1], 'Es sind höchstens 3 Nachrichten je Veranstaltung und Tag möglich.');

echo "-- Online-Anmeldung: Schutz\n";
$d = ['first_name' => 'Fiona', 'last_name' => 'Testerin', 'email' => 'fiona.t@example.org', 'street' => 'Weg 1', 'zip' => '30159', 'city' => 'Hannover', 'ack_privacy' => 1, 'ack_statute' => 1, 'ft' => md_apply_form_token(time() - 10)];
md_exec("DELETE FROM md_settings WHERE setting_key = 'apply.enabled'");
$check('nicht freigeschaltet', md_apply_submit($d, 'ip1')[1], 'Die Online-Anmeldung ist zurzeit nicht freigeschaltet.');
md_exec("INSERT INTO md_settings (setting_key, setting_value, description) VALUES ('apply.enabled', '1', 't')");
$check('Formular-Token: 10 s alt ok', md_apply_form_token_ok(md_apply_form_token(time() - 10)), true);
$check('Formular-Token: zu schnell / zu alt / verändert', [md_apply_form_token_ok(md_apply_form_token(time() - 1)), md_apply_form_token_ok(md_apply_form_token(time() - 8000)), md_apply_form_token_ok(str_replace('.', '1.', md_apply_form_token(time() - 10)))], [false, false, false]);
$check('zu schnell abgeschickt', md_apply_submit(array_merge($d, ['ft' => md_apply_form_token()]), 'ip1')[0], false);
$n0 = (int)md_one('SELECT COUNT(*) c FROM md_applications')['c'];
$check('Köderfeld: scheinbar angenommen, nichts gespeichert', [md_apply_submit(array_merge($d, ['website' => 'http://spam']), 'ip1'), (int)md_one('SELECT COUNT(*) c FROM md_applications')['c']], [[true, ''], $n0]);
$check('Name fehlt', md_apply_submit(array_merge($d, ['last_name' => '']), 'ip1')[1], 'Bitte Vor- und Nachnamen angeben.');
$check('E-Mail ungültig', md_apply_submit(array_merge($d, ['email' => 'abc']), 'ip1')[1], 'Bitte eine gültige E-Mail-Adresse angeben.');
$check('Anschrift fehlt', md_apply_submit(array_merge($d, ['city' => '']), 'ip1')[1], 'Bitte die vollständige Anschrift angeben (für Rechnungen und Post des Vereins).');
$check('Geburtsdatum ungültig', md_apply_submit(array_merge($d, ['birthdate' => '31.02.1990']), 'ip1')[1], 'Geburtsdatum ungültig (TT.MM.JJJJ).');
$check('Mitgliedsart nicht öffentlich', md_apply_submit(array_merge($d, ['fee_group_id' => 3]), 'ip1')[1], 'Bitte eine der angebotenen Mitgliedsarten wählen.');
$check('Zustimmung fehlt', md_apply_submit(array_merge($d, ['ack_statute' => 0]), 'ip1')[1], 'Bitte bestätige die Datenschutzhinweise und die Kenntnisnahme der Satzung.');

echo "-- Online-Anmeldung: Ablauf\n";
$pub = md_one("SELECT group_id FROM md_fee_groups WHERE public = 1 AND active = 1 ORDER BY group_id LIMIT 1");
$d['fee_group_id'] = $pub['group_id']; $d['birthdate'] = '02.06.1991'; $d['salutation'] = 'Frau'; $d['phone'] = '0511 99'; $d['message'] = 'Hallo';
$check('Antrag angenommen', md_apply_submit($d, 'ip2'), [true, '']);
$app = md_one("SELECT * FROM md_applications WHERE email = 'fiona.t@example.org'");
$check('Status unbestätigt, noch kein Mitglied', [$app['status'], md_one("SELECT COUNT(*) c FROM svmembers WHERE email = 'fiona.t@example.org'")['c']], ['unconfirmed', 0]);
$mail = md_one("SELECT * FROM md_mail_outbox WHERE to_email = 'fiona.t@example.org' ORDER BY mail_id DESC LIMIT 1");
$check('Bestätigungs-Mail gesendet, Link im Ausgang entfernt', [$mail['status'], $mail['kind'], str_contains($mail['body'], 'apply_confirm.php?token='), str_contains($mail['body'], 'Link entfernt')], ['sent', 'apply', false, true]);
$tf = glob("$mailDir/*.eml"); usort($tf, fn($a, $b) => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));
$txt = '';
foreach (array_diff($tf, $before) as $f) { $e = file_get_contents($f); if (str_contains($e, 'To: fiona.t@example.org')) { $txt = base64_decode(explode("\r\n\r\n", $e, 2)[1]); break; } }
preg_match('~token=([0-9a-f]{48})~', $txt, $tm);
$token = $tm[1] ?? '';
$check('Link in der gesendeten Mail', strlen($token), 48);
$check('erneutes Absenden ersetzt den offenen Antrag (kein Duplikat)', [md_apply_submit(array_merge($d, ['ft' => md_apply_form_token(time() - 10), 'phone' => '0511 11']), 'ip2')[0], (int)md_one("SELECT COUNT(*) c FROM md_applications WHERE email = 'fiona.t@example.org'")['c']], [true, 1]);
$check('alter Link nach erneutem Absenden ungültig', md_apply_confirm($token)[0], false);
$check('ungültiges Token-Format', md_apply_confirm('abc')[1], 'Der Link ist ungültig.');
$tf2 = array_diff(glob("$mailDir/*.eml") ?: [], $before, $tf ?: []);
foreach (array_diff(glob("$mailDir/*.eml"), $before) as $f) { $e = file_get_contents($f); if (str_contains($e, 'To: fiona.t@example.org')) { $t2 = base64_decode(explode("\r\n\r\n", $e, 2)[1]); if (preg_match('~token=([0-9a-f]{48})~', $t2, $m2) && $m2[1] !== $token) $token = $m2[1]; } }
$check('Bestätigung', md_apply_confirm($token), [true, '']);
$check('Status neu, bestätigt', md_one("SELECT status FROM md_applications WHERE email = 'fiona.t@example.org'")['status'], 'new');
$check('Link nur einmal nutzbar', md_apply_confirm($token)[0], false);
$check('in der Zählung offener Anträge', md_apply_open_count() >= 2, true);
// bekannte Adresse
$mk = md_one('SELECT email FROM svmembers WHERE member_id = ?', [$max])['email'];
$cntA = (int)md_one('SELECT COUNT(*) c FROM md_applications')['c'];
$check('bekannte Adresse: scheinbar angenommen, kein Antrag, Hinweis-Mail', [md_apply_submit(array_merge($d, ['email' => $mk, 'ft' => md_apply_form_token(time() - 10)]), 'ip3'), (int)md_one('SELECT COUNT(*) c FROM md_applications')['c'], (int)md_one("SELECT COUNT(*) c FROM md_mail_outbox WHERE to_email = ? AND subject LIKE 'Deine Anmeldung%'", [$mk])['c']], [[true, ''], $cntA, 1]);
foreach (range(1, 5) as $i) md_rate_hit('apply|ip|ipX');
$check('Begrenzung je Absender', md_apply_submit(array_merge($d, ['ft' => md_apply_form_token(time() - 10)]), 'ipX')[1], 'Zu viele Anträge in kurzer Zeit. Bitte später noch einmal versuchen.');

echo "-- Aufnahme\n";
$appId = (int)$app['app_id'];
$check('Eintrittsdatum ungültig', md_apply_approve($appId, ['join_date' => '99.99.2026'], $max)[1], 'Eintrittsdatum ungültig (TT.MM.JJJJ).');
$grp = md_one('SELECT group_id FROM md_groups ORDER BY group_id LIMIT 1');
[$newId, $er, $note] = md_apply_approve($appId, ['join_date' => date('d.m.Y'), 'fee_group_id' => $pub['group_id'], 'group_ids' => [$grp['group_id']]], $max);
$check('Mitglied angelegt', [(bool)$newId, $er, $note], [true, '', '']);
$nm = md_one('SELECT * FROM svmembers WHERE member_id = ?', [$newId]);
$check('Stammdaten', [$nm['first_name'], $nm['last_name'], $nm['email']], ['Fiona', 'Testerin', 'fiona.t@example.org']);
$np = md_load_profile($newId);
$check('Profil: Anrede, Geburtsdatum, Status, Eintritt', [$np['salutation'], $np['birthdate'], $np['status'], $np['join_date']], ['Frau', '1991-06-02', 'aktiv', date('Y-m-d')]);
$ad = md_load_addresses($newId)[1] ?? [];
$check('Anschrift', [$ad['street'] ?? null, $ad['zip'] ?? null, $ad['city'] ?? null], ['Weg 1', '30159', 'Hannover']);
$check('Beitragsgruppe und Teilgruppe', [(int)md_one('SELECT group_id FROM md_member_fees WHERE member_id = ?', [$newId])['group_id'], (int)md_one('SELECT COUNT(*) c FROM md_member_groups WHERE member_id = ?', [$newId])['c']], [(int)$pub['group_id'], 1]);
$check('Rolle Mitglied, ohne Funktion', md_one('SELECT COUNT(*) c FROM md_member_functions WHERE member_id = ?', [$newId])['c'], 0);
$check('Antrag aufgenommen und verknüpft', array_values(array_intersect_key(md_one('SELECT status, member_id FROM md_applications WHERE app_id = ?', [$appId]), ['status' => 1, 'member_id' => 1])), ['approved', $newId]);
$inv = md_one("SELECT * FROM md_mail_outbox WHERE member_id = ? AND kind = 'invite'", [$newId]);
$check('Einladung zum Passwort gesendet', $inv['status'] ?? null, 'sent');
$check('noch ohne Passwort (kann sich nicht anmelden)', md_login_check('fiona.t@example.org', 'irgendwas')[0], null);
$check('Entscheidung nur einmal', md_apply_approve($appId, [], $max)[1], 'Antrag nicht gefunden oder schon entschieden.');
$check('Mitgliedsnummer eindeutig', (int)md_one('SELECT COUNT(*) c FROM svmembers WHERE membership_number = ?', [$nm['membership_number']])['c'], 1);
$check('Testuser unverändert (nur 1 Mitglied mehr)', (int)md_one('SELECT COUNT(*) c FROM svmembers')['c'], $members0 + 1);
// Ablehnung
md_apply_submit(array_merge($d, ['email' => 'abgelehnt@example.org', 'ft' => md_apply_form_token(time() - 10)]), 'ip5');
$rej = md_one("SELECT app_id FROM md_applications WHERE email = 'abgelehnt@example.org'");
$check('unbestätigten Antrag nicht entscheidbar', md_apply_reject((int)$rej['app_id'], 'x', $max), 'Antrag nicht gefunden oder schon entschieden.');
md_exec("UPDATE md_applications SET status = 'new', confirmed_at = ? WHERE app_id = ?", [md_now(), $rej['app_id']]);
$check('Ablehnung', md_apply_reject((int)$rej['app_id'], 'Zurzeit keine freien Plätze.', $max), '');
$rm = md_one("SELECT * FROM md_mail_outbox WHERE to_email = 'abgelehnt@example.org' ORDER BY mail_id DESC LIMIT 1");
$check('Absage per E-Mail mit Begründung', [$rm['status'], str_contains($rm['body'], 'keine freien Plätze')], ['sent', true]);

echo "-- Aufbewahrung\n";
md_exec("UPDATE md_applications SET created_at = ? WHERE email = 'bernd.wartend@example.org'", [date('Y-m-d H:i:s', strtotime('-5 days'))]);
$cOld = md_apply_purge();
$check('Unbestätigte nach 3 Tagen gelöscht', [$cOld >= 1, (int)md_one("SELECT COUNT(*) c FROM md_applications WHERE email = 'bernd.wartend@example.org'")['c']], [true, 0]);
$check('Entschiedene bleiben zunächst', (int)md_one("SELECT COUNT(*) c FROM md_applications WHERE app_id = ?", [$appId])['c'], 1);
md_exec("UPDATE md_applications SET decided_at = ? WHERE app_id = ?", [date('Y-m-d H:i:s', strtotime('-100 days')), $appId]);
md_apply_purge();
$check('… und werden nach 90 Tagen gelöscht', (int)md_one("SELECT COUNT(*) c FROM md_applications WHERE app_id = ?", [$appId])['c'], 0);

echo "-- Datenschutz\n";
$exp = md_privacy_export_data($one, $one);
$check('Auskunft nennt abbestellte Verteiler', array_map(fn($x) => $x['verteiler'], $exp['verteiler_abbestellt']), ['TEST-Gruppe']);

md_db()->rollBack();
foreach (array_diff(glob("$mailDir/*.eml") ?: [], $before) as $f) unlink($f);
echo $fail ? "\n$fail Fehler\n" : "\nalles ok\n";
exit($fail ? 1 : 0);
