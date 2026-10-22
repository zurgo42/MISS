<?php
declare(strict_types=1);

/**
 * Buchungsliste (md_ledger): Einzelbuchung, Zahlung vermerken, Storno, CSV-Import.
 * Beträge werden in Cent gespeichert. Buchungen werden nie gelöscht, sondern storniert.
 */

/** "1.234,56", "1234.56", "12,5 €", "-12,50" -> Cent (immer positiv); null bei ungültig. */
function md_parse_amount(?string $s): ?int
{
    $s = trim(str_replace(['€', 'EUR', ' ', "\xC2\xA0"], '', (string)$s));
    $s = ltrim($s, '+-');
    if ($s === '' || !preg_match('/^\d{1,3}([.]\d{3})*(,\d{1,2})?$|^\d+([.,]\d{1,2})?$/', $s)) return null;
    if (strpos($s, ',') === false && preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) return null;   // "1.234" ist mehrdeutig (1,234 oder 1234): lieber ablehnen
    if (strpos($s, ',') !== false) $s = str_replace('.', '', $s);          // 1.234,56 -> 1234,56
    $s = str_replace(',', '.', $s);
    $cents = (int)round(((float)$s) * 100);
    return $cents > 0 ? $cents : null;
}

function md_ledger_kind(string $text): ?string
{
    $t = mb_strtolower(trim($text));
    if (isset(MD_LEDGER_KINDS[$t])) return $t;
    foreach (MD_LEDGER_KINDS as $k => [$label]) if ($t === mb_strtolower($label)) return $k;
    $alias = ['beitrag' => 'beitrag', 'mitgliedsbeitrag' => 'beitrag', 'jahresbeitrag' => 'beitrag', 'eintritt' => 'eintritt', 'teilnahme' => 'eintritt', 'teilnahmegebühr' => 'eintritt',
              'veranstaltung' => 'eintritt', 'spende' => 'spende', 'erstattung' => 'erstattung', 'auslagen' => 'erstattung', 'kostenerstattung' => 'erstattung',
              'reisekosten' => 'erstattung', 'übungsleiter' => 'uebungsleiter', 'übungsleiterpauschale' => 'uebungsleiter', 'uebungsleiterpauschale' => 'uebungsleiter', 'ül' => 'uebungsleiter', 'mahngebühr' => 'gebuehr', 'mahngebuehr' => 'gebuehr'];
    return $alias[$t] ?? null;
}

function md_ledger_status(string $text, ?string $paidDate): ?string
{
    $t = mb_strtolower(trim($text));
    if ($t === '') return $paidDate ? 'paid' : 'open';
    $map = ['open' => 'open', 'offen' => 'open', 'unbezahlt' => 'open', 'paid' => 'paid', 'bezahlt' => 'paid', 'gezahlt' => 'paid', 'beglichen' => 'paid',
            'cancelled' => 'cancelled', 'storniert' => 'cancelled', 'storno' => 'cancelled'];
    return $map[$t] ?? null;
}

function md_ledger_describe(array $r): string
{
    return (MD_LEDGER_KINDS[$r['kind']][0] ?? $r['kind']) . ' ' . md_money((int)$r['amount_cents']) . ' – ' . $r['description'];
}

