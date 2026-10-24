<?php
declare(strict_types=1);

/**
 * SEPA-Basis-Lastschrift (pain.008): Mandate (IBAN verschlüsselt), Einzugslauf, XML-Datei, Bestätigung, Rücklastschriften.
 *
 *  - Pro Mitglied und Lauf eine Lastschrift über alle fälligen Forderungen (Beitrag, Eintritt); Folgeart FRST beim ersten erfolgreichen Einzug eines Mandats, danach RCUR.
 *  - Das Einzugsdatum muss ein TARGET2-Tag sein und mindestens "Vorlauf" TARGET-Tage nach heute liegen (Einstellung sepa.lead_days).
 *  - Vorabankündigung: Rechnungsdatum + sepa.prenotify_days (Standard 14) <= Einzugsdatum, sonst nur mit ausdrücklicher Freigabe (verkürzte Frist vereinbart).
 *  - Mandate verfallen, wenn sie 36 Monate nicht genutzt wurden (seit der letzten erfolgreichen Lastschrift bzw. Unterschrift).
 *  - Die Datei wird bei jedem Download aus den gespeicherten Posten neu erzeugt; es wird keine Klartext-IBAN gespeichert.
 *  - Ein Mal mit der Bank testen: Banken prüfen zusätzlich eigene Einreichungsfristen, Limits und die Gläubiger-ID.
 */

// ---------------------------------------------------------------- Verschlüsselung

function md_crypt_key(): ?string
{
    $k = defined('MD_ENCRYPTION_KEY') ? (string)MD_ENCRYPTION_KEY : '';
    return preg_match('/^[0-9a-fA-F]{64}$/', $k) ? hex2bin($k) : null;
}

function md_encrypt(string $plain): ?string
{
    $key = md_crypt_key();
    if ($key === null || !function_exists('openssl_encrypt')) return null;
    $iv = random_bytes(12);
    $c = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $c === false ? null : 'v1:' . base64_encode($iv . $tag . $c);
}

function md_decrypt(?string $blob): ?string
{
    $key = md_crypt_key();
    if ($key === null || !$blob || !str_starts_with($blob, 'v1:')) return null;
    $raw = base64_decode(substr($blob, 3), true);
    if ($raw === false || strlen($raw) < 29) return null;
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? null : $p;
}

// ---------------------------------------------------------------- Prüfungen

function md_iban_normalize(string $iban): string
{
    return strtoupper(preg_replace('/\s+/', '', $iban));
}

function md_iban_valid(string $iban): bool
{
    $i = md_iban_normalize($iban);
    if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $i)) return false;
    if (str_starts_with($i, 'DE') && strlen($i) !== 22) return false;
    return md_mod97(substr($i, 4) . substr($i, 0, 4)) === 1;
}

/** Modulo 97 einer Zeichenfolge aus Ziffern und Buchstaben (A=10 … Z=35), stückweise gerechnet. */
function md_mod97(string $s): int
{
    $num = '';
    foreach (str_split(strtoupper($s)) as $ch) $num .= ctype_digit($ch) ? $ch : (string)(ord($ch) - 55);
    $r = 0;
    foreach (str_split($num, 7) as $chunk) $r = (int)($r . $chunk) % 97;
    return $r;
}

function md_bic_valid(string $bic): bool
{
    return (bool)preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', strtoupper(trim($bic)));
}

/** Gläubiger-Identifikationsnummer (z. B. DE98ZZZ09999999999) inkl. Prüfziffern. */
function md_sepa_creditor_id_valid(string $id): bool
{
    $id = strtoupper(preg_replace('/\s+/', '', $id));
    if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{3}[A-Z0-9]{1,28}$/', $id)) return false;
    $calc = 98 - md_mod97(substr($id, 7) . substr($id, 0, 2) . '00');
    return sprintf('%02d', $calc) === substr($id, 2, 2);
}

/** Auf den SEPA-Zeichensatz bringen (Umlaute umschreiben, Unzulässiges entfernen) und kürzen. */
function md_sepa_text(string $s, int $max): string
{
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss', '&' => '+', '€' => 'EUR', '–' => '-', '—' => '-', '„' => '', '“' => '', '”' => '', '’' => "'", '‘' => "'", "\n" => ' ', "\r" => ' ']);
    $s = (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = preg_replace("/[^A-Za-z0-9\\/\\-?:().,'+ ]/", '', $s);
    $s = trim(preg_replace('/\s+/', ' ', $s));
    return substr($s, 0, $max);
}

/** Kennung wie MsgId/EndToEndId: nur Buchstaben, Ziffern und - / . bis 35 Zeichen. */
function md_sepa_id(string $s, int $max = 35): string
{
    return substr(preg_replace('/[^A-Za-z0-9\\-\\/.]/', '', md_sepa_text($s, 80)), 0, $max);
}

function md_iban_mask(string $iban): string
{
    $i = md_iban_normalize($iban);
    return strlen($i) < 8 ? $i : substr($i, 0, 4) . ' … ' . substr($i, -4);
}

// ---------------------------------------------------------------- TARGET2-Tage

function md_easter(int $y): string
{
    $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31); $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $y, $month, $day);
}

