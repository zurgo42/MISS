<?php
declare(strict_types=1);

/**
 * DATEV-Import: übernimmt Buchungen aus einem DATEV-Export (Buchungsstapel, Format "EXTF", Kategorie 21) in das Kassenbuch der Mitglieder.
 *
 *  - Mitglieder werden über ihr Personenkonto (Debitor/Kreditor) erkannt; Tabelle md_datev_accounts, einmal gepflegt und beim Zuordnen merkbar.
 *  - Zahlungseingang (Haben auf dem Debitor): bezahlt die passende offene Forderung (Rechnungsnummer im Belegfeld/Buchungstext oder gleicher Betrag),
 *    sonst entsteht eine bezahlte Buchung. Die Art kommt aus dem Gegenkonto (Tabelle md_datev_kinds) oder der Voreinstellung.
 *  - Forderung (Soll auf dem Debitor): nur auf Wunsch als offene Forderung übernommen (Rechnungen entstehen normalerweise in MISS).
 *  - Zahlung an ein Mitglied (Soll auf dem Kreditor): bezahlte Auszahlung (Erstattung, Übungsleiterpauschale, ...).
 *  - Jede Buchungszeile hat eine Prüfsumme; was schon übernommen wurde, wird nie ein zweites Mal gebucht.
 * Bei Unsicherheit wird nichts vorangekreuzt: Der Anwender bestätigt die Vorschau.
 */

const MD_DATEV_MAX_ROWS = 20000;

