<?php
declare(strict_types=1);

/**
 * Datenschutz: Auskunft (Art. 15 DSGVO), Anfragen mit Frist, Löschung nach Austritt in zwei Stufen.
 *
 *  Stufe 1 "Profildaten löschen": nach Ablauf von privacy.profile_months nach dem Austritt. Löscht Adressen, Kontakte, Fotos, Texte, Sichtbarkeit,
 *          Gruppen und die Änderungshistorie der Profilfelder und sperrt den Zugang. Name, Mitgliedsnummer, Eintritt/Austritt, Funktionen,
 *          Buchungen, Bestätigungen und Zustimmungsnachweise bleiben (Aufbewahrungspflichten).
 *  Stufe 2 "Anonymisieren": nach Ablauf von privacy.ledger_years nach Ende des Kalenderjahres der letzten Buchung/Bestätigung. Löscht den Rest
 *          und anonymisiert die Identität in svmembers.
 *  Gesperrt bei: Löschsperre, offenen Posten, Mitglied nicht ausgetreten. Sämtliche Fristen sind Einstellungen und vom Verein (Steuerberater,
 *  Datenschutzbeauftragte) zu bestätigen.
 *  Nicht Teil dieser Anwendung: Daten der Sitzungsverwaltung (Protokolle, Anträge, Beschlüsse), siehe docs/DATENSCHUTZ.md.
 */

function md_privacy_conf(): array
{
    return ['profile_months' => max(0, (int)md_setting('privacy.profile_months', '12')), 'ledger_years' => max(0, (int)md_setting('privacy.ledger_years', '10'))];
}

function md_privacy_log(int $member, string $event, ?int $by, ?string $note = null): void
{
    md_exec('INSERT INTO md_privacy_log (member_id, event, event_at, event_by, note) VALUES (?,?,?,?,?)', [$member, $event, md_now(), $by, $note ? mb_substr($note, 0, 255) : null]);
}

const MD_PRIVACY_KEEP_KEYS = ['ledger', 'receipt', 'consent', 'rights', 'function', 'created', 'permissions', 'settings', 'privacy'];

/** Stand der Löschfristen eines Mitglieds. */
function md_privacy_state(int $member): array
{
    $p = md_load_profile($member);
    $c = md_privacy_conf();
    $stage = !empty($p['anonymized_at']) ? 2 : (!empty($p['purged_at']) ? 1 : 0);
    $leave = $p['leave_date'] ?? null;
    $purgeDue = $leave ? date('Y-m-d', strtotime('+' . $c['profile_months'] . ' months', strtotime($leave))) : null;
    // Letztes aufbewahrungspflichtiges Datum: Buchungen, Bestätigungen, Funktionen (Vereinshistorie), Zustimmungsnachweise
    $lastFin = md_one("SELECT MAX(d) d FROM (SELECT MAX(entry_date) d FROM md_ledger WHERE member_id = ? UNION ALL SELECT MAX(paid_date) FROM md_ledger WHERE member_id = ?
                       UNION ALL SELECT MAX(period_to) FROM md_receipts WHERE member_id = ?
                       UNION ALL SELECT MAX(COALESCE(valid_to, valid_from)) FROM md_member_functions WHERE member_id = ?
                       UNION ALL SELECT SUBSTR(MAX(consented_at), 1, 10) FROM md_terms_consents WHERE member_id = ?) x", [$member, $member, $member, $member, $member])['d'] ?? null;
    $finEnd = $lastFin ? ((int)substr($lastFin, 0, 4) + $c['ledger_years']) . '-12-31' : null;
    $anonDue = $purgeDue ? (($finEnd && $finEnd > $purgeDue) ? $finEnd : $purgeDue) : null;
    $open = (int)md_one("SELECT COUNT(*) c FROM md_ledger WHERE member_id = ? AND status = 'open'", [$member])['c'];
    $blockers = [];
    if (($p['status'] ?? '') !== 'ausgetreten') $blockers[] = 'Mitglied ist noch nicht ausgetreten';
    if (($p['status'] ?? '') === 'ausgetreten' && !$leave) $blockers[] = 'Austrittsdatum fehlt';
    if (!empty($p['retention_hold'])) $blockers[] = 'Löschsperre' . (!empty($p['hold_reason']) ? ': ' . $p['hold_reason'] : '');
    if ($open > 0) $blockers[] = "$open offene Buchung(en)";
    return ['stage' => $stage, 'status' => $p['status'] ?? 'aktiv', 'leave_date' => $leave, 'purge_due' => $purgeDue, 'anon_due' => $anonDue, 'last_fin' => $lastFin,
            'blockers' => $blockers, 'hold' => !empty($p['retention_hold']), 'purged_at' => $p['purged_at'] ?? null, 'anonymized_at' => $p['anonymized_at'] ?? null];
}

