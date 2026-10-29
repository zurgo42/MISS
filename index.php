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
$acc = function (string $id, string $title, string $body, bool $open = false, string $sub = '', string $cls = ''): string {
    return '<details class="acc ' . $cls . '" id="' . h($id) . '"' . ($open ? ' open' : '') . '><summary>' . $title . ($sub !== '' ? '<span class="sub">' . $sub . '</span>' : '') . '</summary><div class="accbody">' . $body . '</div></details>';
};
// Felder der gemeinsam gespeicherten Kacheln zeigen auf das Formular "mainform" (Speichern-Knopf unter den Kacheln)
$inMain = fn(string $html): string => preg_replace('/<(input|select|textarea)\b(?![^>]*\bform=)/', '<$1 form="mainform"', $html);
$form = function (string $section, string $anchor, string $extra = '') use ($mid, $hidden): string {
    return '<input type="hidden" name="section" value="' . $section . '"><input type="hidden" name="anchor" value="' . $anchor . '">'
        . md_csrf_field() . $hidden('') . $extra;
};


// ======================================================================= Bausteine
// ---- Nutzungsbedingungen
$termsBlock = '';
$termsOk = $terms['state'] === 'ok';
if ($terms['state'] !== 'none') {
    $c = $terms['current'];
    ob_start();
    echo '<div class="flash ' . ($termsOk ? 'ok' : 'warn') . '">';
    if ($termsOk) {
        $cs = $terms['consent'];
        echo 'Zugestimmt zu Version ' . h($cs['version_label']) . ' am ' . h(md_date($cs['consented_at'])) . ' (' . h(['self' => 'online', 'admin' => 'durch Verwaltung', 'paper' => 'schriftlich', 'import' => 'Import'][$cs['channel']] ?? $cs['channel']) . ').';
    } elseif ($terms['state'] === 'outdated') {
        echo 'Es gibt eine neue Fassung (Version ' . h($c['version_label']) . '). Bitte neu zustimmen.';
    } else {
        echo 'Die Zustimmung zu den Nutzungsbedingungen liegt noch nicht vor.';
    }
    echo '</div><p><a href="terms.php?id=' . (int)$c['terms_id'] . '">Nutzungsbedingungen lesen (Version ' . h($c['version_label']) . ')</a></p>';
    if (!$termsOk && !$staff) {
        echo '<form method="post" action="save.php">' . $form('consent', 'terms', '<input type="hidden" name="terms_id" value="' . (int)$c['terms_id'] . '">')
            . '<label class="check"><input type="checkbox" name="agree" value="1"> Ich habe die Nutzungsbedingungen gelesen und stimme zu.</label> <button>Zustimmung speichern</button></form>';
    } elseif ($termsOk && $terms['consent'] && !$staff) {
        echo '<form method="post" action="save.php" onsubmit="return confirm(\'Zustimmung wirklich widerrufen?\')">' . $form('consent_revoke', 'terms', '<input type="hidden" name="consent_id" value="' . (int)$terms['consent']['consent_id'] . '">')
            . '<button class="link">Zustimmung widerrufen</button></form>';
    } elseif ($staff) {
        echo '<p class="muted">Zustimmungen erfasst die Verwaltung unter <a href="admin/terms.php?member=' . $mid . '">Nutzungsbedingungen</a>.</p>';
    }
    $termsBlock = $acc('terms', 'Nutzungsbedingungen', ob_get_clean(), !$termsOk, $termsOk ? 'zugestimmt' : 'Zustimmung fehlt', $termsOk ? '' : 'warn');
}

