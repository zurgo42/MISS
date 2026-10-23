<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('finance', 'view');
$aid = (int)$me['member_id'];
$edit = md_can($me, 'finance', 'edit');
$self = 'admin/datev.php';
$kinds = array_map(fn($k) => $k[0], MD_LEDGER_KINDS);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    if (!$edit) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
    $act = $_POST['action'] ?? '';
    $err = '';
    $ok = 'Gespeichert.';
    if ($act === 'upload') {
        $f = $_FILES['csv'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 20 * 1024 * 1024) { md_flash('Upload fehlgeschlagen (max. 20 MB).', 'err'); header('Location: ' . md_url($self)); exit; }
        $token = md_import_store($f['tmp_name'], $_POST['encoding'] ?? 'Windows-1252');
        $_SESSION['md_datev_name'] = mb_substr(basename((string)$f['name']), 0, 200);
        header('Location: ' . md_url($self . '?token=' . $token));
        exit;
    } elseif ($act === 'account_add') {
        $m = md_one('SELECT member_id FROM svmembers WHERE membership_number = ?', [trim((string)($_POST['number'] ?? ''))]);
        $err = $m ? md_datev_account_save((string)($_POST['account'] ?? ''), (int)$m['member_id'], $_POST['type'] ?? null) : 'Mitgliedsnummer nicht gefunden.';
    } elseif ($act === 'accounts_bulk') {
        [$n, $errs] = md_datev_accounts_bulk((string)($_POST['bulk'] ?? ''));
        $ok = "$n Personenkonto/-konten übernommen.";
        if ($errs) $err = $ok . ' Probleme: ' . implode('; ', array_slice($errs, 0, 8));
    } elseif ($act === 'account_del') {
        md_exec('DELETE FROM md_datev_accounts WHERE account = ?', [(string)($_POST['account'] ?? '')]);
        $ok = 'Personenkonto entfernt.';
    } elseif ($act === 'kind_add') {
        $err = md_datev_kind_rule_save((string)($_POST['from'] ?? ''), (string)($_POST['to'] ?? ''), (string)($_POST['kind'] ?? ''));
    } elseif ($act === 'kind_del') {
        md_exec('DELETE FROM md_datev_kinds WHERE rule_id = ?', [(int)($_POST['rule_id'] ?? 0)]);
        $ok = 'Regel entfernt.';
    }
    if ($act !== 'preview' && $act !== 'commit') {
        md_flash($err ?: $ok, $err ? 'err' : 'ok');
        header('Location: ' . md_url($self . '#' . (str_starts_with($act, 'kind') ? 'kinds' : 'accounts')));
        exit;
    }
}

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$path = $token !== '' ? md_import_path($token) : null;
$parsed = null;
$plan = null;
$perr = '';
$opts = ['claims' => !empty($_POST['o_claims']), 'in_kind' => (string)($_POST['o_in'] ?? 'beitrag'), 'out_kind' => (string)($_POST['o_out'] ?? 'erstattung')];
if ($path) {
    try { $parsed = md_datev_parse($path); } catch (Throwable $e) { $perr = $e->getMessage(); }
}
if ($parsed && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['preview', 'commit'], true) && $edit) {
    $assign = [];
    foreach ((array)($_POST['assign'] ?? []) as $row => $nr) {
        if (trim((string)$nr) === '') continue;
        $m = md_one('SELECT member_id FROM svmembers WHERE membership_number = ?', [trim((string)$nr)]);
        if ($m) $assign[(int)$row] = (int)$m['member_id']; else md_flash('Mitgliedsnummer „' . $nr . '“ (Zeile ' . (int)$row . ') nicht gefunden.', 'err');
    }
    $plan = md_datev_plan($parsed, $opts, $assign);
    $sig = hash('sha256', json_encode([$token, $opts, $assign]));
    if ($_POST['action'] === 'preview') $_SESSION['md_datev_sig'] = $sig;
    elseif (($_SESSION['md_datev_sig'] ?? '') !== $sig) { md_flash('Bitte zuerst die Vorschau mit diesen Einstellungen ansehen.', 'err'); $_SESSION['md_datev_sig'] = $sig; }
    else {
        [$paid, $new, $errs, $iid] = md_datev_apply($parsed, $plan, array_map('intval', (array)($_POST['accept'] ?? [])), array_map('intval', (array)($_POST['remember'] ?? [])), (string)($_SESSION['md_datev_name'] ?? 'DATEV-Export'), $aid);
        if ($errs) md_flash('Abgebrochen, nichts wurde gebucht: ' . implode(' ', $errs), 'err');
        else { unset($_SESSION['md_datev_sig']); @unlink($path); md_flash("Übernommen: $paid Forderung(en) als bezahlt vermerkt, $new neue Buchung(en) angelegt."); header('Location: ' . md_url($self)); exit; }
    }
}