/** TARGET2-Geschäftstag: Montag bis Freitag außer Neujahr, Karfreitag, Ostermontag, 1. Mai, 25. und 26. Dezember. */
function md_is_target_day(string $date): bool
{
    $t = strtotime($date);
    if ((int)date('N', $t) >= 6) return false;
    $y = (int)date('Y', $t);
    $easter = md_easter($y);
    $hol = ["$y-01-01", "$y-05-01", "$y-12-25", "$y-12-26", date('Y-m-d', strtotime('-2 days', strtotime($easter))), date('Y-m-d', strtotime('+1 day', strtotime($easter)))];
    return !in_array($date, $hol, true);
}

function md_target_add(string $date, int $days): string
{
    $d = $date;
    while ($days > 0) {
        $d = date('Y-m-d', strtotime('+1 day', strtotime($d)));
        if (md_is_target_day($d)) $days--;
    }
    return $d;
}

function md_target_next(string $date): string
{
    while (!md_is_target_day($date)) $date = date('Y-m-d', strtotime('+1 day', strtotime($date)));
    return $date;
}

// ---------------------------------------------------------------- Einstellungen des Vereins

const MD_SEPA_FIELDS = [   // Schlüssel => [Bezeichnung, Standard, Hinweis]
    'creditor_id' => ['Gläubiger-Identifikationsnummer', '', 'DE98ZZZ09999999999 (bei der Bundesbank beantragt)'],
    'bic'         => ['BIC des Vereinskontos (optional)', '', ''],
    'format'      => ['Dateiformat', 'pain.008.001.08', ''],
    'lead_days'   => ['Vorlauf in TARGET-Tagen bis zum Einzugsdatum', '2', ''],
    'prenotify_days' => ['Vorabankündigung in Tagen (Rechnung vor Einzug)', '14', ''],
];

function md_sepa_conf(): array
{
    $c = [];
    foreach (MD_SEPA_FIELDS as $k => [, $def]) $c[$k] = md_setting('sepa.' . $k, $def);
    $c['lead_days'] = max(1, (int)$c['lead_days']);
    $c['prenotify_days'] = max(0, (int)$c['prenotify_days']);
    if (!in_array($c['format'], ['pain.008.001.08', 'pain.008.001.02'], true)) $c['format'] = 'pain.008.001.08';
    $fees = md_fee_conf();
    $c['name'] = $fees['holder'] !== '' ? $fees['holder'] : md_org_name();
    $c['iban'] = md_iban_normalize($fees['iban']);
    if ($fees['bic'] !== '' && $c['bic'] === '') $c['bic'] = $fees['bic'];
    return $c;
}

function md_sepa_conf_problems(array $c): array
{
    $p = [];
    if (md_crypt_key() === null) $p[] = 'Verschlüsselungsschlüssel fehlt (MD_ENCRYPTION_KEY in config.php, Erzeugen mit php tools/make_key.php)';
    if (!function_exists('openssl_encrypt')) $p[] = 'PHP-Erweiterung openssl fehlt';
    if (trim($c['name']) === '') $p[] = 'Vereinsname fehlt (Administration → Verein)';
    if ($c['iban'] === '' || !md_iban_valid($c['iban'])) $p[] = 'IBAN des Vereins fehlt oder ist ungültig (Administration → Vereinskonten)';
    if ($c['creditor_id'] === '' || !md_sepa_creditor_id_valid($c['creditor_id'])) $p[] = 'Gläubiger-ID fehlt oder ist ungültig';
    if ($c['bic'] !== '' && !md_bic_valid($c['bic'])) $p[] = 'BIC des Vereins ist ungültig';
    return $p;
}

// ---------------------------------------------------------------- Mandate

const MD_SEPA_STATUS = ['active' => 'aktiv', 'suspended' => 'ausgesetzt', 'revoked' => 'widerrufen'];

function md_sepa_mandate_ref_next(int $member): string
{
    $nr = md_one('SELECT membership_number FROM svmembers WHERE member_id = ?', [$member])['membership_number'] ?? (string)$member;
    $n = (int)md_one('SELECT COUNT(*) c FROM md_sepa_mandates WHERE member_id = ?', [$member])['c'] + 1;
    do {
        $ref = md_sepa_id('M-' . $nr . '-' . sprintf('%02d', $n), 35);
        $n++;
    } while (md_one('SELECT 1 FROM md_sepa_mandates WHERE mandate_ref = ?', [$ref]));
    return $ref;
}