// ---- Kopf mit Sonderfunktionen
$primary = $photos[1] ?? reset($photos) ?: null;
ob_start();
echo '<section class="card head">';
echo $primary ? '<img class="portrait" src="photo.php?id=' . (int)$primary['photo_id'] . '" alt="">' : '<span class="avatar big">' . h(md_initials($m)) . '</span>';
echo '<div class="grow"><h1>' . h(trim(($p['title'] ?? '') . ' ' . $m['first_name'] . ' ' . $m['last_name'])) . '</h1>';
echo '<p class="muted">Mitgliedsnr. ' . h($m['membership_number']) . ' · ' . h(MD_STATUS[$p['status'] ?? 'aktiv'] ?? '') . ($p['join_date'] ? ' · Mitglied seit ' . h(md_date($p['join_date'])) : '') . '</p>';
echo '<p>';
foreach ($funcs as $f) if (!$f['valid_to'] || $f['valid_to'] >= date('Y-m-d')) echo '<span class="chip fn">' . h($f['name']) . '</span> ';
foreach ($groups as $g) echo '<span class="chip">' . h($g['name']) . '</span> ';
echo '</p>';
$special = !$staff ? md_special_groups($actor) : [];
if ($special) {
    echo '<details class="acc inner" id="sonder"><summary>Sonderfunktionen:</summary><div class="accbody">';
    foreach ($special as $gname => $items) {
        echo '<p class="spgroup"><strong>' . h($gname) . ':</strong> ';
        foreach ($items as [$url, $label]) echo '<a class="btn small" href="' . h($url) . '">' . h($label) . '</a> ';
        echo '</p>';
    }
    echo '</div></details>';
}
echo '</div></section>';
$headBlock = ob_get_clean();

// ---- Meine Veranstaltungen
$eventsBlock = '';
if (!$staff) {
    $myEv = md_q("SELECT e.*, r.status rstatus FROM md_event_regs r JOIN md_events e ON e.event_id = r.event_id WHERE r.member_id = ? AND r.status IN ('registered','waitlist') AND e.starts_at >= ? AND e.status = 'open' ORDER BY e.starts_at", [$mid, date('Y-m-d H:i:s')]);
    $orgEv = md_q("SELECT * FROM md_events WHERE organizer_id = ? AND starts_at >= ? AND status = 'open' ORDER BY starts_at", [$mid, date('Y-m-d H:i:s')]);
    $soon = md_q("SELECT * FROM md_events WHERE status = 'open' AND starts_at >= ? AND organizer_id <> ? AND event_id NOT IN (SELECT event_id FROM md_event_regs WHERE member_id = ? AND status <> 'cancelled') ORDER BY starts_at LIMIT 3", [date('Y-m-d H:i:s'), $mid, $mid]);
    ob_start();
    echo '<ul class="plain evlist">';
    foreach ($myEv as $e) echo '<li>' . h(md_event_dt($e['starts_at'])) . ' · <a href="event.php?id=' . (int)$e['event_id'] . '">' . h($e['title']) . '</a> <span class="chip ' . ($e['rstatus'] === 'registered' ? 'ok' : 'warn') . '">' . ($e['rstatus'] === 'registered' ? 'angemeldet' : 'Warteliste') . '</span></li>';
    foreach ($orgEv as $e) echo '<li>' . h(md_event_dt($e['starts_at'])) . ' · <a href="event.php?id=' . (int)$e['event_id'] . '">' . h($e['title']) . '</a> <span class="chip">ich veranstalte</span></li>';
    if (!$myEv && !$orgEv) echo '<li class="muted">Du bist zurzeit für keine Veranstaltung angemeldet.</li>';
    echo '</ul>';
    if ($soon) { echo '<h3>Demnächst</h3><ul class="plain evlist">'; foreach ($soon as $e) echo '<li>' . h(md_event_dt($e['starts_at'])) . ' · <a href="event.php?id=' . (int)$e['event_id'] . '">' . h($e['title']) . '</a></li>'; echo '</ul>'; }
    echo '<p><a class="btn small" href="events.php">Alle Veranstaltungen</a> <a class="btn small secondary" href="event.php?new=1">Veranstaltung anlegen</a></p>';
    $eventsBlock = $acc('events', 'Meine Veranstaltungen', ob_get_clean(), (bool)($myEv || $orgEv), count($myEv) + count($orgEv) ? (count($myEv) + count($orgEv)) . ' anstehend' : '');
}

