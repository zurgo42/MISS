<?php
require_once __DIR__ . '/src/bootstrap.php';
$actor = md_require_login();
if ((int)($_GET['member'] ?? 0) && (int)$_GET['member'] !== (int)$actor['member_id'] && !md_consent_ok((int)$actor['member_id'])) {
    md_flash('Fremde Daten sind erst nach der Zustimmung zu den Nutzungsbedingungen sichtbar.', 'warn');
    header('Location: ' . md_url('index.php#terms'));
    exit;
}
[$m, $via] = md_target_member($actor, $_GET['member'] ?? 0, 'view');
$mid = (int)$m['member_id'];
$staff = $via === 'admin';
$ownConsent = md_consent_ok((int)$actor['member_id']);
$canEdit = $staff ? md_can($actor, 'members', 'edit') : $ownConsent;   // eigene Daten nur mit Zustimmung bearbeitbar
$canClub = $staff && $canEdit;                                         // Vereinsdaten, Gruppen, Funktionen
$year = (int)date('Y');

$p = md_load_profile($mid);
$addr = md_load_addresses($mid);
$photos = md_load_photos($mid);
$contacts = md_load_contacts($mid);
$groups = md_load_groups($mid);
$funcs = md_load_functions($mid);
$vmap = md_visibility_map($mid);
$masked = 0;
if ($staff && !$canEdit) {   // nur ansehen: Felder mit "Nur ich" bleiben für das Mitglied reserviert
    foreach (array_keys(MD_PROFILE_FIELDS) as $k) if (($vmap[$k] ?? 'me') === 'me' && ($p[$k] ?? '') !== '') { $p[$k] = null; $masked++; }
    foreach (MD_SLOTS as $sl) {
        if (($vmap["address.$sl"] ?? 'me') === 'me' && isset($addr[$sl])) { unset($addr[$sl]); $masked++; }
        if (($vmap["photo.$sl"] ?? 'me') === 'me' && isset($photos[$sl])) { unset($photos[$sl]); $masked++; }
    }
    foreach (array_keys($contacts) as $k) if (($vmap["contact.$k"] ?? 'me') === 'me') { unset($contacts[$k]); $masked++; }
}
$fin = md_finance_summary($mid, $year);
$showFinance = !$staff || md_can($actor, 'finance', 'view');
$terms = md_terms_status($mid);
$changes = md_recent_changes($mid, 12);
$hidden = fn(string $name) => $staff ? '<input type="hidden" name="member" value="' . $mid . '">' : '';
$form = function (string $section, string $anchor, string $extra = '') use ($mid, $hidden): string {
    return '<input type="hidden" name="section" value="' . $section . '"><input type="hidden" name="anchor" value="' . $anchor . '">'
        . md_csrf_field() . $hidden('') . $extra;
};

md_header('Dashboard', $actor, 'dashboard');
md_show_flash();
if ($staff) {
    echo '<div class="flash warn">Verwaltungsmodus: ' . ($canEdit ? 'Du bearbeitest' : 'Du siehst (nur lesend)') . ' die Daten von <strong>' . h($m['first_name'] . ' ' . $m['last_name'])
        . '</strong>.' . ($canEdit ? ' Änderungen werden als „Admin“ protokolliert.' : '') . ($masked ? " $masked Angabe(n) mit „Nur ich“ sind für dich ausgeblendet." : '') . ' <a href="admin/index.php">zur Liste</a></div>';
} elseif (!$ownConsent) {
    echo '<div class="flash warn"><strong>Nur Ansicht:</strong> Solange du den Nutzungsbedingungen nicht zugestimmt hast, kannst du nichts ändern und siehst nur deine eigenen Daten.</div>';
}

