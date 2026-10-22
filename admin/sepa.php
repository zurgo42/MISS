<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('sepa', 'view');
$aid = (int)$me['member_id'];
$edit = md_can($me, 'sepa', 'edit');
$conf = md_sepa_conf();
$problems = md_sepa_conf_problems($conf);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    if (!$edit) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
    $act = $_POST['action'] ?? '';
    $err = '';
    $ok = 'Gespeichert.';
    if ($act === 'conf') {
        foreach (array_keys(MD_SEPA_FIELDS) as $k) {
            $v = trim((string)($_POST['sepa'][$k] ?? ''));
            if ($k === 'creditor_id') $v = md_iban_normalize($v);
            if ($k === 'bic') $v = strtoupper($v);
            if ($k === 'creditor_id' && $v !== '' && !md_sepa_creditor_id_valid($v)) { $err = 'Gläubiger-ID ungültig (Prüfziffern stimmen nicht).'; break; }
            if ($k === 'bic' && $v !== '' && !md_bic_valid($v)) { $err = 'BIC ungültig.'; break; }
            if ($k === 'format' && !in_array($v, ['pain.008.001.08', 'pain.008.001.02'], true)) $v = 'pain.008.001.08';
            if (in_array($k, ['lead_days', 'prenotify_days'], true) && (!ctype_digit($v) || (int)$v > 30)) { $err = 'Vorlauf und Vorabankündigung: ganze Zahl 0–30.'; break; }
            $old = md_setting('sepa.' . $k, '');
            if ($old === $v) continue;
            if (md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', ['sepa.' . $k])) md_exec('UPDATE md_settings SET setting_value = ? WHERE setting_key = ?', [$v, 'sepa.' . $k]);
            else md_exec('INSERT INTO md_settings (setting_key, setting_value, description) VALUES (?,?,?)', ['sepa.' . $k, $v, 'SEPA: ' . MD_SEPA_FIELDS[$k][0]]);
            md_log_change($aid, 'settings', "sepa.$k: $old", $v, $aid, 'self');
        }
        $ok = 'SEPA-Einstellungen gespeichert.';
    } elseif ($act === 'mandate_add') {
        $m = (int)($_POST['member_id'] ?? 0);
        $name = trim((string)($_POST['debtor_name'] ?? ''));
        if ($name === '') { $r = md_one('SELECT first_name, last_name FROM svmembers WHERE member_id = ?', [$m]); $name = $r ? $r['first_name'] . ' ' . $r['last_name'] : ''; }
        [$id, $err] = md_sepa_mandate_add($m, array_merge($_POST, ['debtor_name' => $name]), $aid);
        $ok = 'Lastschriftmandat angelegt.';
    } elseif ($act === 'mandate_status') {
        $err = md_sepa_mandate_set_status((int)($_POST['mandate_id'] ?? 0), (string)($_POST['status'] ?? ''), (string)($_POST['reason'] ?? ''), $aid);
        $ok = 'Mandatsstatus geändert.';
    } elseif ($act === 'reveal') {
        $mand = md_one('SELECT * FROM md_sepa_mandates WHERE mandate_id = ?', [(int)($_POST['mandate_id'] ?? 0)]);
        $iban = $mand ? md_sepa_mandate_iban($mand) : null;
        if ($iban) { md_privacy_log((int)$mand['member_id'], 'iban_view', $aid, 'Mandat ' . $mand['mandate_ref']); md_flash('IBAN ' . $mand['mandate_ref'] . ': ' . trim(chunk_split($iban, 4, ' ')) . ' (Abruf wurde protokolliert)'); header('Location: ' . md_url('admin/sepa.php#mandates')); exit; }
        $err = 'IBAN nicht lesbar (Verschlüsselungsschlüssel?).';
    } elseif ($act === 'batch_create') {
        [$bid, $err] = md_sepa_batch_create((string)($_POST['collection'] ?? ''), (array)($_POST['members'] ?? []), $aid, !empty($_POST['allow_short']), trim((string)($_POST['note'] ?? '')) ?: null);
        if ($bid) { md_flash('Lauf erstellt. Als Nächstes die Datei herunterladen, bei der Bank einreichen und dann hier „eingereicht“ vermerken.'); header('Location: ' . md_url('admin/sepa_batch.php?id=' . $bid)); exit; }
    }
    md_flash($err ?: $ok, $err ? 'err' : 'ok');
    header('Location: ' . md_url('admin/sepa.php'));
    exit;
}