// ---- Persönliche Daten
$byGroup = [];
foreach (MD_PROFILE_FIELDS as $k => $f) $byGroup[$f[2]][$k] = $f;
$fieldRow = function (string $k, array $f) use ($p, $vmap): string {
    $o = '<div class="field"><label for="f_' . $k . '">' . h($f[0]) . '</label><div class="inp">';
    $val = (string)($p[$k] ?? '');
    if ($f[1] === 'textarea') $o .= '<textarea id="f_' . $k . '" name="' . $k . '" rows="3">' . h($val) . '</textarea>';
    elseif ($f[1] === 'select') {
        $o .= '<select id="f_' . $k . '" name="' . $k . '">';
        foreach ($f[4] as $opt) $o .= '<option' . ($opt === $val ? ' selected' : '') . '>' . h($opt) . '</option>';
        $o .= '</select>';
    } else $o .= '<input id="f_' . $k . '" name="' . $k . '" type="text" value="' . h($f[1] === 'date' ? md_date($val) : $val) . '"' . ($f[1] === 'date' ? ' placeholder="TT.MM.JJJJ"' : '') . '>';
    return $o . '</div>' . md_vis_toggle($k, $vmap) . '</div>';
};
ob_start();
echo '<div class="rowfields"><label>Anrede<select name="salutation">';
foreach (MD_PROFILE_FIELDS['salutation'][4] as $opt) echo '<option' . ($opt === (string)($p['salutation'] ?? '') ? ' selected' : '') . '>' . h($opt) . '</option>';
echo '</select></label><label>Titel<input name="title" value="' . h($p['title'] ?? '') . '"></label><span></span></div>';
echo '<div class="field"><label for="f_email">E-Mail-Adresse</label><div class="inp"><input id="f_email" name="email" type="email" value="' . h($m['email']) . '" autocomplete="email"><span class="muted">Mit dieser Adresse meldest du dich an; Rechnungen und Nachrichten kommen hierhin.</span></div>' . md_vis_toggle('email', $vmap) . '</div>';
foreach ($byGroup['Persönliche Daten'] as $k => $f) if (!in_array($k, ['salutation', 'title'], true)) echo $fieldRow($k, $f);
$personalBlock = $acc('personal', 'Persönliche Daten: ' . h($m['first_name'] . ' ' . $m['last_name']), $inMain(ob_get_clean()));

// ---- Fotos
ob_start();
echo '<p class="muted">Diese Fotos erscheinen im Mitgliederverzeichnis, wenn du sie freigibst (Foto 1 ist das Hauptbild). Sie werden auf höchstens 900 px verkleinert, Standortdaten werden entfernt.</p><div class="photoslots">';
foreach (MD_SLOTS as $sl) {
    $ph = $photos[$sl] ?? null;
    echo '<div class="pslot"><div class="slotimg sm">' . ($ph ? '<img src="photo.php?id=' . (int)$ph['photo_id'] . '" alt="">' : 'frei') . '</div><span class="muted">Foto ' . $sl . '</span>';
    echo '<form method="post" action="save.php" enctype="multipart/form-data">' . $form('photo_upload', 'photos', '<input type="hidden" name="slot" value="' . $sl . '">')
        . '<span class="btn small filebtn">' . ($ph ? 'Ersetzen' : 'Hochladen') . '<input type="file" name="photo" accept="image/jpeg,image/png,image/gif,image/webp" data-autosubmit required title="Foto wählen"></span></form>';
    if ($ph) {
        echo '<form method="post" action="save.php">' . $form('photo_vis', 'photos') . md_vis_toggle("photo.$sl", $vmap, 'im Verzeichnis', true) . '</form>';
        echo '<form method="post" action="save.php" onsubmit="return confirm(\'Foto löschen?\')">' . $form('photo_delete', 'photos', '<input type="hidden" name="slot" value="' . $sl . '">') . '<button class="small danger">Löschen</button></form>';
    }
    echo '</div>';
}
echo '</div>';
$photosBlock = $acc('photos', 'Foto(s) für das Mitgliederverzeichnis', ob_get_clean());

// ---- Beruf & Interessen, Vorstellung
$groupBlocks = '';
foreach (['Beruf & Interessen' => 'work', 'Vorstellung' => 'intro'] as $gname => $aid) {
    ob_start();
    foreach ($byGroup[$gname] as $k => $f) echo $fieldRow($k, $f);
    $groupBlocks .= $acc($aid, h($gname), $inMain(ob_get_clean()));
}

