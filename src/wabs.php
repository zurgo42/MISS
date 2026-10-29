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
function md_wabs_pdf_dir(): string
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

    $dir = md_wabs_pdf_dir();
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
    $path = md_wabs_pdf_dir() . '/' . $election['pdf_file'];
    if (is_file($path) && !@unlink($path)) return 'Die Datei konnte nicht gelöscht werden - bitte auf dem Server prüfen.';
    md_exec('UPDATE md_wabs_elections SET pdf_deleted_at = ? WHERE election_id = ?', [md_now(), $electionId]);
    md_exec('INSERT INTO md_changelog (member_id, field_key, old_value, new_value, changed_by, changed_via, changed_at) VALUES (?,?,?,?,?,?,?)',
        [$by, 'wabs.pdf_deleted', null, $election['pdf_file'] . ' (Wahl „' . $election['title'] . '“)', $by, 'admin', md_now()]);
    return '';
}