/** Prüft und legt eine Buchung an. Gibt [entry_id|null, Fehlertext] zurück. */
function md_ledger_add(array $d, int $actor, string $source = 'manual'): array
{
    $member = (int)($d['member_id'] ?? 0);
    if (!md_one('SELECT 1 FROM svmembers WHERE member_id = ?', [$member])) return [null, 'Mitglied nicht gefunden.'];
    $kind = (string)($d['kind'] ?? '');
    if (!isset(MD_LEDGER_KINDS[$kind])) return [null, 'Unbekannte Art.'];
    $cents = is_int($d['amount_cents'] ?? null) ? $d['amount_cents'] : md_parse_amount((string)($d['amount'] ?? ''));
    if (!$cents) return [null, 'Betrag ungültig.'];
    $entry = md_parse_date($d['entry_date'] ?? '') ?? null;
    if (!$entry) return [null, 'Datum der Buchung ungültig (TT.MM.JJJJ).'];
    $desc = trim((string)($d['description'] ?? ''));
    if ($desc === '') return [null, 'Beschreibung fehlt.'];
    $due = md_parse_date($d['due_date'] ?? '');
    $paid = md_parse_date($d['paid_date'] ?? '');
    $status = $d['status'] ?? ($paid ? 'paid' : 'open');
    if (!in_array($status, ['open', 'paid'], true)) return [null, 'Status ungültig.'];
    if ($status === 'paid' && !$paid) $paid = $entry;
    if ($status === 'open') $paid = null;
    $ref = trim((string)($d['reference'] ?? '')) ?: null;
    md_exec('INSERT INTO md_ledger (member_id, kind, direction, amount_cents, entry_date, due_date, paid_date, status, description, reference, created_at, created_by, source)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$member, $kind, MD_LEDGER_KINDS[$kind][1], $cents, $entry, $due, $paid, $status, mb_substr($desc, 0, 255), $ref ? mb_substr($ref, 0, 80) : null, md_now(), $actor, $source]);
    $id = (int)md_db()->lastInsertId();
    md_log_change($member, 'ledger', null, md_ledger_describe(['kind' => $kind, 'amount_cents' => $cents, 'description' => $desc]) . ($status === 'paid' ? ' (bezahlt)' : ' (offen)'), $actor, 'admin');
    return [$id, ''];
}

function md_ledger_mark_paid(int $entryId, ?string $date, int $actor): string
{
    $r = md_one('SELECT * FROM md_ledger WHERE entry_id = ?', [$entryId]);
    if (!$r || $r['status'] !== 'open') return 'Buchung nicht offen.';
    $paid = md_parse_date($date ?? '') ?? date('Y-m-d');
    md_exec("UPDATE md_ledger SET status = 'paid', paid_date = ? WHERE entry_id = ?", [$paid, $entryId]);
    md_log_change((int)$r['member_id'], 'ledger', md_ledger_describe($r) . ' (offen)', md_ledger_describe($r) . ' (bezahlt ' . md_date($paid) . ')', $actor, 'admin');
    return '';
}

function md_ledger_cancel(int $entryId, string $reason, int $actor): string
{
    $r = md_one('SELECT * FROM md_ledger WHERE entry_id = ?', [$entryId]);
    if (!$r || $r['status'] === 'cancelled') return 'Buchung nicht gefunden oder schon storniert.';
    $reason = trim($reason);
    if ($reason === '') return 'Bitte einen Grund für den Storno angeben.';
    md_exec("UPDATE md_ledger SET status = 'cancelled', cancelled_at = ?, cancelled_by = ?, cancel_reason = ? WHERE entry_id = ?", [md_now(), $actor, mb_substr($reason, 0, 255), $entryId]);
    md_log_change((int)$r['member_id'], 'ledger', md_ledger_describe($r), 'storniert: ' . $reason, $actor, 'admin');
    return '';
}

/** Zielfelder des Buchungsimports: key => [Anzeigename, Synonyme]. */
const MD_LEDGER_IMPORT_TARGETS = [
    'membership_number' => ['Mitgliedsnummer', ['mitgliedsnummer', 'mitgliedsnr', 'mitglnr', 'mnr', 'nr', 'kundennummer']],
    'email'       => ['E-Mail (alternativ zur Nummer)', ['email', 'e-mail', 'mail']],
    'kind'        => ['Art (Beitrag, Spende, …)', ['art', 'buchungsart', 'kategorie', 'typ', 'buchungstyp']],
    'amount'      => ['Betrag', ['betrag', 'summe', 'umsatz', 'betrageur', 'amount']],
    'entry_date'  => ['Buchungsdatum', ['datum', 'buchungsdatum', 'buchungstag', 'belegdatum', 'rechnungsdatum']],
    'due_date'    => ['Fällig am', ['fällig', 'fälligkeit', 'faellig', 'fälligam', 'zahlungsziel']],
    'paid_date'   => ['Bezahlt am', ['bezahltam', 'zahldatum', 'zahlungsdatum', 'valuta', 'wertstellung', 'bezahlt']],
    'status'      => ['Status (offen/bezahlt)', ['status', 'zahlstatus']],
    'description' => ['Beschreibung / Verwendungszweck', ['beschreibung', 'verwendungszweck', 'buchungstext', 'text', 'bezeichnung']],
    'reference'   => ['Beleg-/Rechnungsnummer', ['beleg', 'belegnr', 'belegnummer', 'rechnungsnummer', 'rechnungsnr', 'referenz', 'reference']],
];

function md_ledger_guess_mapping(array $headers): array
{
    $norm = fn(string $v) => preg_replace('/[^a-z0-9äöüß]/u', '', mb_strtolower($v));
    $map = [];
    foreach (MD_LEDGER_IMPORT_TARGETS as $key => [$label, $syn]) {
        $syn = array_map($norm, array_merge($syn, [$label]));
        foreach ($headers as $i => $hd) if (in_array($norm($hd), $syn, true) && !in_array($i, $map, true)) { $map[$key] = $i; break; }
    }
    return $map;
}

/**
 * Prüft die Zeilen. opts: default_kind (wenn Spalte Art fehlt/leer), default_status ('open'|'paid'|'' = nach Bezahldatum).
 * Plan-Eintrag: row, action (create|error), data, errors[], warnings[]
 */
function md_ledger_import_plan(array $rows, array $map, array $opts): array
{
    $plan = [];
    foreach ($rows as $i => $cells) {
        $d = [];
        foreach ($map as $key => $col) $d[$key] = trim((string)($cells[(int)$col] ?? ''));
        $err = [];
        $warn = [];
        $member = null;
        if (($d['membership_number'] ?? '') !== '') $member = md_one('SELECT member_id, first_name, last_name FROM svmembers WHERE membership_number = ?', [$d['membership_number']]);
        if (!$member && ($d['email'] ?? '') !== '') $member = md_one('SELECT member_id, first_name, last_name FROM svmembers WHERE LOWER(email) = ?', [mb_strtolower($d['email'])]);
        if (!$member) $err[] = 'Mitglied nicht gefunden (Nummer/E-Mail)';
        $kind = ($d['kind'] ?? '') !== '' ? md_ledger_kind($d['kind']) : ($opts['default_kind'] ?? null);
        if (!$kind) $err[] = ($d['kind'] ?? '') !== '' ? 'Art unbekannt: ' . $d['kind'] : 'Art fehlt (Standardart wählen oder Spalte zuordnen)';
        $cents = md_parse_amount($d['amount'] ?? '');
        if (!$cents) $err[] = 'Betrag ungültig: ' . ($d['amount'] ?? '');
        $entry = md_parse_date($d['entry_date'] ?? '');
        if (!$entry) $err[] = 'Buchungsdatum ungültig: ' . ($d['entry_date'] ?? '');
        $due = md_parse_date($d['due_date'] ?? '');
        $paid = md_parse_date($d['paid_date'] ?? '');
        if (($d['due_date'] ?? '') !== '' && !$due) $warn[] = 'Fälligkeit nicht lesbar – ignoriert';
        if (($d['paid_date'] ?? '') !== '' && !$paid) $warn[] = 'Zahldatum nicht lesbar – ignoriert';
        $st = md_ledger_status(($d['status'] ?? '') !== '' ? $d['status'] : ($opts['default_status'] ?? ''), $paid);
        if ($st === null) { $warn[] = 'Status unbekannt – wird aus dem Zahldatum abgeleitet'; $st = $paid ? 'paid' : 'open'; }
        if ($st === 'cancelled') $err[] = 'Stornierte Buchungen werden nicht importiert';
        $desc = ($d['description'] ?? '') !== '' ? $d['description'] : ($kind ? (MD_LEDGER_KINDS[$kind][0] ?? '') : '');
        if ($desc === '') $err[] = 'Beschreibung fehlt';
        $ref = ($d['reference'] ?? '') !== '' ? $d['reference'] : null;
        // Dublette: gleiche Belegnummer je Mitglied, sonst gleiche Art/Betrag/Datum/Text
        if ($member && $kind && $cents && $entry) {
            $dup = $ref ? md_one("SELECT 1 FROM md_ledger WHERE member_id = ? AND reference = ? AND status <> 'cancelled'", [$member['member_id'], $ref])
                        : md_one("SELECT 1 FROM md_ledger WHERE member_id = ? AND kind = ? AND amount_cents = ? AND entry_date = ? AND description = ? AND status <> 'cancelled'",
                                 [$member['member_id'], $kind, $cents, $entry, $desc]);
            if ($dup) $err[] = 'Schon vorhanden (' . ($ref ? 'Beleg ' . $ref : 'gleiche Buchung') . ')';
        }
        $plan[] = ['row' => $i + 2, 'action' => $err ? 'error' : 'create', 'errors' => $err, 'warnings' => $warn,
                   'member_name' => $member ? $member['first_name'] . ' ' . $member['last_name'] : '',
                   'data' => ['member_id' => $member['member_id'] ?? null, 'kind' => $kind, 'amount_cents' => $cents, 'entry_date' => $entry, 'due_date' => $due,
                              'paid_date' => $st === 'paid' ? ($paid ?: $entry) : null, 'status' => $st, 'description' => $desc, 'reference' => $ref]];
    }
    // Doppelte Zeilen innerhalb der Datei
    $seen = [];
    foreach ($plan as &$p) {
        if ($p['action'] !== 'create') continue;
        $d = $p['data'];
        $key = $d['member_id'] . '|' . ($d['reference'] ?: $d['kind'] . '|' . $d['amount_cents'] . '|' . $d['entry_date'] . '|' . $d['description']);
        if (isset($seen[$key])) { $p['action'] = 'error'; $p['errors'][] = 'Doppelt in der Datei (wie Zeile ' . $seen[$key] . ')'; } else $seen[$key] = $p['row'];
    }
    unset($p);
    return $plan;
}

/** Schreibt den Plan (eine Transaktion). Gibt [angelegt, übersprungen] zurück. */
function md_ledger_import_apply(array $plan, int $actor): array
{
    $pdo = md_db();
    $pdo->beginTransaction();
    $n = $skip = 0;
    try {
        foreach ($plan as $e) {
            if ($e['action'] !== 'create') { $skip++; continue; }
            [$id, $err] = md_ledger_add($e['data'], $actor, 'csv');
            if (!$id) throw new RuntimeException("Zeile {$e['row']}: $err");
            $n++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$n, $skip];
}