// ---- Adressen
ob_start();
foreach (MD_SLOTS as $sl) {
    $a = $addr[$sl] ?? [];
    echo '<fieldset><legend>Adresse ' . $sl . '</legend><div class="addr">';
    foreach (MD_ADDRESS_FIELDS as $f => $label) echo '<label>' . h($label) . '<input name="address_' . $sl . '_' . $f . '" value="' . h($a[$f] ?? '') . '"></label>';
    echo '</div><div class="row"><span></span>' . md_vis_toggle("address.$sl", $vmap) . '</div></fieldset>';
}
$addrBlock = $acc('addresses', 'Adressen', $inMain(ob_get_clean()));

// ---- Kontakt
ob_start();
foreach (MD_CONTACT_KINDS as $k => $label) echo '<div class="field"><label for="c_' . $k . '">' . h($label) . '</label><div class="inp"><input id="c_' . $k . '" name="contact_' . $k . '" value="' . h($contacts[$k] ?? '') . '"></div>' . md_vis_toggle("contact.$k", $vmap) . '</div>';
$contactBlock = $acc('contacts', 'Kontakt &amp; Social Media', $inMain(ob_get_clean()));

// ---- Gemeinsamer Speichern-Knopf für Persönliche Daten, Beruf & Interessen, Vorstellung, Adressen und Kontakt.
// Die Felder stehen in den Kacheln und gehören über das Attribut form="mainform" zu diesem Formular (so lassen sich Fotos und Verwaltung mit eigenen Formularen dazwischen schalten).
$saveBlock = '';
if ($canEdit) {
    $saveBlock = '<form id="mainform" class="savecard card" method="post" action="save.php">' . $form('all', 'personal')
        . (!$staff ? '<p class="consent">' . h(MD_DIRECTORY_CONSENT) . '</p>' : '<p class="muted">Gilt für Persönliche Daten, Beruf &amp; Interessen, Vorstellung, Adressen und Kontakt.</p>')
        . '<button>Alle Eingaben speichern</button></form>';
}

// ---- Vereinsdaten
ob_start();
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
    foreach (md_q('SELECT * FROM md_groups WHERE active = 1 ORDER BY name') as $g) echo '<option value="' . (int)$g['group_id'] . '">' . h($g['name']) . '</option>';
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
    foreach (md_q('SELECT * FROM md_functions WHERE active = 1 ORDER BY code') as $f) echo '<option value="' . (int)$f['function_id'] . '">' . h($f['code'] . ' – ' . $f['name'] . ' (Level ' . $f['sv_aktiv_level'] . ')') . '</option>';
    echo '</select> <input name="aktiv_level" size="3" placeholder="Level" title="Optional: Level abweichend vom Standard der Funktion (0–19)">';
    if (md_ressorts()) {
        echo ' <select name="ressort_id"><option value="">kein Ressort</option>';
        foreach (md_ressorts() as $rid => $rn) echo '<option value="' . $rid . '">' . h($rn) . '</option>';
        echo '</select>';
    }
    echo ' <button>Funktion zuordnen</button></form>';
}
$clubBlock = $acc('club', 'Vereinsdaten', ob_get_clean());


// ---- Zugang (Verwaltung): Einladung und Passwort-Link
$accessBlock = '';
if ($staff && $canClub && md_can($actor, 'mail', 'edit')) {
    $has = !empty($m['password_hash']);
    $chn = md_one('SELECT letter_channel FROM md_profiles WHERE member_id = ?', [$mid])['letter_channel'] ?? 'post';
    ob_start();
    echo '<p>Passwort: <strong>' . ($has ? 'gesetzt' : 'noch nicht gesetzt') . '</strong> · E-Mail: ' . (md_mail_address_of($mid) ? h($m['email']) : '<span class="bad">keine gültige Adresse</span>') . ' · Rechnungen und Mahnungen: ' . ($chn === 'mail' ? 'per E-Mail' : 'per Post') . '</p>';
    if (md_mail_address_of($mid)) echo '<form method="post" action="save.php">' . $form('invite', 'access') . '<button>' . ($has ? 'Link zum Zurücksetzen senden' : 'Einladung senden') . '</button> <span class="muted">Das Mitglied legt das Passwort selbst fest; die Verwaltung sieht es nie.</span></form>';
    $accessBlock = $acc('access', 'Zugang', ob_get_clean());
}