md_header('DATEV-Import', $me, 'finance');
md_show_flash();
echo '<h1>DATEV-Import</h1><p class="muted">Übernimmt Buchungen aus einem DATEV-Export (Buchungsstapel, CSV) in das Kassenbuch der Mitglieder. <a href="admin/finance.php">← Kasse</a></p>';
if (!$path || $perr) {
    if ($perr) echo '<div class="flash err">' . h($perr) . '</div>';
    if ($edit) {
        echo '<section class="card"><h2>1. Datei wählen</h2><p>In DATEV: <em>Buchungsstapel exportieren</em> (Format CSV). Die Mitglieder werden über ihr <strong>Personenkonto</strong> (Debitor/Kreditor) erkannt, siehe unten. Schon übernommene Buchungen werden nie doppelt gebucht.</p>'
            . '<form method="post" enctype="multipart/form-data">' . md_csrf_field() . '<input type="hidden" name="action" value="upload"><div class="addr"><label>DATEV-Datei<input type="file" name="csv" accept=".csv,.txt,text/csv" required></label>'
            . '<label>Zeichensatz<select name="encoding"><option value="Windows-1252">Windows-1252 (DATEV-Standard)</option><option value="UTF-8">UTF-8</option></select></label></div><button>Hochladen</button></form></section>';
    }
} else {
    $meta = $parsed['meta'];
    echo '<section class="card"><h2>2. Einstellungen und Vorschau</h2><p class="muted">' . count($parsed['rows']) . ' Buchungszeilen · Mandant ' . h($meta['client']) . ' · Zeitraum ' . h($meta['from'] ? md_date($meta['from']) : '?') . ' bis ' . h($meta['to'] ? md_date($meta['to']) : '?') . ($meta['label'] ? ' · „' . h($meta['label']) . '“' : '') . '</p>'
        . '<form method="post">' . md_csrf_field() . '<input type="hidden" name="token" value="' . h($token) . '">'
        . '<label class="block"><input type="checkbox" name="o_claims" value="1"' . ($opts['claims'] ? ' checked' : '') . '> Forderungen (Soll auf dem Debitor) als offene Forderungen übernehmen <span class="muted">– nur nötig, wenn Rechnungen in DATEV geschrieben werden</span></label>'
        . '<div class="addr"><label>Art für Zahlungen ohne Kontenregel<select name="o_in">';
    foreach ($kinds as $k => $l) if (MD_LEDGER_KINDS[$k][1] === 'to_club') echo '<option value="' . h($k) . '"' . ($opts['in_kind'] === $k ? ' selected' : '') . '>' . h($l) . '</option>';
    echo '</select></label><label>Art für Zahlungen an Mitglieder<select name="o_out">';
    foreach ($kinds as $k => $l) if (MD_LEDGER_KINDS[$k][1] === 'to_member') echo '<option value="' . h($k) . '"' . ($opts['out_kind'] === $k ? ' selected' : '') . '>' . h($l) . '</option>';
    echo '</select></label></div><button name="action" value="preview"' . ($edit ? '' : ' disabled') . '>Vorschau</button></form></section>';
    if ($plan !== null) {
        $cnt = ['sure' => 0, 'suggest' => 0, 'manual' => 0, 'dup' => 0, 'skip' => 0];
        foreach ($plan as $r) $cnt[$r['level']]++;
        $labels = ['pay' => 'Forderung bezahlt', 'new_paid' => 'neue bezahlte Buchung', 'new_open' => 'neue offene Forderung', 'new_out' => 'Zahlung an Mitglied', 'skip' => 'nicht übernommen'];
        $names = [];
        foreach (md_q('SELECT member_id, first_name, last_name, membership_number FROM svmembers') as $m) $names[(int)$m['member_id']] = $m['first_name'] . ' ' . $m['last_name'] . ' (' . $m['membership_number'] . ')';
        echo '<section class="card"><h2>3. Vorschau</h2><p><span class="chip ok">' . $cnt['sure'] . ' sicher</span> <span class="chip">' . $cnt['suggest'] . ' Vorschlag</span> <span class="chip warn">' . $cnt['manual'] . ' bitte prüfen</span> <span class="chip">' . $cnt['dup'] . ' schon übernommen</span> <span class="chip">' . $cnt['skip'] . ' nicht relevant</span></p>';
        echo '<form method="post" onsubmit="return confirm(\'Die markierten Zeilen jetzt ins Kassenbuch übernehmen?\')">' . md_csrf_field() . '<input type="hidden" name="token" value="' . h($token) . '">'
            . ($opts['claims'] ? '<input type="hidden" name="o_claims" value="1">' : '') . '<input type="hidden" name="o_in" value="' . h($opts['in_kind']) . '"><input type="hidden" name="o_out" value="' . h($opts['out_kind']) . '">';
        echo '<table><thead><tr><th></th><th>Zeile</th><th>Datum</th><th class="r">Betrag</th><th>Konten</th><th>Beleg / Text</th><th>Ergebnis</th></tr></thead><tbody>';
        foreach (array_slice($plan, 0, 800) as $r) {
            $can = $edit && in_array($r['level'], ['sure', 'suggest', 'manual'], true) && $r['action'] !== 'skip' && $r['member_id'];
            $needs = $edit && $r['level'] === 'manual' && !$r['member_id'];
            echo '<tr class="' . ($r['error'] ? 'rowbad' : (in_array($r['level'], ['skip', 'dup'], true) ? 'muted' : '')) . '"><td>' . ($can ? '<input type="checkbox" name="accept[]" value="' . $r['row'] . '"' . ($r['level'] === 'sure' ? ' checked' : '') . '>' : '') . '</td><td>' . $r['row'] . '</td><td>' . h($r['date'] ? md_date($r['date']) : '') . '</td>'
                . '<td class="r">' . ($r['cents'] ? md_money($r['cents']) . ' ' . h($r['sh']) : '') . '</td><td>' . h($r['konto'] . ' / ' . $r['gegen']) . '</td><td>' . h($r['beleg']) . '<br><span class="muted">' . h($r['text']) . '</span></td><td>'
                . '<strong>' . h($labels[$r['action']]) . '</strong>' . ($r['kind'] ? ' (' . h($kinds[$r['kind']] ?? $r['kind']) . ')' : '') . ($r['member_id'] ? '<br>' . h($names[$r['member_id']] ?? '') : '') . '<br><span class="muted">' . h($r['note']) . '</span>';
            if ($needs) echo '<br><input name="assign[' . $r['row'] . ']" placeholder="Mitgliedsnummer" size="14"> <label class="check"><input type="checkbox" name="remember[]" value="' . $r['row'] . '" checked> Konto merken</label>';
            elseif ($can && $r['person_account'] && !md_datev_account_of($r['person_account'])) echo '<br><label class="check"><input type="checkbox" name="remember[]" value="' . $r['row'] . '" checked> Konto ' . h($r['person_account']) . ' merken</label>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        if (count($plan) > 800) echo '<p class="muted">Es werden die ersten 800 von ' . count($plan) . ' Zeilen angezeigt.</p>';
        if ($edit) echo '<p><button name="action" value="preview">Zuordnung übernehmen, Vorschau aktualisieren</button> ' . ($cnt['sure'] + $cnt['suggest'] + $cnt['manual'] ? '<button name="action" value="commit">Markierte übernehmen</button> <span class="muted">„Sicher“ ist vorangekreuzt; Vorschläge und „bitte prüfen“ nur nach Kontrolle ankreuzen.</span>' : '') . '</p>';
        echo '</form></section>';
    }
}

// ---- Personenkonten
$accts = md_q('SELECT a.*, s.first_name, s.last_name, s.membership_number FROM md_datev_accounts a JOIN svmembers s ON s.member_id = a.member_id ORDER BY a.account');
echo '<section class="card" id="accounts"><h2>Personenkonten (' . count($accts) . ')</h2><p class="muted">Welches Konto in der Buchhaltung gehört zu welchem Mitglied? Debitor = Forderungen an das Mitglied (Beiträge, Spenden), Kreditor = Zahlungen an das Mitglied (Erstattungen, Übungsleiterpauschale).</p>';
if ($accts) {
    echo '<table><thead><tr><th>Konto</th><th>Art</th><th>Mitglied</th><th></th></tr></thead><tbody>';
    foreach (array_slice($accts, 0, 300) as $a) echo '<tr><td>' . h($a['account']) . '</td><td>' . ($a['type'] === 'kreditor' ? 'Kreditor' : 'Debitor') . '</td><td>' . h($a['first_name'] . ' ' . $a['last_name']) . ' <span class="muted">' . h($a['membership_number']) . '</span></td><td>'
        . ($edit ? '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="account_del"><input type="hidden" name="account" value="' . h($a['account']) . '"><button class="link">entfernen</button></form>' : '') . '</td></tr>';
    echo '</tbody></table>' . (count($accts) > 300 ? '<p class="muted">Es werden die ersten 300 angezeigt.</p>' : '');
}
if ($edit) {
    echo '<form method="post" class="inline">' . md_csrf_field() . '<input type="hidden" name="action" value="account_add"><input name="account" placeholder="Konto z. B. 10001" size="12" required> <select name="type"><option value="">Art automatisch</option><option value="debitor">Debitor</option><option value="kreditor">Kreditor</option></select> <input name="number" placeholder="Mitgliedsnummer" size="14" required> <button>Hinzufügen</button></form>';
    echo '<details><summary>Viele auf einmal (Liste einfügen)</summary><form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="accounts_bulk"><label class="block">Eine Zeile je Konto: <code>Konto;Mitgliedsnummer</code><textarea name="bulk" rows="6" style="width:100%" placeholder="10001;DEMO0001&#10;10002;DEMO0002"></textarea></label><button>Übernehmen</button></form></details>';
}
echo '</section>';

// ---- Kontenregeln
$rules = md_q('SELECT * FROM md_datev_kinds ORDER BY CAST(account_from AS UNSIGNED)');
echo '<section class="card" id="kinds"><h2>Buchungsart nach Erlös-/Aufwandskonto</h2><p class="muted">Aus dem Gegenkonto (z. B. 8100 Mitgliedsbeiträge, 8300 Spenden) ergibt sich die Art der Buchung im Kassenbuch. Ohne passende Regel gilt die Voreinstellung bei der Vorschau.</p>';
if ($rules) {
    echo '<table><thead><tr><th>Konto von</th><th>bis</th><th>Buchungsart</th><th></th></tr></thead><tbody>';
    foreach ($rules as $r) echo '<tr><td>' . h($r['account_from']) . '</td><td>' . h($r['account_to']) . '</td><td>' . h($kinds[$r['kind']] ?? $r['kind']) . '</td><td>' . ($edit ? '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="kind_del"><input type="hidden" name="rule_id" value="' . (int)$r['rule_id'] . '"><button class="link">entfernen</button></form>' : '') . '</td></tr>';
    echo '</tbody></table>';
}
if ($edit) {
    echo '<form method="post" class="inline">' . md_csrf_field() . '<input type="hidden" name="action" value="kind_add"><input name="from" placeholder="Konto von" size="8" required> <input name="to" placeholder="bis (optional)" size="8"> <select name="kind">';
    foreach ($kinds as $k => $l) echo '<option value="' . h($k) . '">' . h($l) . '</option>';
    echo '</select> <button>Regel hinzufügen</button></form>';
}
echo '</section>';

// ---- Protokoll
$imps = md_q('SELECT i.*, s.first_name, s.last_name FROM md_datev_imports i LEFT JOIN svmembers s ON s.member_id = i.created_by ORDER BY i.import_id DESC LIMIT 15');
echo '<section class="card"><h2>Bisherige Importe</h2><table><thead><tr><th>Wann</th><th>Datei</th><th>Zeitraum</th><th class="r">Zeilen</th><th class="r">bezahlt</th><th class="r">neu</th><th>Von</th></tr></thead><tbody>';
foreach ($imps as $i) echo '<tr><td>' . h(date('d.m.Y H:i', strtotime($i['created_at']))) . '</td><td>' . h($i['file_name']) . '</td><td>' . h($i['period_from'] ? md_date($i['period_from']) . ' – ' . md_date($i['period_to']) : '') . '</td><td class="r">' . (int)$i['rows_total'] . '</td><td class="r">' . (int)$i['paid_count'] . '</td><td class="r">' . (int)$i['created_count'] . '</td><td>' . h(trim($i['first_name'] . ' ' . $i['last_name'])) . '</td></tr>';
if (!$imps) echo '<tr><td colspan="7" class="muted">Noch kein Import.</td></tr>';
echo '</tbody></table></section>';
md_footer();
