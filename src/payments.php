<?php
declare(strict_types=1);

/**
 * Zahlungszuordnung aus einem Kontoauszug (CSV): ordnet Zahlungseingänge offenen Forderungen zu und vermerkt sie nach Bestätigung als bezahlt.
 *
 *  - Zuordnung über Rechnungs-/Schreibennummer im Verwendungszweck (R2027-0001, M2027-0001; Leerzeichen und Bindestriche egal). Stimmt der Betrag mit der Summe
 *    der gefundenen Forderungen überein: sicher. Sonst "Betrag weicht ab" (nicht automatisch).
 *  - Ohne Nummer: Mitgliedsnummer im Verwendungszweck und genau eine offene Forderung mit demselben Betrag: Vorschlag (muss bestätigt werden).
 *  - Ausgänge (negative Beträge) und nicht zuordenbare Zahlungen werden nur angezeigt. Teilzahlungen sind nicht vorgesehen.
 */

const MD_PAYMENT_TARGETS = [
    'date'    => ['Buchungs-/Wertstellungsdatum', ['datum', 'buchungstag', 'buchungsdatum', 'valuta', 'wertstellung', 'valutadatum']],
    'amount'  => ['Betrag', ['betrag', 'umsatz', 'betrageur', 'betragindeur', 'amount']],
    'purpose' => ['Verwendungszweck', ['verwendungszweck', 'buchungstext', 'text', 'zweck', 'beschreibung']],
    'payer'   => ['Auftraggeber/Name (optional)', ['auftraggeber', 'name', 'beguenstigterzahlungspflichtiger', 'zahlungspflichtiger', 'empfaenger', 'auftraggebername']],
];

function md_payment_guess_mapping(array $headers): array
{
    $norm = fn(string $v) => preg_replace('/[^a-z0-9]/', '', strtr(mb_strtolower($v), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']));
    $map = [];
    foreach (MD_PAYMENT_TARGETS as $key => [$label, $syn]) {
        $syn = array_map($norm, array_merge($syn, [$label]));
        foreach ($headers as $i => $hd) if (in_array($norm($hd), $syn, true) && !in_array($i, $map, true)) { $map[$key] = $i; break; }
    }
    return $map;
}

/** Muster für eine Nummer wie R2027-0001: Bestandteile mit beliebigen Trennzeichen dazwischen, ohne Treffer mitten in längeren Nummern. */
function md_payment_pattern(string $ref): ?string
{
    $tokens = preg_split('/[^A-Za-z0-9]+/', $ref, -1, PREG_SPLIT_NO_EMPTY);
    if (!$tokens || strlen(implode('', $tokens)) < 5) return null;
    return '/(?<![A-Za-z0-9])' . implode('[\\s\\-_\\/.]*', array_map(fn($t) => preg_quote($t, '/'), $tokens)) . '(?![A-Za-z0-9])/i';
}

/**
 * Plan: je Zeile status ('sure'|'suggest'|'diff'|'none'|'debit'|'error'), entry_ids[], date, cents, purpose, payer, note.
 */
function md_payment_plan(array $rows, array $map): array
{
    $claims = md_q("SELECT l.*, s.first_name, s.last_name, s.membership_number FROM md_ledger l JOIN svmembers s ON s.member_id = l.member_id
                    WHERE l.status = 'open' AND l.direction = 'to_club' AND l.reference IS NOT NULL ORDER BY l.entry_date");
    $openAll = md_q("SELECT l.*, s.membership_number FROM md_ledger l JOIN svmembers s ON s.member_id = l.member_id WHERE l.status = 'open' AND l.direction = 'to_club'");
    $plan = [];
    $used = [];
    foreach ($rows as $i => $cells) {
        $get = fn(string $k) => isset($map[$k]) ? trim((string)($cells[(int)$map[$k]] ?? '')) : '';
        $rawAmount = $get('amount');
        $row = ['row' => $i + 2, 'status' => 'none', 'entry_ids' => [], 'date' => md_parse_date($get('date')), 'cents' => md_parse_amount($rawAmount), 'purpose' => $get('purpose'), 'payer' => $get('payer'), 'note' => '', 'matches' => []];
        if (str_starts_with(ltrim($rawAmount), '-')) { $row['status'] = 'debit'; $row['note'] = 'Ausgang'; $plan[] = $row; continue; }
        if (!$row['cents'] || !$row['date']) { $row['status'] = 'error'; $row['note'] = !$row['cents'] ? 'Betrag ungültig' : 'Datum ungültig'; $plan[] = $row; continue; }
        $found = [];
        foreach ($claims as $c) {
            $pat = md_payment_pattern((string)$c['reference']);
            if ($pat && preg_match($pat, $row['purpose']) && !isset($used[$c['entry_id']])) $found[] = $c;
        }
        if ($found) {
            $sum = array_sum(array_map(fn($c) => (int)$c['amount_cents'], $found));
            $row['matches'] = array_map(fn($c) => $c['reference'] . ' ' . $c['first_name'] . ' ' . $c['last_name'] . ' ' . md_money((int)$c['amount_cents']), $found);
            if ($sum === $row['cents']) { $row['status'] = 'sure'; $row['entry_ids'] = array_map(fn($c) => (int)$c['entry_id'], $found); foreach ($row['entry_ids'] as $id) $used[$id] = true; }
            else { $row['status'] = 'diff'; $row['note'] = 'Betrag ' . md_money($row['cents']) . ' weicht von ' . md_money($sum) . ' ab'; }
            $plan[] = $row;
            continue;
        }
        // über die Mitgliedsnummer
        $cand = [];
        foreach ($openAll as $c) {
            $pat = md_payment_pattern((string)$c['membership_number']);
            if ($pat && (int)$c['amount_cents'] === $row['cents'] && !isset($used[$c['entry_id']]) && preg_match($pat, $row['purpose'])) $cand[] = $c;
        }
        if (count($cand) === 1) {
            $row['status'] = 'suggest';
            $row['entry_ids'] = [(int)$cand[0]['entry_id']];
            $row['matches'] = [$cand[0]['reference'] . ' ' . $cand[0]['description'] . ' ' . md_money((int)$cand[0]['amount_cents'])];
            $row['note'] = 'über Mitgliedsnummer und Betrag';
            $used[$cand[0]['entry_id']] = true;
        } elseif (count($cand) > 1) { $row['note'] = 'mehrere passende Forderungen'; }
        $plan[] = $row;
    }
    return $plan;
}

/** Vermerkt die bestätigten Zahlungen. $accept: Zeilennummern. Gibt [bezahlt, Fehlerliste] zurück. */
function md_payment_apply(array $plan, array $accept, int $actor): array
{
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    $n = 0;
    $errs = [];
    try {
        foreach ($plan as $r) {
            if (!in_array($r['row'], $accept, true) || !in_array($r['status'], ['sure', 'suggest'], true)) continue;
            foreach ($r['entry_ids'] as $id) {
                $err = md_ledger_mark_paid($id, md_date($r['date']), $actor);
                if ($err) throw new RuntimeException("Zeile {$r['row']}: $err");
                $n++;
            }
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return [0, [$e->getMessage()]];
    }
    return [$n, $errs];
}