// ---- Finanzen
$financeBlock = '';
if ($showFinance || md_can($actor, 'fees', 'view') || md_can($actor, 'sepa', 'view')) {
ob_start();
if (!$staff || md_can($actor, 'fees', 'view')) {
    $fa = md_fee_assignment($mid, date('Y-m-d'), date('Y-m-d'));
    $feeHist = md_q('SELECT f.*, g.name, g.amount_cents, g.rhythm FROM md_member_fees f JOIN md_fee_groups g ON g.group_id = f.group_id WHERE f.member_id = ? ORDER BY f.valid_from DESC, f.fee_id DESC LIMIT 6', [$mid]);
    echo '<h3>Beitrag</h3>';
    if ($fa) {
        $amt = $fa['amount_override_cents'] !== null ? (int)$fa['amount_override_cents'] : (int)$fa['group_cents'];
        echo '<p><strong>' . h($fa['group_name']) . '</strong> · ' . md_money($amt) . ' ' . h(MD_RHYTHMS[$fa['rhythm']]) . ($fa['discount_percent'] !== null ? ', ' . (int)$fa['discount_percent'] . ' % Ermäßigung' : '') . ' <span class="muted">seit ' . h(md_date($fa['valid_from'])) . '</span></p>';
        if ($fa['reason'] && ($fa['amount_override_cents'] !== null || $fa['discount_percent'] !== null)) echo '<p class="muted">Abweichung: ' . h($fa['reason']) . ($fa['approved_by'] ? ' (bewilligt von ' . h($fa['approved_by']) . ')' : '') . '</p>';
    } else echo '<p class="muted">Keine Beitragsgruppe zugeordnet.</p>';
    if ($staff && md_can($actor, 'fees', 'edit')) {
        echo '<details class="acc inner"><summary>Beitragsgruppe zuordnen oder ändern</summary><div class="accbody"><form method="post" action="save.php">' . $form('fee_assign', 'finance') . '<div class="addr"><label>Gruppe<select name="group_id">';
        foreach (md_fee_groups(true) as $g) echo '<option value="' . (int)$g['group_id'] . '"' . ($fa && (int)$fa['group_id'] === (int)$g['group_id'] ? ' selected' : '') . '>' . h($g['name'] . ' (' . md_money((int)$g['amount_cents']) . ' ' . MD_RHYTHMS[$g['rhythm']] . ')') . '</option>';
        echo '</select></label><label>Gültig ab<input name="valid_from" value="' . date('d.m.Y') . '"></label><label>Abweichender Betrag in €<input name="override" placeholder="optional"></label><label>oder Ermäßigung in %<input name="discount" size="4" placeholder="optional"></label>'
            . '<label>Grund (bei Abweichung Pflicht)<input name="reason"></label><label>Bewilligt von<input name="approved_by" placeholder="Name/Funktion"></label></div><button>Speichern</button></form></div></details>';
    }
    if (count($feeHist) > 1) { echo '<p class="muted">Bisher: '; foreach (array_slice($feeHist, 1) as $fh) echo h($fh['name']) . ' (' . h(md_date($fh['valid_from'])) . ($fh['valid_to'] ? ' – ' . h(md_date($fh['valid_to'])) : '') . ') '; echo '</p>'; }
}


// ---- Lastschrift (eigene Daten bzw. Verwaltung mit Recht "SEPA")
if (!$staff || md_can($actor, 'sepa', 'view')) {
    $mandates = md_q('SELECT * FROM md_sepa_mandates WHERE member_id = ? ORDER BY mandate_id DESC', [$mid]);
    if ($mandates || ($staff && md_can($actor, 'sepa', 'edit'))) {
        echo '<h3>Lastschrift</h3>';
        if (!$mandates) echo '<p class="muted">Kein Lastschriftmandat. Beiträge werden per Überweisung bezahlt.</p>';
        foreach ($mandates as $mn) {
            [$usable, $why] = md_sepa_mandate_usable($mn, date('Y-m-d'));
            echo '<p' . ($mn['status'] === 'revoked' ? ' class="muted"' : '') . '><strong>' . h($mn['mandate_ref']) . '</strong> · IBAN …' . h($mn['iban_last4']) . ' · unterschrieben ' . h(md_date($mn['signed_on'])) . ' · ' . h(MD_SEPA_STATUS[$mn['status']])
                . ($mn['status_reason'] ? ' (' . h($mn['status_reason']) . ')' : '') . ($mn['status'] === 'active' && !$usable ? ' <span class="bad">– ' . h($why) . '</span>' : '') . '</p>';
        }
        if ($staff && md_can($actor, 'sepa', 'edit')) echo '<p><a href="admin/sepa.php#mandates">Mandate verwalten</a></p>';
        elseif (!$staff && $mandates) echo '<p class="muted">Zum Ändern oder Widerrufen des Mandats wende dich bitte an die Verwaltung.</p>';
    }
}


if ($showFinance) {
echo '<h3>Übersicht ' . $year . '</h3><div class="kpis">';
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
}
$financeBlock = $acc('finance', ($staff ? 'Finanzen ' : 'Meine Finanzen ') . $year, ob_get_clean(), false, $fin['open_claims'] > 0 ? 'offen: ' . md_money($fin['open_claims']) : '');
}