function md_privacy_can_purge(array $st, bool $early = false): string
{
    if ($st['stage'] >= 1) return 'Profildaten sind schon gelöscht.';
    if ($st['blockers']) return implode('; ', $st['blockers']);
    if (!$early && $st['purge_due'] && $st['purge_due'] > date('Y-m-d')) return 'Die Frist läuft bis ' . md_date($st['purge_due']) . '.';
    return '';
}

function md_privacy_can_anonymize(array $st): string
{
    if ($st['stage'] >= 2) return 'Schon anonymisiert.';
    if ($st['stage'] < 1) return 'Zuerst die Profildaten löschen (Stufe 1).';
    if ($st['blockers']) return implode('; ', $st['blockers']);
    if ($st['anon_due'] && $st['anon_due'] > date('Y-m-d')) return 'Aufbewahrungsfrist läuft bis ' . md_date($st['anon_due']) . '.';
    return '';
}

/** Stufe 1: Profildaten löschen. Gibt einen Fehlertext zurück (leer = erledigt). */
function md_privacy_purge(int $member, int $actor, bool $early = false, ?string $note = null): string
{
    $st = md_privacy_state($member);
    if ($err = md_privacy_can_purge($st, $early)) return $err;
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        foreach (md_load_photos($member) as $ph) @unlink(MD_STORAGE_DIR . '/photos/' . basename($ph['file_name']));
        foreach (['md_photos', 'md_addresses', 'md_contacts', 'md_visibility', 'md_member_groups'] as $t) md_exec("DELETE FROM $t WHERE member_id = ?", [$member]);
        $keep = implode(',', array_fill(0, count(MD_PRIVACY_KEEP_KEYS), '?'));
        md_exec("DELETE FROM md_changelog WHERE member_id = ? AND field_key NOT IN ($keep)", array_merge([$member], MD_PRIVACY_KEEP_KEYS));
        md_exec('UPDATE md_profiles SET salutation = NULL, title = NULL, nickname = NULL, birthdate = NULL, occupation = NULL, employer = NULL, relationship_status = NULL,
                 hobbies = NULL, interests = NULL, intro_text = NULL, wishes_text = NULL, admin_note = NULL, purged_at = ?, updated_at = ? WHERE member_id = ?', [md_now(), md_now(), $member]);
        // Lastschriftmandate beenden (die Unterlagen bleiben bis zur Anonymisierung wegen der Rückgabefristen)
        md_exec("UPDATE md_sepa_mandates SET status = 'revoked', status_reason = 'Austritt', status_at = ? WHERE member_id = ? AND status <> 'revoked'", [md_now(), $member]);
        md_exec('DELETE FROM md_login_tokens WHERE member_id = ?', [$member]);
        md_exec('DELETE FROM md_event_regs WHERE member_id = ?', [$member]);
        // Zugang sperren und Kontaktadresse entfernen (die Zeile selbst bleibt für Buchungen und die Sitzungsverwaltung)
        $sets = ['email = ?'];
        $par = ['geloescht-' . $member . '@example.invalid'];
        $cols = md_sv_columns();
        if (in_array('is_active', $cols, true)) { $sets[] = 'is_active = 0'; }
        if (in_array('password_hash', $cols, true)) { $sets[] = 'password_hash = NULL'; }
        if (in_array('phone', $cols, true)) { $sets[] = 'phone = NULL'; }
        $par[] = $member;
        md_exec('UPDATE svmembers SET ' . implode(', ', $sets) . ' WHERE member_id = ?', $par);
        md_privacy_log($member, 'purged', $actor, ($early ? 'vorzeitig' : 'nach Frist') . ($note ? ': ' . $note : ''));
        md_log_change($member, 'privacy', null, 'Profildaten gelöscht (Stufe 1)', $actor, 'admin');
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return 'Fehler: ' . $e->getMessage();
    }
    return '';
}

