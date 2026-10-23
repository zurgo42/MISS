<?php
declare(strict_types=1);

/**
 * E-Mail-Ausgang: Nachrichten werden in md_mail_outbox gestellt und von dort versendet (Nachweis bleibt erhalten).
 * Zustellweg (config.php): MD_MAIL_TRANSPORT = 'log' (Demo: schreibt .eml-Dateien nach storage/mail), 'mail' (PHP mail()), 'smtp' (MD_SMTP_HOST/PORT/SECURE/USER/PASS).
 * Absender und Antwortadresse stehen in den Einstellungen (mail.from_email, mail.from_name, mail.reply_to).
 */

const MD_MAIL_KINDS = ['dunning' => 'Mahnung', 'invoice' => 'Rechnung', 'invite' => 'Einladung', 'reset' => 'Passwort', 'receipt' => 'Zuwendungsbestätigung', 'list' => 'Verteiler', 'apply' => 'Anmeldung', 'other' => 'Sonstiges'];
const MD_MAIL_STATUS = ['queued' => 'wartet', 'sent' => 'versendet', 'failed' => 'fehlgeschlagen', 'cancelled' => 'abgebrochen'];

function md_mail_transport(): string
{
    $t = defined('MD_MAIL_TRANSPORT') ? (string)MD_MAIL_TRANSPORT : (MD_DEMO_MODE ? 'log' : 'mail');
    return in_array($t, ['log', 'mail', 'smtp'], true) ? $t : 'mail';
}

function md_mail_conf(): array
{
    $club = md_club();
    return ['from_email' => trim(md_setting('mail.from_email', '')), 'from_name' => trim(md_setting('mail.from_name', '')) ?: ($club['name'] ?: MD_CLUB_NAME),
            'reply_to' => trim(md_setting('mail.reply_to', '')), 'transport' => md_mail_transport()];
}

function md_mail_conf_problems(array $c): array
{
    $p = [];
    if ($c['from_email'] === '' || !filter_var($c['from_email'], FILTER_VALIDATE_EMAIL)) $p[] = 'Absenderadresse fehlt oder ist ungültig';
    if ($c['reply_to'] !== '' && !filter_var($c['reply_to'], FILTER_VALIDATE_EMAIL)) $p[] = 'Antwortadresse ist ungültig';
    if ($c['transport'] === 'smtp' && (!defined('MD_SMTP_HOST') || trim((string)MD_SMTP_HOST) === '')) $p[] = 'SMTP-Server fehlt (MD_SMTP_HOST in config.php)';
    return $p;
}

/** Kopfzeilen dürfen keine Zeilenumbrüche enthalten (Header-Injection). */
function md_mail_clean_header(string $s): string
{
    return trim(preg_replace('/[\r\n\0]+/', ' ', $s));
}

function md_mail_encode_header(string $s): string
{
    $s = md_mail_clean_header($s);
    return preg_match('/^[\x20-\x7e]*$/', $s) ? $s : '=?UTF-8?B?' . base64_encode($s) . '?=';
}

function md_mail_address(string $email, string $name = ''): string
{
    $email = md_mail_clean_header($email);
    $name = md_mail_clean_header($name);
    return $name === '' ? $email : md_mail_encode_header($name) . ' <' . $email . '>';
}