// ---- Protokoll
ob_start();
echo '<table><thead><tr><th>Wann</th><th>Was</th><th>Alt</th><th>Neu</th><th>Wer</th></tr></thead><tbody>';
foreach ($changes as $c) {
    $who = $c['changed_via'] === 'admin' ? 'Admin ' . trim($c['first_name'] . ' ' . $c['last_name']) : ['import' => 'Import', 'system' => 'System (Tagesabgleich)'][$c['changed_via']] ?? 'selbst';
    $long = fn($v) => $v === null ? '–' : (mb_strlen($v) > 60 ? mb_substr($v, 0, 60) . '…' : $v);
    echo '<tr><td>' . h(date('d.m.Y H:i', strtotime($c['changed_at']))) . '</td><td>' . h(md_field_label($c['field_key'])) . '</td><td>' . h($long($c['old_value'])) . '</td><td>' . h($long($c['new_value'])) . '</td><td>' . h($who) . '</td></tr>';
}
if (!$changes) echo '<tr><td colspan="5" class="muted">Noch keine Änderungen.</td></tr>';
echo '</tbody></table>';
$logBlock = $acc('log', 'Änderungsprotokoll', ob_get_clean());

// ---- DSGVO Datenauskunft – nur für die eigenen Daten, unabhängig von der Zustimmung
$privacyBlock = '';
if (!$staff) {
    ob_start();
    $myReq = md_q("SELECT * FROM md_privacy_requests WHERE member_id = ? ORDER BY request_id DESC LIMIT 5", [$mid]);
    $openErase = false;
    foreach ($myReq as $rq) if ($rq['kind'] === 'erasure' && $rq['status'] === 'open') $openErase = true;
    echo '<p>Du kannst jederzeit alle über dich gespeicherten Daten ansehen oder herunterladen:</p><p>'
        . '<a class="btn small" href="privacy_export.php?format=html" target="_blank">Ansehen und drucken</a> <a class="btn small secondary" href="privacy_export.php?format=json">JSON-Datei</a>' . (class_exists('ZipArchive') ? ' <a class="btn small secondary" href="privacy_export.php?format=zip">ZIP mit Fotos</a>' : '') . '</p>';
    if ($openErase) echo '<div class="flash warn">Dein Löschantrag liegt der Verwaltung vor.</div>';
    else echo '<details class="acc inner"><summary>Löschung meiner Daten beantragen</summary><div class="accbody"><p class="muted">Die Verwaltung prüft den Antrag und antwortet innerhalb eines Monats. Während der Mitgliedschaft und aufgrund von Aufbewahrungspflichten (z. B. Buchungen, Spendenbescheinigungen) können nicht alle Daten sofort gelöscht werden.</p>'
        . '<form method="post" action="save.php">' . $form('privacy_request', 'privacy') . '<label class="block">Anmerkung (optional)<input name="note"></label><button>Löschung beantragen</button></form></div></details>';
    if ($myReq) {
        echo '<ul class="plain">';
        foreach ($myReq as $rq) echo '<li class="muted">' . ($rq['kind'] === 'access' ? 'Auskunft' : 'Löschantrag') . ' vom ' . h(md_date(substr($rq['received_at'], 0, 10))) . ': ' . ['open' => 'offen', 'done' => 'erledigt', 'refused' => 'abgelehnt'][$rq['status']] . ($rq['response_note'] ? ' – ' . h($rq['response_note']) : '') . '</li>';
        echo '</ul>';
    }
    $privacyBlock = $acc('privacy', 'DSGVO Datenauskunft', ob_get_clean());
}

