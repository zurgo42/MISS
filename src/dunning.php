<?php
declare(strict_types=1);

/**
 * Mahnwesen: einstellbare Stufen, Mahnlauf (Vorschau, Ausstellen), Mahnschreiben mit Momentaufnahme, Stundung, Storno.
 *
 *  - Mahnfähig sind offene Forderungen an Mitglieder der Art Beitrag und Eintritt mit Fälligkeit. Mahngebühren werden als eigene Forderung gebucht
 *    und in späteren Schreiben mit aufgeführt, lösen aber selbst keine Mahnung aus.
 *  - Stufe 1 ab "days" Tagen nach der Fälligkeit; Stufe 2 und 3 ab "days" Tagen nach der vorherigen Mahnung.
 *  - Ein Schreiben je Mitglied fasst alle gerade fälligen Forderungen zusammen; Gebühr und Text richten sich nach der höchsten Stufe darin.
 *  - Gestundete Forderungen werden bis zum Stundungsdatum nicht gemahnt. Ab der letzten Stufe gibt es keine automatische Eskalation.
 */

function md_dunning_levels(): array
{
    $out = [];
    foreach (md_q('SELECT * FROM md_dunning_levels ORDER BY level') as $r) $out[(int)$r['level']] = $r;
    return $out;
}

function md_dunning_default_levels(): array
{
    return [
        [1, 'Zahlungserinnerung', 14, 14, 0, "{anrede},\n\nbei der Durchsicht unserer Buchhaltung ist uns aufgefallen, dass die unten aufgeführten Forderungen noch nicht beglichen wurden. Vielleicht haben Sie die Zahlung übersehen. Wir bitten Sie, den Betrag von {total} bis zum {deadline} zu überweisen.\n\nSollten Sie bereits gezahlt haben, betrachten Sie dieses Schreiben bitte als gegenstandslos."],
        [2, '1. Mahnung', 21, 14, 500, "{anrede},\n\nleider konnten wir trotz unserer Zahlungserinnerung noch keinen Zahlungseingang feststellen. Wir bitten Sie, den offenen Betrag von {total} (darin enthalten Mahngebühr {fee}) bis zum {deadline} zu überweisen.\n\nSollten Sie bereits gezahlt haben, betrachten Sie dieses Schreiben bitte als gegenstandslos."],
        [3, '2. Mahnung', 21, 10, 1000, "{anrede},\n\nauf unsere bisherigen Schreiben haben wir keine Zahlung erhalten. Wir fordern Sie letztmalig auf, den offenen Betrag von {total} (darin enthalten Mahngebühr {fee}) bis zum {deadline} zu überweisen.\n\nBei weiterem Zahlungsverzug müssen wir die in unserer Satzung vorgesehenen Maßnahmen prüfen."],
    ];
}

/** Trägt die Standardstufen ein, wenn noch keine existieren. */
function md_dunning_seed(): int
{
    if ((int)md_one('SELECT COUNT(*) c FROM md_dunning_levels')['c'] > 0) return 0;
    foreach (md_dunning_default_levels() as [$lv, $name, $days, $dl, $fee, $body]) md_exec('INSERT INTO md_dunning_levels (level, name, days, deadline_days, fee_cents, body) VALUES (?,?,?,?,?,?)', [$lv, $name, $days, $dl, $fee, $body]);
    return count(md_dunning_default_levels());
}

/** Ist die Forderung am Stichtag für die nächste Mahnstufe fällig? Gibt die Stufe oder 0 zurück. */
function md_dunning_next_level(array $e, array $levels, string $today): int
{
    $max = max(array_keys($levels) ?: [0]);
    $next = (int)$e['dunning_level'] + 1;
    if ($next > $max || !isset($levels[$next])) return 0;
    if (!empty($e['deferred_until']) && $e['deferred_until'] >= $today) return 0;
    if (empty($e['due_date'])) return 0;
    $ref = $next === 1 ? $e['due_date'] : ($e['last_dunned_at'] ?: $e['due_date']);
    return date('Y-m-d', strtotime('+' . (int)$levels[$next]['days'] . ' days', strtotime($ref))) <= $today ? $next : 0;
}