$minDate = md_sepa_min_collection();
$collection = md_parse_date($_GET['collection'] ?? '') ?: md_target_next(date('Y-m-d', strtotime('+' . max(0, $conf['prenotify_days']) . ' days')));
$allowShort = !empty($_GET['allow_short']);
md_header('SEPA-Lastschrift', $me, 'sepa');
md_show_flash();
echo '<h1>SEPA-Lastschrift</h1><p class="muted">Mandate verwalten, Einzugsläufe erstellen (pain.008-Datei für das Online-Banking), Einzug bestätigen, Rücklastschriften erfassen. <a href="admin/fees.php">Beiträge</a> · <a href="admin/dunning.php">Mahnwesen</a></p>';
if ($problems) {
    echo '<div class="flash err"><strong>Einrichtung unvollständig – es können keine Läufe erstellt werden:</strong><ul>';
    foreach ($problems as $p) echo '<li>' . h($p) . '</li>';
    echo '</ul></div>';
}

// ---- Mandate
$mands = md_q('SELECT m.*, s.first_name, s.last_name, s.membership_number FROM md_sepa_mandates m JOIN svmembers s ON s.member_id = m.member_id ORDER BY s.last_name, s.first_name, m.mandate_id DESC');
echo '<section class="card" id="mandates"><h2>Mandate (' . count($mands) . ')</h2><table><thead><tr><th>Mitglied</th><th>Mandatsreferenz</th><th>IBAN</th><th>Unterschrieben</th><th>Letzter Einzug</th><th>Status</th><th></th></tr></thead><tbody>';
foreach ($mands as $m) {
    $last = md_sepa_last_use((int)$m['mandate_id']);
    [$usable, $why] = md_sepa_mandate_usable($m, date('Y-m-d'));
    echo '<tr' . ($m['status'] === 'revoked' ? ' class="muted"' : '') . '><td><a href="index.php?member=' . (int)$m['member_id'] . '#sepa">' . h($m['first_name'] . ' ' . $m['last_name']) . '</a><br><span class="muted">' . h($m['membership_number']) . '</span></td><td>' . h($m['mandate_ref']) . '</td>'
        . '<td>' . h('…' . $m['iban_last4']) . ($m['bic'] ? '<br><span class="muted">' . h($m['bic']) . '</span>' : '') . '</td><td>' . h(md_date($m['signed_on'])) . '</td><td>' . h($last ? md_date($last) : '–') . '</td>'
        . '<td>' . h(MD_SEPA_STATUS[$m['status']]) . ($m['status_reason'] ? '<br><span class="muted">' . h($m['status_reason']) . '</span>' : '') . ($m['status'] === 'active' && !$usable ? '<br><span class="bad">' . h($why) . '</span>' : '') . '</td><td class="act">';
    if ($edit && $m['status'] !== 'revoked') {
        echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="mandate_id" value="' . (int)$m['mandate_id'] . '"><button class="link" name="action" value="reveal" title="IBAN anzeigen (wird protokolliert)">IBAN anzeigen</button></form>';
        echo '<form method="post" onsubmit="return this.reason.value.trim() !== \'\' || this.status.value === \'active\' || (alert(\'Bitte einen Grund angeben.\'), false)">' . md_csrf_field() . '<input type="hidden" name="action" value="mandate_status"><input type="hidden" name="mandate_id" value="' . (int)$m['mandate_id'] . '">'
            . '<input name="reason" placeholder="Grund"><select name="status"><option value="suspended">aussetzen</option>' . ($m['status'] === 'suspended' ? '<option value="active">wieder aktivieren</option>' : '') . '<option value="revoked">widerrufen</option></select> <button>OK</button></form>';
    }
    echo '</td></tr>';
}
if (!$mands) echo '<tr><td colspan="7" class="muted">Noch keine Mandate.</td></tr>';
echo '</tbody></table>';
if ($edit) {
    echo '<details><summary><strong>Neues Mandat erfassen</strong></summary><p class="muted">Erfasst wird ein <strong>unterschriebenes</strong> Mandat (auf Papier oder in Textform). Ein bisher aktives Mandat des Mitglieds wird als ersetzt widerrufen. Die IBAN wird verschlüsselt gespeichert.</p><form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="mandate_add"><div class="addr"><label>Mitglied<select name="member_id">';
    foreach (md_q("SELECT member_id, first_name, last_name, membership_number FROM svmembers WHERE membership_number NOT LIKE 'DEL-%' ORDER BY last_name, first_name") as $s) echo '<option value="' . (int)$s['member_id'] . '">' . h($s['last_name'] . ', ' . $s['first_name'] . ' (' . $s['membership_number'] . ')') . '</option>';
    echo '</select></label><label>Kontoinhaber (leer = Name des Mitglieds)<input name="debtor_name"></label><label>IBAN<input name="iban" required></label><label>BIC (optional)<input name="bic"></label><label>Unterschrieben am<input name="signed_on" value="' . date('d.m.Y') . '"></label>'
        . '<label>Mandatsreferenz (leer = automatisch)<input name="mandate_ref"></label><label>Ablageort der Unterlage<input name="document_note" placeholder="z. B. Ordner Mandate 2027"></label></div><button>Mandat speichern</button></form></details>';
    echo '<details><summary>Mandats-Vordruck zum Unterschreiben drucken</summary><form method="get" action="admin/mandate_print.php" target="_blank"><label>Mitglied<select name="member">';
    foreach (md_q("SELECT member_id, first_name, last_name FROM svmembers WHERE membership_number NOT LIKE 'DEL-%' ORDER BY last_name, first_name") as $s) echo '<option value="' . (int)$s['member_id'] . '">' . h($s['last_name'] . ', ' . $s['first_name']) . '</option>';
    echo '</select></label> <button>Vordruck öffnen</button></form></details>';
}
echo '</section>';