// ======================================================================= Ausgabe
md_header('Dashboard', $actor, 'dashboard');
md_show_flash();
// Einmaliger Hinweis, solange die Einwilligung zum aktuellen Wortlaut fehlt (nach dem Login auf dem Dashboard)
if (!$staff && !md_directory_consent_given($mid)) {   // auch ohne Zustimmung zu den Nutzungsbedingungen: Daten können aus einer Übernahme vorliegen
    echo '<div class="flash warn consentnote"><strong>Bitte bestätige deine Einwilligung zur Datenverwendung.</strong>'
        . '<p><em>Grund:</em> Die Vereinsverwaltung holt für das Mitgliederverzeichnis und die Verwendung deiner Daten eine ausdrückliche Einwilligung nach der DSGVO ein. '
        . 'Sie fehlt bei dir noch, weil du deine Angaben seit dieser Regelung nicht gespeichert hast (Angaben können auch aus einer Datenübernahme vorliegen). Du kannst sie jederzeit widerrufen, indem du Angaben löschst oder die Sichtbarkeit zurücknimmst.</p>'
        . '<p class="consent">' . h(MD_DIRECTORY_CONSENT) . '</p>'
        . '<form method="post" action="save.php">' . $form('directory_consent', 'personal') . '<button>Einwilligung bestätigen</button> '
        . '<span class="muted">oder unten Angaben prüfen und „Alle Eingaben speichern“ wählen.</span></form></div>';
}
if (!$staff) echo md_portal_html($actor);
if ($staff) {
    echo '<div class="flash warn">Verwaltungsmodus: ' . ($canEdit ? 'Du bearbeitest' : 'Du siehst (nur lesend)') . ' die Daten von <strong>' . h($m['first_name'] . ' ' . $m['last_name'])
        . '</strong>.' . ($canEdit ? ' Änderungen werden als „Admin“ protokolliert.' : '') . ($masked ? " $masked Angabe(n), die nicht im Verzeichnis freigegeben sind, sind für dich ausgeblendet." : '') . ' <a href="admin/index.php">zur Liste</a></div>';
} elseif (!$ownConsent) {
    echo '<div class="flash warn"><strong>Nur Ansicht:</strong> Solange du den Nutzungsbedingungen nicht zugestimmt hast, kannst du nichts ändern und siehst nur deine eigenen Daten.</div>';
}
echo $headBlock;
echo '<p class="toolbar"><button type="button" class="small secondary" id="toggleAll">Alle ein-/ausklappen</button></p>';
if (!$termsOk) echo $termsBlock;
echo $eventsBlock;
echo '<fieldset class="roset"' . ($canEdit ? '' : ' disabled') . '>' . $personalBlock . $photosBlock . $groupBlocks . $addrBlock . $contactBlock . $saveBlock . $clubBlock . $accessBlock . '</fieldset>';
echo $financeBlock . $logBlock;
if ($termsOk) echo $termsBlock;
echo $privacyBlock;
md_footer();