/** Legt ein Mandat an; ein bisher aktives Mandat des Mitglieds wird als ersetzt widerrufen. Gibt [mandate_id|null, Fehlertext] zurück. */
function md_sepa_mandate_add(int $member, array $d, int $actor): array
{
    $iban = md_iban_normalize((string)($d['iban'] ?? ''));
    if (!md_iban_valid($iban)) return [null, 'IBAN ungültig.'];
    $bic = strtoupper(trim((string)($d['bic'] ?? '')));
    if ($bic !== '' && !md_bic_valid($bic)) return [null, 'BIC ungültig.'];
    $signed = md_parse_date($d['signed_on'] ?? '');
    if (!$signed || $signed > date('Y-m-d')) return [null, 'Unterschriftsdatum ungültig (TT.MM.JJJJ, nicht in der Zukunft).'];
    $name = trim((string)($d['debtor_name'] ?? ''));
    if ($name === '') return [null, 'Kontoinhaber fehlt.'];
    $enc = md_encrypt($iban);
    if ($enc === null) return [null, 'IBAN kann nicht verschlüsselt werden: MD_ENCRYPTION_KEY fehlt in config.php (php tools/make_key.php).'];
    $ref = md_sepa_id(trim((string)($d['mandate_ref'] ?? '')) ?: md_sepa_mandate_ref_next($member), 35);
    if ($ref === '' || md_one('SELECT 1 FROM md_sepa_mandates WHERE mandate_ref = ?', [$ref])) return [null, 'Mandatsreferenz fehlt oder ist schon vergeben.'];
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        foreach (md_q("SELECT mandate_id, mandate_ref FROM md_sepa_mandates WHERE member_id = ? AND status = 'active'", [$member]) as $old) {
            md_exec("UPDATE md_sepa_mandates SET status = 'revoked', status_reason = ?, status_at = ? WHERE mandate_id = ?", ['ersetzt durch neues Mandat ' . $ref, md_now(), $old['mandate_id']]);
            md_log_change($member, 'sepa', 'Mandat ' . $old['mandate_ref'], 'ersetzt durch ' . $ref, $actor, 'admin');
        }
        md_exec('INSERT INTO md_sepa_mandates (member_id, mandate_ref, signed_on, debtor_name, iban_enc, iban_last4, bic, status, document_note, created_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$member, $ref, $signed, mb_substr($name, 0, 100), $enc, substr($iban, -4), $bic ?: null, 'active', trim((string)($d['document_note'] ?? '')) ?: null, md_now(), $actor]);
        $id = (int)$pdo->lastInsertId();
        md_log_change($member, 'sepa', null, "Lastschriftmandat $ref angelegt (IBAN " . md_iban_mask($iban) . ", unterschrieben " . md_date($signed) . ')', $actor, 'admin');
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return [null, $e->getMessage()];
    }
    return [$id, ''];
}

function md_sepa_mandate_set_status(int $mandateId, string $status, string $reason, int $actor): string
{
    $m = md_one('SELECT * FROM md_sepa_mandates WHERE mandate_id = ?', [$mandateId]);
    if (!$m) return 'Mandat nicht gefunden.';
    if (!isset(MD_SEPA_STATUS[$status])) return 'Ungültiger Status.';
    if ($m['status'] === 'revoked') return 'Ein widerrufenes Mandat kann nicht wieder aktiviert werden; bitte ein neues Mandat anlegen.';
    if ($status !== 'active' && trim($reason) === '') return 'Bitte einen Grund angeben.';
    if ($status === 'active' && md_one("SELECT 1 FROM md_sepa_mandates WHERE member_id = ? AND status = 'active' AND mandate_id <> ?", [$m['member_id'], $mandateId])) return 'Es gibt schon ein aktives Mandat für dieses Mitglied.';
    md_exec('UPDATE md_sepa_mandates SET status = ?, status_reason = ?, status_at = ? WHERE mandate_id = ?', [$status, $status === 'active' ? null : mb_substr(trim($reason), 0, 255), md_now(), $mandateId]);
    md_log_change((int)$m['member_id'], 'sepa', 'Mandat ' . $m['mandate_ref'] . ' ' . MD_SEPA_STATUS[$m['status']], MD_SEPA_STATUS[$status] . ($reason ? ': ' . trim($reason) : ''), $actor, 'admin');
    return '';
}

function md_sepa_mandate_iban(array $m): ?string
{
    return md_decrypt($m['iban_enc'] ?? null);
}

/** Letzte erfolgreiche Nutzung des Mandats (Einzugsdatum) oder null. */
function md_sepa_last_use(int $mandateId): ?string
{
    return md_one("SELECT MAX(b.collection_date) d FROM md_sepa_items i JOIN md_sepa_batches b ON b.batch_id = i.batch_id WHERE i.mandate_id = ? AND i.status = 'collected'", [$mandateId])['d'] ?? null;
}

