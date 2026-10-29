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
