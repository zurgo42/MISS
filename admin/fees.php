<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('fees', 'view');
$aid = (int)$me['member_id'];
$edit = md_can($me, 'fees', 'edit');
$conf = md_fee_conf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    if (!$edit) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
    $act = $_POST['action'] ?? '';
    $err = '';
    $ok = 'Gespeichert.';
    $back = 'admin/fees.php';
    if ($act === 'group_save') {
        $err = md_fee_group_save((int)($_POST['group_id'] ?? 0) ?: null, $_POST);
        $ok = 'Beitragsgruppe gespeichert. Änderungen wirken auf künftige Beitragsläufe.';
    } elseif ($act === 'assign') {
        $m = (int)($_POST['member_id'] ?? 0);
        $err = md_fee_assign($m, (int)($_POST['group_id'] ?? 0), $_POST, $aid);
        $ok = 'Beitragsgruppe zugeordnet.';
    } elseif ($act === 'conf') {
        foreach (array_keys(MD_FEE_FIELDS) as $k) {
            $v = trim((string)($_POST['fees'][$k] ?? ''));
            if ($k === 'due_days') { if (!ctype_digit($v) || (int)$v > 120) { $err = 'Zahlungsziel: ganze Zahl 0–120.'; break; } }
            $v = mb_substr($v, 0, 255);
            $old = md_setting('fees.' . $k, '');
            if ($old === $v) continue;
            if (md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', ['fees.' . $k])) md_exec('UPDATE md_settings SET setting_value = ? WHERE setting_key = ?', [$v, 'fees.' . $k]);
            else md_exec('INSERT INTO md_settings (setting_key, setting_value, description) VALUES (?,?,?)', ['fees.' . $k, $v, 'Rechnungen und Mahnungen: ' . MD_FEE_FIELDS[$k][0]]);
            md_log_change($aid, 'settings', "fees.$k: $old", $v, $aid, 'self');
        }
        $ok = 'Angaben für Rechnungen gespeichert.';
    } elseif ($act === 'run') {
        $key = (string)($_POST['period'] ?? '');
        if (($_SESSION['md_fee_preview'] ?? '') !== $key) { $err = 'Bitte zuerst die Vorschau für genau diesen Zeitraum ansehen.'; }
        else {
            [$rid, $err] = md_fee_run_apply($key, (string)($_POST['due'] ?? ''), $aid, trim((string)($_POST['note'] ?? '')) ?: null);
            if ($rid) { unset($_SESSION['md_fee_preview']); md_flash('Beitragslauf durchgeführt. Die Rechnungen sind angelegt; dies ist die Druckansicht für alle.'); header('Location: ' . md_url('admin/invoice_print.php?run=' . $rid)); exit; }
        }
        $back = 'admin/fees.php?period=' . urlencode($key);
    }
    md_flash($err ?: $ok, $err ? 'err' : 'ok');
    header('Location: ' . md_url($back));
    exit;
}

$year = (int)($_GET['year'] ?? date('Y'));
$period = (string)($_GET['period'] ?? (string)$year);
$pinfo = md_fee_period($period);
if ($pinfo) $year = (int)substr($period, 0, 4);
$groups = md_fee_groups();
md_header('Beiträge', $me, 'fees');
md_show_flash();
echo '<h1>Beiträge</h1><p class="muted">Beitragsgruppen, Beitragslauf und Rechnungen. <a href="admin/dunning.php">Mahnwesen</a> · <a href="admin/payments.php">Zahlungen zuordnen</a> · <a href="admin/finance.php">Buchungen</a></p>';

if ($conf['iban'] === '') echo '<div class="flash warn">Es ist keine IBAN hinterlegt: Rechnungen und Mahnungen enthalten dann keine Zahlungsangaben. Bitte unten unter „Angaben für Rechnungen und Mahnungen“ ergänzen.</div>';