/** Ist das Mandat am Tag $on verwendbar? Gibt [true, ''] oder [false, Grund]. */
function md_sepa_mandate_usable(array $m, string $on): array
{
    if ($m['status'] !== 'active') return [false, 'Mandat ' . MD_SEPA_STATUS[$m['status']]];
    if ($m['signed_on'] > $on) return [false, 'Mandat wurde erst nach dem Einzugsdatum unterschrieben'];
    $ref = md_sepa_last_use((int)$m['mandate_id']) ?? $m['signed_on'];
    if (strtotime('+36 months', strtotime($ref)) < strtotime($on)) return [false, 'Mandat verfallen (36 Monate nicht genutzt) – neues Mandat nötig'];
    $iban = md_sepa_mandate_iban($m);
    if ($iban === null) return [false, 'IBAN nicht lesbar (Schlüssel?)'];
    if (!md_iban_valid($iban)) return [false, 'IBAN ungültig'];
    if ((int)md_one("SELECT COUNT(*) c FROM md_sepa_items WHERE mandate_id = ? AND status = 'pending'", [$m['mandate_id']])['c'] > 0) return [false, 'Vorheriger Einzug noch nicht bestätigt'];
    return [true, ''];
}

function md_sepa_active_mandate(int $member): ?array
{
    return md_one("SELECT * FROM md_sepa_mandates WHERE member_id = ? AND status = 'active' ORDER BY mandate_id DESC LIMIT 1", [$member]);
}

/** FRST beim ersten erfolgreichen Einzug eines Mandats, danach RCUR. */
function md_sepa_seq(int $mandateId): string
{
    return md_sepa_last_use($mandateId) === null ? 'FRST' : 'RCUR';
}

// ---------------------------------------------------------------- Einzugslauf

/** Frühestes zulässiges Einzugsdatum: heute + Vorlauf in TARGET-Tagen. */
function md_sepa_min_collection(?string $today = null): string
{
    return md_target_add($today ?? date('Y-m-d'), md_sepa_conf()['lead_days']);
}

/**
 * Vorschau: je Mitglied mit mahnfähigen... fälligen Forderungen und Mandat: ok, Betrag, Folgeart, Probleme.
 * Fällig = Fälligkeit <= Einzugsdatum, offen, Art Beitrag/Eintritt, nicht gestundet, nicht schon in einem Lauf.
 */