// ---- Kopf
$primary = $photos[1] ?? reset($photos) ?: null;
echo '<section class="card head">';
echo $primary ? '<img class="portrait" src="photo.php?id=' . (int)$primary['photo_id'] . '" alt="">' : '<span class="avatar big">' . h(md_initials($m)) . '</span>';
echo '<div><h1>' . h(trim(($p['title'] ?? '') . ' ' . $m['first_name'] . ' ' . $m['last_name'])) . '</h1>';
echo '<p class="muted">Mitgliedsnr. ' . h($m['membership_number']) . ' · ' . h(MD_STATUS[$p['status'] ?? 'aktiv'] ?? '') . ($p['join_date'] ? ' · Mitglied seit ' . h(md_date($p['join_date'])) : '') . '</p>';
echo '<p>';
foreach ($funcs as $f) if (!$f['valid_to'] || $f['valid_to'] >= date('Y-m-d')) echo '<span class="chip fn">' . h($f['name']) . '</span> ';
foreach ($groups as $g) echo '<span class="chip">' . h($g['name']) . '</span> ';
echo '</p></div></section>';

// ---- Zustimmung
if ($terms['state'] !== 'none') {
    $c = $terms['current'];
    $cls = $terms['state'] === 'ok' ? 'ok' : 'warn';
    echo '<section class="card" id="terms"><h2>Nutzungsbedingungen</h2><div class="flash ' . $cls . '">';
    if ($terms['state'] === 'ok') {
        $cs = $terms['consent'];
        echo 'Zugestimmt zu Version ' . h($cs['version_label']) . ' am ' . h(md_date($cs['consented_at'])) . ' (' . h(['self' => 'online', 'admin' => 'durch Verwaltung', 'paper' => 'schriftlich', 'import' => 'Import'][$cs['channel']] ?? $cs['channel']) . ').';
    } elseif ($terms['state'] === 'outdated') {
        echo 'Es gibt eine neue Fassung (Version ' . h($c['version_label']) . '). Bitte neu zustimmen.';
    } else {
        echo 'Die Zustimmung zu den Nutzungsbedingungen liegt noch nicht vor.';
    }
    echo '</div><p><a href="terms.php?id=' . (int)$c['terms_id'] . '">Nutzungsbedingungen lesen (Version ' . h($c['version_label']) . ')</a></p>';
    if ($terms['state'] !== 'ok' && !$staff) {
        echo '<form method="post" action="save.php">' . $form('consent', 'terms', '<input type="hidden" name="terms_id" value="' . (int)$c['terms_id'] . '">')
            . '<label class="check"><input type="checkbox" name="agree" value="1"> Ich habe die Nutzungsbedingungen gelesen und stimme zu.</label> <button>Zustimmung speichern</button></form>';
    } elseif ($terms['state'] === 'ok' && $terms['consent'] && !$staff) {
        echo '<form method="post" action="save.php" onsubmit="return confirm(\'Zustimmung wirklich widerrufen?\')">' . $form('consent_revoke', 'terms', '<input type="hidden" name="consent_id" value="' . (int)$terms['consent']['consent_id'] . '">')
            . '<button class="link">Zustimmung widerrufen</button></form>';
    } elseif ($staff) {
        echo '<p class="muted">Zustimmungen erfasst die Verwaltung unter <a href="admin/terms.php?member=' . $mid . '">Nutzungsbedingungen</a>.</p>';
    }
    echo '</section>';
}

// ---- Meine Daten (Auskunft, Löschung) – nur für die eigenen Daten, unabhängig von der Zustimmung
if (!$staff) {
    $myReq = md_q("SELECT * FROM md_privacy_requests WHERE member_id = ? ORDER BY request_id DESC LIMIT 5", [$mid]);
    $openErase = false;
    foreach ($myReq as $rq) if ($rq['kind'] === 'erasure' && $rq['status'] === 'open') $openErase = true;
    echo '<section class="card" id="privacy"><h2>Meine Daten</h2><p>Du kannst jederzeit alle über dich gespeicherten Daten ansehen oder herunterladen:</p><p>'
        . '<a href="privacy_export.php?format=html" target="_blank">Ansehen und drucken</a> · <a href="privacy_export.php?format=json">JSON-Datei</a>' . (class_exists('ZipArchive') ? ' · <a href="privacy_export.php?format=zip">ZIP mit Fotos</a>' : '') . '</p>';
    if ($openErase) echo '<div class="flash warn">Dein Löschantrag liegt der Verwaltung vor.</div>';
    else echo '<details><summary>Löschung meiner Daten beantragen</summary><p class="muted">Die Verwaltung prüft den Antrag und antwortet innerhalb eines Monats. Während der Mitgliedschaft und aufgrund von Aufbewahrungspflichten (z. B. Buchungen, Spendenbescheinigungen) können nicht alle Daten sofort gelöscht werden.</p>'
        . '<form method="post" action="save.php">' . $form('privacy_request', 'privacy') . '<label class="block">Anmerkung (optional)<input name="note"></label><button>Löschung beantragen</button></form></details>';
    if ($myReq) {
        echo '<ul class="plain">';
        foreach ($myReq as $rq) echo '<li class="muted">' . ($rq['kind'] === 'access' ? 'Auskunft' : 'Löschantrag') . ' vom ' . h(md_date(substr($rq['received_at'], 0, 10))) . ': ' . ['open' => 'offen', 'done' => 'erledigt', 'refused' => 'abgelehnt'][$rq['status']] . ($rq['response_note'] ? ' – ' . h($rq['response_note']) : '') . '</li>';
        echo '</ul>';
    }
    echo '</section>';
}