function md_datev_norm(string $s): string
{
    return preg_replace('/[^a-z0-9]/', '', strtr(mb_strtolower($s), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']));
}

/** Liest eine DATEV-Datei (bereits nach UTF-8 gewandelt). Gibt ['meta' => [...], 'rows' => [...]] zurück oder wirft RuntimeException. */
function md_datev_parse(string $file): array
{
    $fh = fopen($file, 'r');
    if (!$fh) throw new RuntimeException('Datei nicht lesbar.');
    $all = [];
    while (($r = fgetcsv($fh, 0, ';', '"', '')) !== false) {
        if ($r === [null] || !array_filter($r, fn($c) => trim((string)$c) !== '')) continue;
        $all[] = array_map(fn($c) => trim((string)$c), $r);
        if (count($all) > MD_DATEV_MAX_ROWS + 3) throw new RuntimeException('Die Datei hat mehr als ' . MD_DATEV_MAX_ROWS . ' Buchungen. Bitte in Zeiträume aufteilen.');
    }
    fclose($fh);
    if (!$all || strtoupper($all[0][0] ?? '') !== 'EXTF') throw new RuntimeException('Das ist keine DATEV-Datei im Format „Buchungsstapel“ (die erste Zeile beginnt nicht mit EXTF). Export in DATEV: „Buchungsstapel exportieren (Format CSV)“.');
    $h = $all[0];
    if (($h[2] ?? '') !== '21') throw new RuntimeException('Die Datei enthält keine Buchungen (Formatkategorie ' . ($h[2] ?? '?') . ' statt 21 = Buchungsstapel).');
    $meta = ['version' => $h[1] ?? '', 'name' => $h[3] ?? '', 'created' => $h[5] ?? '', 'client' => $h[11] ?? '', 'from' => md_datev_ymd($h[14] ?? ''), 'to' => md_datev_ymd($h[15] ?? ''), 'fy_start' => md_datev_ymd($h[12] ?? ''), 'account_length' => (int)($h[13] ?? 4) ?: 4, 'label' => $h[16] ?? ''];
    // Spaltenüberschriften (Zeile 2) – sonst feste Reihenfolge des Standardformats
    $cols = [];
    $data = array_slice($all, 1);
    if ($data && !preg_match('/^[0-9]/', $data[0][0] ?? '') && preg_match('/umsatz/i', $data[0][0] ?? '')) {
        foreach ($data[0] as $i => $name) $cols[md_datev_norm($name)] = $i;
        $data = array_slice($data, 1);
    }
    $find = function (array $names, int $fallback) use ($cols): int {
        foreach ($names as $n) foreach ($cols as $key => $i) if (str_starts_with($key, $n)) return $i;
        return $fallback;
    };
    $ix = ['cents' => $find(['umsatz'], 0), 'sh' => $find(['sollhaben'], 1), 'cur' => $find(['wkzumsatz'], 2), 'konto' => $find(['konto'], 6), 'gegen' => $find(['gegenkonto'], 7), 'date' => $find(['belegdatum'], 9),
           'beleg' => $find(['belegfeld1'], 10), 'text' => $find(['buchungstext'], 13), 'gu' => $find(['generalumkehr'], -1), 'bu' => $find(['buschluessel', 'buschl'], 8)];
    if (isset($cols['konto'])) $ix['konto'] = $cols['konto'];   // "Konto" genau
    $rows = [];
    $occ = [];
    foreach ($data as $n => $c) {
        $get = fn(string $k) => $ix[$k] >= 0 ? trim((string)($c[$ix[$k]] ?? '')) : '';
        $sh = strtoupper($get('sh'));
        $cents = md_parse_amount($get('cents'));
        $date = md_datev_date($get('date'), $meta);
        $row = ['row' => $n + 3, 'cents' => $cents, 'sh' => $sh, 'currency' => $get('cur') ?: 'EUR', 'konto' => preg_replace('/\D/', '', $get('konto')), 'gegen' => preg_replace('/\D/', '', $get('gegen')),
                'date' => $date, 'beleg' => $get('beleg'), 'text' => $get('text'), 'gu' => $get('gu') === '1', 'error' => ''];
        if (!$cents || $cents <= 0) $row['error'] = 'Betrag ungültig';
        elseif (!in_array($sh, ['S', 'H'], true)) $row['error'] = 'Soll/Haben-Kennzeichen fehlt';
        elseif (!$date) $row['error'] = 'Belegdatum ungültig';
        elseif ($row['konto'] === '' || $row['gegen'] === '') $row['error'] = 'Konto fehlt';
        elseif ($row['currency'] !== 'EUR') $row['error'] = 'Währung ' . $row['currency'] . ' wird nicht unterstützt';
        $base = implode('|', [$row['date'], $row['cents'], $row['sh'], $row['konto'], $row['gegen'], $row['beleg'], $row['text']]);
        $occ[$base] = ($occ[$base] ?? 0) + 1;
        $row['fingerprint'] = sha1($base . '|' . $occ[$base]);
        $rows[] = $row;
    }
    return ['meta' => $meta, 'rows' => $rows];
}

function md_datev_ymd(string $s): ?string
{
    return preg_match('/^(\d{4})(\d{2})(\d{2})/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
}

/** Belegdatum ist "TTMM" (ohne Jahr); das Jahr ergibt sich aus dem Zeitraum der Datei. */
function md_datev_date(string $s, array $meta): ?string
{
    if (!preg_match('/^\d{3,4}$/', $s) && !preg_match('/^\d{6,8}$/', $s)) return null;
    if (strlen($s) >= 6) {   // vollständiges Datum TTMMJJ(JJ), kommt in manchen Exporten vor
        $y = (int)substr($s, 4); if ($y < 100) $y += 2000;
        $d = (int)substr($s, 0, 2); $m = (int)substr($s, 2, 2);
        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }
    $s = str_pad($s, 4, '0', STR_PAD_LEFT);
    $d = (int)substr($s, 0, 2); $m = (int)substr($s, 2, 2);
    $years = array_values(array_unique(array_filter([$meta['from'] ? (int)substr($meta['from'], 0, 4) : null, $meta['to'] ? (int)substr($meta['to'], 0, 4) : null, $meta['fy_start'] ? (int)substr($meta['fy_start'], 0, 4) : null])));
    foreach ($years ?: [(int)date('Y')] as $y) {
        if (!checkdate($m, $d, $y)) continue;
        $iso = sprintf('%04d-%02d-%02d', $y, $m, $d);
        if ((!$meta['from'] || $iso >= $meta['from']) && (!$meta['to'] || $iso <= $meta['to'])) return $iso;
    }
    return null;
}

// ---------------------------------------------------------------- Stammdaten

function md_datev_account_of(string $account): ?array
{
    return md_one('SELECT * FROM md_datev_accounts WHERE account = ?', [$account]) ?: null;
}

/** Art des Personenkontos nach Nummernkreis (bei 4-stelligen Sachkonten: 10000–69999 Debitoren, ab 70000 Kreditoren). */
function md_datev_guess_type(string $account): string
{
    return $account !== '' && $account[0] >= '7' ? 'kreditor' : 'debitor';
}

function md_datev_account_save(string $account, int $member, ?string $type = null): string
{
    $account = preg_replace('/\D/', '', $account);
    if ($account === '' || strlen($account) > 9) return 'Kontonummer ungültig.';
    if (!md_one('SELECT 1 FROM svmembers WHERE member_id = ?', [$member])) return 'Mitglied nicht gefunden.';
    $type = in_array($type, ['debitor', 'kreditor'], true) ? $type : md_datev_guess_type($account);
    if (md_one('SELECT 1 FROM md_datev_accounts WHERE account = ?', [$account])) md_exec('UPDATE md_datev_accounts SET member_id = ?, type = ? WHERE account = ?', [$member, $type, $account]);
    else md_exec('INSERT INTO md_datev_accounts (account, member_id, type) VALUES (?,?,?)', [$account, $member, $type]);
    return '';
}

/** Mehrere Zeilen "Konto;Mitgliedsnummer". Gibt [übernommen, Fehlerzeilen] zurück. */
function md_datev_accounts_bulk(string $text): array
{
    $n = 0;
    $errs = [];
    foreach (preg_split('/\R/', trim($text)) as $i => $line) {
        if (trim($line) === '') continue;
        $p = preg_split('/[;\t,]/', $line);
        $m = md_one('SELECT member_id FROM svmembers WHERE membership_number = ?', [trim($p[1] ?? '')]);
        if (!$m) { $errs[] = 'Zeile ' . ($i + 1) . ': Mitgliedsnummer „' . trim($p[1] ?? '') . '“ nicht gefunden'; continue; }
        if ($e = md_datev_account_save($p[0], (int)$m['member_id'])) { $errs[] = 'Zeile ' . ($i + 1) . ': ' . $e; continue; }
        $n++;
    }
    return [$n, $errs];
}

function md_datev_kind_for(string $account, string $default): string
{
    $a = (int)$account;
    foreach (md_q('SELECT * FROM md_datev_kinds ORDER BY rule_id') as $r) if ($a >= (int)$r['account_from'] && $a <= (int)$r['account_to']) return $r['kind'];
    return $default;
}

function md_datev_kind_rule_save(string $from, string $to, string $kind): string
{
    $from = preg_replace('/\D/', '', $from);
    $to = preg_replace('/\D/', '', $to) ?: $from;
    if ($from === '' || (int)$to < (int)$from) return 'Kontonummern ungültig (von ≤ bis).';
    if (!isset(MD_LEDGER_KINDS[$kind])) return 'Buchungsart unbekannt.';
    md_exec('INSERT INTO md_datev_kinds (account_from, account_to, kind) VALUES (?,?,?)', [$from, $to, $kind]);
    return '';
}

// ---------------------------------------------------------------- Plan

/**
 * Vorschau. $opts: claims (Soll auf Debitor als Forderung übernehmen), in_kind, out_kind (Voreinstellungen).
 * $assign: Zeilennummer => Mitglied (manuelle Zuordnung). Je Zeile: action pay|new_paid|new_open|new_out|skip, level sure|suggest|manual|skip, member_id, entry_ids[], kind, note.
 */
function md_datev_plan(array $parsed, array $opts, array $assign = []): array
{
    $inKind = isset(MD_LEDGER_KINDS[$opts['in_kind'] ?? '']) ? $opts['in_kind'] : 'beitrag';
    $outKind = isset(MD_LEDGER_KINDS[$opts['out_kind'] ?? '']) ? $opts['out_kind'] : 'erstattung';
    $accts = [];
    foreach (md_q('SELECT * FROM md_datev_accounts') as $a) $accts[$a['account']] = $a;
    $done = array_flip(array_column(md_q('SELECT fingerprint FROM md_datev_rows'), 'fingerprint'));
    $claims = md_q("SELECT l.*, s.membership_number FROM md_ledger l JOIN svmembers s ON s.member_id = l.member_id WHERE l.status = 'open' AND l.direction = 'to_club' AND l.sepa_item_id IS NULL");
    $byMember = [];
    foreach ($claims as $c) $byMember[(int)$c['member_id']][] = $c;
    $used = [];
    $plan = [];
    foreach ($parsed['rows'] as $r) {
        $p = $r + ['action' => 'skip', 'level' => 'skip', 'member_id' => null, 'entry_ids' => [], 'kind' => null, 'note' => '', 'type' => null, 'person_account' => null, 'other_account' => null];
        if ($r['error']) { $p['note'] = $r['error']; $plan[] = $p; continue; }
        if (isset($done[$r['fingerprint']])) { $p['note'] = 'schon übernommen'; $p['level'] = 'dup'; $plan[] = $p; continue; }
        if ($r['gu']) { $p['note'] = 'Generalumkehr (Stornobuchung) – bitte von Hand prüfen'; $plan[] = $p; continue; }
        // Personenkonto bestimmen
        $person = null;
        foreach (['konto', 'gegen'] as $side) if (isset($accts[$r[$side]])) { $person = $side; break; }
        $member = null; $type = null;
        if ($person) { $member = (int)$accts[$r[$person]]['member_id']; $type = $accts[$r[$person]]['type']; }
        elseif (!empty($assign[$r['row']])) {
            $member = (int)$assign[$r['row']];
            $cand = array_values(array_filter([$r['konto'], $r['gegen']], fn($a) => strlen($a) > $parsed['meta']['account_length']));
            $person = $cand ? (strlen($r['konto']) > $parsed['meta']['account_length'] ? 'konto' : 'gegen') : null;
            $type = $person ? md_datev_guess_type($r[$person]) : null;
        }
        $p['member_id'] = $member;
        $other = $person ? ($person === 'konto' ? $r['gegen'] : $r['konto']) : $r['gegen'];
        $p['person_account'] = $person ? $r[$person] : null;
        $p['other_account'] = $other;
        // Seite des Personenkontos: S/H gilt für "Konto"; das Gegenkonto steht auf der anderen Seite
        $side = $person === 'gegen' ? ($r['sh'] === 'S' ? 'H' : 'S') : $r['sh'];
        if (!$member) {
            // ohne Personenkonto: Rechnungsnummer im Text kann das Mitglied verraten (nur Zahlungseingänge)
            $text = $r['beleg'] . ' ' . $r['text'];
            $hit = [];
            foreach ($claims as $c) if ($c['reference'] && ($pat = md_payment_pattern((string)$c['reference'])) && preg_match($pat, $text) && !isset($used[$c['entry_id']])) $hit[] = $c;
            $members = array_unique(array_map(fn($c) => (int)$c['member_id'], $hit));
            if (count($members) === 1) {
                $sum = array_sum(array_map(fn($c) => (int)$c['amount_cents'], $hit));
                $p['member_id'] = $members[0];
                $p['action'] = 'pay'; $p['level'] = $sum === $r['cents'] ? 'suggest' : 'manual'; $p['entry_ids'] = array_map(fn($c) => (int)$c['entry_id'], $hit);
                $p['note'] = 'über die Rechnungsnummer im Text, Konto nicht zugeordnet'; $p['type'] = 'debitor';
                if ($sum !== $r['cents']) { $p['action'] = 'skip'; $p['level'] = 'manual'; $p['entry_ids'] = []; $p['note'] = 'Rechnungsnummer gefunden, aber Betrag weicht ab'; }
                else foreach ($p['entry_ids'] as $id) $used[$id] = true;
            } else { $p['level'] = 'manual'; $p['note'] = 'Kein Personenkonto bekannt – Mitglied zuordnen'; }
            $plan[] = $p;
            continue;
        }
        $p['type'] = $type ?? ($side === 'H' ? 'debitor' : 'kreditor');
        if ($p['type'] === 'debitor' && $side === 'H') {
            // Zahlungseingang
            $mine = array_values(array_filter($byMember[$member] ?? [], fn($c) => !isset($used[$c['entry_id']])));
            $text = $r['beleg'] . ' ' . $r['text'];
            $hit = array_values(array_filter($mine, fn($c) => $c['reference'] && ($pat = md_payment_pattern((string)$c['reference'])) && preg_match($pat, $text)));
            $sumHit = array_sum(array_map(fn($c) => (int)$c['amount_cents'], $hit));
            if ($hit && $sumHit === $r['cents']) { $p['action'] = 'pay'; $p['level'] = 'sure'; $p['entry_ids'] = array_map(fn($c) => (int)$c['entry_id'], $hit); $p['note'] = 'Rechnungsnummer ' . implode(', ', array_column($hit, 'reference')); }
            else {
                $same = array_values(array_filter($mine, fn($c) => (int)$c['amount_cents'] === $r['cents']));
                if (count($same) === 1) { $p['action'] = 'pay'; $p['level'] = 'suggest'; $p['entry_ids'] = [(int)$same[0]['entry_id']]; $p['note'] = 'gleicher Betrag wie offene Forderung ' . $same[0]['reference']; }
                else {
                    $p['action'] = 'new_paid'; $p['level'] = $hit ? 'manual' : 'suggest'; $p['kind'] = md_datev_kind_for($other, $inKind);
                    $p['note'] = $hit ? 'Rechnungsnummer gefunden, aber Betrag weicht ab (' . md_money($sumHit) . ' offen) – als neue bezahlte Buchung?' : 'keine offene Forderung passt – neue bezahlte Buchung';
                }
            }
            foreach ($p['entry_ids'] as $id) $used[$id] = true;
        } elseif ($p['type'] === 'debitor' && $side === 'S') {
            if (!empty($opts['claims'])) {
                if ($r['beleg'] !== '' && md_one('SELECT 1 FROM md_ledger WHERE member_id = ? AND reference = ?', [$member, $r['beleg']])) { $p['note'] = 'Forderung ' . $r['beleg'] . ' ist schon im Kassenbuch'; }
                else { $p['action'] = 'new_open'; $p['level'] = 'sure'; $p['kind'] = md_datev_kind_for($other, $inKind); $p['note'] = 'Forderung übernehmen'; }
            } else $p['note'] = 'Forderung (Rechnungen kommen aus MISS)';
        } elseif ($p['type'] === 'kreditor' && $side === 'S') {
            $p['action'] = 'new_out'; $p['level'] = 'suggest'; $p['kind'] = md_datev_kind_for($other, $outKind); $p['note'] = 'Zahlung an das Mitglied';
        } else {
            $p['note'] = $p['type'] === 'kreditor' ? 'Verbindlichkeit (wird mit der Zahlung gebucht)' : 'Gegenbuchung/Korrektur – bitte von Hand prüfen';
        }
        $plan[] = $p;
    }
    return $plan;
}

/**
 * Bucht die bestätigten Zeilen (in einer Transaktion). $accept: Zeilennummern; $remember: Zeilennummern, deren Personenkonto gemerkt werden soll.
 * Gibt [bezahlt, neu angelegt, Fehlerliste, import_id] zurück.
 */
function md_datev_apply(array $parsed, array $plan, array $accept, array $remember, string $fileName, int $actor): array
{
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    $paid = $new = $skipped = 0;
    try {
        md_exec('INSERT INTO md_datev_imports (file_name, created_at, created_by, period_from, period_to, rows_total) VALUES (?,?,?,?,?,?)',
            [mb_substr($fileName, 0, 200), md_now(), $actor, $parsed['meta']['from'], $parsed['meta']['to'], count($plan)]);
        $iid = (int)$pdo->lastInsertId();
        $dueDays = md_fee_conf()['due_days'];
        foreach ($plan as $r) {
            if (!in_array($r['row'], $accept, true) || !in_array($r['level'], ['sure', 'suggest', 'manual'], true) || $r['action'] === 'skip' || !$r['member_id']) { continue; }
            if (md_one('SELECT 1 FROM md_datev_rows WHERE fingerprint = ?', [$r['fingerprint']])) { $skipped++; continue; }
            $entry = null;
            $desc = mb_substr('DATEV: ' . ($r['text'] !== '' ? $r['text'] : 'Buchung ' . $r['beleg']), 0, 255);
            $ref = $r['beleg'] !== '' ? $r['beleg'] : 'DATEV-' . substr($r['fingerprint'], 0, 10);
            if ($r['action'] === 'pay') {
                foreach ($r['entry_ids'] as $id) {
                    if ($e = md_ledger_mark_paid($id, md_date($r['date']), $actor)) throw new RuntimeException("Zeile {$r['row']}: $e");
                    $entry = $entry ?? $id;
                    $paid++;
                }
            } else {
                $dir = $r['action'] === 'new_out' ? 'to_member' : 'to_club';
                $kind = $r['kind'];
                if ($r['action'] === 'new_out' && MD_LEDGER_KINDS[$kind][1] !== 'to_member') throw new RuntimeException("Zeile {$r['row']}: Die Art „" . MD_LEDGER_KINDS[$kind][0] . '“ passt nicht zu einer Zahlung an das Mitglied.');
                if ($r['action'] !== 'new_out' && MD_LEDGER_KINDS[$kind][1] !== 'to_club') throw new RuntimeException("Zeile {$r['row']}: Die Art „" . MD_LEDGER_KINDS[$kind][0] . '“ passt nicht zu einer Forderung an das Mitglied.');
                [$entry, $e] = md_ledger_add(['member_id' => $r['member_id'], 'kind' => $kind, 'amount_cents' => $r['cents'], 'entry_date' => md_date($r['date']), 'description' => $desc, 'reference' => $ref,
                    'status' => $r['action'] === 'new_open' ? 'open' : 'paid', 'paid_date' => $r['action'] === 'new_open' ? '' : md_date($r['date']),
                    'due_date' => $r['action'] === 'new_open' ? md_date(date('Y-m-d', strtotime("+$dueDays days", strtotime($r['date'])))) : ''], $actor, 'datev');
                if (!$entry) throw new RuntimeException("Zeile {$r['row']}: $e");
                $new++;
            }
            md_exec('INSERT INTO md_datev_rows (fingerprint, import_id, row_no, entry_id, imported_at) VALUES (?,?,?,?,?)', [$r['fingerprint'], $iid, $r['row'], $entry, md_now()]);
            if (in_array($r['row'], $remember, true) && $r['person_account'] && !md_datev_account_of($r['person_account'])) md_datev_account_save($r['person_account'], (int)$r['member_id']);
        }
        md_exec('UPDATE md_datev_imports SET paid_count = ?, created_count = ?, skipped_count = ? WHERE import_id = ?', [$paid, $new, $skipped, $iid]);
        md_log_change($actor, 'datev', $fileName, "$paid Forderung(en) bezahlt, $new Buchung(en) angelegt", $actor, 'self');
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return [0, 0, [$e->getMessage()], 0];
    }
    return [$paid, $new, [], $iid];
}