/** Stufe 2: vollständig anonymisieren. */
function md_privacy_anonymize(int $member, int $actor, ?string $note = null): string
{
    $st = md_privacy_state($member);
    if ($err = md_privacy_can_anonymize($st)) return $err;
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        md_exec('DELETE FROM md_receipt_events WHERE receipt_id IN (SELECT receipt_id FROM md_receipts WHERE member_id = ?)', [$member]);
        md_exec('DELETE FROM md_sepa_items WHERE member_id = ?', [$member]);
        md_exec('DELETE FROM md_mail_outbox WHERE member_id = ?', [$member]);
        foreach (['md_sepa_mandates', 'md_receipts', 'md_ledger', 'md_terms_consents', 'md_member_functions', 'md_privacy_requests', 'md_changelog'] as $t) md_exec("DELETE FROM $t WHERE member_id = ?", [$member]);
        md_exec("UPDATE md_profiles SET leave_date = NULL, join_date = NULL, retention_hold = 0, hold_reason = NULL, anonymized_at = ?, updated_at = ? WHERE member_id = ?", [md_now(), md_now(), $member]);
        $cols = md_sv_columns();
        $set = ['first_name = ?', 'last_name = ?', 'email = ?'];
        $par = ['Gelöscht', '(anonymisiert)', 'geloescht-' . $member . '@example.invalid'];
        if (in_array('membership_number', $cols, true)) { $set[] = 'membership_number = ?'; $par[] = 'DEL-' . $member; }
        if (in_array('name', $cols, true)) { $set[] = 'Name = ?'; $par[] = '(anonymisiert)'; }
        if (in_array('vorname', $cols, true)) { $set[] = 'Vorname = ?'; $par[] = 'Gelöscht'; }
        if (in_array('kurzn', $cols, true)) { $set[] = 'KurzN = ?'; $par[] = 'G. (anonymisiert)'; }
        foreach (['is_active' => '0', 'aktiv' => '0'] as $c => $v) if (in_array($c, $cols, true)) $set[] = "$c = $v";
        if (in_array('funktion', $cols, true)) $set[] = 'funktion = NULL';
        if (in_array('password_hash', $cols, true)) $set[] = 'password_hash = NULL';
        if (in_array('phone', $cols, true)) $set[] = 'phone = NULL';
        $par[] = $member;
        md_exec('UPDATE svmembers SET ' . implode(', ', $set) . ' WHERE member_id = ?', $par);
        md_privacy_log($member, 'anonymized', $actor, $note);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return 'Fehler: ' . $e->getMessage();
    }
    return '';
}

function md_privacy_set_hold(int $member, bool $on, string $reason, int $actor): string
{
    $reason = trim($reason);
    if ($on && $reason === '') return 'Bitte einen Grund für die Löschsperre angeben.';
    md_ensure_profile($member);
    md_exec('UPDATE md_profiles SET retention_hold = ?, hold_reason = ? WHERE member_id = ?', [$on ? 1 : 0, $on ? mb_substr($reason, 0, 255) : null, $member]);
    md_privacy_log($member, $on ? 'hold_on' : 'hold_off', $actor, $on ? $reason : null);
    md_log_change($member, 'privacy', null, $on ? 'Löschsperre gesetzt: ' . $reason : 'Löschsperre aufgehoben', $actor, 'admin');
    return '';
}