echo '<fieldset class="roset"' . ($canEdit ? '' : ' disabled') . '>';

// ---- Fotos
echo '<section class="card" id="photos"><h2>Fotos</h2><div class="grid3">';
foreach (MD_SLOTS as $s) {
    $ph = $photos[$s] ?? null;
    echo '<div class="slot"><div class="slotimg">' . ($ph ? '<img src="photo.php?id=' . (int)$ph['photo_id'] . '" alt="">' : '<span class="muted">Platz ' . $s . ' frei</span>') . '</div>';
    echo '<form method="post" action="save.php" enctype="multipart/form-data">' . $form('photo_upload', 'photos', '<input type="hidden" name="slot" value="' . $s . '">');
    echo '<input type="file" name="photo" accept="image/jpeg,image/png,image/gif,image/webp" required>';
    echo '<input type="text" name="caption" placeholder="Bildunterschrift" value="' . h($ph['caption'] ?? '') . '">';
    echo '<div class="row"><label>Sichtbar für ' . md_vis_select("photo.$s", $vmap) . '</label><button>' . ($ph ? 'Ersetzen' : 'Hochladen') . '</button></div></form>';
    if ($ph) echo '<form method="post" action="save.php" onsubmit="return confirm(\'Foto löschen?\')">' . $form('photo_delete', 'photos', '<input type="hidden" name="slot" value="' . $s . '">') . '<button class="link">Löschen</button></form>';
    echo '</div>';
}
echo '</div><p class="muted">Bilder werden auf max. 900 px verkleinert; Standortdaten werden entfernt.</p></section>';

// ---- Profilfelder (je Gruppe eine Karte)
$byGroup = [];
foreach (MD_PROFILE_FIELDS as $k => $f) $byGroup[$f[2]][$k] = $f;
foreach ($byGroup as $gname => $fields) {
    $aid = 'g_' . preg_replace('/[^a-z]/', '', strtolower($gname));
    echo '<section class="card" id="' . $aid . '"><h2>' . h($gname) . '</h2><form method="post" action="save.php">' . $form('profile', $aid, '<input type="hidden" name="group" value="' . h($gname) . '">');
    if ($gname === 'Persönliche Daten') {
        echo '<p class="muted">Name und Anmelde-E-Mail (' . h($m['first_name'] . ' ' . $m['last_name'] . ', ' . $m['email']) . ') ändert die Verwaltung.</p>';
    }
    foreach ($fields as $k => $f) {
        echo '<div class="field"><label for="f_' . $k . '">' . h($f[0]) . '</label><div class="inp">';
        $val = (string)($p[$k] ?? '');
        if ($f[1] === 'textarea') echo '<textarea id="f_' . $k . '" name="' . $k . '" rows="3">' . h($val) . '</textarea>';
        elseif ($f[1] === 'select') {
            echo '<select id="f_' . $k . '" name="' . $k . '">';
            foreach ($f[4] as $o) echo '<option' . ($o === $val ? ' selected' : '') . '>' . h($o) . '</option>';
            echo '</select>';
        } else echo '<input id="f_' . $k . '" name="' . $k . '" type="text" value="' . h($f[1] === 'date' ? md_date($val) : $val) . '"' . ($f[1] === 'date' ? ' placeholder="TT.MM.JJJJ"' : '') . '>';
        echo '</div>' . md_vis_select($k, $vmap) . '</div>';
    }
    echo '<button>Speichern</button></form></section>';
}

