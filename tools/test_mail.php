<?php
/**
 * test_mail.php – prüft E-Mail-Ausgang, Texte für Rechnungen/Mahnungen, Zustellweg, SMTP (gegen tools/fake_smtp.py) und Anmeldung/Passwort-Links.
 * Aufruf: php tools/test_mail.php   (nach demo_setup.php bzw. demo_load.php). Läuft in einer Transaktion und rollt zurück.
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
$max = $by('DEMO0001'); $erika = $by('DEMO0002'); $lukas = $by('DEMO0003'); $mia = $by('DEMO0011'); $karl = $by('DEMO0010'); $rolf = $by('DEMO0012');
$mailDir = MD_STORAGE_DIR . '/mail';
$before = glob("$mailDir/*.eml") ?: [];

md_db()->beginTransaction();
echo "-- Kopfzeilen\n";
$check('Zeilenumbrüche im Betreff entfernt', md_mail_clean_header("Hallo\r\nBcc: x@y.de"), 'Hallo Bcc: x@y.de');
$check('Umlaute im Betreff kodiert', md_mail_encode_header('Mahnung für Müller'), '=?UTF-8?B?' . base64_encode('Mahnung für Müller') . '?=');
$check('ASCII bleibt lesbar', md_mail_encode_header('Rechnung R2026-0001'), 'Rechnung R2026-0001');
$check('Adresse mit Name', md_mail_address('a@b.de', 'Verein e. V.'), 'Verein e. V. <a@b.de>');

echo "-- Warteschlange\n";
$check('ungültige Adresse', md_mail_queue($max, 'kein-mail', 'x', 'y')[1], 'E-Mail-Adresse fehlt oder ist ungültig.');
$check('Adresse mit Zeilenumbruch', md_mail_queue($max, "a@b.de\r\nBcc: c@d.de", 'x', 'y')[1], 'E-Mail-Adresse fehlt oder ist ungültig.');
$check('leerer Text', md_mail_queue($max, 'a@b.de', 'x', ' ')[1], 'Betreff und Text dürfen nicht leer sein.');
[$id, $e] = md_mail_queue($max, 'max@example.org', "Betreff\nmit Umbruch", "Zeile 1\r\nZeile 2", 'other', 'T1', $max);
$row = md_one('SELECT * FROM md_mail_outbox WHERE mail_id = ?', [$id]);
$check('gespeichert, Status wartet, Umbruch im Betreff entfernt', [$row['status'], $row['subject'], $row['body']], ['queued', 'Betreff mit Umbruch', "Zeile 1\nZeile 2"]);
md_exec("DELETE FROM md_settings WHERE setting_key = 'mail.from_email'");
$check('ohne Absender kein Versand', str_starts_with(md_mail_send((int)$id), 'E-Mail-Einstellungen unvollständig'), true);
md_exec("INSERT INTO md_settings (setting_key, setting_value, description) VALUES ('mail.from_email', 'verein@example.org', 't')");
$check('Versand (Protokolldatei)', md_mail_send((int)$id), '');
$files = array_values(array_diff(glob("$mailDir/*.eml") ?: [], $before));
$check('.eml angelegt', count($files), 1);
$eml = file_get_contents($files[0]);
$check('Kopf: From, To, UTF-8, base64', [str_contains($eml, 'From: '), str_contains($eml, 'To: max@example.org'), str_contains($eml, 'charset=UTF-8'), str_contains($eml, 'Content-Transfer-Encoding: base64')], [true, true, true, true]);
[, $b64] = explode("\r\n\r\n", $eml, 2);
$check('Text lesbar nach Dekodierung', str_replace("\r\n", "\n", base64_decode($b64)), "Zeile 1\nZeile 2");
$check('Status versendet', md_one('SELECT status FROM md_mail_outbox WHERE mail_id = ?', [$id])['status'], 'sent');
$check('zweiter Versand abgelehnt', md_mail_send((int)$id), 'Diese Nachricht ist nicht mehr versandbereit.');
[$id2] = md_mail_queue($max, 'zwei@example.org', 'B', 'T');
$check('Abbrechen', [md_mail_cancel((int)$id2), md_mail_cancel((int)$id2)], ['', 'Nur wartende oder fehlgeschlagene Nachrichten können abgebrochen werden.']);
md_mail_queue($max, 'drei@example.org', 'C', 'T');
[$ok, $bad] = md_mail_send_pending();
$check('Sammelversand', [$ok, $bad], [1, 0]);
unlink($files[0]);
foreach (array_diff(glob("$mailDir/*.eml") ?: [], $before) as $f) unlink($f);

echo "-- SMTP\n";
if (shell_exec('command -v python3')) {
    $port = 20000 + random_int(0, 9999);
    $out = sys_get_temp_dir() . '/md_smtp_' . $port . '.txt';
    $proc = proc_open(['python3', __DIR__ . '/fake_smtp.py', (string)$port, $out], [], $pipes);
    usleep(500000);
    define('MD_SMTP_HOST', '127.0.0.1'); define('MD_SMTP_PORT', $port); define('MD_SMTP_SECURE', 'none'); define('MD_SMTP_USER', 'u'); define('MD_SMTP_PASS', 'p');
    [$id3] = md_mail_queue($max, 'smtp@example.org', 'Punkt-Test', "Zeile\n.Zeile mit Punkt\nEnde");
    $m = md_one('SELECT * FROM md_mail_outbox WHERE mail_id = ?', [$id3]);
    $conf = md_mail_conf();
    [$h, $b] = md_mail_build($m, $conf);
    $res = 'ok';
    try { md_mail_transport_smtp($m, $conf, $h, $b); } catch (Throwable $e) { $res = $e->getMessage(); }
    proc_close($proc);
    $log = is_file($out) ? file_get_contents($out) : '';
    $check('SMTP-Sitzung ohne Fehler', $res, 'ok');
    $check('SMTP: Absender, Empfänger, Anmeldung, Nachricht', [str_contains($log, 'MAIL FROM:<verein@example.org>'), str_contains($log, 'RCPT TO:<smtp@example.org>'), str_contains($log, 'AUTH LOGIN'), str_contains($log, 'Subject: Punkt-Test')], [true, true, true, true]);
    @unlink($out);
} else echo "skip SMTP (python3 fehlt)\n";

echo "-- Texte und Zustellweg\n";
$check('Standard: per Post', md_letter_channel($erika), 'post');
$check('Lukas und Mia wünschen E-Mail (Demo)', [md_letter_channel($lukas), md_letter_channel($mia)], ['mail', 'mail']);
md_exec("UPDATE svmembers SET email = 'hans@platzhalter.invalid' WHERE member_id = ?", [$karl]);
$check('Platzhalteradresse zählt nicht', md_mail_address_of($karl), null);
// Rechnung von Mia (offene Forderung)
$inv = md_one("SELECT entry_id FROM md_ledger WHERE member_id = ? AND status = 'open' AND kind = 'beitrag'", [$mia]);
[$mi, $err] = md_mail_queue_invoice((int)$inv['entry_id'], $max);
$check('Rechnung vorgemerkt', [(bool)$mi, $err], [true, '']);
$mrow = md_one('SELECT * FROM md_mail_outbox WHERE mail_id = ?', [$mi]);
$check('Rechnungstext enthält Nummer, Betrag, Mandatsreferenz', [str_contains($mrow['body'], 'RD2026-0011'), str_contains($mrow['body'], '60,00'), str_contains($mrow['body'], 'Mandatsreferenz: M-DEMO0011')], [true, true, true]);
$check('Rechnung mit Lastschrift-Hinweis (Mia hat Mandat)', str_contains($mrow['body'], 'SEPA-Basislastschrift'), true);
$check('Rechnung nicht doppelt', md_mail_queue_invoice((int)$inv['entry_id'], $max)[1], 'Diese Rechnung wurde schon per E-Mail versandt oder liegt in der Warteschlange.');
// Mahnung für Lukas
$cands = md_dunning_candidates(date('Y-m-d', strtotime('+2 days')));
[$lid, $er] = md_dunning_issue($lukas, array_map(fn($i) => (int)$i['entry_id'], $cands[$lukas]['items'] ?? []), $max, date('Y-m-d', strtotime('+2 days')));
$check('Mahnung ausgestellt', [(bool)$lid, $er], [true, '']);
[$mm, $er2] = md_mail_queue_letter((int)$lid, $max);
$mb = md_one('SELECT * FROM md_mail_outbox WHERE mail_id = ?', [$mm]);
$check('Mahnung vorgemerkt, Betreff mit Nummer', [(bool)$mm, str_contains($mb['subject'], 'M' . date('Y') . '-')], [true, true]);
$check('Mahntext enthält Gesamtbetrag und Frist', [str_contains($mb['body'], 'Offener Gesamtbetrag'), str_contains($mb['body'], 'Bitte überweisen Sie bis zum')], [true, true]);
$check('Mahnung nicht doppelt', md_mail_queue_letter((int)$lid, $max)[1], 'Dieses Schreiben wurde schon per E-Mail versandt oder liegt in der Warteschlange.');
$check('Mitglied ohne Adresse', md_mail_queue_letter(0, $max)[1], 'Mahnschreiben nicht gefunden oder storniert.');
$check('Protokoll im Änderungsprotokoll', (int)md_one("SELECT COUNT(*) c FROM md_changelog WHERE member_id = ? AND new_value LIKE '%E-Mail%'", [$lukas])['c'] >= 1, true);

echo "-- Passwort\n";
$m = md_one('SELECT * FROM svmembers WHERE member_id = ?', [$erika]);
$check('zu kurz', md_password_error('kurz', $m), 'Das Passwort muss mindestens 10 Zeichen lang sein.');
$check('wie die Mitgliedsnummer', str_contains(md_password_error('demo0002xx', $m) . md_password_error(strtolower($m['email']), $m), 'darf nicht'), true);
$check('nur ein Zeichen', md_password_error('aaaaaaaaaaaa', $m), 'Das Passwort ist zu einfach.');
$check('gutes Passwort', md_password_error('Kaffee-Tasse-42!', $m), '');
$check('setzen', md_password_set($erika, 'Kaffee-Tasse-42!', $erika), '');
$h = md_one('SELECT password_hash FROM svmembers WHERE member_id = ?', [$erika])['password_hash'];
$check('als Hash gespeichert', [str_starts_with($h, '$'), str_contains($h, 'Kaffee')], [true, false]);
$check('Passwort nicht im Protokoll', (int)md_one("SELECT COUNT(*) c FROM md_changelog WHERE new_value LIKE '%Kaffee%' OR old_value LIKE '%Kaffee%'")['c'], 0);

echo "-- Anmeldung\n";
$em = $m['email'];
[$ok1, $e1] = md_login_check($em, 'Kaffee-Tasse-42!');
$check('Anmeldung mit E-Mail', $ok1['member_id'] ?? null, $erika);
$check('Anmeldung mit Mitgliedsnummer', md_login_check('DEMO0002', 'Kaffee-Tasse-42!')[0]['member_id'] ?? null, $erika);
$check('E-Mail unabhängig von Groß/Klein', md_login_check(strtoupper($em), 'Kaffee-Tasse-42!')[0]['member_id'] ?? null, $erika);
$check('falsches Passwort', md_login_check($em, 'falsch')[1], 'Anmeldung nicht möglich. Bitte Eingaben prüfen oder das Passwort zurücksetzen.');
$check('unbekannte Kennung, gleiche Meldung', md_login_check('gibtsnicht@x.de', 'x')[1], 'Anmeldung nicht möglich. Bitte Eingaben prüfen oder das Passwort zurücksetzen.');
$check('Mitglied ohne Passwort kann sich nicht anmelden', md_login_check('DEMO0005', '')[0], null);
foreach (range(1, 5) as $i) md_login_check('DEMO0004', 'falsch' . $i);
$check('gesperrt nach 5 Fehlversuchen', str_starts_with(md_login_check('DEMO0004', 'falsch')[1], 'Zu viele Fehlversuche'), true);
md_password_set($by('DEMO0004'), 'Richtig-Richtig-1', $max);
$check('auch das richtige Passwort wird während der Sperre abgewiesen', md_login_check('DEMO0004', 'Richtig-Richtig-1')[0], null);
md_exec('DELETE FROM md_login_attempts');
$check('nach Ablauf der Sperre wieder möglich', md_login_check('DEMO0004', 'Richtig-Richtig-1')[0]['member_id'] ?? null, $by('DEMO0004'));
md_exec('UPDATE svmembers SET is_active = 0 WHERE member_id = ?', [$erika]);
$check('gesperrter Zugang (is_active = 0)', md_login_check($em, 'Kaffee-Tasse-42!')[0], null);
md_exec('UPDATE svmembers SET is_active = 1 WHERE member_id = ?', [$erika]);
try {   // die echte Tabelle kennt keine UNIQUE-Bedingung auf email
    md_exec('UPDATE svmembers SET email = ? WHERE member_id = ?', ['x@dup.test', $erika]); md_exec('UPDATE svmembers SET email = ? WHERE member_id = ?', ['x@dup.test', $mia]);
    $check('mehrdeutige Adresse wird nicht angemeldet', md_login_find('x@dup.test'), null);
} catch (PDOException $e) { echo "skip mehrdeutige Adresse (UNIQUE in dieser Tabelle)\n"; }
md_exec('UPDATE svmembers SET email = ? WHERE member_id = ?', [$em, $erika]);

echo "-- Links\n";
$t = md_token_create($erika, 'reset', 2);
$check('Token nur als Hash gespeichert', (int)md_one('SELECT COUNT(*) c FROM md_login_tokens WHERE token_hash = ?', [$t])['c'], 0);
$check('Token gefunden', md_token_find($t)['member_id'] ?? null, $erika);
$check('ungültiges Format', md_token_find('abc'), null);
$t2 = md_token_create($erika, 'reset', 2);
$check('neuer Token macht den alten ungültig', [md_token_find($t), (bool)md_token_find($t2)], [null, true]);
md_exec('UPDATE md_login_tokens SET expires_at = ? WHERE member_id = ?', [date('Y-m-d H:i:s', time() - 5), $erika]);
$check('abgelaufen', md_token_find($t2), null);
$t3 = md_token_create($erika, 'reset', 2);
$check('schwaches Passwort abgelehnt, Token bleibt gültig', [md_login_use_token($t3, 'kurz')[1] !== '', (bool)md_token_find($t3)], [true, true]);
[$who, $e3] = md_login_use_token($t3, 'Neues-Passwort-77');
$check('Passwort mit Token gesetzt', [$who, $e3], [$erika, '']);
$check('Token verbraucht', md_token_find($t3), null);
$check('neues Passwort gilt', md_login_check($em, 'Neues-Passwort-77')[0]['member_id'] ?? null, $erika);
$check('Link-Mail: Einladung an Mitglied ohne Passwort', md_login_send_link($by('DEMO0005'), 'invite', $max), '');
$inv2 = md_one("SELECT * FROM md_mail_outbox WHERE kind = 'invite' ORDER BY mail_id DESC LIMIT 1");
$check('Einladung versendet, Link im Ausgang entfernt', [$inv2['status'], str_contains($inv2['body'], 'reset.php?token='), str_contains($inv2['body'], 'Link entfernt')], ['sent', false, true]);
$sent = array_values(array_diff(glob("$mailDir/*.eml") ?: [], $before));
$emlText = $sent ? base64_decode(explode("\r\n\r\n", file_get_contents(end($sent)), 2)[1]) : '';
$check('gesendete Nachricht enthält den Link', (bool)preg_match('~reset\.php\?token=[0-9a-f]{48}~', $emlText), true);
foreach ($sent as $f) unlink($f);
$check('Link-Mail ohne Adresse', md_login_send_link($karl, 'invite', $max), 'Keine gültige E-Mail-Adresse beim Mitglied.');
md_login_request_reset('gibtsnicht@x.de');
$check('Zurücksetzen für Unbekannte: keine Mail', (int)md_one("SELECT COUNT(*) c FROM md_mail_outbox WHERE to_email = 'gibtsnicht@x.de'")['c'], 0);
foreach (range(1, 5) as $i) md_login_request_reset($em);
$check('höchstens 3 Links je Stunde', (int)md_one("SELECT COUNT(*) c FROM md_login_tokens WHERE member_id = ? AND created_at >= ?", [$erika, date('Y-m-d H:i:s', time() - 3600)])['c'] <= 4, true);
foreach (array_diff(glob("$mailDir/*.eml") ?: [], $before) as $f) unlink($f);
$check('Uneingeladene gelistet', in_array($by('DEMO0006'), array_map(fn($x) => (int)$x['member_id'], md_login_uninvited()), true), true);

echo "-- Datenschutz\n";
$exp = md_privacy_export_data($lukas, $lukas);
$check('Auskunft enthält E-Mails ohne Inhalt', [count($exp['e_mails']) >= 1, isset($exp['e_mails'][0]['body'])], [true, false]);
$check('Passwort-Hash nicht im Export', array_filter(array_keys($exp['stammdaten_sitzungsverwaltung']), fn($k) => preg_match('/hash|password/i', $k)), []);
md_exec("INSERT INTO md_login_tokens (member_id, token_hash, kind, created_at, expires_at) VALUES (?,?,?,?,?)", [$rolf, str_repeat('a', 64), 'reset', md_now(), date('Y-m-d H:i:s', time() + 3600)]);
md_password_set($rolf, 'Alt-Mitglied-4711', $max);
$check('Austritt Stufe 1: Zugang weg', [md_privacy_purge($rolf, $max, true), md_one('SELECT password_hash FROM svmembers WHERE member_id = ?', [$rolf])['password_hash'], (int)md_one('SELECT COUNT(*) c FROM md_login_tokens WHERE member_id = ?', [$rolf])['c']], ['', null, 0]);
$check('… und keine Anmeldung mehr', md_login_find('DEMO0012'), null);

md_db()->rollBack();
foreach (array_diff(glob("$mailDir/*.eml") ?: [], $before) as $f) unlink($f);
echo $fail ? "\n$fail Fehler\n" : "\nalles ok\n";
exit($fail ? 1 : 0);
