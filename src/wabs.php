<?php
declare(strict_types=1);

/**
 * WABS (Wahl und Abstimmung): vom Wahlausschuss angelegte Wahlen.
 * Phase 1: nur Titel/Zeitraum anlegen und anzeigen. Token-Erzeugung, Mailversand,
 * PDF/QR-Sammeldruck und Übertragung zum Wahlserver folgen in späteren Phasen.
 */

/** Alle Wahlen, neueste zuerst. */
function md_wabs_elections(): array
{
    return md_q('SELECT * FROM md_wabs_elections ORDER BY election_id DESC');
}

/** Legt eine neue Wahl an. Gibt bei einem Eingabefehler den Fehlertext zurück, sonst ''. */
function md_wabs_election_create(string $title, string $startAt, string $endAt, int $by): string
{
    $title = trim($title);
    if ($title === '') return 'Bitte eine Bezeichnung angeben.';
    $start = strtotime($startAt);
    $end = strtotime($endAt);
    if ($start === false || $end === false) return 'Bitte Start- und Enddatum (mit Uhrzeit) angeben.';
    if ($end <= $start) return 'Das Ende muss nach dem Start liegen.';
    md_exec('INSERT INTO md_wabs_elections (title, start_at, end_at, status, created_by, created_at) VALUES (?,?,?,?,?,?)',
        [mb_substr($title, 0, 200), date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end), 'angelegt', $by, md_now()]);
    return '';
}

/** Die aktuell laufende Wahl (start_at <= jetzt <= end_at), falls es eine gibt - für den Dashboard-Hinweis. */
function md_wabs_election_running(): ?array
{
    return md_one("SELECT * FROM md_wabs_elections WHERE start_at <= ? AND end_at >= ? ORDER BY end_at ASC LIMIT 1", [md_now(), md_now()]);
}

function md_wabs_election_get(int $id): ?array
{
    return md_one('SELECT * FROM md_wabs_elections WHERE election_id = ?', [$id]);
}

/** Speichert die Basis-URL zum Wahlserver für diese Wahl (ohne abschließenden Slash, ohne Dateiname). */
function md_wabs_election_set_vote_base_url(int $id, string $url): string
{
    $url = trim($url);
    if ($url !== '' && !preg_match('~^https?://~i', $url)) return 'Die Link-Basis-URL muss mit http:// oder https:// beginnen.';
    md_exec('UPDATE md_wabs_elections SET vote_base_url = ? WHERE election_id = ?', [$url !== '' ? rtrim($url, '/') : null, $id]);
    return '';
}

/**
 * Alle aktiven Mitglieder (gleicher Filter wie bei den E-Mail-Verteilern, siehe
 * md_list_recipients()): gesperrte, ausgetretene und anonymisierte Mitglieder sind
 * ausgeschlossen.
 */