// ---- Adressen
echo '<section class="card" id="addresses"><h2>Adressen</h2><form method="post" action="save.php">' . $form('addresses', 'addresses');
foreach (MD_SLOTS as $s) {
    $a = $addr[$s] ?? [];
    echo '<fieldset><legend>Adresse ' . $s . '</legend><div class="addr">';
    foreach (MD_ADDRESS_FIELDS as $f => $label) echo '<label>' . h($label) . '<input name="address_' . $s . '_' . $f . '" value="' . h($a[$f] ?? '') . '"></label>';
    echo '</div><div class="row"><span class="muted">Sichtbar für</span>' . md_vis_select("address.$s", $vmap) . '</div></fieldset>';
}
echo '<button>Speichern</button></form></section>';

// ---- Kontakt
echo '<section class="card" id="contacts"><h2>Kontakt &amp; Social Media</h2><form method="post" action="save.php">' . $form('contacts', 'contacts');
foreach (MD_CONTACT_KINDS as $k => $label) {
    echo '<div class="field"><label for="c_' . $k . '">' . h($label) . '</label><div class="inp"><input id="c_' . $k . '" name="contact_' . $k . '" value="' . h($contacts[$k] ?? '') . '"></div>' . md_vis_select("contact.$k", $vmap) . '</div>';
}
echo '<button>Speichern</button></form></section>';

// ---- Verein
echo '<section class="card" id="club"><h2>Vereinsdaten</h2>';
if ($canClub) {
    echo '<form method="post" action="save.php">' . $form('club', 'club') . '<div class="addr">';
    echo '<label>Status<select name="status">';
    foreach (MD_STATUS as $k => $l) echo '<option value="' . $k . '"' . (($p['status'] ?? '') === $k ? ' selected' : '') . '>' . h($l) . '</option>';
    echo '</select></label><label>Eintritt<input name="join_date" value="' . h(md_date($p['join_date'] ?? '')) . '"></label><label>Austritt<input name="leave_date" value="' . h(md_date($p['leave_date'] ?? '')) . '"></label></div>';
    echo '<label class="block">Verwaltungsnotiz (nie für Mitglieder sichtbar)<textarea name="admin_note" rows="2">' . h($p['admin_note'] ?? '') . '</textarea></label><button>Speichern</button></form>';
} else {
    echo '<p>Status: ' . h(MD_STATUS[$p['status'] ?? 'aktiv'] ?? '') . ' · Eintritt: ' . h(md_date($p['join_date'] ?? '') ?: '–') . ($p['leave_date'] ? ' · Austritt: ' . h(md_date($p['leave_date'])) : '') . '</p>';
}
echo '<h3>Teilgruppen</h3><ul class="plain">';
foreach ($groups as $g) {
    echo '<li>' . h($g['name']) . ' <span class="muted">seit ' . h(md_date($g['since'])) . '</span>';
    if ($canClub) echo ' <form class="inline" method="post" action="save.php">' . $form('group_remove', 'club', '<input type="hidden" name="group_id" value="' . (int)$g['group_id'] . '">') . '<button class="link">entfernen</button></form>';
    echo '</li>';
}
if (!$groups) echo '<li class="muted">keine</li>';
echo '</ul>';
if ($canClub) {
    echo '<form class="inline" method="post" action="save.php">' . $form('group_add', 'club') . '<select name="group_id">';
    foreach (md_q('SELECT * FROM md_groups ORDER BY name') as $g) echo '<option value="' . (int)$g['group_id'] . '">' . h($g['name']) . '</option>';
    echo '</select> <button>Gruppe zuordnen</button></form>';
}
echo '<h3>Funktionen <span class="muted">(alle werden dokumentiert; für die Rechte im Sitzungstool gilt das höchste Level)</span></h3><ul class="plain">';
$today = date('Y-m-d');
foreach ($funcs as $f) {
    $active = (!$f['valid_to'] || $f['valid_to'] >= $today) && (!$f['valid_from'] || $f['valid_from'] <= $today);
    $res = $f['ressort_id'] ? (md_ressorts()[(int)$f['ressort_id']] ?? null) : null;
    echo '<li' . ($active ? '' : ' class="muted"') . '>' . h($f['name']) . ($res ? ' – ' . h($res) : '') . ' <span class="muted">Level ' . (int)$f['level'] . ' · ' . h(md_date($f['valid_from'])) . ' – ' . h($f['valid_to'] ? md_date($f['valid_to']) : 'heute') . '</span>';
    if ($canClub && $active) echo ' <form class="inline" method="post" action="save.php">' . $form('func_remove', 'club', '<input type="hidden" name="member_function_id" value="' . (int)$f['member_function_id'] . '">') . '<button class="link">beenden</button></form>';
    echo '</li>';
}
if (!$funcs) echo '<li class="muted">keine</li>';
echo '</ul>';
$eff = md_effective_function($mid);
if (md_member_is_managed($mid)) {
    echo '<p class="muted">Rechte im Sitzungstool: <strong>' . ($eff ? h($eff['code']) . ', Level ' . (int)$eff['level'] : 'keine Funktion, Level ' . (int)md_setting('default_aktiv', '0')) . '</strong>'
        . ' · Rolle ' . h(md_role_for($eff ? (int)$eff['level'] : (int)md_setting('default_aktiv', '0'), $eff['code'] ?? null)) . '</p>';
}
if ($canClub) {
    echo '<form class="inline" method="post" action="save.php">' . $form('func_add', 'club') . '<select name="function_id">';
    foreach (md_q('SELECT * FROM md_functions ORDER BY code') as $f) echo '<option value="' . (int)$f['function_id'] . '">' . h($f['code'] . ' – ' . $f['name'] . ' (Level ' . $f['sv_aktiv_level'] . ')') . '</option>';
    echo '</select> <input name="aktiv_level" size="3" placeholder="Level" title="Optional: Level abweichend vom Standard der Funktion (0–19)">';
    if (md_ressorts()) {
        echo ' <select name="ressort_id"><option value="">kein Ressort</option>';
        foreach (md_ressorts() as $rid => $rn) echo '<option value="' . $rid . '">' . h($rn) . '</option>';
        echo '</select>';
    }
    echo ' <button>Funktion zuordnen</button></form>';
}
echo '</section>';