/** Legt eine Anfrage an (Auskunft oder Löschung). Frist: ein Monat (Art. 12 Abs. 3 DSGVO). */
function md_privacy_request_add(int $member, string $kind, string $via, ?int $by, ?string $note, ?string $receivedAt = null): array
{
    if (!in_array($kind, ['access', 'erasure'], true)) return [null, 'Unbekannte Art.'];
    if (!in_array($via, ['self', 'mail', 'letter', 'phone', 'other'], true)) $via = 'other';
    if (md_one("SELECT 1 FROM md_privacy_requests WHERE member_id = ? AND kind = ? AND status = 'open'", [$member, $kind])) return [null, 'Es gibt schon eine offene Anfrage dieser Art.'];
    md_exec('INSERT INTO md_privacy_requests (member_id, kind, received_at, received_via, recorded_by, note, status) VALUES (?,?,?,?,?,?,?)',
        [$member, $kind, $receivedAt ?? md_now(), $via, $by, $note ? mb_substr($note, 0, 2000) : null, 'open']);
    $id = (int)md_db()->lastInsertId();
    md_privacy_log($member, 'request', $by, ($kind === 'access' ? 'Auskunft' : 'Löschung') . ' über ' . $via);
    md_log_change($member, 'privacy', null, ($kind === 'access' ? 'Auskunftsanfrage' : 'Löschantrag') . ' eingegangen', $by ?? $member, $via === 'self' ? 'self' : 'admin');
    return [$id, ''];
}

function md_privacy_request_deadline(array $r): string
{
    return date('Y-m-d', strtotime('+1 month', strtotime($r['received_at'])));
}

function md_privacy_request_handle(int $id, string $status, string $note, int $actor): string
{
    $r = md_one('SELECT * FROM md_privacy_requests WHERE request_id = ?', [$id]);
    if (!$r || $r['status'] !== 'open') return 'Anfrage nicht gefunden oder schon bearbeitet.';
    if (!in_array($status, ['done', 'refused'], true)) return 'Ungültiger Status.';
    if ($status === 'refused' && trim($note) === '') return 'Bei einer Ablehnung bitte die Begründung angeben.';
    md_exec('UPDATE md_privacy_requests SET status = ?, handled_at = ?, handled_by = ?, response_note = ? WHERE request_id = ?', [$status, md_now(), $actor, mb_substr(trim($note), 0, 500) ?: null, $id]);
    md_privacy_log((int)$r['member_id'], 'handled', $actor, ($r['kind'] === 'access' ? 'Auskunft ' : 'Löschung ') . ($status === 'done' ? 'erledigt' : 'abgelehnt'));
    return '';
}

// ---------------------------------------------------------------- Auskunft