/** Fällige Mahnungen, gruppiert je Mitglied: member_id => [member, items, level, fee_cents, recipient]. */
function md_dunning_candidates(?string $today = null): array
{
    $today = $today ?? date('Y-m-d');
    $levels = md_dunning_levels();
    if (!$levels) return [];
    $rows = md_q("SELECT l.*, s.first_name, s.last_name, s.membership_number FROM md_ledger l JOIN svmembers s ON s.member_id = l.member_id
                  WHERE l.status = 'open' AND l.direction = 'to_club' AND l.kind IN ('beitrag', 'eintritt') AND l.due_date IS NOT NULL AND l.sepa_item_id IS NULL AND s.membership_number NOT LIKE 'DEL-%'
                  ORDER BY s.last_name, s.first_name, l.due_date");
    $out = [];
    foreach ($rows as $e) {
        $lv = md_dunning_next_level($e, $levels, $today);
        if (!$lv) continue;
        $m = (int)$e['member_id'];
        $e['next_level'] = $lv;
        $out[$m]['member'] = ['member_id' => $m, 'name' => $e['first_name'] . ' ' . $e['last_name'], 'number' => $e['membership_number']];
        $out[$m]['items'][] = $e;
    }
    foreach ($out as $m => &$g) {
        $g['level'] = max(array_column($g['items'], 'next_level'));
        $g['fee_cents'] = (int)$levels[$g['level']]['fee_cents'];
        $g['recipient'] = md_letter_recipient($m);
    }
    unset($g);
    return $out;
}

function md_dunning_salutation(int $member): string
{
    $m = md_one('SELECT first_name, last_name FROM svmembers WHERE member_id = ?', [$member]);
    $p = md_load_profile($member);
    $n = $m ? $m['last_name'] : '';
    return match ($p['salutation'] ?? '') {
        'Frau' => "Sehr geehrte Frau $n",
        'Herr' => "Sehr geehrter Herr $n",
        default => 'Guten Tag ' . ($m ? $m['first_name'] . ' ' . $m['last_name'] : ''),
    };
}

function md_dunning_render_body(string $tpl, array $vars): string
{
    return strtr($tpl, $vars);
}

function md_dunning_letter_no(int $year): string
{
    $last = md_one('SELECT letter_no FROM md_dunning_letters WHERE letter_no LIKE ? ORDER BY letter_no DESC LIMIT 1', ["M$year-%"]);
    $n = $last ? (int)substr($last['letter_no'], strlen("M$year-")) + 1 : 1;
    return sprintf('M%d-%04d', $year, $n);
}

/**
 * Stellt ein Mahnschreiben für ein Mitglied aus (alle angegebenen, jetzt fälligen Forderungen). Gibt [letter_id|null, Fehlertext] zurück.
 */
function md_dunning_issue(int $member, array $entryIds, int $actor, ?string $today = null): array
{
    $today = $today ?? date('Y-m-d');
    $levels = md_dunning_levels();
    $club = md_club();
    if (!$levels) return [null, 'Keine Mahnstufen eingerichtet.'];
    if ($club['name'] === '' || $club['street'] === '' || $club['city'] === '') return [null, 'Vereinsname und Anschrift fehlen (Einstellungen → Verein).'];
    $rcp = md_letter_recipient($member);
    if (!$rcp) return [null, 'Für dieses Mitglied fehlt eine vollständige Anschrift (Straße, PLZ, Ort).'];
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $items = [];
        foreach ($entryIds as $id) {
            $e = md_one("SELECT * FROM md_ledger WHERE entry_id = ? AND member_id = ?", [(int)$id, $member]);
            if (!$e || $e['status'] !== 'open') throw new RuntimeException('Eine Forderung ist nicht mehr offen. Bitte neu laden.');
            $lv = md_dunning_next_level($e, $levels, $today);
            if (!$lv) throw new RuntimeException('Eine Forderung ist nicht (mehr) mahnfähig. Bitte neu laden.');
            $e['next_level'] = $lv;
            $items[] = $e;
        }
        if (!$items) throw new RuntimeException('Keine Forderung ausgewählt.');
        $L = max(array_column($items, 'next_level'));
        $lv = $levels[$L];
        $fee = (int)$lv['fee_cents'];
        $deadline = date('Y-m-d', strtotime('+' . (int)$lv['deadline_days'] . ' days', strtotime($today)));
        $no = md_dunning_letter_no((int)date('Y', strtotime($today)));
        // bereits früher berechnete, noch offene Mahngebühren stehen mit im Schreiben
        $prevFees = md_q("SELECT * FROM md_ledger WHERE member_id = ? AND kind = 'gebuehr' AND status = 'open'", [$member]);
        $sum = array_sum(array_map(fn($x) => (int)$x['amount_cents'], $items)) + array_sum(array_map(fn($x) => (int)$x['amount_cents'], $prevFees)) + $fee;
        $feeEntry = null;
        if ($fee > 0) {
            md_exec("INSERT INTO md_ledger (member_id, kind, direction, amount_cents, entry_date, due_date, status, description, reference, created_at, created_by, source) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                [$member, 'gebuehr', 'to_club', $fee, $today, $deadline, 'open', 'Mahngebühr (' . $lv['name'] . ')', $no, md_now(), $actor, 'dunning']);
            $feeEntry = (int)md_db()->lastInsertId();
        }
        $snapItems = [];
        foreach ($items as $e) {
            $snapItems[] = ['entry_id' => (int)$e['entry_id'], 'reference' => $e['reference'], 'description' => $e['description'], 'due_date' => $e['due_date'], 'cents' => (int)$e['amount_cents'],
                            'prev_level' => (int)$e['dunning_level'], 'prev_dunned' => $e['last_dunned_at'], 'new_level' => (int)$e['next_level']];
            md_exec('UPDATE md_ledger SET dunning_level = ?, last_dunned_at = ? WHERE entry_id = ?', [$e['next_level'], $today, $e['entry_id']]);
        }
        foreach ($prevFees as $f) $snapItems[] = ['entry_id' => (int)$f['entry_id'], 'reference' => $f['reference'], 'description' => $f['description'], 'due_date' => $f['due_date'], 'cents' => (int)$f['amount_cents'], 'prev_level' => null, 'prev_dunned' => null, 'new_level' => null];
        $body = md_dunning_render_body($lv['body'], ['{anrede}' => md_dunning_salutation($member), '{name}' => $rcp['name'], '{total}' => md_money($sum), '{fee}' => md_money($fee), '{deadline}' => md_date($deadline)]);
        $snapshot = json_encode(['club' => $club, 'conf' => md_fee_conf(), 'recipient' => $rcp, 'level_name' => $lv['name'], 'body' => $body, 'items' => $snapItems, 'fee_cents' => $fee, 'fee_entry_id' => $feeEntry,
            'total_cents' => $sum, 'deadline' => $deadline, 'membership_number' => md_one('SELECT membership_number FROM svmembers WHERE member_id = ?', [$member])['membership_number']], JSON_UNESCAPED_UNICODE);
        md_exec('INSERT INTO md_dunning_letters (letter_no, member_id, level, issued_at, issued_by, total_cents, fee_cents, deadline, status, snapshot) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$no, $member, $L, md_now(), $actor, $sum, $fee, $deadline, 'issued', $snapshot]);
        $lid = (int)$pdo->lastInsertId();
        md_log_change($member, 'dunning', null, $lv['name'] . " $no: " . md_money($sum) . ' offen, Zahlungsfrist ' . md_date($deadline) . ($fee ? ', Mahngebühr ' . md_money($fee) : ''), $actor, 'admin');
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return [null, $e->getMessage()];
    }
    return [$lid, ''];
}

/** Storniert ein Mahnschreiben: Gebühr wird storniert (wenn nicht bezahlt), Mahnstufen der Forderungen werden zurückgesetzt. */
function md_dunning_void(int $letterId, string $reason, int $actor): string
{
    $l = md_one('SELECT * FROM md_dunning_letters WHERE letter_id = ?', [$letterId]);
    if (!$l || $l['status'] !== 'issued') return 'Mahnschreiben nicht gefunden oder schon storniert.';
    $reason = trim($reason);
    if ($reason === '') return 'Bitte einen Grund angeben.';
    $s = json_decode($l['snapshot'], true);
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if (!empty($s['fee_entry_id'])) {
            $fe = md_one('SELECT status FROM md_ledger WHERE entry_id = ?', [(int)$s['fee_entry_id']]);
            if ($fe && $fe['status'] === 'paid') throw new RuntimeException('Die Mahngebühr ist schon bezahlt; bitte zuerst erstatten/umbuchen.');
            if ($fe && $fe['status'] === 'open') { $err = md_ledger_cancel((int)$s['fee_entry_id'], 'Mahnung ' . $l['letter_no'] . ' storniert', $actor); if ($err) throw new RuntimeException($err); }
        }
        foreach ($s['items'] as $it) {
            if ($it['new_level'] === null) continue;
            md_exec('UPDATE md_ledger SET dunning_level = ?, last_dunned_at = ? WHERE entry_id = ? AND dunning_level = ?', [$it['prev_level'], $it['prev_dunned'], $it['entry_id'], $it['new_level']]);
        }
        md_exec("UPDATE md_dunning_letters SET status = 'void', void_reason = ? WHERE letter_id = ?", [mb_substr($reason, 0, 255), $letterId]);
        md_log_change((int)$l['member_id'], 'dunning', $s['level_name'] . ' ' . $l['letter_no'], 'storniert: ' . $reason, $actor, 'admin');
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return $e->getMessage();
    }
    return '';
}

/** Stundung: bis zum angegebenen Tag (einschließlich) wird nicht gemahnt. */
function md_dunning_defer(int $entryId, string $until, string $reason, int $actor): string
{
    $e = md_one("SELECT * FROM md_ledger WHERE entry_id = ? AND status = 'open'", [$entryId]);
    if (!$e) return 'Forderung nicht gefunden oder nicht offen.';
    $d = md_parse_date($until);
    if (!$d || $d < date('Y-m-d')) return 'Stundungsdatum ungültig (TT.MM.JJJJ, nicht in der Vergangenheit).';
    if (trim($reason) === '') return 'Bitte einen Grund für die Stundung angeben.';
    md_exec('UPDATE md_ledger SET deferred_until = ?, deferred_reason = ? WHERE entry_id = ?', [$d, mb_substr(trim($reason), 0, 255), $entryId]);
    md_log_change((int)$e['member_id'], 'dunning', md_ledger_describe($e), 'gestundet bis ' . md_date($d) . ': ' . trim($reason), $actor, 'admin');
    return '';
}

function md_dunning_letter_html(array $l): string
{
    $s = json_decode($l['snapshot'], true);
    $club = $s['club'];
    $conf = $s['conf'];
    $o = '<article class="doc">';
    if ($l['status'] === 'void') $o .= '<div class="mark">STORNIERT</div>';
    $o .= '<p class="sender">' . h($club['name']) . ' · ' . h($club['street']) . ' · ' . h($club['zip'] . ' ' . $club['city']) . '</p>';
    $o .= '<p class="rcp">' . h($s['recipient']['name']) . '<br>' . implode('<br>', array_map('h', $s['recipient']['lines'])) . '</p>';
    $o .= '<table class="meta"><tr><td>Schreiben</td><td><strong>' . h($l['letter_no']) . '</strong></td></tr><tr><td>Datum</td><td>' . h(md_date(substr($l['issued_at'], 0, 10))) . '</td></tr>'
        . '<tr><td>Mitgliedsnummer</td><td>' . h($s['membership_number']) . '</td></tr><tr><td>Zahlungsfrist</td><td><strong>' . h(md_date($s['deadline'])) . '</strong></td></tr></table>';
    $o .= '<h2>' . h($s['level_name']) . '</h2><p>' . nl2br(h($s['body'])) . '</p>';
    $o .= '<table class="list"><thead><tr><th>Nr.</th><th>Leistung</th><th>Fällig</th><th class="r">Betrag</th></tr></thead><tbody>';
    foreach ($s['items'] as $it) $o .= '<tr><td>' . h($it['reference']) . '</td><td>' . h($it['description']) . '</td><td>' . h(md_date($it['due_date'])) . '</td><td class="r">' . h(md_money($it['cents'])) . '</td></tr>';
    if ($s['fee_cents'] > 0) $o .= '<tr><td>' . h($l['letter_no']) . '</td><td>Mahngebühr (' . h($s['level_name']) . ')</td><td>' . h(md_date($s['deadline'])) . '</td><td class="r">' . h(md_money($s['fee_cents'])) . '</td></tr>';
    $o .= '<tr><td colspan="3"><strong>Offener Gesamtbetrag</strong></td><td class="r"><strong>' . h(md_money($s['total_cents'])) . '</strong></td></tr></tbody></table>';
    $o .= '<p>Bitte überweisen Sie den Betrag bis zum <strong>' . h(md_date($s['deadline'])) . '</strong> unter Angabe von <strong>' . h($l['letter_no']) . ' ' . h($s['membership_number']) . '</strong> auf folgendes Konto:</p>';
    $o .= '<table class="meta"><tr><td>Empfänger</td><td>' . h($club['name']) . '</td></tr>' . ($conf['iban'] !== '' ? '<tr><td>IBAN</td><td>' . h($conf['iban']) . '</td></tr>' : '')
        . ($conf['bic'] !== '' ? '<tr><td>BIC</td><td>' . h($conf['bic']) . '</td></tr>' : '') . ($conf['bank'] !== '' ? '<tr><td>Bank</td><td>' . h($conf['bank']) . '</td></tr>' : '') . '</table>';
    $o .= '<p class="sign">Mit freundlichen Grüßen<br><br>' . h($club['signer']) . '</p></article>';
    return $o;
}