// ---- Zugang (Verwaltung): Einladung und Passwort-Link
if ($staff && $canClub && md_can($actor, 'mail', 'edit')) {
    $has = !empty($target['password_hash']);
    $chn = md_one('SELECT letter_channel FROM md_profiles WHERE member_id = ?', [$mid])['letter_channel'] ?? 'post';
    echo '<section class="card" id="access"><h2>Zugang</h2><p>Passwort: <strong>' . ($has ? 'gesetzt' : 'noch nicht gesetzt') . '</strong> · E-Mail: ' . (md_mail_address_of($mid) ? h($target['email']) : '<span class="bad">keine gültige Adresse</span>') . ' · Rechnungen und Mahnungen: ' . ($chn === 'mail' ? 'per E-Mail' : 'per Post') . '</p>';
    if (md_mail_address_of($mid)) echo '<form method="post" action="save.php">' . $form('invite', 'access') . '<button>' . ($has ? 'Link zum Zurücksetzen senden' : 'Einladung senden') . '</button> <span class="muted">Das Mitglied legt das Passwort selbst fest; die Verwaltung sieht es nie.</span></form>';
    echo '</section>';
}

// ---- Finanzen
if ($showFinance) {
echo '<section class="card" id="finance"><h2>' . ($staff ? 'Finanzen ' : 'Meine Finanzen ') . $year . '</h2><div class="kpis">';
$kpi = fn(string $label, int $c, string $cls = '') => '<div class="kpi ' . $cls . '"><span>' . h($label) . '</span><strong>' . md_money($c) . '</strong></div>';
echo $kpi('Offene Forderungen', $fin['open_claims'], $fin['overdue'] ? 'bad' : '');
echo $kpi('Beiträge bezahlt', $fin['fees_paid']) . $kpi('Eintrittsgelder', $fin['tickets_paid']) . $kpi('Spenden', $fin['donations']);
echo $kpi('Einnahmen vom Verein', $fin['income_paid'], 'good') . $kpi('Auszahlung offen', $fin['income_open']);
echo '</div>';
if ($fin['ueb_year'] > 0) {
    $pct = min(100, (int)round($fin['ueb_year'] / max(1, $fin['ueb_limit']) * 100));
    echo '<p class="muted">Übungsleiterpauschale ' . $year . ': ' . md_money($fin['ueb_year']) . ' von ' . md_money($fin['ueb_limit']) . ' Freibetrag</p><div class="bar"><i style="width:' . $pct . '%"></i></div>';
}
echo '<table><thead><tr><th>Datum</th><th>Art</th><th>Beschreibung</th><th class="r">Betrag</th><th>Status</th></tr></thead><tbody>';
$dlevels = md_dunning_levels();
foreach ($fin['rows'] as $r) {
    $out = $r['direction'] === 'to_club';
    $stat = $r['status'] === 'paid' ? 'bezahlt ' . md_date($r['paid_date']) : ($out ? 'offen' . ($r['due_date'] ? ', fällig ' . md_date($r['due_date']) : '') : 'Auszahlung offen');
    $overdue = $out && $r['status'] === 'open' && $r['due_date'] && $r['due_date'] < date('Y-m-d');
    $invLink = $r['reference'] && $out && in_array($r['kind'], ['beitrag', 'eintritt'], true) ? ' <a href="admin/invoice_print.php?id=' . (int)$r['entry_id'] . '" target="_blank">Rechnung</a>' : '';
    $dun = (int)$r['dunning_level'] > 0 && $r['status'] === 'open' ? '<br><span class="bad">' . h($dlevels[(int)$r['dunning_level']]['name'] ?? 'Mahnung') . ' vom ' . h(md_date($r['last_dunned_at'])) . '</span>' : '';
    $defer = !empty($r['deferred_until']) && $r['status'] === 'open' && $r['deferred_until'] >= date('Y-m-d') ? '<br><span class="muted">gestundet bis ' . h(md_date($r['deferred_until'])) . '</span>' : '';
    echo '<tr><td>' . h(md_date($r['entry_date'])) . '</td><td>' . h(md_ledger_label($r)) . '</td><td>' . h($r['description']) . ($r['reference'] ? ' <span class="muted">' . h($r['reference']) . '</span>' : '') . $invLink . '</td>';
    echo '<td class="r ' . ($out ? 'neg' : 'pos') . '">' . ($out ? '− ' : '+ ') . md_money((int)$r['amount_cents']) . '</td><td class="' . ($overdue ? 'bad' : '') . '">' . h($stat) . $dun . $defer . '</td></tr>';
}
if (!$fin['rows']) echo '<tr><td colspan="5" class="muted">Keine Buchungen.</td></tr>';
echo '</tbody></table>';
$myReceipts = md_q("SELECT * FROM md_receipts WHERE member_id = ? AND status = 'issued' ORDER BY receipt_no DESC", [$mid]);
if ($myReceipts) {
    echo '<h3>Spendenbescheinigungen</h3><ul class="plain">';
    foreach ($myReceipts as $rc) echo '<li><a href="admin/receipt_print.php?id=' . (int)$rc['receipt_id'] . '" target="_blank">Nr. ' . h($rc['receipt_no']) . '</a> · ' . ($rc['kind'] === 'annual' ? 'Sammelbestätigung ' . h(md_date($rc['period_from'])) . ' – ' . h(md_date($rc['period_to'])) : 'Zuwendung vom ' . h(md_date($rc['period_from']))) . ' · ' . md_money((int)$rc['total_cents']) . ' <span class="muted">ausgestellt ' . h(date('d.m.Y', strtotime($rc['issued_at']))) . '</span></li>';
    echo '</ul><p class="muted">Der Ausdruck ist eine Kopie der ausgestellten Bestätigung.</p>';
}
$myLetters = md_q("SELECT * FROM md_dunning_letters WHERE member_id = ? AND status = 'issued' ORDER BY letter_id DESC", [$mid]);
if ($myLetters) {
    echo '<h3>Mahnschreiben</h3><ul class="plain">';
    foreach ($myLetters as $lt) { $ls = json_decode($lt['snapshot'], true); echo '<li><a href="admin/dunning_print.php?id=' . (int)$lt['letter_id'] . '" target="_blank">' . h($ls['level_name'] ?? 'Mahnung') . ' ' . h($lt['letter_no']) . '</a> · ' . md_money((int)$lt['total_cents']) . ' · Frist ' . h(md_date($lt['deadline'])) . '</li>'; }
    echo '</ul>';
}
echo '</section>';
}
echo '</fieldset>';