function md_sepa_candidates(string $collection, bool $allowShort = false): array
{
    $conf = md_sepa_conf();
    $rows = md_q("SELECT l.*, s.first_name, s.last_name, s.membership_number FROM md_ledger l JOIN svmembers s ON s.member_id = l.member_id
                  WHERE l.status = 'open' AND l.direction = 'to_club' AND l.kind IN ('beitrag', 'eintritt') AND l.sepa_item_id IS NULL AND l.source <> 'event' AND l.due_date IS NOT NULL AND l.due_date <= ?
                  AND (l.deferred_until IS NULL OR l.deferred_until < ?) AND l.amount_cents > 0 AND s.membership_number NOT LIKE 'DEL-%' ORDER BY s.last_name, s.first_name, l.due_date", [$collection, $collection]);
    $out = [];
    foreach ($rows as $e) {
        $m = (int)$e['member_id'];
        $out[$m]['member'] = ['member_id' => $m, 'name' => $e['first_name'] . ' ' . $e['last_name'], 'number' => $e['membership_number']];
        $out[$m]['entries'][] = $e;
    }
    foreach ($out as $m => &$g) {
        $g['cents'] = array_sum(array_map(fn($e) => (int)$e['amount_cents'], $g['entries']));
        $g['problems'] = [];
        $g['ok'] = false;
        $g['mandate'] = null;
        $mand = md_sepa_active_mandate($m);
        if (!$mand) {
            $any = md_one('SELECT status FROM md_sepa_mandates WHERE member_id = ? ORDER BY mandate_id DESC LIMIT 1', [$m]);
            if (!$any) { unset($out[$m]); continue; }   // Mitglied zahlt per Überweisung: nicht Teil des Einzugs
            $g['problems'][] = 'Mandat ' . MD_SEPA_STATUS[$any['status']];
            continue;
        }
        $g['mandate'] = $mand;
        [$usable, $why] = md_sepa_mandate_usable($mand, $collection);
        if (!$usable) $g['problems'][] = $why;
        $latest = max(array_map(fn($e) => $e['entry_date'], $g['entries']));
        $earliest = date('Y-m-d', strtotime('+' . $conf['prenotify_days'] . ' days', strtotime($latest)));
        $g['short_notice'] = $earliest > $collection;
        if ($g['short_notice'] && !$allowShort) $g['problems'][] = 'Vorabankündigung zu kurz (Rechnung vom ' . md_date($latest) . ', frühester Einzug ' . md_date($earliest) . ')';
        $g['seq'] = md_sepa_seq((int)$mand['mandate_id']);
        $g['ok'] = !$g['problems'];
    }
    unset($g);
    return $out;
}

function md_sepa_batch_no(int $year): string
{
    $last = md_one('SELECT batch_no FROM md_sepa_batches WHERE batch_no LIKE ? ORDER BY batch_no DESC LIMIT 1', ["L$year-%"]);
    $n = $last ? (int)substr($last['batch_no'], strlen("L$year-")) + 1 : 1;
    return sprintf('L%d-%04d', $year, $n);
}

function md_sepa_event(int $batchId, string $event, ?int $by, ?string $note = null): void
{
    md_exec('INSERT INTO md_sepa_events (batch_id, event, event_at, event_by, note) VALUES (?,?,?,?,?)', [$batchId, $event, md_now(), $by, $note ? mb_substr($note, 0, 255) : null]);
}

/** Erstellt einen Lauf für die gewählten Mitglieder. Gibt [batch_id|null, Fehlertext] zurück. */
function md_sepa_batch_create(string $collectionDmy, array $memberIds, int $actor, bool $allowShort = false, ?string $note = null, ?string $today = null): array
{
    $today = $today ?? date('Y-m-d');
    $conf = md_sepa_conf();
    if ($p = md_sepa_conf_problems($conf)) return [null, 'Einstellungen unvollständig: ' . implode('; ', $p) . '.'];
    $collection = md_parse_date($collectionDmy);
    if (!$collection) return [null, 'Einzugsdatum ungültig (TT.MM.JJJJ).'];
    if (!md_is_target_day($collection)) return [null, 'Das Einzugsdatum muss ein Bankarbeitstag (TARGET2) sein.'];
    $min = md_sepa_min_collection($today);
    if ($collection < $min) return [null, 'Das Einzugsdatum liegt zu früh: frühestens ' . md_date($min) . ' (Vorlauf ' . $conf['lead_days'] . ' TARGET-Tage).'];
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $cands = md_sepa_candidates($collection, $allowShort);
        $sel = array_filter($cands, fn($g, $m) => in_array($m, array_map('intval', $memberIds), true) && $g['ok'], ARRAY_FILTER_USE_BOTH);
        if (!$sel) throw new RuntimeException('Keine einziehbare Lastschrift ausgewählt.');
        $no = md_sepa_batch_no((int)date('Y', strtotime($today)));
        $creditor = json_encode(['name' => $conf['name'], 'iban' => $conf['iban'], 'bic' => $conf['bic'], 'creditor_id' => md_iban_normalize($conf['creditor_id'])], JSON_UNESCAPED_UNICODE);
        md_exec('INSERT INTO md_sepa_batches (batch_no, created_at, created_by, collection_date, format, status, items, total_cents, creditor, note) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$no, md_now(), $actor, $collection, $conf['format'], 'created', 0, 0, $creditor, $note]);
        $bid = (int)$pdo->lastInsertId();
        $n = 0;
        $total = 0;
        foreach ($sel as $m => $g) {
            $mand = $g['mandate'];
            $n++;
            $ids = array_map(fn($e) => (int)$e['entry_id'], $g['entries']);
            $refs = array_filter(array_map(fn($e) => $e['reference'], $g['entries']));
            $e2e = count($g['entries']) === 1 && $g['entries'][0]['reference'] ? md_sepa_id((string)$g['entries'][0]['reference']) : '';
            if ($e2e === '') $e2e = md_sepa_id($no . '-' . sprintf('%03d', $n));
            $purpose = md_sepa_text(($g['entries'][0]['description'] ?? 'Mitgliedsbeitrag') . ($refs ? ' ' . implode(' ', $refs) : ''), 140);
            if (count($g['entries']) > 1) $purpose = md_sepa_text('Beitraege ' . implode(' ', $refs), 140);
            md_exec('INSERT INTO md_sepa_items (batch_id, member_id, mandate_id, mandate_ref, signed_on, debtor_name, iban_enc, bic, amount_cents, seq_type, end_to_end_id, purpose, entry_ids, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$bid, $m, $mand['mandate_id'], $mand['mandate_ref'], $mand['signed_on'], $mand['debtor_name'], $mand['iban_enc'], $mand['bic'], $g['cents'], $g['seq'], $e2e, $purpose, implode(',', $ids), 'pending']);
            $iid = (int)$pdo->lastInsertId();
            md_exec('UPDATE md_ledger SET sepa_item_id = ? WHERE sepa_item_id IS NULL AND entry_id IN (' . implode(',', $ids) . ')', [$iid]);
            md_log_change($m, 'sepa', null, "Lastschrift $no vorgesehen: " . md_money($g['cents']) . ' zum ' . md_date($collection) . " (Mandat {$mand['mandate_ref']}, {$g['seq']})", $actor, 'admin');
            $total += $g['cents'];
        }
        md_exec('UPDATE md_sepa_batches SET items = ?, total_cents = ? WHERE batch_id = ?', [$n, $total, $bid]);
        md_sepa_event($bid, 'created', $actor, $n . ' Lastschrift(en), ' . md_money($total));
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return [null, $e->getMessage()];
    }
    return [$bid, ''];
}

// ---------------------------------------------------------------- XML

/** pain.008-Datei eines Laufs (aus den gespeicherten Posten erzeugt). */
function md_sepa_xml(int $batchId): string
{
    $b = md_one('SELECT * FROM md_sepa_batches WHERE batch_id = ?', [$batchId]);
    if (!$b) throw new RuntimeException('Lauf nicht gefunden.');
    $cred = json_decode($b['creditor'], true);
    $items = md_q("SELECT * FROM md_sepa_items WHERE batch_id = ? AND status <> 'cancelled' ORDER BY item_id", [$batchId]);
    if (!$items) throw new RuntimeException('Der Lauf enthält keine Lastschriften.');
    $v8 = $b['format'] === 'pain.008.001.08';
    $ns = 'urn:iso:std:iso:20022:tech:xsd:' . $b['format'];
    $bicTag = $v8 ? 'BICFI' : 'BIC';
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;
    $el = function (DOMNode $parent, string $name, ?string $text = null) use ($doc, $ns): DOMElement {
        $e = $doc->createElementNS($ns, $name);
        if ($text !== null) $e->appendChild($doc->createTextNode($text));
        $parent->appendChild($e);
        return $e;
    };
    $agent = function (DOMNode $parent, string $tag, ?string $bic) use ($el, $bicTag) {
        $a = $el($parent, $tag);
        $f = $el($a, 'FinInstnId');
        if ($bic) $el($f, $bicTag, strtoupper($bic));
        else $el($el($f, 'Othr'), 'Id', 'NOTPROVIDED');
    };
    $amt = fn(int $c) => number_format($c / 100, 2, '.', '');
    $root = $doc->createElementNS($ns, 'Document');
    $doc->appendChild($root);
    $init = $el($root, 'CstmrDrctDbtInitn');
    $created = strtotime($b['created_at']);
    $total = array_sum(array_map(fn($i) => (int)$i['amount_cents'], $items));
    $gh = $el($init, 'GrpHdr');
    $el($gh, 'MsgId', md_sepa_id($b['batch_no'] . '-' . date('YmdHis', $created)));
    $el($gh, 'CreDtTm', date('Y-m-d\TH:i:s', $created));
    $el($gh, 'NbOfTxs', (string)count($items));
    $el($gh, 'CtrlSum', $amt($total));
    $el($el($gh, 'InitgPty'), 'Nm', md_sepa_text($cred['name'], 70));
    foreach (['FRST', 'RCUR'] as $seq) {
        $grp = array_values(array_filter($items, fn($i) => $i['seq_type'] === $seq));
        if (!$grp) continue;
        $pi = $el($init, 'PmtInf');
        $el($pi, 'PmtInfId', md_sepa_id($b['batch_no'] . '-' . $seq));
        $el($pi, 'PmtMtd', 'DD');
        $el($pi, 'BtchBookg', 'true');
        $el($pi, 'NbOfTxs', (string)count($grp));
        $el($pi, 'CtrlSum', $amt(array_sum(array_map(fn($i) => (int)$i['amount_cents'], $grp))));
        $tp = $el($pi, 'PmtTpInf');
        $el($el($tp, 'SvcLvl'), 'Cd', 'SEPA');
        $el($el($tp, 'LclInstrm'), 'Cd', 'CORE');
        $el($tp, 'SeqTp', $seq);
        $el($pi, 'ReqdColltnDt', $b['collection_date']);
        $el($el($pi, 'Cdtr'), 'Nm', md_sepa_text($cred['name'], 70));
        $el($el($el($pi, 'CdtrAcct'), 'Id'), 'IBAN', $cred['iban']);
        $agent($pi, 'CdtrAgt', $cred['bic'] ?: null);
        $el($pi, 'ChrgBr', 'SLEV');
        $other = $el($el($el($el($pi, 'CdtrSchmeId'), 'Id'), 'PrvtId'), 'Othr');
        $el($other, 'Id', $cred['creditor_id']);
        $el($el($other, 'SchmeNm'), 'Prtry', 'SEPA');
        foreach ($grp as $i) {
            $iban = md_decrypt($i['iban_enc']);
            if ($iban === null) throw new RuntimeException('IBAN nicht lesbar (Verschlüsselungsschlüssel?).');
            $tx = $el($pi, 'DrctDbtTxInf');
            $el($el($tx, 'PmtId'), 'EndToEndId', $i['end_to_end_id']);
            $ia = $el($tx, 'InstdAmt', $amt((int)$i['amount_cents']));
            $ia->setAttribute('Ccy', 'EUR');
            $mr = $el($el($tx, 'DrctDbtTx'), 'MndtRltdInf');
            $el($mr, 'MndtId', $i['mandate_ref']);
            $el($mr, 'DtOfSgntr', $i['signed_on']);
            $agent($tx, 'DbtrAgt', $i['bic'] ?: null);
            $el($el($tx, 'Dbtr'), 'Nm', md_sepa_text($i['debtor_name'], 70));
            $el($el($el($tx, 'DbtrAcct'), 'Id'), 'IBAN', $iban);
            $el($el($tx, 'RmtInf'), 'Ustrd', md_sepa_text($i['purpose'], 140));
        }
    }
    return (string)$doc->saveXML();
}

// ---------------------------------------------------------------- Ablauf nach der Erstellung

function md_sepa_submit(int $batchId, int $actor, ?string $note = null): string
{
    $b = md_one('SELECT * FROM md_sepa_batches WHERE batch_id = ?', [$batchId]);
    if (!$b || $b['status'] !== 'created') return 'Nur ein erzeugter Lauf kann als eingereicht vermerkt werden.';
    md_exec("UPDATE md_sepa_batches SET status = 'submitted', submitted_at = ?, submitted_by = ? WHERE batch_id = ?", [md_now(), $actor, $batchId]);
    md_sepa_event($batchId, 'submitted', $actor, $note ?: 'bei der Bank eingereicht');
    return '';
}

/** Einzug bestätigt: Posten gelten als eingezogen, die Forderungen als bezahlt (Zahltag = Einzugsdatum). */
function md_sepa_close(int $batchId, int $actor): string
{
    $b = md_one('SELECT * FROM md_sepa_batches WHERE batch_id = ?', [$batchId]);
    if (!$b || $b['status'] !== 'submitted') return 'Nur ein eingereichter Lauf kann als eingezogen bestätigt werden.';
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        foreach (md_q("SELECT * FROM md_sepa_items WHERE batch_id = ? AND status = 'pending'", [$batchId]) as $i) {
            foreach (array_filter(array_map('intval', explode(',', $i['entry_ids']))) as $eid) {
                $err = md_ledger_mark_paid($eid, md_date($b['collection_date']), $actor);
                if ($err) throw new RuntimeException("Forderung #$eid: $err");
            }
            md_exec("UPDATE md_sepa_items SET status = 'collected' WHERE item_id = ?", [$i['item_id']]);
        }
        md_exec("UPDATE md_sepa_batches SET status = 'closed', closed_at = ? WHERE batch_id = ?", [md_now(), $batchId]);
        md_sepa_event($batchId, 'closed', $actor, 'Einzug bestätigt');
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return $e->getMessage();
    }
    return '';
}

const MD_SEPA_RETURN_CODES = [
    'AC01' => 'IBAN fehlerhaft', 'AC04' => 'Konto aufgelöst', 'AC06' => 'Konto gesperrt', 'AG01' => 'Lastschrift auf dem Konto nicht zugelassen', 'AM04' => 'Keine Deckung',
    'AM05' => 'Doppelt eingereicht', 'BE05' => 'Gläubiger-ID unbekannt', 'MD01' => 'Kein gültiges Mandat', 'MD06' => 'Rückgabe auf Wunsch des Kunden (Widerspruch)',
    'MD07' => 'Kontoinhaber verstorben', 'MS02' => 'Ablehnung durch den Kunden', 'MS03' => 'Sonstiger Grund', 'SL01' => 'Ablehnung durch die Bank', 'ZZZZ' => 'Anderer Grund (siehe Vermerk)',
];
/** Rückgabegründe, nach denen das Mandat ausgesetzt wird. */
const MD_SEPA_SUSPEND_CODES = ['AC01', 'AC04', 'AC06', 'AG01', 'MD01', 'MD06', 'MD07', 'MS02'];

/** Rücklastschrift erfassen: Forderungen wieder offen, Mandat ggf. ausgesetzt, optional Gebühr an das Mitglied. */
function md_sepa_return(int $itemId, string $code, string $text, ?string $dateDmy, int $feeCents, bool $chargeMember, int $actor): string
{
    $i = md_one('SELECT i.*, b.batch_no, b.collection_date FROM md_sepa_items i JOIN md_sepa_batches b ON b.batch_id = i.batch_id WHERE i.item_id = ?', [$itemId]);
    if (!$i || $i['status'] !== 'collected') return 'Nur eingezogene Lastschriften können zurückgegeben werden.';
    if (!isset(MD_SEPA_RETURN_CODES[$code])) return 'Rückgabegrund wählen.';
    $date = $dateDmy !== null && trim($dateDmy) !== '' ? md_parse_date($dateDmy) : date('Y-m-d');
    if (!$date) return 'Datum ungültig.';
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        md_exec("UPDATE md_sepa_items SET status = 'returned', returned_at = ?, return_code = ?, return_text = ?, return_fee_cents = ? WHERE item_id = ?", [$date, $code, mb_substr(trim($text), 0, 255) ?: null, $feeCents ?: null, $itemId]);
        $ids = array_filter(array_map('intval', explode(',', $i['entry_ids'])));
        md_exec("UPDATE md_ledger SET status = 'open', paid_date = NULL, sepa_item_id = NULL WHERE entry_id IN (" . implode(',', $ids) . ") AND status = 'paid'");
        $m = (int)$i['member_id'];
        md_log_change($m, 'sepa', "Lastschrift {$i['batch_no']} " . md_money((int)$i['amount_cents']), "zurückgegeben ($code " . MD_SEPA_RETURN_CODES[$code] . ') am ' . md_date($date) . '; Forderungen wieder offen', $actor, 'admin');
        if (in_array($code, MD_SEPA_SUSPEND_CODES, true) && $i['mandate_id']) {
            $mand = md_one('SELECT status FROM md_sepa_mandates WHERE mandate_id = ?', [$i['mandate_id']]);
            if ($mand && $mand['status'] === 'active') md_sepa_mandate_set_status((int)$i['mandate_id'], 'suspended', "Rücklastschrift $code", $actor);
        }
        if ($chargeMember && $feeCents > 0) {
            md_exec("INSERT INTO md_ledger (member_id, kind, direction, amount_cents, entry_date, due_date, status, description, reference, created_at, created_by, source) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                [$m, 'gebuehr', 'to_club', $feeCents, $date, date('Y-m-d', strtotime('+14 days', strtotime($date))), 'open', "Rücklastschriftgebühr ($code) zu Lastschrift {$i['batch_no']}", 'RL-' . $i['batch_no'] . '-' . $itemId, md_now(), $actor, 'sepa']);
        }
        md_sepa_event((int)$i['batch_id'], 'returned', $actor, "$code, " . md_money((int)$i['amount_cents']));
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return $e->getMessage();
    }
    return '';
}

/** Lauf stornieren (nur solange er nicht eingereicht ist): Forderungen werden wieder frei. */
function md_sepa_void(int $batchId, string $reason, int $actor): string
{
    $b = md_one('SELECT * FROM md_sepa_batches WHERE batch_id = ?', [$batchId]);
    if (!$b || $b['status'] !== 'created') return 'Nur ein noch nicht eingereichter Lauf kann storniert werden.';
    if (trim($reason) === '') return 'Bitte einen Grund angeben.';
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        foreach (md_q('SELECT item_id, member_id FROM md_sepa_items WHERE batch_id = ?', [$batchId]) as $i) {
            md_exec('UPDATE md_ledger SET sepa_item_id = NULL WHERE sepa_item_id = ?', [$i['item_id']]);
            md_log_change((int)$i['member_id'], 'sepa', "Lastschrift {$b['batch_no']}", 'storniert: ' . trim($reason), $actor, 'admin');
        }
        md_exec("UPDATE md_sepa_items SET status = 'cancelled' WHERE batch_id = ?", [$batchId]);
        md_exec("UPDATE md_sepa_batches SET status = 'void', void_reason = ? WHERE batch_id = ?", [mb_substr(trim($reason), 0, 255), $batchId]);
        md_sepa_event($batchId, 'void', $actor, $reason);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return $e->getMessage();
    }
    return '';
}

/** Zahlungsinfo für die Rechnung: aktives Mandat, das am Fälligkeitstag verwendbar wäre (ohne Rücksicht auf noch offene Läufe). Sonst null. */
function md_sepa_debit_info(int $member, ?string $due): ?array
{
    $m = md_sepa_active_mandate($member);
    if (!$m || !$due) return null;
    if ($m['signed_on'] > $due) return null;
    $ref = md_sepa_last_use((int)$m['mandate_id']) ?? $m['signed_on'];
    if (strtotime('+36 months', strtotime($ref)) < strtotime($due)) return null;
    $iban = md_sepa_mandate_iban($m);
    if ($iban === null || !md_iban_valid($iban)) return null;
    $conf = md_sepa_conf();
    return ['mandate_ref' => $m['mandate_ref'], 'iban_mask' => md_iban_mask($iban), 'creditor_id' => md_iban_normalize($conf['creditor_id']), 'date' => $due];
}