/** Stellt eine Nachricht in die Warteschlange. Gibt [mail_id|null, Fehlertext] zurück. */
function md_mail_queue(?int $member, string $to, string $subject, string $body, string $kind = 'other', ?string $ref = null, ?int $by = null, ?string $replyTo = null, ?string $unsubUrl = null): array
{
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) return [null, 'E-Mail-Adresse fehlt oder ist ungültig.'];
    $subject = md_mail_clean_header($subject);
    if ($subject === '' || trim($body) === '') return [null, 'Betreff und Text dürfen nicht leer sein.'];
    if (!isset(MD_MAIL_KINDS[$kind])) $kind = 'other';
    if ($replyTo !== null && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $replyTo = null;
    md_exec('INSERT INTO md_mail_outbox (member_id, to_email, subject, body, kind, ref, status, attempts, created_at, created_by, reply_to, unsub_url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [$member, $to, mb_substr($subject, 0, 255), str_replace(["\r\n", "\r"], "\n", $body), $kind, $ref ? mb_substr($ref, 0, 40) : null, 'queued', 0, md_now(), $by, $replyTo, $unsubUrl ? mb_substr($unsubUrl, 0, 255) : null]);
    return [(int)md_db()->lastInsertId(), ''];
}

/** Fertige Nachricht (Kopf und Text) für den Versand. */
function md_mail_build(array $m, array $conf): array
{
    $headers = [
        'Date: ' . date('r'),
        'From: ' . md_mail_address($conf['from_email'], $conf['from_name']),
        'To: ' . md_mail_address($m['to_email']),
        'Subject: ' . md_mail_encode_header($m['subject']),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'Auto-Submitted: auto-generated',
        'Message-ID: <md-' . (int)$m['mail_id'] . '.' . bin2hex(random_bytes(6)) . '@' . (preg_replace('/^.*@/', '', $conf['from_email']) ?: 'localhost') . '>',
    ];
    $rt = !empty($m['reply_to']) ? $m['reply_to'] : $conf['reply_to'];
    if ($rt !== '') $headers[] = 'Reply-To: ' . md_mail_address($rt);
    if (!empty($m['unsub_url'])) { $headers[] = 'List-Unsubscribe: <' . md_mail_clean_header($m['unsub_url']) . '>'; $headers[] = 'Precedence: bulk'; }
    $body = chunk_split(base64_encode(str_replace("\n", "\r\n", $m['body'])), 76, "\r\n");
    return [$headers, $body];
}

/** Versendet eine wartende oder fehlgeschlagene Nachricht. Gibt Fehlertext oder '' zurück. */
function md_mail_send(int $id): string
{
    $m = md_one('SELECT * FROM md_mail_outbox WHERE mail_id = ?', [$id]);
    if (!$m) return 'Nachricht nicht gefunden.';
    if (!in_array($m['status'], ['queued', 'failed'], true)) return 'Diese Nachricht ist nicht mehr versandbereit.';
    $conf = md_mail_conf();
    if ($p = md_mail_conf_problems($conf)) return 'E-Mail-Einstellungen unvollständig: ' . implode('; ', $p) . '.';
    [$headers, $body] = md_mail_build($m, $conf);
    try {
        match ($conf['transport']) {
            'log' => md_mail_transport_log($m, $headers, $body),
            'smtp' => md_mail_transport_smtp($m, $conf, $headers, $body),
            default => md_mail_transport_mail($m, $headers, $body),
        };
        md_exec("UPDATE md_mail_outbox SET status = 'sent', attempts = attempts + 1, last_error = NULL, sent_at = ? WHERE mail_id = ?", [md_now(), $id]);
        return '';
    } catch (Throwable $e) {
        md_exec("UPDATE md_mail_outbox SET status = 'failed', attempts = attempts + 1, last_error = ? WHERE mail_id = ?", [mb_substr($e->getMessage(), 0, 255), $id]);
        return $e->getMessage();
    }
}

/** Versendet alle wartenden Nachrichten (höchstens $limit). Gibt [versendet, fehlgeschlagen, erste Fehlermeldung] zurück. */
function md_mail_send_pending(int $limit = 50): array
{
    $ok = $bad = 0;
    $first = '';
    foreach (md_q("SELECT mail_id FROM md_mail_outbox WHERE status = 'queued' ORDER BY mail_id LIMIT " . (int)$limit) as $r) {
        $err = md_mail_send((int)$r['mail_id']);
        if ($err === '') $ok++;
        else { $bad++; $first = $first ?: $err; }
    }
    return [$ok, $bad, $first];
}

function md_mail_cancel(int $id): string
{
    $m = md_one('SELECT status FROM md_mail_outbox WHERE mail_id = ?', [$id]);
    if (!$m || !in_array($m['status'], ['queued', 'failed'], true)) return 'Nur wartende oder fehlgeschlagene Nachrichten können abgebrochen werden.';
    md_exec("UPDATE md_mail_outbox SET status = 'cancelled' WHERE mail_id = ?", [$id]);
    return '';
}

/** Warteschlange und sofort senden – für Nachrichten, die gleich beim Mitglied ankommen sollen (Passwort-Link). */
function md_mail_now(?int $member, string $to, string $subject, string $body, string $kind, ?string $ref = null, ?int $by = null): string
{
    [$id, $err] = md_mail_queue($member, $to, $subject, $body, $kind, $ref, $by);
    return $id ? md_mail_send($id) : $err;
}

function md_mail_transport_log(array $m, array $headers, string $body): void
{
    $dir = MD_STORAGE_DIR . '/mail';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Ordner storage/mail kann nicht angelegt werden.');
    $file = sprintf('%s/%s-%05d.eml', $dir, date('Ymd-His'), (int)$m['mail_id']);
    if (file_put_contents($file, implode("\r\n", $headers) . "\r\n\r\n" . $body) === false) throw new RuntimeException('Nachricht kann nicht gespeichert werden.');
}

function md_mail_transport_mail(array $m, array $headers, string $body): void
{
    $subject = array_values(array_filter($headers, fn($h) => str_starts_with($h, 'Subject: ')))[0] ?? 'Subject: ';
    $rest = array_values(array_filter($headers, fn($h) => !str_starts_with($h, 'Subject: ') && !str_starts_with($h, 'To: ') && !str_starts_with($h, 'Date: ')));
    if (!function_exists('mail') || !@mail($m['to_email'], substr($subject, 9), $body, implode("\r\n", $rest))) throw new RuntimeException('PHP mail() hat die Nachricht nicht angenommen (Server-Einstellung prüfen, besser SMTP verwenden).');
}

function md_mail_transport_smtp(array $m, array $conf, array $headers, string $body): void
{
    $host = (string)MD_SMTP_HOST;
    $port = defined('MD_SMTP_PORT') ? (int)MD_SMTP_PORT : 587;
    $secure = defined('MD_SMTP_SECURE') ? strtolower((string)MD_SMTP_SECURE) : 'tls';   // tls = STARTTLS, ssl = direkt verschlüsselt, none
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port, $en, $es, 15);
    if (!$fp) throw new RuntimeException("SMTP: keine Verbindung zu $host:$port ($es)");
    stream_set_timeout($fp, 15);
    $read = function () use ($fp): array {
        $code = '';
        $text = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $text .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') { $code = substr($line, 0, 3); break; }
        }
        return [$code, trim($text)];
    };
    $cmd = function (string $c, array $okCodes) use ($fp, $read): string {
        if ($c !== '') fwrite($fp, $c . "\r\n");
        [$code, $text] = $read();
        if (!in_array($code, $okCodes, true)) throw new RuntimeException('SMTP: ' . mb_substr($text !== '' ? $text : 'keine Antwort', 0, 200));
        return $text;
    };
    try {
        $cmd('', ['220']);
        $ehlo = 'EHLO ' . (preg_replace('/[^A-Za-z0-9.-]/', '', (string)($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost');
        $cmd($ehlo, ['250']);
        if ($secure === 'tls') {
            $cmd('STARTTLS', ['220']);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP: TLS-Verbindung fehlgeschlagen');
            $cmd($ehlo, ['250']);
        }
        if (defined('MD_SMTP_USER') && (string)MD_SMTP_USER !== '') {
            $cmd('AUTH LOGIN', ['334']);
            $cmd(base64_encode((string)MD_SMTP_USER), ['334']);
            $cmd(base64_encode(defined('MD_SMTP_PASS') ? (string)MD_SMTP_PASS : ''), ['235']);
        }
        $cmd('MAIL FROM:<' . md_mail_clean_header($conf['from_email']) . '>', ['250']);
        $cmd('RCPT TO:<' . md_mail_clean_header($m['to_email']) . '>', ['250', '251']);
        $cmd('DATA', ['354']);
        $msg = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $msg = preg_replace('/^\./m', '..', $msg);   // Punkt-Maskierung
        fwrite($fp, $msg . "\r\n.\r\n");
        [$code, $text] = $read();
        if ($code !== '250') throw new RuntimeException('SMTP: ' . mb_substr($text, 0, 200));
        @fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}

// ---------------------------------------------------------------- Texte

function md_mail_footer(): string
{
    $club = md_club();
    return "\n--\n" . $club['name'] . ($club['street'] !== '' ? "\n" . $club['street'] . ', ' . trim($club['zip'] . ' ' . $club['city']) : '');
}

/** Empfängeradresse eines Mitglieds (svmembers.email); Platzhalteradressen der Importe zählen nicht. */
function md_mail_address_of(int $member): ?string
{
    $r = md_one('SELECT email FROM svmembers WHERE member_id = ?', [$member]);
    $e = trim((string)($r['email'] ?? ''));
    if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL) || preg_match('/@platzhalter\.|\.invalid$/i', $e)) return null;
    return $e;
}

/** Mahnschreiben als reiner Text für den Mailkörper. */
function md_dunning_mail_text(array $l): string
{
    $s = json_decode($l['snapshot'], true);
    $club = $s['club'];
    $conf = $s['conf'];
    $t = $s['level_name'] . ' ' . $l['letter_no'] . ' vom ' . md_date(substr($l['issued_at'], 0, 10)) . "\nMitgliedsnummer: " . $s['membership_number'] . "\n\n" . $s['body'] . "\n\n";
    foreach ($s['items'] as $it) $t .= sprintf("  %s  %s (fällig %s): %s\n", $it['reference'], $it['description'], md_date($it['due_date']), md_money($it['cents']));
    if ($s['fee_cents'] > 0) $t .= '  ' . $l['letter_no'] . '  Mahngebühr (' . $s['level_name'] . '): ' . md_money($s['fee_cents']) . "\n";
    $t .= "\nOffener Gesamtbetrag: " . md_money($s['total_cents']) . "\nBitte überweisen Sie bis zum " . md_date($s['deadline']) . ' unter Angabe von ' . $l['letter_no'] . ' ' . $s['membership_number'] . " auf:\n\n"
        . '  Empfänger: ' . $club['name'] . "\n" . ($conf['iban'] !== '' ? '  IBAN: ' . $conf['iban'] . "\n" : '') . ($conf['bic'] !== '' ? '  BIC: ' . $conf['bic'] . "\n" : '') . ($conf['bank'] !== '' ? '  Bank: ' . $conf['bank'] . "\n" : '')
        . "\nMit freundlichen Grüßen\n" . $club['signer'] . "\n" . md_mail_footer();
    return $t;
}

/** Rechnung als reiner Text. $sepa wie bei md_invoice_html. */
function md_invoice_mail_text(array $e, array $club, array $conf, ?array $sepa = null): string
{
    $due = $e['due_date'] ? md_date($e['due_date']) : '';
    $t = 'Rechnung ' . $e['reference'] . ' vom ' . md_date($e['entry_date']) . "\nMitgliedsnummer: " . $e['membership_number'] . ($due ? "\nZahlbar bis: $due" : '') . "\n\n" . $conf['invoice_text'] . "\n\n"
        . '  ' . $e['description'] . ': ' . md_money((int)$e['amount_cents']) . "\n  Gesamtbetrag: " . md_money((int)$e['amount_cents']) . "\n";
    if (trim($conf['tax_note']) !== '') $t .= "\n" . $conf['tax_note'] . "\n";
    if ($sepa) {
        $t .= "\nDer Betrag wird am " . md_date($sepa['date']) . ' per SEPA-Basislastschrift von Ihrem Konto ' . $sepa['iban_mask'] . ' eingezogen (Vorabankündigung). Gläubiger-Identifikationsnummer: ' . $sepa['creditor_id'] . ', Mandatsreferenz: ' . $sepa['mandate_ref'] . ". Bitte sorgen Sie für ausreichende Deckung; eine Überweisung ist nicht erforderlich.\n";
    } else {
        $t .= "\nBitte überweisen Sie den Betrag" . ($due ? " bis zum $due" : '') . ' unter Angabe von ' . $e['reference'] . ' ' . $e['membership_number'] . " auf:\n\n  Empfänger: " . $club['name'] . "\n"
            . ($conf['iban'] !== '' ? '  IBAN: ' . $conf['iban'] . "\n" : '') . ($conf['bic'] !== '' ? '  BIC: ' . $conf['bic'] . "\n" : '') . ($conf['bank'] !== '' ? '  Bank: ' . $conf['bank'] . "\n" : '');
    }
    return $t . "\nMit freundlichen Grüßen\n" . $club['signer'] . "\n" . md_mail_footer();
}

/** Mahnschreiben per E-Mail in die Warteschlange stellen. Gibt [mail_id|null, Fehlertext] zurück. */
function md_mail_queue_letter(int $letterId, int $by): array
{
    $l = md_one('SELECT * FROM md_dunning_letters WHERE letter_id = ?', [$letterId]);
    if (!$l || $l['status'] !== 'issued') return [null, 'Mahnschreiben nicht gefunden oder storniert.'];
    if (md_one("SELECT 1 FROM md_mail_outbox WHERE kind = 'dunning' AND ref = ? AND status IN ('queued','sent')", [$l['letter_no']])) return [null, 'Dieses Schreiben wurde schon per E-Mail versandt oder liegt in der Warteschlange.'];
    $to = md_mail_address_of((int)$l['member_id']);
    if (!$to) return [null, 'Keine gültige E-Mail-Adresse beim Mitglied.'];
    $s = json_decode($l['snapshot'], true);
    [$id, $err] = md_mail_queue((int)$l['member_id'], $to, $s['level_name'] . ' ' . $l['letter_no'] . ' – ' . $s['club']['name'], md_dunning_mail_text($l), 'dunning', $l['letter_no'], $by);
    if ($id) md_log_change((int)$l['member_id'], 'dunning', $s['level_name'] . ' ' . $l['letter_no'], 'per E-Mail zum Versand vorgemerkt', $by, 'admin');
    return [$id, $err];
}

/** Rechnung (Forderung) per E-Mail in die Warteschlange stellen. */
function md_mail_queue_invoice(int $entryId, int $by): array
{
    $e = md_one('SELECT l.*, s.membership_number FROM md_ledger l JOIN svmembers s ON s.member_id = l.member_id WHERE l.entry_id = ?', [$entryId]);
    if (!$e || $e['direction'] !== 'to_club' || empty($e['reference'])) return [null, 'Keine Rechnung.'];
    if ($e['status'] !== 'open') return [null, 'Die Rechnung ist nicht mehr offen.'];
    if (md_one("SELECT 1 FROM md_mail_outbox WHERE kind = 'invoice' AND ref = ? AND status IN ('queued','sent')", [$e['reference']])) return [null, 'Diese Rechnung wurde schon per E-Mail versandt oder liegt in der Warteschlange.'];
    $to = md_mail_address_of((int)$e['member_id']);
    if (!$to) return [null, 'Keine gültige E-Mail-Adresse beim Mitglied.'];
    $club = md_club();
    [$id, $err] = md_mail_queue((int)$e['member_id'], $to, 'Rechnung ' . $e['reference'] . ' – ' . $club['name'], md_invoice_mail_text($e, $club, md_fee_conf(), md_sepa_debit_info((int)$e['member_id'], $e['due_date'])), 'invoice', $e['reference'], $by);
    if ($id) md_log_change((int)$e['member_id'], 'fee', 'Rechnung ' . $e['reference'], 'per E-Mail zum Versand vorgemerkt', $by, 'admin');
    return [$id, $err];
}

/** Zustellweg des Mitglieds für Rechnungen und Mahnungen: 'mail' nur, wenn gewählt und eine Adresse vorhanden ist. */
function md_letter_channel(int $member): string
{
    $p = md_one('SELECT letter_channel FROM md_profiles WHERE member_id = ?', [$member]);
    return ($p['letter_channel'] ?? 'post') === 'mail' && md_mail_address_of($member) ? 'mail' : 'post';
}