// ---- Beitrag (außerhalb des gesperrten Bereichs: Zuordnung braucht das Recht "Beiträge", nicht "Mitglieder bearbeiten")
if (!$staff || md_can($actor, 'fees', 'view')) {
    $fa = md_fee_assignment($mid, date('Y-m-d'), date('Y-m-d'));
    $feeHist = md_q('SELECT f.*, g.name, g.amount_cents, g.rhythm FROM md_member_fees f JOIN md_fee_groups g ON g.group_id = f.group_id WHERE f.member_id = ? ORDER BY f.valid_from DESC, f.fee_id DESC LIMIT 6', [$mid]);
    echo '<section class="card" id="fee"><h2>Beitrag</h2>';
    if ($fa) {
        $amt = $fa['amount_override_cents'] !== null ? (int)$fa['amount_override_cents'] : (int)$fa['group_cents'];
        echo '<p><strong>' . h($fa['group_name']) . '</strong> · ' . md_money($amt) . ' ' . h(MD_RHYTHMS[$fa['rhythm']]) . ($fa['discount_percent'] !== null ? ', ' . (int)$fa['discount_percent'] . ' % Ermäßigung' : '') . ' <span class="muted">seit ' . h(md_date($fa['valid_from'])) . '</span></p>';
        if ($fa['reason'] && ($fa['amount_override_cents'] !== null || $fa['discount_percent'] !== null)) echo '<p class="muted">Abweichung: ' . h($fa['reason']) . ($fa['approved_by'] ? ' (bewilligt von ' . h($fa['approved_by']) . ')' : '') . '</p>';
    } else echo '<p class="muted">Keine Beitragsgruppe zugeordnet.</p>';
    if ($staff && md_can($actor, 'fees', 'edit')) {
        echo '<details><summary>Beitragsgruppe zuordnen oder ändern</summary><form method="post" action="save.php">' . $form('fee_assign', 'fee') . '<div class="addr"><label>Gruppe<select name="group_id">';
        foreach (md_fee_groups(true) as $g) echo '<option value="' . (int)$g['group_id'] . '"' . ($fa && (int)$fa['group_id'] === (int)$g['group_id'] ? ' selected' : '') . '>' . h($g['name'] . ' (' . md_money((int)$g['amount_cents']) . ' ' . MD_RHYTHMS[$g['rhythm']] . ')') . '</option>';
        echo '</select></label><label>Gültig ab<input name="valid_from" value="' . date('d.m.Y') . '"></label><label>Abweichender Betrag in €<input name="override" placeholder="optional"></label><label>oder Ermäßigung in %<input name="discount" size="4" placeholder="optional"></label>'
            . '<label>Grund (bei Abweichung Pflicht)<input name="reason"></label><label>Bewilligt von<input name="approved_by" placeholder="Name/Funktion"></label></div><button>Speichern</button></form></details>';
    }
    if (count($feeHist) > 1) { echo '<p class="muted">Bisher: '; foreach (array_slice($feeHist, 1) as $fh) echo h($fh['name']) . ' (' . h(md_date($fh['valid_from'])) . ($fh['valid_to'] ? ' – ' . h(md_date($fh['valid_to'])) : '') . ') '; echo '</p>'; }
    echo '</section>';
}