function md_wabs_eligible_members(): array
{
    return md_q("SELECT DISTINCT s.* FROM svmembers s LEFT JOIN md_profiles p ON p.member_id = s.member_id
                 WHERE COALESCE(s.is_active, 1) <> 0 AND s.membership_number NOT LIKE 'DEL-%' AND COALESCE(p.status, 'aktiv') <> 'ausgetreten'
                   AND (p.leave_date IS NULL OR p.leave_date >= ?) ORDER BY s.last_name, s.first_name", [date('Y-m-d')]);
}

/** Zahlen für die Anzeige: bereits versendet/fehlgeschlagen/wartend, sowie (noch) nicht berücksichtigt mit/ohne E-Mail (ohne E-Mail zusätzlich danach, ob eine Postanschrift vorliegt). */
function md_wabs_requests_summary(int $electionId): array
{
    $byStatus = ['sent' => 0, 'failed' => 0, 'queued' => 0];
    foreach (md_q('SELECT status, COUNT(*) AS n FROM md_wabs_recipients WHERE election_id = ? GROUP BY status', [$electionId]) as $r) {
        $byStatus[$r['status']] = (int)$r['n'];
    }
    $done = array_column(md_q('SELECT member_id FROM md_wabs_recipients WHERE election_id = ?', [$electionId]), 'member_id');
    $doneMap = array_flip(array_map('intval', $done));
    $offenMitMail = 0;
    $offenOhneMailMitAdresse = 0;
    $unreachable = 0;
    foreach (md_wabs_eligible_members() as $m) {
        $mid = (int)$m['member_id'];
        if (isset($doneMap[$mid])) continue;
        if (md_mail_address_of($mid)) { $offenMitMail++; continue; }
        if (md_donor($mid)) $offenOhneMailMitAdresse++;
        else $unreachable++;
    }
    return ['sent' => $byStatus['sent'], 'failed' => $byStatus['failed'], 'queued_recipients' => $byStatus['queued'],
        'queue_rows' => (int)(md_one('SELECT COUNT(*) c FROM md_wabs_mail_queue WHERE election_id = ?', [$electionId])['c'] ?? 0),
        'offen_mit_mail' => $offenMitMail, 'offen_ohne_mail' => $offenOhneMailMitAdresse, 'unreachable' => $unreachable];
}

/** Namen der Mitglieder, deren Wahlaufforderung fehlgeschlagen ist (für die "erneut versuchen"-Liste). */
function md_wabs_failed_recipients(int $electionId): array
{
    return md_q("SELECT s.member_id, s.first_name, s.last_name, r.attempted_at FROM md_wabs_recipients r
                 JOIN svmembers s ON s.member_id = r.member_id WHERE r.election_id = ? AND r.status = 'failed' ORDER BY s.last_name, s.first_name", [$electionId]);
}

/** Erzeugt ein neues, kryptografisch sicheres Token und trägt dessen Hash in die anonyme Token-Liste ein. Gibt [token_id, Klartext-Token] zurück. */
function md_wabs_token_issue(int $electionId): array
{
    $raw = bin2hex(random_bytes(24));
    md_exec('INSERT INTO md_wabs_tokens (election_id, token_hash, created_at) VALUES (?,?,?)', [$electionId, hash('sha256', $raw), md_now()]);
    return [(int)md_db()->lastInsertId(), $raw];
}

/** Text der Wahlaufforderung (Mail und später PDF teilen sich diesen Wortlaut). */
function md_wabs_invitation_text(array $election, string $voteUrl): string
{
    return 'Bitte nimm an der folgenden Wahl/Abstimmung teil:' . "\n\n" . '„' . $election['title'] . '“' . "\n"
        . 'Zeitraum: ' . date('d.m.Y H:i', strtotime($election['start_at'])) . ' bis ' . date('d.m.Y H:i', strtotime($election['end_at'])) . ' Uhr' . "\n\n"
        . 'Dein persönlicher Zugang (bitte nicht weitergeben):' . "\n" . $voteUrl . "\n\n"
        . 'Der Zugang ist nur einmal gültig und wird mit der Stimmabgabe ungültig.';
}

/**
 * Stellt für alle aktiven Mitglieder mit E-Mail-Adresse, die für diese Wahl noch
 * nicht berücksichtigt wurden, ein Token aus und reiht die Wahlaufforderung in die
 * Warteschlange ein. Mitglieder ohne E-Mail-Adresse werden nur gezählt (siehe
 * Phase 3: PDF-Sammeldruck). Gibt [eingereiht, ohneMail, Fehler] zurück.
 */
function md_wabs_requests_generate(int $electionId): array
{
    $election = md_wabs_election_get($electionId);
    if (!$election) return [0, 0, 'Wahl nicht gefunden.'];
    if (!empty($election['transferred_at'])) return [0, 0, 'Diese Wahl wurde bereits zum Wahlserver übertragen - neue Token würden dort nicht mehr ankommen.'];
    if (trim((string)($election['vote_base_url'] ?? '')) === '') return [0, 0, 'Bitte zuerst die Link-Basis-URL zum Wahlserver eintragen.'];
    $done = array_flip(array_map('intval', array_column(md_q('SELECT member_id FROM md_wabs_recipients WHERE election_id = ?', [$electionId]), 'member_id')));
    $queued = 0;
    $noMail = 0;
    foreach (md_wabs_eligible_members() as $m) {
        $mid = (int)$m['member_id'];
        if (isset($done[$mid])) continue;
        $email = md_mail_address_of($mid);
        if (!$email) { $noMail++; continue; }
        [$tokenId, $raw] = md_wabs_token_issue($electionId);
        $voteUrl = $election['vote_base_url'] . '/vote.php?token=' . $raw;
        md_exec('INSERT INTO md_wabs_mail_queue (election_id, member_id, token_id, to_email, subject, body, created_at) VALUES (?,?,?,?,?,?,?)',
            [$electionId, $mid, $tokenId, $email, 'Wahlaufforderung: ' . $election['title'], md_wabs_invitation_text($election, $voteUrl), md_now()]);
        md_exec('INSERT INTO md_wabs_recipients (election_id, member_id, status, attempted_at) VALUES (?,?,?,?)', [$electionId, $mid, 'queued', md_now()]);
        $queued++;
    }
    return [$queued, $noMail, ''];
}

/** Stößt für ein einzelnes, zuvor fehlgeschlagenes Mitglied ein neues Token und eine neue Wahlaufforderung an. */
function md_wabs_request_retry(int $electionId, int $memberId): string
{
    $election = md_wabs_election_get($electionId);
    if (!$election) return 'Wahl nicht gefunden.';
    if (!empty($election['transferred_at'])) return 'Diese Wahl wurde bereits zum Wahlserver übertragen - ein neues Token würde dort nicht mehr ankommen.';
    if (trim((string)($election['vote_base_url'] ?? '')) === '') return 'Bitte zuerst die Link-Basis-URL zum Wahlserver eintragen.';
    $r = md_one("SELECT status FROM md_wabs_recipients WHERE election_id = ? AND member_id = ?", [$electionId, $memberId]);
    if (!$r || $r['status'] !== 'failed') return 'Für dieses Mitglied liegt kein Fehlschlag vor.';
    $email = md_mail_address_of($memberId);
    if (!$email) return 'Dieses Mitglied hat keine (gültige) E-Mail-Adresse.';
    [$tokenId, $raw] = md_wabs_token_issue($electionId);
    $voteUrl = $election['vote_base_url'] . '/vote.php?token=' . $raw;
    md_exec('INSERT INTO md_wabs_mail_queue (election_id, member_id, token_id, to_email, subject, body, created_at) VALUES (?,?,?,?,?,?,?)',
        [$electionId, $memberId, $tokenId, $email, 'Wahlaufforderung: ' . $election['title'], md_wabs_invitation_text($election, $voteUrl), md_now()]);
    md_exec("UPDATE md_wabs_recipients SET status = 'queued', attempted_at = ? WHERE election_id = ? AND member_id = ?", [md_now(), $electionId, $memberId]);
    return '';
}

/** Versendet eine Zeile der Warteschlange und löscht sie sofort - erfolgreich oder nicht (siehe Tabellenkommentar in schema.sql). Gibt Fehlertext oder '' zurück. */
function md_wabs_mail_queue_send_one(array $row): string
{
    $conf = md_mail_conf();
    $problems = md_mail_conf_problems($conf);
    if ($problems) return 'E-Mail-Einstellungen unvollständig: ' . implode('; ', $problems) . '.';
    $m = ['mail_id' => $row['queue_id'], 'to_email' => $row['to_email'], 'subject' => $row['subject'], 'body' => $row['body']];
    [$headers, $body] = md_mail_build($m, $conf);
    $err = '';
    try {
        match ($conf['transport']) {
            'log' => md_mail_transport_log($m, $headers, $body),
            'smtp' => md_mail_transport_smtp($m, $conf, $headers, $body),
            default => md_mail_transport_mail($m, $headers, $body),
        };
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    md_exec('DELETE FROM md_wabs_mail_queue WHERE queue_id = ?', [(int)$row['queue_id']]);
    if ($err === '') {
        md_exec("UPDATE md_wabs_recipients SET status = 'sent', attempted_at = ? WHERE election_id = ? AND member_id = ?", [md_now(), (int)$row['election_id'], (int)$row['member_id']]);
    } else {
        // Fehlgeschlagener Versand: das zugehörige Token war nie zustellbar und wird ungültig gemacht -
        // ein erneuter Versuch (siehe md_wabs_request_retry()) erzeugt bewusst ein komplett neues Token.
        md_exec('DELETE FROM md_wabs_tokens WHERE token_id = ?', [(int)$row['token_id']]);
        md_exec("UPDATE md_wabs_recipients SET status = 'failed', attempted_at = ? WHERE election_id = ? AND member_id = ?", [md_now(), (int)$row['election_id'], (int)$row['member_id']]);
    }
    return $err;
}

/** Versendet bis zu $limit wartende Wahlaufforderungen dieser Wahl. Gibt [versendet, fehlgeschlagen, erste Fehlermeldung] zurück. */
function md_wabs_queue_send(int $electionId, int $limit = 50): array
{
    $ok = $bad = 0;
    $first = '';
    foreach (md_q('SELECT * FROM md_wabs_mail_queue WHERE election_id = ? ORDER BY queue_id LIMIT ' . (int)$limit, [$electionId]) as $row) {
        $err = md_wabs_mail_queue_send_one($row);
        if ($err === '') $ok++;
        else { $bad++; $first = $first ?: $err; }
    }
    return [$ok, $bad, $first];
}

// ============================================
// PHASE 3: PDF-SAMMELDRUCK MIT QR-CODE
// Für Mitglieder ohne E-Mail-Adresse, aber mit Postanschrift. FPDF und phpqrcode
// liegen bewusst NICHT im Repo, sondern als fertige Dateien zwei Ebenen über dem
// Anwendungsverzeichnis (gemeinsam mit den Geschwisteranwendungen VERA/KONA
// nutzbar) - siehe docs/WABS.md.
// ============================================

/** Verzeichnis für die PDF-Sammeldrucke - außerhalb des Web-Dokumentenverzeichnisses, kein Direktzugriff über die URL (wie storage/mail). */
function md_wabs_storage_dir(): string
{
    $dir = MD_STORAGE_DIR . '/wabs';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Ordner $dir kann nicht angelegt werden.");
    }
    return $dir;
}

/** Leerer String, wenn FPDF und phpqrcode gefunden wurden, sonst eine Fehlermeldung mit dem erwarteten Pfad. */
function md_wabs_pdf_libs_missing(): string
{
    $root = dirname(__DIR__, 2);
    if (!is_file($root . '/fpdf/fpdf.php')) return "FPDF wurde nicht gefunden (erwartet unter $root/fpdf/fpdf.php).";
    if (!is_file($root . '/phpqrcode/qrlib.php')) return "phpqrcode wurde nicht gefunden (erwartet unter $root/phpqrcode/qrlib.php).";
    return '';
}

/**
 * FPDFs Kernschriften (Arial/Helvetica usw.) verstehen kein UTF-8, sondern CP1252
 * (Windows-1252) - ohne diese Umwandlung werden Umlaute und typografische
 * Anführungszeichen als Mojibake ausgegeben.
 */
function md_wabs_pdf_text(string $s): string
{
    return iconv('UTF-8', 'CP1252//TRANSLIT', $s);
}

/** Eine Seite des PDF-Sammeldrucks: Anschrift, Erklärtext mit Link, QR-Code. */
function md_wabs_pdf_add_page(FPDF $pdf, array $election, array $donor, string $voteUrl, string $qrFile): void
{
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 11);
    $pdf->SetXY(20, 50);
    $pdf->MultiCell(90, 6, md_wabs_pdf_text($donor['name'] . "\n" . implode("\n", $donor['lines'])));
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->SetXY(20, 95);
    $pdf->MultiCell(170, 8, md_wabs_pdf_text('Wahlaufforderung: ' . $election['title']));
    $pdf->SetFont('Arial', '', 11);
    $pdf->SetXY(20, 115);
    $pdf->MultiCell(170, 6, md_wabs_pdf_text(md_wabs_invitation_text($election, $voteUrl)));
    $pdf->Image($qrFile, 140, 190, 40, 40);
}

/**
 * Erzeugt für alle aktiven Mitglieder ohne E-Mail-Adresse, aber mit Postanschrift, die
 * für diese Wahl noch nicht berücksichtigt wurden, je ein Token und eine Seite im
 * PDF-Sammeldruck. Mitglieder ohne jede Erreichbarkeit (weder E-Mail noch Anschrift)
 * werden nur gezählt (siehe md_wabs_requests_summary(): "unreachable"). Solange ein
 * vorheriger Sammeldruck nicht als gelöscht bestätigt ist, wird nichts Neues erzeugt.
 * Gibt [Anzahl Seiten, Fehler] zurück.
 */
function md_wabs_pdf_generate(int $electionId, int $by): array
{
    $election = md_wabs_election_get($electionId);
    if (!$election) return [0, 'Wahl nicht gefunden.'];
    if (!empty($election['transferred_at'])) return [0, 'Diese Wahl wurde bereits zum Wahlserver übertragen - neue Token würden dort nicht mehr ankommen.'];
    if (trim((string)($election['vote_base_url'] ?? '')) === '') return [0, 'Bitte zuerst die Link-Basis-URL zum Wahlserver eintragen.'];
    if (!empty($election['pdf_file']) && empty($election['pdf_deleted_at'])) {
        return [0, 'Es liegt noch ein nicht als gelöscht bestätigter PDF-Sammeldruck vor - bitte diesen zuerst abschließen.'];
    }
    if ($missing = md_wabs_pdf_libs_missing()) return [0, $missing];

    $done = array_flip(array_map('intval', array_column(md_q('SELECT member_id FROM md_wabs_recipients WHERE election_id = ?', [$electionId]), 'member_id')));
    $kandidaten = [];
    foreach (md_wabs_eligible_members() as $m) {
        $mid = (int)$m['member_id'];
        if (isset($done[$mid]) || md_mail_address_of($mid)) continue;
        $donor = md_donor($mid);
        if ($donor) $kandidaten[] = [$mid, $donor];
    }
    if (!$kandidaten) return [0, 'Keine Mitglieder zu berücksichtigen (ohne E-Mail-Adresse, aber mit Postanschrift).'];

    $root = dirname(__DIR__, 2);
    require_once $root . '/fpdf/fpdf.php';
    require_once $root . '/phpqrcode/qrlib.php';

    $dir = md_wabs_storage_dir();
    $tmpQr = $dir . '/tmp-' . bin2hex(random_bytes(6)) . '.png';
    $pdf = new FPDF();
    foreach ($kandidaten as [$mid, $donor]) {
        [, $raw] = md_wabs_token_issue($electionId);
        $voteUrl = $election['vote_base_url'] . '/vote.php?token=' . $raw;
        QRcode::png($voteUrl, $tmpQr, QR_ECLEVEL_M, 5, 2);
        md_wabs_pdf_add_page($pdf, $election, $donor, $voteUrl, $tmpQr);
        @unlink($tmpQr);
        md_exec('INSERT INTO md_wabs_recipients (election_id, member_id, status, channel, attempted_at) VALUES (?,?,?,?,?)',
            [$electionId, $mid, 'sent', 'pdf', md_now()]);
    }
    $filename = 'wahl-' . $electionId . '-anschreiben-' . date('Ymd-His') . '.pdf';
    $pdf->Output($dir . '/' . $filename, 'F');
    md_exec('UPDATE md_wabs_elections SET pdf_file = ?, pdf_generated_at = ?, pdf_deleted_at = NULL WHERE election_id = ?', [$filename, md_now(), $electionId]);
    md_exec('INSERT INTO md_changelog (member_id, field_key, old_value, new_value, changed_by, changed_via, changed_at) VALUES (?,?,?,?,?,?,?)',
        [$by, 'wabs.pdf_generated', null, $filename . ' (' . count($kandidaten) . ' Seiten, Wahl „' . $election['title'] . '“)', $by, 'admin', md_now()]);
    return [count($kandidaten), ''];
}

/** Löscht den PDF-Sammeldruck endgültig von der Festplatte (Pflicht nach Druck/Versand) und protokolliert das. */
function md_wabs_pdf_delete(int $electionId, int $by): string
{
    $election = md_wabs_election_get($electionId);
    if (!$election) return 'Wahl nicht gefunden.';
    if (empty($election['pdf_file'])) return 'Es liegt kein PDF-Sammeldruck vor.';
    if (!empty($election['pdf_deleted_at'])) return 'Dieser PDF-Sammeldruck wurde bereits als gelöscht bestätigt.';
    $path = md_wabs_storage_dir() . '/' . $election['pdf_file'];
    if (is_file($path) && !@unlink($path)) return 'Die Datei konnte nicht gelöscht werden - bitte auf dem Server prüfen.';
    md_exec('UPDATE md_wabs_elections SET pdf_deleted_at = ? WHERE election_id = ?', [md_now(), $electionId]);
    md_exec('INSERT INTO md_changelog (member_id, field_key, old_value, new_value, changed_by, changed_via, changed_at) VALUES (?,?,?,?,?,?,?)',
        [$by, 'wabs.pdf_deleted', null, $election['pdf_file'] . ' (Wahl „' . $election['title'] . '“)', $by, 'admin', md_now()]);
    return '';
}

// ============================================
// PHASE 4: ÜBERTRAGUNG DER TOKEN-LISTE ZUM WAHLSERVER
// Bei gleichem Server/gleicher Datenbank: direkte Übertragung in zwei schlanke
// Zieltabellen (wabs_elections, wabs_tokens - Grundlage für den eigenständigen
// Wahlserver aus Phase 5, der darauf aufbaut). Bei externem Server: CSV-Export
// zum manuellen Import dort. In beiden Fällen wird die Token-Liste danach aus
// MISS gelöscht - sie hat dort ihren Zweck erfüllt. Die Wahlserver-Verbindung
// ist eine einzige, installationsweite Einstellung (nicht je Wahl), um den
// Aufwand gering zu halten.
// ============================================

/** Zahl der aktuell noch gültigen (nicht gelöschten) Token dieser Wahl. */
function md_wabs_tokens_count(int $electionId): int
{
    return (int)(md_one('SELECT COUNT(*) c FROM md_wabs_tokens WHERE election_id = ?', [$electionId])['c'] ?? 0);
}

/** Aktuelle Verbindungseinstellungen zum Wahlserver (Passwort entschlüsselt, leer wenn nicht gesetzt/entschlüsselbar). */
function md_wabs_transfer_conf(): array
{
    return [
        'mode' => md_setting('wabs.transfer_mode', ''),
        'host' => md_setting('wabs.db_host', ''),
        'port' => md_setting('wabs.db_port', '3306'),
        'name' => md_setting('wabs.db_name', ''),
        'user' => md_setting('wabs.db_user', ''),
        'pass' => (string)(md_decrypt(md_setting('wabs.db_pass_enc', '')) ?? ''),
    ];
}

/** Speichert die Verbindungseinstellungen zum Wahlserver. Das Passwort wird nur bei Eingabe überschrieben (leer = unverändert). */
function md_wabs_transfer_conf_save(string $mode, string $host, string $port, string $name, string $user, string $pass): string
{
    if (!in_array($mode, ['direct', 'csv'], true)) return 'Bitte eine Übertragungsart wählen.';
    if ($mode === 'direct' && ($host === '' || $name === '' || $user === '')) return 'Bitte Datenbankserver, Datenbankname und Benutzer angeben.';
    foreach ([['wabs.transfer_mode', $mode, 'Übertragungsart für die WABS-Token-Liste (direct, csv)'],
              ['wabs.db_host', $host, 'Wahlserver-Datenbank: Host'], ['wabs.db_port', $port !== '' ? $port : '3306', 'Wahlserver-Datenbank: Port'],
              ['wabs.db_name', $name, 'Wahlserver-Datenbank: Datenbankname'], ['wabs.db_user', $user, 'Wahlserver-Datenbank: Benutzer']] as [$k, $v, $d]) {
        if (md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', [$k])) md_exec('UPDATE md_settings SET setting_value = ? WHERE setting_key = ?', [$v, $k]);
        else md_exec('INSERT INTO md_settings (setting_key, setting_value, description) VALUES (?,?,?)', [$k, $v, $d]);
    }
    if (trim($pass) !== '') {
        $enc = md_encrypt($pass);
        if ($enc === null) return 'Das Passwort kann nicht verschlüsselt werden: MD_ENCRYPTION_KEY fehlt in config.php (php tools/make_key.php).';
        if (md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', ['wabs.db_pass_enc'])) md_exec('UPDATE md_settings SET setting_value = ? WHERE setting_key = ?', [$enc, 'wabs.db_pass_enc']);
        else md_exec('INSERT INTO md_settings (setting_key, setting_value, description) VALUES (?,?,?)', ['wabs.db_pass_enc', $enc, 'Wahlserver-Datenbank: Passwort (verschlüsselt)']);
    }
    return '';
}

/** Öffnet die Verbindung zur Wahlserver-Datenbank. Wirft eine Exception bei Fehlern (Aufrufer fängt sie). */
function md_wabs_transfer_db(array $conf): PDO
{
    $pdo = new PDO('mysql:host=' . $conf['host'] . ';port=' . ((int)$conf['port'] ?: 3306) . ';dbname=' . $conf['name'] . ';charset=utf8mb4', $conf['user'], $conf['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

/** Stellt die beiden schlanken Zieltabellen auf dem Wahlserver sicher - Grundlage für Phase 5. */
function md_wabs_transfer_ensure_schema(PDO $tdb): void
{
    $tdb->exec('CREATE TABLE IF NOT EXISTS wabs_elections (
        election_id INT PRIMARY KEY, title VARCHAR(200) NOT NULL, start_at DATETIME NOT NULL, end_at DATETIME NOT NULL, transferred_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $tdb->exec('CREATE TABLE IF NOT EXISTS wabs_tokens (
        token_id INT PRIMARY KEY AUTO_INCREMENT, election_id INT NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/**
 * Überträgt die Token-Liste direkt in die konfigurierte Wahlserver-Datenbank
 * (gleicher Server/gleiche Datenbank-Instanz) und löscht sie danach sofort aus
 * MISS - die direkte, erfolgreich bestätigte Übertragung ersetzt hier den
 * zweistufigen Bestätigungsschritt, den der CSV-Weg braucht. Gibt Fehlertext
 * oder '' zurück.
 */
function md_wabs_transfer_direct(int $electionId, int $by): string
{
    $election = md_wabs_election_get($electionId);
    if (!$election) return 'Wahl nicht gefunden.';
    if (!empty($election['transferred_at'])) return 'Diese Wahl wurde bereits übertragen.';
    $tokenCount = md_wabs_tokens_count($electionId);
    if ($tokenCount === 0) return 'Es liegen keine Token zum Übertragen vor.';
    $conf = md_wabs_transfer_conf();
    if ($conf['mode'] !== 'direct') return 'Die direkte Übertragung ist nicht als Übertragungsart eingestellt.';

    try {
        $tdb = md_wabs_transfer_db($conf);
    } catch (Throwable $e) {
        return 'Verbindung zur Wahlserver-Datenbank fehlgeschlagen: ' . $e->getMessage();
    }
    try {
        md_wabs_transfer_ensure_schema($tdb);
        $tdb->beginTransaction();
        $tdb->prepare('DELETE FROM wabs_elections WHERE election_id = ?')->execute([$electionId]);
        $tdb->prepare('INSERT INTO wabs_elections (election_id, title, start_at, end_at, transferred_at) VALUES (?,?,?,?,?)')
            ->execute([$electionId, $election['title'], $election['start_at'], $election['end_at'], md_now()]);
        $ins = $tdb->prepare('INSERT IGNORE INTO wabs_tokens (election_id, token_hash) VALUES (?,?)');
        foreach (md_q('SELECT token_hash FROM md_wabs_tokens WHERE election_id = ?', [$electionId]) as $t) {
            $ins->execute([$electionId, $t['token_hash']]);
        }
        $tdb->commit();
    } catch (Throwable $e) {
        if ($tdb->inTransaction()) $tdb->rollBack();
        return 'Übertragung fehlgeschlagen, es wurde nichts verändert: ' . $e->getMessage();
    }

    md_exec('DELETE FROM md_wabs_tokens WHERE election_id = ?', [$electionId]);
    md_exec("UPDATE md_wabs_elections SET transferred_at = ?, transfer_mode = 'direct', status = 'uebertragen' WHERE election_id = ?", [md_now(), $electionId]);
    md_exec('INSERT INTO md_changelog (member_id, field_key, old_value, new_value, changed_by, changed_via, changed_at) VALUES (?,?,?,?,?,?,?)',
        [$by, 'wabs.tokens_transferred', null, $tokenCount . ' Token direkt übertragen (Wahl „' . $election['title'] . '“)', $by, 'admin', md_now()]);
    return '';
}

/** Erzeugt die CSV-Datei mit der Token-Liste (nur Hashes) zum manuellen Import auf einem externen Wahlserver. Gibt [Dateiname, Fehler] zurück. */
function md_wabs_transfer_csv_generate(int $electionId, int $by): array
{
    $election = md_wabs_election_get($electionId);
    if (!$election) return ['', 'Wahl nicht gefunden.'];
    if (!empty($election['transferred_at'])) return ['', 'Diese Wahl wurde bereits übertragen.'];
    if (!empty($election['csv_file']) && empty($election['csv_deleted_at'])) return ['', 'Es liegt noch eine nicht als importiert bestätigte CSV-Datei vor - bitte diese zuerst abschließen.'];
    $tokenCount = md_wabs_tokens_count($electionId);
    if ($tokenCount === 0) return ['', 'Es liegen keine Token zum Übertragen vor.'];

    $dir = md_wabs_storage_dir();
    $filename = 'wahl-' . $electionId . '-tokenliste-' . date('Ymd-His') . '.csv';
    $fh = fopen($dir . '/' . $filename, 'w');
    if (!$fh) return ['', 'Die Datei konnte nicht angelegt werden.'];
    fwrite($fh, "# election_id;{$electionId}\n# title;{$election['title']}\n# start_at;{$election['start_at']}\n# end_at;{$election['end_at']}\n");
    fwrite($fh, "token_hash\n");
    foreach (md_q('SELECT token_hash FROM md_wabs_tokens WHERE election_id = ?', [$electionId]) as $t) {
        fwrite($fh, $t['token_hash'] . "\n");
    }
    fclose($fh);

    md_exec('UPDATE md_wabs_elections SET csv_file = ?, csv_generated_at = ?, csv_deleted_at = NULL WHERE election_id = ?', [$filename, md_now(), $electionId]);
    md_exec('INSERT INTO md_changelog (member_id, field_key, old_value, new_value, changed_by, changed_via, changed_at) VALUES (?,?,?,?,?,?,?)',
        [$by, 'wabs.csv_generated', null, $filename . ' (' . $tokenCount . ' Token, Wahl „' . $election['title'] . '“)', $by, 'admin', md_now()]);
    return [$filename, ''];
}

/**
 * Bestätigt den erfolgreichen Import der CSV-Datei auf dem externen Wahlserver:
 * löscht die Datei endgültig von der Festplatte und die Token-Liste aus MISS.
 */
function md_wabs_transfer_csv_confirm(int $electionId, int $by): string
{
    $election = md_wabs_election_get($electionId);
    if (!$election) return 'Wahl nicht gefunden.';
    if (empty($election['csv_file'])) return 'Es liegt keine Token-CSV vor.';
    if (!empty($election['csv_deleted_at'])) return 'Diese CSV-Datei wurde bereits als gelöscht bestätigt.';
    $path = md_wabs_storage_dir() . '/' . $election['csv_file'];
    if (is_file($path) && !@unlink($path)) return 'Die Datei konnte nicht gelöscht werden - bitte auf dem Server prüfen.';

    $tokenCount = md_wabs_tokens_count($electionId);
    md_exec('DELETE FROM md_wabs_tokens WHERE election_id = ?', [$electionId]);
    md_exec("UPDATE md_wabs_elections SET csv_deleted_at = ?, transferred_at = ?, transfer_mode = 'csv', status = 'uebertragen' WHERE election_id = ?", [md_now(), md_now(), $electionId]);
    md_exec('INSERT INTO md_changelog (member_id, field_key, old_value, new_value, changed_by, changed_via, changed_at) VALUES (?,?,?,?,?,?,?)',
        [$by, 'wabs.tokens_transferred', null, $tokenCount . ' Token per CSV übertragen, Datei gelöscht (Wahl „' . $election['title'] . '“)', $by, 'admin', md_now()]);
    return '';
}

// ============================================
// PHASE 5: ZUGANG ZUM EIGENSTÄNDIGEN WAHLSERVER (WABS-GRUNDGERÜST)
// Der Wahlausschuss legt selbst fest, welche zwei seiner Mitglieder (Funktion WA)
// Zugang zum eigenständigen Wahlserver (mv/WABS, eigenes Login, kein SSO zu MISS)
// bekommen, und vergibt sich dort selbst Benutzername/Passwort - ganz ohne
// Administrationsaufwand. Die ersten zwei, die sich hier ankreuzen, sind die
// Gewählten; wer sich abwählt, gibt den Platz sofort frei. Nur der Passwort-HASH
// verlässt MISS (gleiche Übertragung wie die Token-Liste aus Phase 4, siehe
// md_wabs_committee_sync()) - das Klartext-Passwort wird nirgends gespeichert.
// ============================================

const MD_WABS_COMMITTEE_MAX_EXTERNAL = 2;

/** Hat dieses Mitglied aktuell (gültigkeitsgeprüft) die Funktion Wahlausschuss (WA)? */
function md_wabs_is_committee_member(int $memberId): bool
{
    $today = date('Y-m-d');
    return (bool)md_one("SELECT 1 FROM md_member_functions mf JOIN md_functions f ON f.function_id = mf.function_id
                          WHERE mf.member_id = ? AND f.code = 'WA' AND f.active = 1
                            AND (mf.valid_from IS NULL OR mf.valid_from <= ?) AND (mf.valid_to IS NULL OR mf.valid_to >= ?)",
        [$memberId, $today, $today]);
}

/** Alle aktuellen Wahlausschuss-Mitglieder mit ihrem evtl. vorhandenen Zugang zum Wahlserver. */
function md_wabs_committee_members(): array
{
    $today = date('Y-m-d');
    return md_q("SELECT s.member_id, s.first_name, s.last_name, a.is_external, a.external_username, a.updated_at
                 FROM md_member_functions mf JOIN md_functions f ON f.function_id = mf.function_id
                 JOIN svmembers s ON s.member_id = mf.member_id
                 LEFT JOIN md_wabs_committee_access a ON a.member_id = s.member_id
                 WHERE f.code = 'WA' AND f.active = 1
                   AND (mf.valid_from IS NULL OR mf.valid_from <= ?) AND (mf.valid_to IS NULL OR mf.valid_to >= ?)
                 ORDER BY s.last_name, s.first_name", [$today, $today]);
}

/** Zahl der aktuell festgelegten externen Zugänge (höchstens MD_WABS_COMMITTEE_MAX_EXTERNAL). */
function md_wabs_committee_external_count(): int
{
    return (int)(md_one('SELECT COUNT(*) c FROM md_wabs_committee_access WHERE is_external = 1')['c'] ?? 0);
}

/**
 * Setzt oder löscht den eigenen Zugang zum Wahlserver - nur für sich selbst, kein
 * Admin-Override: das Wahlausschuss-Mitglied bestimmt und sichert sein Passwort
 * selbst. Höchstens MD_WABS_COMMITTEE_MAX_EXTERNAL gleichzeitig, Benutzername muss
 * eindeutig sein, Passwort unterliegt denselben Regeln wie bei der eigenen
 * MISS-Anmeldung (siehe md_password_error()). Überträgt bei Erfolg sofort zum
 * Wahlserver (siehe md_wabs_committee_sync()); ein Fehler dabei verhindert nicht
 * das lokale Speichern, kommt aber als Hinweis zurück.
 */
function md_wabs_committee_set(array $me, bool $external, string $username, string $password): string
{
    $memberId = (int)$me['member_id'];
    if (!md_wabs_is_committee_member($memberId)) return 'Nur aktuelle Wahlausschuss-Mitglieder können ihren Zugang zum Wahlserver verwalten.';

    if (!$external) {
        md_exec('DELETE FROM md_wabs_committee_access WHERE member_id = ?', [$memberId]);
        return md_wabs_committee_sync();
    }

    $username = trim($username);
    if ($username === '') return 'Bitte einen Benutzernamen angeben.';
    $perr = md_password_error($password, $me);
    if ($perr !== '') return $perr;
    $already = md_one('SELECT * FROM md_wabs_committee_access WHERE member_id = ?', [$memberId]);
    if (!($already && $already['is_external']) && md_wabs_committee_external_count() >= MD_WABS_COMMITTEE_MAX_EXTERNAL) {
        return 'Es sind bereits ' . MD_WABS_COMMITTEE_MAX_EXTERNAL . ' externe Zugänge festgelegt - zuerst muss sich jemand abwählen.';
    }
    if (md_one('SELECT 1 FROM md_wabs_committee_access WHERE external_username = ? AND member_id <> ?', [$username, $memberId])) {
        return 'Dieser Benutzername ist schon vergeben - bitte einen anderen wählen.';
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($already) {
        md_exec('UPDATE md_wabs_committee_access SET is_external = 1, external_username = ?, external_password_hash = ?, updated_at = ? WHERE member_id = ?',
            [$username, $hash, md_now(), $memberId]);
    } else {
        md_exec('INSERT INTO md_wabs_committee_access (member_id, is_external, external_username, external_password_hash, updated_at) VALUES (?,1,?,?,?)',
            [$memberId, $username, $hash, md_now()]);
    }
    return md_wabs_committee_sync();
}

/**
 * Überträgt den aktuellen Bestand (höchstens zwei externe Zugänge) vollständig zur
 * Wahlserver-Datenbank - wie bei der Token-Übertragung wird dort immer der gesamte
 * Inhalt ersetzt, nie nur einzelne Zeilen geändert. Nur bei direkter Übertragungsart
 * (CSV-Übertragung für diese Zugänge ist noch nicht eingerichtet); ohne direkte
 * Verbindung bleibt die lokale Änderung trotzdem gespeichert, nur eben noch nicht
 * übertragen. Gibt '' bei Erfolg oder fehlender direkter Verbindung zurück, sonst
 * eine Fehlermeldung.
 */
function md_wabs_committee_sync(): string
{
    $conf = md_wabs_transfer_conf();
    if ($conf['mode'] !== 'direct' || $conf['host'] === '' || $conf['name'] === '' || $conf['user'] === '') return '';
    try {
        $tdb = md_wabs_transfer_db($conf);
    } catch (Throwable $e) {
        return 'Gespeichert, aber die Übertragung zum Wahlserver ist fehlgeschlagen: ' . $e->getMessage();
    }
    try {
        $tdb->exec('CREATE TABLE IF NOT EXISTS wabs_committee_users (
            user_id INT PRIMARY KEY AUTO_INCREMENT, username VARCHAR(100) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $tdb->beginTransaction();
        $tdb->exec('DELETE FROM wabs_committee_users');
        $ins = $tdb->prepare('INSERT INTO wabs_committee_users (username, password_hash, updated_at) VALUES (?,?,?)');
        foreach (md_q('SELECT external_username, external_password_hash, updated_at FROM md_wabs_committee_access WHERE is_external = 1') as $r) {
            $ins->execute([$r['external_username'], $r['external_password_hash'], $r['updated_at']]);
        }
        $tdb->commit();
    } catch (Throwable $e) {
        if ($tdb->inTransaction()) $tdb->rollBack();
        return 'Gespeichert, aber die Übertragung zum Wahlserver ist fehlgeschlagen: ' . $e->getMessage();
    }
    return '';
}