// ---- Lastschriftlauf
echo '<section class="card" id="run"><h2>Lastschriftlauf</h2>';
echo '<form class="search" method="get"><label>Einzugsdatum <input name="collection" value="' . h(md_date($collection)) . '" size="10"></label> <label class="check"><input type="checkbox" name="allow_short" value="1"' . ($allowShort ? ' checked' : '') . '> verkürzte Vorabankündigung vereinbart</label> <button>Vorschau</button></form>';
echo '<p class="muted">Frühestes Einzugsdatum: <strong>' . h(md_date($minDate)) . '</strong> (Vorlauf ' . (int)$conf['lead_days'] . ' TARGET-Tage; Banken haben oft eigene, längere Einreichungsfristen). Eingezogen werden bezahlbare Forderungen (Beitrag, Eintritt), die zum Einzugsdatum fällig sind, von Mitgliedern mit gültigem Mandat. Vorabankündigung: ' . (int)$conf['prenotify_days'] . ' Tage nach Rechnungsdatum.</p>';
$cdErr = !md_is_target_day($collection) ? 'Das Einzugsdatum ist kein Bankarbeitstag (TARGET2).' : ($collection < $minDate ? 'Das Einzugsdatum liegt vor dem frühesten Datum.' : '');
if ($cdErr) echo '<div class="flash warn">' . h($cdErr) . '</div>';
$cands = md_sepa_candidates($collection, $allowShort);
if (!$cands) echo '<p class="muted">Für dieses Einzugsdatum gibt es keine fälligen Forderungen von Mitgliedern mit Lastschriftmandat.</p>';
else {
    $okN = count(array_filter($cands, fn($g) => $g['ok']));
    echo '<form method="post" onsubmit="return confirm(\'Lauf jetzt erstellen? Die Forderungen sind danach für den Einzug reserviert.\')">' . md_csrf_field() . '<input type="hidden" name="action" value="batch_create"><input type="hidden" name="collection" value="' . h(md_date($collection)) . '">' . ($allowShort ? '<input type="hidden" name="allow_short" value="1">' : '');
    echo '<p><span class="chip ok">' . $okN . ' einziehbar</span> <span class="chip' . (count($cands) - $okN ? ' warn' : '') . '">' . (count($cands) - $okN) . ' mit Problem</span></p><table><thead><tr><th></th><th>Mitglied</th><th>Forderungen</th><th class="r">Betrag</th><th>Folgeart</th><th>Hinweis</th></tr></thead><tbody>';
    foreach ($cands as $m => $g) {
        echo '<tr class="' . ($g['ok'] ? '' : 'rowbad') . '"><td><input type="checkbox" name="members[]" value="' . $m . '"' . ($g['ok'] ? ' checked' : ' disabled') . '></td><td><a href="index.php?member=' . $m . '#finance">' . h($g['member']['name']) . '</a><br><span class="muted">' . h($g['member']['number']) . '</span></td><td>';
        foreach ($g['entries'] as $e) echo h($e['reference'] . ' ' . $e['description']) . ' – ' . md_money((int)$e['amount_cents']) . '<br>';
        echo '</td><td class="r">' . md_money($g['cents']) . '</td><td>' . h($g['seq'] ?? '') . '</td><td>' . h(implode('; ', $g['problems'])) . '</td></tr>';
    }
    echo '</tbody></table>';
    if ($edit && !$problems && !$cdErr && $okN) echo '<label class="block">Vermerk (optional)<input name="note"></label><button>Lauf erstellen</button>';
    echo '</form>';
}
echo '</section>';

