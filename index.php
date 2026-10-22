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
foreach ($fin['rows'] as $r) {
    $out = $r['direction'] === 'to_club';
    $stat = $r['status'] === 'paid' ? 'bezahlt ' . md_date($r['paid_date']) : ($out ? 'offen' . ($r['due_date'] ? ', fällig ' . md_date($r['due_date']) : '') : 'Auszahlung offen');
    $overdue = $out && $r['status'] === 'open' && $r['due_date'] && $r['due_date'] < date('Y-m-d');
    echo '<tr><td>' . h(md_date($r['entry_date'])) . '</td><td>' . h(md_ledger_label($r)) . '</td><td>' . h($r['description']) . ($r['reference'] ? ' <span class="muted">' . h($r['reference']) . '</span>' : '') . '</td>';
    echo '<td class="r ' . ($out ? 'neg' : 'pos') . '">' . ($out ? '− ' : '+ ') . md_money((int)$r['amount_cents']) . '</td><td class="' . ($overdue ? 'bad' : '') . '">' . h($stat) . '</td></tr>';
}
if (!$fin['rows']) echo '<tr><td colspan="5" class="muted">Keine Buchungen.</td></tr>';
echo '</tbody></table></section>';
}
echo '</fieldset>';

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
