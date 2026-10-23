<?php
declare(strict_types=1);

/**
 * Beiträge: Gruppen, Zuordnung mit Abweichungen, Beitragslauf (Vorschau, Erzeugen), Rechnungsnummern und -daten.
 *
 *  - Eine Beitragsgruppe hat einen Betrag je Abrechnungszeitraum (Rhythmus jährlich/halbjährlich/vierteljährlich/monatlich) und eine Eintrittsregel
 *    (voll oder anteilig ab Eintrittsmonat bis Austrittsmonat).
 *  - Je Mitglied gilt die am Zeitraum gültige Zuordnung, optional mit abweichendem Betrag oder Prozent-Ermäßigung (nur mit Grund).
 *  - Ein Beitragslauf erzeugt je Mitglied eine Forderung (md_ledger, Art Beitrag) mit Rechnungsnummer R<Jahr>-<laufende Nummer>.
 *    Pro Mitglied und Zeitraum nie doppelt.
 */

const MD_RHYTHMS = ['yearly' => 'jährlich', 'halfyearly' => 'halbjährlich', 'quarterly' => 'vierteljährlich', 'monthly' => 'monatlich'];
const MD_ENTRY_RULES = ['full' => 'voller Betrag (wenn im Zeitraum Mitglied)', 'prorated' => 'anteilig ab Eintrittsmonat bis Austrittsmonat'];
const MD_MONTH_NAMES = [1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'];

/** Einstellungen für Rechnungen und Mahnungen (md_settings fees.*). */
const MD_FEE_FIELDS = [   // Schlüssel => [Bezeichnung, Standardwert, Hinweis im Eingabefeld]
    'iban'         => ['IBAN des Vereins', '', 'DE00 0000 0000 0000 0000 00'],
    'bic'          => ['BIC', '', ''],
    'bank'         => ['Bank', '', ''],
    'due_days'     => ['Zahlungsziel in Tagen', '14', ''],
    'invoice_text' => ['Einleitung der Rechnung', 'für Ihre Mitgliedschaft berechnen wir Ihnen:', ''],
    'tax_note'     => ['Steuerhinweis (bitte mit dem Steuerberater abstimmen)', 'Mitgliedsbeiträge sind umsatzsteuerfrei.', ''],
];

function md_fee_conf(): array
{
    $c = [];
    foreach (MD_FEE_FIELDS as $k => [, $def]) $c[$k] = md_setting('fees.' . $k, $def);
    $c['due_days'] = max(0, (int)$c['due_days']);
    return $c;
}

/** Zeitraumschlüssel -> ['rhythm', 'start', 'end', 'months', 'label'] oder null. */
function md_fee_period(string $key): ?array
{
    if (preg_match('/^(\d{4})$/', $key, $m)) return ['rhythm' => 'yearly', 'start' => "$m[1]-01-01", 'end' => "$m[1]-12-31", 'months' => 12, 'label' => $m[1]];
    if (preg_match('/^(\d{4})-H([12])$/', $key, $m)) { $a = $m[2] === '1' ? 1 : 7; return ['rhythm' => 'halfyearly', 'start' => sprintf('%s-%02d-01', $m[1], $a), 'end' => date('Y-m-t', strtotime(sprintf('%s-%02d-01', $m[1], $a + 5))), 'months' => 6, 'label' => $m[2] . '. Halbjahr ' . $m[1]]; }
    if (preg_match('/^(\d{4})-Q([1-4])$/', $key, $m)) { $a = ($m[2] - 1) * 3 + 1; return ['rhythm' => 'quarterly', 'start' => sprintf('%s-%02d-01', $m[1], $a), 'end' => date('Y-m-t', strtotime(sprintf('%s-%02d-01', $m[1], $a + 2))), 'months' => 3, 'label' => $m[2] . '. Quartal ' . $m[1]]; }
    if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $key, $m)) return ['rhythm' => 'monthly', 'start' => "$m[1]-$m[2]-01", 'end' => date('Y-m-t', strtotime("$m[1]-$m[2]-01")), 'months' => 1, 'label' => MD_MONTH_NAMES[(int)$m[2]] . ' ' . $m[1]];
    return null;
}