// ---- Läufe
$batches = md_q('SELECT b.*, u.first_name ufn, u.last_name uln FROM md_sepa_batches b LEFT JOIN svmembers u ON u.member_id = b.created_by ORDER BY b.batch_id DESC LIMIT 50');
$st = ['created' => 'erstellt', 'submitted' => 'eingereicht', 'closed' => 'eingezogen', 'void' => 'storniert'];
echo '<section class="card" id="batches"><h2>Einzugsläufe</h2><table><thead><tr><th>Nr.</th><th>Einzugsdatum</th><th class="r">Lastschriften</th><th class="r">Summe</th><th>Status</th><th>Erstellt</th></tr></thead><tbody>';
foreach ($batches as $b) echo '<tr' . ($b['status'] === 'void' ? ' class="muted"' : '') . '><td><a href="admin/sepa_batch.php?id=' . (int)$b['batch_id'] . '">' . h($b['batch_no']) . '</a></td><td>' . h(md_date($b['collection_date'])) . '</td><td class="r">' . (int)$b['items'] . '</td><td class="r">' . md_money((int)$b['total_cents']) . '</td><td>' . h($st[$b['status']] ?? $b['status']) . '</td><td>' . h(date('d.m.Y', strtotime($b['created_at']))) . '<br><span class="muted">' . h(trim($b['ufn'] . ' ' . $b['uln'])) . '</span></td></tr>';
if (!$batches) echo '<tr><td colspan="6" class="muted">Noch keine Läufe.</td></tr>';
echo '</tbody></table></section>';

// ---- Einstellungen
if ($edit) {
    echo '<section class="card" id="conf"><h2>Einstellungen</h2><p class="muted">Die IBAN des Vereinskontos steht unter <a href="admin/fees.php#conf">Beiträge → Angaben für Rechnungen</a>, Name und Anschrift unter Einstellungen → Verein. Die Gläubiger-ID beantragt der Verein bei der Deutschen Bundesbank.</p><form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="conf"><div class="addr">';
    foreach (MD_SEPA_FIELDS as $k => [$label, $def, $hint]) {
        if ($k === 'format') { echo '<label>' . h($label) . '<select name="sepa[format]"><option value="pain.008.001.08"' . ($conf['format'] === 'pain.008.001.08' ? ' selected' : '') . '>pain.008.001.08 (aktuell)</option><option value="pain.008.001.02"' . ($conf['format'] === 'pain.008.001.02' ? ' selected' : '') . '>pain.008.001.02 (älter)</option></select></label>'; continue; }
        echo '<label>' . h($label) . '<input name="sepa[' . $k . ']" value="' . h($conf[$k]) . '" placeholder="' . h($hint) . '"></label>';
    }
    echo '</div><button>Speichern</button></form></section>';
}
md_footer();
