<?php
declare(strict_types=1);

function md_setting(string $key, string $default = ''): string
{
    $r = md_one('SELECT setting_value FROM md_settings WHERE setting_key = ?', [$key]);
    return $r ? $r['setting_value'] : $default;
}

/** Kennzahlen fürs Dashboard (Jahr $year) plus Buchungsliste. */
function md_finance_summary(int $member, int $year): array
{
    $rows = md_q("SELECT * FROM md_ledger WHERE member_id = ? AND status <> 'cancelled' ORDER BY entry_date DESC, entry_id DESC", [$member]);
    $s = ['open_claims' => 0, 'overdue' => 0, 'fees_paid' => 0, 'tickets_paid' => 0, 'donations' => 0,
          'income_paid' => 0, 'income_open' => 0, 'ueb_year' => 0, 'rows' => $rows];
    $today = date('Y-m-d');
    foreach ($rows as $r) {
        $c = (int)$r['amount_cents'];
        $inYear = substr((string)($r['paid_date'] ?: $r['entry_date']), 0, 4) === (string)$year;
        if ($r['direction'] === 'to_club') {
            if ($r['status'] === 'open') {
                $s['open_claims'] += $c;
                if ($r['due_date'] && $r['due_date'] < $today) $s['overdue'] += $c;
            } elseif ($inYear) {
                if ($r['kind'] === 'beitrag') $s['fees_paid'] += $c;
                elseif ($r['kind'] === 'eintritt') $s['tickets_paid'] += $c;
                elseif ($r['kind'] === 'spende') $s['donations'] += $c;
            }
        } else {
            if ($r['status'] === 'open') $s['income_open'] += $c;
            elseif ($inYear) {
                $s['income_paid'] += $c;
                if ($r['kind'] === 'uebungsleiter') $s['ueb_year'] += $c;
            }
        }
    }
    // Freibetrag bitte jährlich prüfen (Einstellung ueb_freibetrag_cents).
    $s['ueb_limit'] = (int)md_setting('ueb_freibetrag_cents', '330000');
    return $s;
}

function md_ledger_label(array $r): string
{
    return MD_LEDGER_KINDS[$r['kind']][0] ?? $r['kind'];
}