// ---- Beitragsgruppen
echo '<section class="card" id="groups"><h2>Beitragsgruppen</h2><table><thead><tr><th>Name</th><th class="r">Betrag</th><th>Rhythmus</th><th>Eintritt/Austritt</th><th>Mitglieder</th><th>Aktiv</th><th></th></tr></thead><tbody>';
foreach ($groups as $g) {
    $n = (int)md_one('SELECT COUNT(DISTINCT member_id) c FROM md_member_fees WHERE group_id = ? AND valid_to IS NULL', [$g['group_id']])['c'];
    echo '<tr' . ($g['active'] ? '' : ' class="muted"') . '><td>' . h($g['name']) . ($g['description'] ? '<br><span class="muted">' . h($g['description']) . '</span>' : '') . '</td><td class="r">' . md_money((int)$g['amount_cents']) . '</td><td>' . h(MD_RHYTHMS[$g['rhythm']]) . '</td><td>' . h($g['entry_rule'] === 'prorated' ? 'anteilig' : 'voll') . '</td><td class="r">' . $n . '</td><td>' . ($g['active'] ? 'ja' : 'nein') . '</td><td>';
    if ($edit) {
        echo '<details><summary>ändern</summary><form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="group_save"><input type="hidden" name="group_id" value="' . (int)$g['group_id'] . '"><div class="addr">'
            . '<label>Name<input name="name" value="' . h($g['name']) . '"></label><label>Betrag in €<input name="amount" value="' . h(number_format($g['amount_cents'] / 100, 2, ',', '')) . '"></label>'
            . '<label>Rhythmus<select name="rhythm">';
        foreach (MD_RHYTHMS as $k => $l) echo '<option value="' . $k . '"' . ($g['rhythm'] === $k ? ' selected' : '') . '>' . h($l) . '</option>';
        echo '</select></label><label>Eintritt/Austritt<select name="entry_rule">';
        foreach (MD_ENTRY_RULES as $k => $l) echo '<option value="' . $k . '"' . ($g['entry_rule'] === $k ? ' selected' : '') . '>' . h($l) . '</option>';
        echo '</select></label><label>Beschreibung<input name="description" value="' . h($g['description']) . '"></label></div><label class="check"><input type="checkbox" name="active" value="1"' . ($g['active'] ? ' checked' : '') . '> aktiv (für neue Zuordnungen wählbar)</label> <button>Speichern</button></form></details>';
    }
    echo '</td></tr>';
}
if (!$groups) echo '<tr><td colspan="7" class="muted">Noch keine Beitragsgruppen. Lege unten die erste an.</td></tr>';
echo '</tbody></table>';
if ($edit) {
    echo '<details><summary><strong>Neue Beitragsgruppe</strong></summary><form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="group_save"><div class="addr"><label>Name<input name="name" placeholder="z. B. Vollmitglied"></label><label>Betrag in € je Zeitraum<input name="amount" placeholder="120,00"></label><label>Rhythmus<select name="rhythm">';
    foreach (MD_RHYTHMS as $k => $l) echo '<option value="' . $k . '">' . h($l) . '</option>';
    echo '</select></label><label>Eintritt/Austritt<select name="entry_rule">';
    foreach (MD_ENTRY_RULES as $k => $l) echo '<option value="' . $k . '">' . h($l) . '</option>';
    echo '</select></label><label>Beschreibung<input name="description"></label></div><input type="hidden" name="active" value="1"><button>Anlegen</button></form></details>';
}
echo '</section>';