// ---- Veranstaltungen (eigene Anmeldungen und eigene Veranstaltungen)
if (!$staff) {
    $myEv = md_q("SELECT e.*, r.status rstatus FROM md_event_regs r JOIN md_events e ON e.event_id = r.event_id WHERE r.member_id = ? AND r.status IN ('registered','waitlist') AND e.starts_at >= ? AND e.status = 'open' ORDER BY e.starts_at", [$mid, date('Y-m-d H:i:s')]);
    $orgEv = md_q("SELECT * FROM md_events WHERE organizer_id = ? AND starts_at >= ? AND status = 'open' ORDER BY starts_at", [$mid, date('Y-m-d H:i:s')]);
    if ($myEv || $orgEv) {
        echo '<section class="card" id="events"><h2>Veranstaltungen</h2><ul class="plain">';
        foreach ($myEv as $e) echo '<li>' . h(md_event_dt($e['starts_at'])) . ' · <a href="event.php?id=' . (int)$e['event_id'] . '">' . h($e['title']) . '</a> <span class="muted">(' . ($e['rstatus'] === 'registered' ? 'angemeldet' : 'Warteliste') . ')</span></li>';
        foreach ($orgEv as $e) echo '<li>' . h(md_event_dt($e['starts_at'])) . ' · <a href="event.php?id=' . (int)$e['event_id'] . '">' . h($e['title']) . '</a> <span class="muted">(ich veranstalte)</span></li>';
        echo '</ul><p><a href="events.php">Alle Veranstaltungen</a></p></section>';
    }
}