/** Alle zu einem Mitglied gespeicherten Daten (ohne Zugangsdaten) in lesbarer Gliederung. */
function md_privacy_export_data(int $member, int $by): array
{
    $sv = md_one('SELECT * FROM svmembers WHERE member_id = ?', [$member]) ?? [];
    foreach (array_keys($sv) as $k) if (preg_match('/hash|token|password/i', $k)) unset($sv[$k]);
    $p = md_load_profile($member);
    $labels = [];
    foreach (md_visibility_keys() as $k => [$l]) $labels[$k] = $l;
    $vis = [];
    foreach (md_visibility_map($member) as $k => $lvl) $vis[$labels[$k] ?? $k] = MD_LEVELS[$lvl] ?? $lvl;
    $who = function (?int $id): ?string { if (!$id) return null; $m = md_one('SELECT first_name, last_name FROM svmembers WHERE member_id = ?', [$id]); return $m ? $m['first_name'] . ' ' . $m['last_name'] : null; };
    $c = md_privacy_conf();
    $club = md_club();
    return [
        'meta' => ['erstellt_am' => md_now(), 'erstellt_von' => $by === $member ? 'Mitglied selbst' : ($who($by) ?? 'Verwaltung'), 'verein' => $club['name'] ?: (defined('MD_CLUB_NAME') ? MD_CLUB_NAME : ''), 'mitglied_id' => $member],
        'stammdaten_sitzungsverwaltung' => $sv,
        'profil' => $p,
        'adressen' => array_values(md_load_addresses($member)),
        'kontakt' => md_load_contacts($member),
        'fotos' => array_map(fn($x) => ['platz' => (int)$x['slot'], 'datei' => $x['file_name'], 'bildunterschrift' => $x['caption'], 'hochgeladen' => $x['uploaded_at']], array_values(md_load_photos($member))),
        'sichtbarkeit' => $vis,
        'teilgruppen' => array_map(fn($g) => ['name' => $g['name'], 'seit' => $g['since']], md_load_groups($member)),
        'funktionen' => array_map(fn($f) => ['funktion' => $f['name'], 'code' => $f['code'], 'von' => $f['valid_from'], 'bis' => $f['valid_to'], 'level' => $f['level']], md_load_functions($member)),
        'lastschriftmandate' => array_map(fn($x) => ['mandatsreferenz' => $x['mandate_ref'], 'kontoinhaber' => $x['debtor_name'], 'iban' => md_decrypt($x['iban_enc']) ?? ('…' . $x['iban_last4']), 'bic' => $x['bic'],
            'unterschrieben' => $x['signed_on'], 'status' => $x['status'], 'grund' => $x['status_reason']], md_q('SELECT * FROM md_sepa_mandates WHERE member_id = ? ORDER BY mandate_id', [$member])),
        'lastschriften' => array_map(fn($x) => ['lauf' => $x['batch_no'], 'einzugsdatum' => $x['collection_date'], 'betrag_cent' => (int)$x['amount_cents'], 'verwendungszweck' => $x['purpose'], 'status' => $x['status'], 'rueckgabe' => $x['return_code']],
            md_q('SELECT i.*, b.batch_no, b.collection_date FROM md_sepa_items i JOIN md_sepa_batches b ON b.batch_id = i.batch_id WHERE i.member_id = ? ORDER BY i.item_id', [$member])),
        'e_mails' => array_map(fn($x) => ['erstellt' => $x['created_at'], 'betreff' => $x['subject'], 'art' => MD_MAIL_KINDS[$x['kind']] ?? $x['kind'], 'status' => MD_MAIL_STATUS[$x['status']] ?? $x['status'], 'versendet' => $x['sent_at']],
            md_q('SELECT * FROM md_mail_outbox WHERE member_id = ? ORDER BY mail_id', [$member])),
        'veranstaltungen' => array_map(fn($x) => ['veranstaltung' => $x['title'], 'beginn' => $x['starts_at'], 'status' => MD_EVENT_REG_STATUS[$x['status']] ?? $x['status'], 'in_liste_sichtbar' => (bool)$x['show_in_list'], 'anmerkung' => $x['note'], 'angemeldet_am' => $x['created_at']],
            md_q('SELECT r.*, e.title, e.starts_at FROM md_event_regs r JOIN md_events e ON e.event_id = r.event_id WHERE r.member_id = ? ORDER BY e.starts_at', [$member])),
        'veranstaltungen_als_veranstalter' => array_map(fn($x) => ['titel' => $x['title'], 'beginn' => $x['starts_at'], 'status' => $x['status']], md_q('SELECT title, starts_at, status FROM md_events WHERE organizer_id = ? ORDER BY starts_at', [$member])),
        'buchungen' => md_q('SELECT kind, direction, amount_cents, entry_date, due_date, paid_date, status, description, reference, cancel_reason FROM md_ledger WHERE member_id = ? ORDER BY entry_date, entry_id', [$member]),
        'spendenbescheinigungen' => array_map(fn($r) => ['nummer' => $r['receipt_no'], 'art' => $r['kind'], 'von' => $r['period_from'], 'bis' => $r['period_to'], 'betrag_cent' => (int)$r['total_cents'], 'status' => $r['status'],
            'ausgestellt' => $r['issued_at'], 'ungueltig_grund' => $r['void_reason'], 'inhalt_bei_ausstellung' => json_decode($r['snapshot'], true)], md_q('SELECT * FROM md_receipts WHERE member_id = ? ORDER BY receipt_no', [$member])),
        'zustimmungen_nutzungsbedingungen' => md_q('SELECT v.version_label AS version, v.title, c.consented_at AS zugestimmt_am, c.channel AS weg, c.ip_truncated AS ip_gekuerzt, c.note AS vermerk, c.revoked_at AS widerrufen_am
                                                    FROM md_terms_consents c JOIN md_terms_versions v ON v.terms_id = c.terms_id WHERE c.member_id = ? ORDER BY c.consented_at', [$member]),
        'aenderungsprotokoll' => array_map(fn($r) => ['zeitpunkt' => $r['changed_at'], 'feld' => md_field_label($r['field_key']), 'alt' => $r['old_value'], 'neu' => $r['new_value'], 'geaendert_von' => $who($r['changed_by']), 'weg' => $r['changed_via']],
            md_q('SELECT * FROM md_changelog WHERE member_id = ? ORDER BY change_id', [$member])),
        'datenschutz_anfragen' => md_q('SELECT kind AS art, received_at AS eingegangen, received_via AS weg, note AS inhalt, status, handled_at AS bearbeitet, response_note AS antwort FROM md_privacy_requests WHERE member_id = ? ORDER BY request_id', [$member]),
        'datenschutz_vorgaenge' => md_q('SELECT event AS vorgang, event_at AS zeitpunkt, note AS vermerk FROM md_privacy_log WHERE member_id = ? ORDER BY event_id', [$member]),
        'informationen_art_15' => [
            'zweck' => 'Verwaltung der Mitgliedschaft, Beitrags- und Spendenabwicklung, Kommunikation im Verein, Nachweis der Zustimmung zu den Nutzungsbedingungen.',
            'kategorien' => 'Stammdaten, Kontaktdaten, freiwillige Angaben zur Person, Fotos, Vereinsfunktionen und Gruppen, Zahlungs- und Spendendaten, Protokolle.',
            'empfaenger' => 'Funktionsträger des Vereins im Rahmen ihrer Rechte (Rechtetabelle); andere Mitglieder nur für Angaben, die du dafür freigegeben hast (Sichtbarkeit). Steuerberater/Finanzamt bei Buchungen und Spendenbescheinigungen soweit erforderlich.',
            'speicherdauer' => 'Profildaten (Adresse, Kontakt, Fotos, Texte) werden ' . $c['profile_months'] . ' Monate nach dem Austritt gelöscht. Buchungen, Spendenbescheinigungen und Zustimmungsnachweise werden bis ' . $c['ledger_years'] . ' Jahre nach Ende des Kalenderjahres der letzten Buchung aufbewahrt und danach anonymisiert.',
            'herkunft' => 'Angaben von dir selbst, von der Verwaltung des Vereins oder aus einem Import aus der bisherigen Mitgliederverwaltung.',
            'rechte' => 'Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit, Widerspruch sowie Beschwerde bei einer Datenschutz-Aufsichtsbehörde.',
            'nicht_enthalten' => 'Daten der Sitzungsverwaltung (Sitzungen, Protokolle, Anträge, Beschlüsse) sind nicht Teil dieser Auskunft.',
        ],
    ];
}

function md_privacy_export_html(array $d): string
{
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $titles = ['stammdaten_sitzungsverwaltung' => 'Stammdaten (Sitzungsverwaltung)', 'profil' => 'Profil', 'adressen' => 'Adressen', 'kontakt' => 'Kontakt und Social Media', 'fotos' => 'Fotos', 'sichtbarkeit' => 'Sichtbarkeit für andere Mitglieder',
        'teilgruppen' => 'Teilgruppen', 'funktionen' => 'Funktionen', 'lastschriftmandate' => 'Lastschriftmandate', 'lastschriften' => 'Lastschriften', 'e_mails' => 'E-Mails', 'veranstaltungen' => 'Veranstaltungen (Anmeldungen)', 'veranstaltungen_als_veranstalter' => 'Veranstaltungen (als Veranstalter)', 'buchungen' => 'Buchungen', 'spendenbescheinigungen' => 'Spendenbescheinigungen', 'zustimmungen_nutzungsbedingungen' => 'Zustimmungen zu den Nutzungsbedingungen',
        'aenderungsprotokoll' => 'Änderungsprotokoll', 'datenschutz_anfragen' => 'Datenschutz-Anfragen', 'datenschutz_vorgaenge' => 'Datenschutz-Vorgänge', 'informationen_art_15' => 'Informationen nach Art. 15 DSGVO'];
    $cell = function ($v) use ($h, &$cell) {
        if (is_array($v)) return '<pre>' . $h(json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
        return $v === null || $v === '' ? '<span class="n">–</span>' : nl2br($h($v));
    };
    $o = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow"><title>Auskunft über gespeicherte Daten</title><style>'
        . 'body{font:14px/1.5 system-ui,sans-serif;max-width:900px;margin:20px auto;padding:0 16px;color:#222}h1{font-size:22px}h2{font-size:17px;margin:22px 0 6px;color:#005596;border-bottom:1px solid #ccc}'
        . 'table{border-collapse:collapse;width:100%;margin:4px 0}th,td{border:1px solid #ccc;padding:4px 8px;text-align:left;vertical-align:top;font-size:13px}th{background:#f3f3f3;width:28%}'
        . 'pre{margin:0;white-space:pre-wrap;font-size:12px}.n{color:#999}.bar{background:#005596;color:#fff;padding:8px 14px;margin:-20px -16px 16px}.bar button{padding:5px 12px}@media print{.bar{display:none}}'
        . '</style></head><body><div class="bar"><button onclick="window.print()">Drucken</button></div>';
    $o .= '<h1>Auskunft über die gespeicherten Daten</h1><table>';
    foreach ($d['meta'] as $k => $v) $o .= '<tr><th>' . $h(['erstellt_am' => 'Erstellt am', 'erstellt_von' => 'Erstellt von', 'verein' => 'Verein', 'mitglied_id' => 'Mitglieds-ID'][$k] ?? $k) . '</th><td>' . $cell($v) . '</td></tr>';
    $o .= '</table>';
    foreach ($titles as $key => $title) {
        $v = $d[$key] ?? null;
        $o .= '<h2>' . $h($title) . '</h2>';
        if (!$v) { $o .= '<p class="n">Keine Daten gespeichert.</p>'; continue; }
        $isList = array_is_list($v) && is_array($v[0] ?? null);
        if ($isList) {
            $cols = array_keys($v[0]);
            $o .= '<table><thead><tr>' . implode('', array_map(fn($c) => '<th style="width:auto">' . $h($c) . '</th>', $cols)) . '</tr></thead><tbody>';
            foreach ($v as $row) { $o .= '<tr>'; foreach ($cols as $c) $o .= '<td>' . $cell($row[$c] ?? null) . '</td>'; $o .= '</tr>'; }
            $o .= '</tbody></table>';
        } else {
            $o .= '<table>';
            foreach ($v as $k => $val) $o .= '<tr><th>' . $h($k) . '</th><td>' . $cell($val) . '</td></tr>';
            $o .= '</table>';
        }
    }
    return $o . '</body></html>';
}