/** Zeiträume eines Jahres je Rhythmus (für die Auswahl). */
function md_fee_period_keys(int $year): array
{
    $keys = [(string)$year, "$year-H1", "$year-H2", "$year-Q1", "$year-Q2", "$year-Q3", "$year-Q4"];
    for ($m = 1; $m <= 12; $m++) $keys[] = sprintf('%d-%02d', $year, $m);
    return $keys;
}

/** Anzahl Monate des Zeitraums, in denen das Mitglied zählt (Eintritts- und Austrittsmonat voll). */
function md_fee_months(array $period, ?string $join, ?string $leave): int
{
    $n = 0;
    for ($t = strtotime($period['start']); $t <= strtotime($period['end']); $t = strtotime('+1 month', $t)) {
        $mStart = date('Y-m-01', $t);
        $mEnd = date('Y-m-t', $t);
        if (($join === null || $join <= $mEnd) && ($leave === null || $leave >= $mStart)) $n++;
    }
    return $n;
}

/** Am Zeitraum gültige Beitragszuordnung eines Mitglieds (mit Gruppendaten) oder null. */
function md_fee_assignment(int $member, string $start, string $end): ?array
{
    return md_one('SELECT f.*, g.name AS group_name, g.amount_cents AS group_cents, g.rhythm, g.entry_rule FROM md_member_fees f JOIN md_fee_groups g ON g.group_id = f.group_id
                   WHERE f.member_id = ? AND f.valid_from <= ? AND (f.valid_to IS NULL OR f.valid_to >= ?) ORDER BY f.valid_from DESC, f.fee_id DESC LIMIT 1', [$member, $end, $start]);
}

function md_fee_groups(bool $onlyActive = false): array
{
    return md_q('SELECT * FROM md_fee_groups' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY name');
}

function md_fee_group_save(?int $id, array $d): string
{
    $name = trim((string)($d['name'] ?? ''));
    $cents = md_parse_amount((string)($d['amount'] ?? ''));
    $zero = trim((string)($d['amount'] ?? '')) === '0' || trim((string)($d['amount'] ?? '')) === '0,00';
    if ($name === '') return 'Name fehlt.';
    if (!$cents && !$zero) return 'Betrag ungültig (z. B. 120,00; 0 für beitragsfrei).';
    $rhythm = array_key_exists($d['rhythm'] ?? '', MD_RHYTHMS) ? $d['rhythm'] : 'yearly';
    $rule = array_key_exists($d['entry_rule'] ?? '', MD_ENTRY_RULES) ? $d['entry_rule'] : 'full';
    $active = !empty($d['active']) ? 1 : 0;
    $public = !empty($d['public']) ? 1 : 0;
    $desc = trim((string)($d['description'] ?? '')) ?: null;
    if (md_one('SELECT 1 FROM md_fee_groups WHERE LOWER(name) = ? AND group_id <> ?', [mb_strtolower($name), (int)$id])) return 'Es gibt schon eine Gruppe mit diesem Namen.';
    if ($id) md_exec('UPDATE md_fee_groups SET name=?, amount_cents=?, rhythm=?, entry_rule=?, description=?, active=?, public=? WHERE group_id=?', [$name, (int)$cents, $rhythm, $rule, $desc, $active, $public, $id]);
    else md_exec('INSERT INTO md_fee_groups (name, amount_cents, rhythm, entry_rule, description, active, public) VALUES (?,?,?,?,?,?,?)', [$name, (int)$cents, $rhythm, $rule, $desc, $active, $public]);
    return '';
}

/** Ordnet einem Mitglied eine Beitragsgruppe zu (beendet die bisherige am Tag davor). Abweichung nur mit Grund. */
function md_fee_assign(int $member, int $group, array $d, int $actor): string
{
    $g = md_one('SELECT * FROM md_fee_groups WHERE group_id = ?', [$group]);
    if (!$g) return 'Beitragsgruppe nicht gefunden.';
    $from = md_parse_date($d['valid_from'] ?? '') ?? date('Y-m-d');
    $to = md_parse_date($d['valid_to'] ?? '');
    if ($to && $to < $from) return 'Ende liegt vor dem Beginn.';
    $over = trim((string)($d['override'] ?? '')) !== '' ? md_parse_amount((string)$d['override']) : null;
    if (trim((string)($d['override'] ?? '')) !== '' && !$over) return 'Abweichender Betrag ungültig.';
    $disc = trim((string)($d['discount'] ?? '')) !== '' ? (int)$d['discount'] : null;
    if ($disc !== null && ($disc < 1 || $disc > 100)) return 'Ermäßigung bitte 1–100 Prozent.';
    if ($over && $disc) return 'Entweder abweichender Betrag oder Prozent-Ermäßigung, nicht beides.';
    $reason = trim((string)($d['reason'] ?? ''));
    if (($over || $disc) && $reason === '') return 'Für eine Abweichung ist ein Grund erforderlich (Nachvollziehbarkeit).';
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $cur = md_one('SELECT f.*, g.name FROM md_member_fees f JOIN md_fee_groups g ON g.group_id = f.group_id WHERE f.member_id = ? AND f.valid_to IS NULL ORDER BY f.valid_from DESC LIMIT 1', [$member]);
        if ($cur && $cur['valid_from'] < $from) md_exec('UPDATE md_member_fees SET valid_to = ? WHERE fee_id = ?', [date('Y-m-d', strtotime('-1 day', strtotime($from))), $cur['fee_id']]);
        elseif ($cur && $cur['valid_from'] >= $from) md_exec('DELETE FROM md_member_fees WHERE fee_id = ?', [$cur['fee_id']]);   // gleicher Beginn: ersetzen
        md_exec('INSERT INTO md_member_fees (member_id, group_id, valid_from, valid_to, amount_override_cents, discount_percent, reason, approved_by, created_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$member, $group, $from, $to, $over, $disc, $reason ?: null, trim((string)($d['approved_by'] ?? '')) ?: null, md_now(), $actor]);
        md_log_change($member, 'fee', $cur ? $cur['name'] : null, $g['name'] . ($over ? ' mit ' . md_money($over) : '') . ($disc ? " mit $disc % Ermäßigung" : '') . ($reason ? " ($reason)" : '') . ' ab ' . md_date($from), $actor, 'admin');
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return 'Fehler: ' . $e->getMessage();
    }
    return '';
}

/**
 * Vorschau eines Beitragslaufs. Gibt die Zeilen zurück:
 * member_id, name, number, group, base_cents, cents, months, of, action ('create'|'skip'|'warn'), note
 */
function md_fee_run_plan(string $periodKey): array
{
    $period = md_fee_period($periodKey);
    if (!$period) return [];
    $plan = [];
    $members = md_q("SELECT s.member_id, s.first_name, s.last_name, s.membership_number, p.status, p.join_date, p.leave_date FROM svmembers s LEFT JOIN md_profiles p ON p.member_id = s.member_id
                     WHERE s.membership_number NOT LIKE 'DEL-%' ORDER BY s.last_name, s.first_name");
    foreach ($members as $m) {
        $row = ['member_id' => (int)$m['member_id'], 'name' => $m['first_name'] . ' ' . $m['last_name'], 'number' => $m['membership_number'], 'group' => '', 'base_cents' => 0, 'cents' => 0,
                'months' => 0, 'of' => $period['months'], 'action' => 'skip', 'note' => ''];
        $status = $m['status'] ?? 'aktiv';
        if ($status === 'interessent') { $row['note'] = 'Interessent'; $plan[] = $row; continue; }
        $join = $m['join_date'] ?: null;
        $leave = ($status === 'ausgetreten' && $m['leave_date']) ? $m['leave_date'] : null;
        $months = md_fee_months($period, $join, $leave);
        $row['months'] = $months;
        if ($months === 0) { $row['note'] = $status === 'ausgetreten' ? 'ausgetreten' : 'noch nicht Mitglied'; $plan[] = $row; continue; }
        $a = md_fee_assignment((int)$m['member_id'], $period['start'], $period['end']);
        if (!$a) { $row['action'] = 'warn'; $row['note'] = 'keine Beitragsgruppe zugeordnet'; $plan[] = $row; continue; }
        $row['group'] = $a['group_name'];
        if ($a['rhythm'] !== $period['rhythm']) { $row['note'] = 'Gruppe ist ' . MD_RHYTHMS[$a['rhythm']] . ', nicht ' . MD_RHYTHMS[$period['rhythm']]; $plan[] = $row; continue; }
        $base = $a['amount_override_cents'] !== null ? (int)$a['amount_override_cents'] : (int)$a['group_cents'];
        $notes = [];
        if ($a['amount_override_cents'] !== null) $notes[] = 'abweichender Betrag (' . $a['reason'] . ')';
        if ($a['discount_percent'] !== null) { $base = (int)round($base * (100 - (int)$a['discount_percent']) / 100); $notes[] = $a['discount_percent'] . ' % Ermäßigung (' . $a['reason'] . ')'; }
        $cents = $a['entry_rule'] === 'prorated' ? (int)round($base * $months / $period['months']) : $base;
        if ($a['entry_rule'] === 'prorated' && $months < $period['months']) $notes[] = "anteilig $months/{$period['months']} Monate";
        $row['base_cents'] = $base;
        $row['cents'] = $cents;
        $row['note'] = implode('; ', $notes);
        if ($cents <= 0) { $row['note'] = trim('beitragsfrei ' . $row['note']); $plan[] = $row; continue; }
        if (md_one("SELECT 1 FROM md_ledger WHERE member_id = ? AND kind = 'beitrag' AND fee_period = ? AND status <> 'cancelled'", [(int)$m['member_id'], $periodKey])) { $row['note'] = 'für diesen Zeitraum schon berechnet'; $plan[] = $row; continue; }
        $row['action'] = 'create';
        $row['debit'] = function_exists('md_sepa_active_mandate') && md_sepa_active_mandate((int)$m['member_id']) !== null;
        $plan[] = $row;
    }
    return $plan;
}

/** Nächste Rechnungsnummer R<Jahr>-<laufend> (innerhalb einer Transaktion aufrufen). */
function md_next_invoice_no(int $year): string
{
    $last = md_one("SELECT reference FROM md_ledger WHERE reference LIKE ? ORDER BY reference DESC LIMIT 1", ["R$year-%"]);
    $n = $last ? (int)substr($last['reference'], strlen("R$year-")) + 1 : 1;
    return sprintf('R%d-%04d', $year, $n);
}

/** Erzeugt die Forderungen des Beitragslaufs. Gibt [run_id|null, Fehlertext] zurück. */
function md_fee_run_apply(string $periodKey, string $due, int $actor, ?string $note = null): array
{
    $period = md_fee_period($periodKey);
    if (!$period) return [null, 'Zeitraum ungültig.'];
    $dueIso = md_parse_date($due);
    if (!$dueIso) return [null, 'Fälligkeitsdatum ungültig (TT.MM.JJJJ).'];
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $plan = array_filter(md_fee_run_plan($periodKey), fn($r) => $r['action'] === 'create');   // aktueller Stand, nicht der der Vorschau
        if (!$plan) throw new RuntimeException('Nichts zu berechnen.');
        $total = array_sum(array_column($plan, 'cents'));
        md_exec('INSERT INTO md_fee_runs (period_key, run_at, run_by, due_date, entries, total_cents, note) VALUES (?,?,?,?,?,?,?)', [$periodKey, md_now(), $actor, $dueIso, count($plan), $total, $note]);
        $runId = (int)$pdo->lastInsertId();
        $year = (int)date('Y');
        foreach ($plan as $r) {
            $inv = md_next_invoice_no($year);
            $desc = 'Mitgliedsbeitrag ' . $period['label'] . ' (' . $r['group'] . ')';
            md_exec('INSERT INTO md_ledger (member_id, kind, direction, amount_cents, entry_date, due_date, status, description, reference, created_at, created_by, source, run_id, fee_period) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$r['member_id'], 'beitrag', 'to_club', $r['cents'], date('Y-m-d'), $dueIso, 'open', $desc, $inv, md_now(), $actor, 'run', $runId, $periodKey]);
            md_log_change($r['member_id'], 'ledger', null, "Beitrag " . md_money($r['cents']) . " – $desc (Rechnung $inv, fällig " . md_date($dueIso) . ')', $actor, 'admin');
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return [null, $e->getMessage()];
    }
    return [$runId, ''];
}

/** Anschrift und Namen für Schreiben (Rechnung, Mahnung). Null ohne vollständige Anschrift. */
function md_letter_recipient(int $member): ?array
{
    return md_donor($member);
}

function md_invoice_html(array $e, array $club, array $conf, array $rcp, ?array $sepa = null): string
{
    $due = $e['due_date'] ? md_date($e['due_date']) : '';
    $o = '<article class="doc">';
    $o .= '<p class="sender">' . h($club['name']) . ' · ' . h($club['street']) . ' · ' . h($club['zip'] . ' ' . $club['city']) . '</p>';
    $o .= '<p class="rcp">' . h($rcp['name']) . '<br>' . implode('<br>', array_map('h', $rcp['lines'])) . '</p>';
    $o .= '<table class="meta"><tr><td>Rechnungsnummer</td><td><strong>' . h($e['reference']) . '</strong></td></tr><tr><td>Rechnungsdatum</td><td>' . h(md_date($e['entry_date'])) . '</td></tr>'
        . '<tr><td>Mitgliedsnummer</td><td>' . h($e['membership_number']) . '</td></tr>' . ($due ? '<tr><td>Zahlbar bis</td><td><strong>' . h($due) . '</strong></td></tr>' : '') . '</table>';
    $o .= '<h2>Rechnung ' . h($e['reference']) . '</h2><p>' . h($conf['invoice_text']) . '</p>';
    $o .= '<table class="list"><thead><tr><th>Leistung</th><th class="r">Betrag</th></tr></thead><tbody><tr><td>' . h($e['description']) . '</td><td class="r">' . h(md_money((int)$e['amount_cents'])) . '</td></tr>'
        . '<tr><td><strong>Gesamtbetrag</strong></td><td class="r"><strong>' . h(md_money((int)$e['amount_cents'])) . '</strong></td></tr></tbody></table>';
    if (trim($conf['tax_note']) !== '') $o .= '<p class="small">' . h($conf['tax_note']) . '</p>';
    if ($sepa) {
        $o .= '<p>Der Betrag wird am <strong>' . h(md_date($sepa['date'])) . '</strong> per SEPA-Basislastschrift von Ihrem Konto <strong>' . h($sepa['iban_mask']) . '</strong> eingezogen (Vorabankündigung). '
            . 'Gläubiger-Identifikationsnummer: ' . h($sepa['creditor_id']) . ', Mandatsreferenz: ' . h($sepa['mandate_ref']) . '. Bitte sorgen Sie für ausreichende Deckung; eine Überweisung ist nicht erforderlich.</p>';
    } else {
        $o .= '<p>Bitte überweisen Sie den Betrag' . ($due ? ' bis zum <strong>' . h($due) . '</strong>' : '') . ' unter Angabe von <strong>' . h($e['reference']) . ' ' . h($e['membership_number']) . '</strong> auf folgendes Konto:</p>';
        $o .= '<table class="meta"><tr><td>Empfänger</td><td>' . h($club['name']) . '</td></tr>' . ($conf['iban'] !== '' ? '<tr><td>IBAN</td><td>' . h($conf['iban']) . '</td></tr>' : '')
            . ($conf['bic'] !== '' ? '<tr><td>BIC</td><td>' . h($conf['bic']) . '</td></tr>' : '') . ($conf['bank'] !== '' ? '<tr><td>Bank</td><td>' . h($conf['bank']) . '</td></tr>' : '') . '</table>';
    }
    $o .= '<p class="sign">' . h($club['place'] ?: $club['city']) . ', ' . h(md_date($e['entry_date'])) . '<br>' . h($club['signer']) . '</p></article>';
    return $o;
}