// ---- Lastschrift (eigene Daten bzw. Verwaltung mit Recht "SEPA")
if (!$staff || md_can($actor, 'sepa', 'view')) {
    $mandates = md_q('SELECT * FROM md_sepa_mandates WHERE member_id = ? ORDER BY mandate_id DESC', [$mid]);
    if ($mandates || ($staff && md_can($actor, 'sepa', 'edit'))) {
        echo '<section class="card" id="sepa"><h2>Lastschrift</h2>';
        if (!$mandates) echo '<p class="muted">Kein Lastschriftmandat. Beiträge werden per Überweisung bezahlt.</p>';
        foreach ($mandates as $mn) {
            [$usable, $why] = md_sepa_mandate_usable($mn, date('Y-m-d'));
            echo '<p' . ($mn['status'] === 'revoked' ? ' class="muted"' : '') . '><strong>' . h($mn['mandate_ref']) . '</strong> · IBAN …' . h($mn['iban_last4']) . ' · unterschrieben ' . h(md_date($mn['signed_on'])) . ' · ' . h(MD_SEPA_STATUS[$mn['status']])
                . ($mn['status_reason'] ? ' (' . h($mn['status_reason']) . ')' : '') . ($mn['status'] === 'active' && !$usable ? ' <span class="bad">– ' . h($why) . '</span>' : '') . '</p>';
        }
        if ($staff && md_can($actor, 'sepa', 'edit')) echo '<p><a href="admin/sepa.php#mandates">Mandate verwalten</a></p>';
        elseif (!$staff && $mandates) echo '<p class="muted">Zum Ändern oder Widerrufen des Mandats wende dich bitte an die Verwaltung.</p>';
        echo '</section>';
    }
}

// ---- Protokoll
echo '<section class="card" id="log"><h2>Änderungsprotokoll</h2><table><thead><tr><th>Wann</th><th>Was</th><th>Alt</th><th>Neu</th><th>Wer</th></tr></thead><tbody>';
foreach ($changes as $c) {
    $who = $c['changed_via'] === 'admin' ? 'Admin ' . trim($c['first_name'] . ' ' . $c['last_name']) : ['import' => 'Import', 'system' => 'System (Tagesabgleich)'][$c['changed_via']] ?? 'selbst';
    $long = fn($v) => $v === null ? '–' : (mb_strlen($v) > 60 ? mb_substr($v, 0, 60) . '…' : $v);
    echo '<tr><td>' . h(date('d.m.Y H:i', strtotime($c['changed_at']))) . '</td><td>' . h(md_field_label($c['field_key'])) . '</td><td>' . h($long($c['old_value'])) . '</td><td>' . h($long($c['new_value'])) . '</td><td>' . h($who) . '</td></tr>';
}
if (!$changes) echo '<tr><td colspan="5" class="muted">Noch keine Änderungen.</td></tr>';
echo '</tbody></table></section>';
md_footer();