// ---- Mitglieder ohne Beitragsgruppe
$open = md_q("SELECT s.member_id, s.first_name, s.last_name, s.membership_number, p.join_date FROM svmembers s LEFT JOIN md_profiles p ON p.member_id = s.member_id
              WHERE s.membership_number NOT LIKE 'DEL-%' AND COALESCE(p.status, 'aktiv') NOT IN ('ausgetreten', 'interessent')
              AND NOT EXISTS (SELECT 1 FROM md_member_fees f WHERE f.member_id = s.member_id AND (f.valid_to IS NULL OR f.valid_to >= ?)) ORDER BY s.last_name, s.first_name", [date('Y-m-d')]);
if ($open) {
    echo '<section class="card" id="open"><h2>Mitglieder ohne Beitragsgruppe (' . count($open) . ')</h2><p class="muted">Sie werden im Beitragslauf nicht berücksichtigt, bis eine Gruppe zugeordnet ist. Abweichungen und Ermäßigungen pflegst du im Dashboard des Mitglieds.</p><table><tbody>';
    foreach ($open as $m) {
        echo '<tr><td><a href="index.php?member=' . (int)$m['member_id'] . '#fee">' . h($m['first_name'] . ' ' . $m['last_name']) . '</a> <span class="muted">' . h($m['membership_number']) . '</span></td><td class="act">';
        if ($edit && $groups) {
            echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="assign"><input type="hidden" name="member_id" value="' . (int)$m['member_id'] . '"><input type="hidden" name="valid_from" value="' . h(md_date($m['join_date'] ?: date('Y-m-d'))) . '"><select name="group_id">';
            foreach (md_fee_groups(true) as $g) echo '<option value="' . (int)$g['group_id'] . '">' . h($g['name'] . ' (' . md_money((int)$g['amount_cents']) . ' ' . MD_RHYTHMS[$g['rhythm']] . ')') . '</option>';
            echo '</select> <button>zuordnen</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></section>';
}

// ---- Beitragslauf
echo '<section class="card" id="run"><h2>Beitragslauf</h2><form class="search" method="get"><select name="period">';
foreach (array_merge(md_fee_period_keys($year - 1), md_fee_period_keys($year), md_fee_period_keys($year + 1)) as $k) {
    $pi = md_fee_period($k);
    echo '<option value="' . $k . '"' . ($period === $k ? ' selected' : '') . '>' . h($pi['label'] . ' (' . MD_RHYTHMS[$pi['rhythm']] . ')') . '</option>';
}
echo '</select><button>Vorschau</button></form>';
if ($pinfo) {
    $_SESSION['md_fee_preview'] = $period;
    $plan = md_fee_run_plan($period);
    $create = array_filter($plan, fn($r) => $r['action'] === 'create');
    $total = array_sum(array_column($create, 'cents'));
    $warn = array_filter($plan, fn($r) => $r['action'] === 'warn');
    echo '<p><span class="chip ok">' . count($create) . ' Rechnung(en), Summe ' . md_money($total) . '</span> <span class="chip' . ($warn ? ' warn' : '') . '">' . count($warn) . ' ohne Beitragsgruppe</span> <span class="chip">' . (count($plan) - count($create) - count($warn)) . ' übersprungen</span> – ' . h($pinfo['label']) . ' (' . h(md_date($pinfo['start'])) . ' bis ' . h(md_date($pinfo['end'])) . ')</p>';
    echo '<table><thead><tr><th>Mitglied</th><th>Gruppe</th><th class="r">Betrag</th><th>Zahlweise</th><th>Hinweis</th></tr></thead><tbody>';
    foreach ($plan as $r) {
        if ($r['action'] === 'skip' && $r['note'] === 'ausgetreten') continue;
        $cls = $r['action'] === 'create' ? '' : ($r['action'] === 'warn' ? 'rowbad' : 'muted');
        echo '<tr class="' . $cls . '"><td>' . h($r['name']) . ' <span class="muted">' . h($r['number']) . '</span></td><td>' . h($r['group']) . '</td><td class="r">' . ($r['action'] === 'create' ? md_money($r['cents']) : '') . '</td><td>' . ($r['action'] === 'create' ? (!empty($r['debit']) ? 'Lastschrift' : 'Überweisung') : '') . '</td><td>' . h($r['note']) . '</td></tr>';
    }
    echo '</tbody></table>';
    if ($edit && $create) {
        echo '<form method="post" onsubmit="return confirm(\'' . count($create) . ' Rechnungen jetzt erzeugen? Das lässt sich nur durch Storno der einzelnen Forderungen rückgängig machen.\')">' . md_csrf_field() . '<input type="hidden" name="action" value="run"><input type="hidden" name="period" value="' . h($period) . '">';
        echo '<div class="addr"><label>Zahlbar bis<input name="due" value="' . h(date('d.m.Y', strtotime('+' . $conf['due_days'] . ' days'))) . '"></label><label>Vermerk (optional)<input name="note"></label></div><button>' . count($create) . ' Rechnungen erzeugen</button> <span class="muted">Danach öffnet sich die Druckansicht aller Rechnungen.</span></form>';
    } elseif ($create && !$edit) echo '<p class="muted">Zum Durchführen fehlt das Recht „bearbeiten“.</p>';
}
echo '</section>';

// ---- bisherige Läufe
$runs = md_q('SELECT r.*, s.first_name, s.last_name FROM md_fee_runs r LEFT JOIN svmembers s ON s.member_id = r.run_by ORDER BY r.run_id DESC LIMIT 30');
echo '<section class="card" id="runs"><h2>Bisherige Beitragsläufe</h2><table><thead><tr><th>Zeitraum</th><th>Durchgeführt</th><th class="r">Rechnungen</th><th class="r">Summe</th><th>Zahlbar bis</th><th></th></tr></thead><tbody>';
foreach ($runs as $r) {
    $pi = md_fee_period($r['period_key']);
    echo '<tr><td>' . h($pi ? $pi['label'] : $r['period_key']) . '</td><td>' . h(date('d.m.Y H:i', strtotime($r['run_at']))) . '<br><span class="muted">' . h(trim($r['first_name'] . ' ' . $r['last_name'])) . ($r['note'] ? ' – ' . h($r['note']) : '') . '</span></td><td class="r">' . (int)$r['entries'] . '</td><td class="r">' . md_money((int)$r['total_cents']) . '</td><td>' . h(md_date($r['due_date'])) . '</td><td><a href="admin/invoice_print.php?run=' . (int)$r['run_id'] . '" target="_blank">Rechnungen drucken</a></td></tr>';
}
if (!$runs) echo '<tr><td colspan="6" class="muted">Noch kein Beitragslauf.</td></tr>';
echo '</tbody></table></section>';

// ---- Angaben für Rechnungen
if ($edit) {
    echo '<section class="card" id="conf"><h2>Angaben für Rechnungen und Mahnungen</h2><form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="conf"><div class="addr">';
    foreach (MD_FEE_FIELDS as $k => [$label, $def, $hint]) echo '<label>' . h($label) . '<input name="fees[' . $k . ']" value="' . h($conf[$k]) . '" placeholder="' . h($hint) . '"></label>';
    echo '</div><p class="muted">Name, Anschrift und Unterzeichner des Vereins stammen aus Einstellungen → Verein.</p><button>Speichern</button></form></section>';
}
md_footer();
