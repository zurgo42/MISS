<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('settings', 'edit');
$aid = (int)$me['member_id'];
$allFunctions = md_q('SELECT * FROM md_functions ORDER BY sv_aktiv_level DESC, code');
$functions = array_values(array_filter($allFunctions, fn($f) => (int)$f['active'] === 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    $act = $_POST['action'] ?? '';
    $pdo = md_db();
    $pdo->beginTransaction();
    try {
        if ($act === 'permissions') {
            $old = md_permission_matrix();
            $subjects = array_merge(['*'], array_column($functions, 'code'));
            $changes = [];
            $ph = implode(',', array_fill(0, count($subjects), '?'));
            md_exec("DELETE FROM md_permissions WHERE subject IN ($ph)", $subjects);
            foreach ($subjects as $sub) foreach (MD_AREAS as $area => [, $allowed]) {
                $lvl = (string)($_POST['perm'][$sub][$area] ?? 'none');
                if (!in_array($lvl, $allowed, true)) $lvl = 'none';
                if ($lvl !== 'none') md_exec('INSERT INTO md_permissions (subject, area, level) VALUES (?,?,?)', [$sub, $area, $lvl]);
                $before = $old[$sub][$area] ?? 'none';
                if ($before !== $lvl) $changes[] = ($sub === '*' ? 'alle' : $sub) . '/' . $area . ': ' . $before . ' → ' . $lvl;
            }
            if ($changes) md_log_change($aid, 'permissions', null, implode('; ', $changes), $aid, 'self');
            md_flash($changes ? count($changes) . ' Änderung(en) an der Rechtetabelle gespeichert.' : 'Keine Änderungen.');
        } elseif ($act === 'visibility') {
            $n = 0;
            $defaults = [];
            foreach (md_visibility_keys() as $k => [, $def]) $defaults[$k] = $def;
            $cur = [];
            foreach (md_q('SELECT field_key, level FROM md_visibility_defaults') as $r) $cur[$r['field_key']] = $r['level'];
            foreach (array_keys($defaults) as $k) {
                $lvl = ($_POST['vis_' . str_replace('.', '__', $k)] ?? '') === 'all' ? 'all' : 'me';
                $was = ($cur[$k] ?? $defaults[$k]) === 'all' ? 'all' : 'me';
                if ($lvl === $was) continue;
                md_exec('DELETE FROM md_visibility_defaults WHERE field_key = ?', [$k]);
                if ($lvl !== ($defaults[$k] === 'all' ? 'all' : 'me')) md_exec('INSERT INTO md_visibility_defaults (field_key, level) VALUES (?,?)', [$k, $lvl]);
                md_log_change($aid, 'settings', 'Standard-Sichtbarkeit ' . $k . ': ' . ($was === 'all' ? 'ja' : 'nein'), $lvl === 'all' ? 'ja' : 'nein', $aid, 'self');
                $n++;
            }
            md_flash($n ? "$n Standardwert(e) geändert. Sie gelten für alle, die für das Feld nichts selbst gewählt haben." : 'Keine Änderungen.');
        } elseif ($act === 'general') {
            $freib = md_parse_amount((string)($_POST['ueb'] ?? ''));
            $akt = (int)($_POST['default_aktiv'] ?? 0);
            if ($freib && $akt >= 0 && $akt <= 19) {
                foreach ([['ueb_freibetrag_cents', (string)$freib, 'Jahresfreibetrag Übungsleiterpauschale in Cent (bitte jährlich prüfen)'], ['default_aktiv', (string)$akt, 'aktiv-Level für Mitglieder ohne gültige Funktion (nur bei Mitgliedern mit Funktionszuordnung)']] as [$k, $v, $d]) {
                    $old = md_setting($k, '');
                    if ($old === $v) continue;
                    if (md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', [$k])) md_exec('UPDATE md_settings SET setting_value = ? WHERE setting_key = ?', [$v, $k]);
                    else md_exec('INSERT INTO md_settings (setting_key, setting_value, description) VALUES (?,?,?)', [$k, $v, $d]);
                    md_log_change($aid, 'settings', "$k: $old", $v, $aid, 'self');
                }
                md_flash('Einstellungen gespeichert.');
            } else md_flash('Bitte gültige Werte eingeben (Freibetrag in €, Level 0–19).', 'err');
        } elseif ($act === 'logo' || $act === 'logo_remove') {
            $oldFile = md_setting('club.logo_file', '');
            $new = '';
            $lerr = '';
            if ($act === 'logo') {
                $f = $_FILES['logo'] ?? [];
                $info = ($f['error'] ?? 1) === UPLOAD_ERR_OK && $f['size'] <= 2 * 1024 * 1024 ? @getimagesize($f['tmp_name']) : false;
                if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) $lerr = 'Bitte ein Bild (PNG, JPEG, GIF oder WebP) bis 2 MB wählen.';
                else {
                    $src = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
                    if (!$src) $lerr = 'Bild nicht lesbar.';
                    else {   // neu kodieren (entfernt Zusatzdaten), höchstens 400 px
                        [$w, $h] = [imagesx($src), imagesy($src)];
                        $sc = min(1, 400 / max($w, $h));
                        $dst = imagecreatetruecolor(max(1, (int)round($w * $sc)), max(1, (int)round($h * $sc)));
                        imagealphablending($dst, false); imagesavealpha($dst, true);
                        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
                        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
                        $new = 'logo_' . bin2hex(random_bytes(4)) . '.png';
                        if (!is_dir(MD_STORAGE_DIR)) mkdir(MD_STORAGE_DIR, 0775, true);
                        imagepng($dst, MD_STORAGE_DIR . '/' . $new);
                    }
                }
            }
            if ($lerr) md_flash($lerr, 'err');
            else {
                if (md_one("SELECT 1 FROM md_settings WHERE setting_key = 'club.logo_file'")) md_exec("UPDATE md_settings SET setting_value = ? WHERE setting_key = 'club.logo_file'", [$new]);
                else md_exec("INSERT INTO md_settings (setting_key, setting_value, description) VALUES ('club.logo_file', ?, 'Logo des Vereins (Datei in storage)')", [$new]);
                if ($oldFile !== '') @unlink(MD_STORAGE_DIR . '/' . basename($oldFile));
                md_log_change($aid, 'settings', 'Logo', $new !== '' ? 'hochgeladen' : 'entfernt', $aid, 'self');
                md_flash($new !== '' ? 'Logo gespeichert.' : 'Logo entfernt (Standardzeichen).');
            }
        } elseif ($act === 'club') {
            $changed = 0;
            foreach (MD_CLUB_FIELDS as $k => [$label]) {
                $v = trim((string)($_POST['club'][$k] ?? ''));
                if ($k === 'exemption_kind' && !in_array($v, ['freistellung', 'feststellung'], true)) $v = 'feststellung';
                if ($k === 'exemption_date' && $v !== '') $v = md_parse_date($v) ? md_date(md_parse_date($v)) : $v;
                $v = mb_substr($v, 0, 255);
                $old = md_setting('club.' . $k, '');
                if ($old === $v) continue;
                if (md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', ['club.' . $k])) md_exec('UPDATE md_settings SET setting_value = ? WHERE setting_key = ?', [$v, 'club.' . $k]);
                else md_exec('INSERT INTO md_settings (setting_key, setting_value, description) VALUES (?,?,?)', ['club.' . $k, $v, 'Angaben für Spendenbescheinigungen: ' . $label]);
                md_log_change($aid, 'settings', "$label: $old", $v, $aid, 'self');
                $changed++;
            }
            $pr = md_club_problems(md_club());
            md_flash($changed ? 'Vereinsangaben gespeichert.' . ($pr ? ' Noch offen: ' . implode('; ', $pr) . '.' : ' Spendenbescheinigungen sind freigegeben.') : 'Keine Änderungen.', ($changed && !$pr) || !$changed ? 'ok' : 'warn');
        } elseif ($act === 'org') {
            $changed = 0;
            $keys = [];
            foreach (MD_ORG_FIELDS as $k => [$label]) $keys['org.' . $k] = [$label, mb_substr(trim((string)($_POST['org'][$k] ?? '')), 0, 255)];
            $keys['org.impressum_extra'] = ['Weitere Angaben im Impressum', mb_substr(trim((string)($_POST['org']['impressum_extra'] ?? '')), 0, 4000)];
            $keys['org.privacy_text'] = ['Datenschutzerklärung', mb_substr(trim((string)($_POST['org']['privacy_text'] ?? '')), 0, 60000)];
            $keys['footer.copyright'] = ['Fußzeile (Copyright)', mb_substr(trim((string)($_POST['org']['copyright'] ?? '')), 0, 255)];
            $keys['footer.impressum_url'] = ['Impressum: externe Adresse', mb_substr(trim((string)($_POST['org']['impressum_url'] ?? '')), 0, 255)];
            $keys['footer.privacy_url'] = ['Datenschutz: externe Adresse', mb_substr(trim((string)($_POST['org']['privacy_url'] ?? '')), 0, 255)];
            $bad = '';
            foreach (['footer.impressum_url', 'footer.privacy_url'] as $uk) if ($keys[$uk][1] !== '' && !preg_match('~^(https?://|/)~i', $keys[$uk][1])) $bad = 'Externe Adressen müssen mit http:// oder https:// beginnen.';
            if ($keys['org.email'][1] !== '' && !filter_var($keys['org.email'][1], FILTER_VALIDATE_EMAIL)) $bad = 'E-Mail des Vereins ist ungültig.';
            if ($keys['org.name'][1] === '') $bad = 'Der Vereinsname darf nicht leer sein.';
            if ($bad) md_flash($bad, 'err');
            else {
                foreach ($keys as $key => [$label, $v]) {
                    $old = md_setting($key, '');
                    if ($old === $v) continue;
                    if (md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', [$key])) md_exec('UPDATE md_settings SET setting_value = ? WHERE setting_key = ?', [$v, $key]);
                    else md_exec('INSERT INTO md_settings (setting_key, setting_value, description) VALUES (?,?,?)', [$key, $v, $label]);
                    md_log_change($aid, 'settings', "$label: " . mb_substr($old, 0, 40), mb_substr($v, 0, 40), $aid, 'self');
                    $changed++;
                }
                md_flash($changed ? 'Angaben gespeichert.' : 'Keine Änderungen.');
            }
        } elseif ($act === 'bank_save') {
            $err = md_bank_account_save((int)($_POST['account_id'] ?? 0) ?: null, $_POST);
            md_flash($err ?: 'Konto gespeichert.', $err ? 'err' : 'ok');
        } elseif ($act === 'feegroup_save') {
            $err = md_fee_group_save((int)($_POST['group_id'] ?? 0) ?: null, $_POST);
            md_flash($err ?: 'Mitgliedsart gespeichert. Änderungen wirken auf künftige Beitragsläufe.', $err ? 'err' : 'ok');
        } elseif ($act === 'function_save') {
            $n = 0;
            foreach (md_q('SELECT * FROM md_functions') as $f) {
                $id = (int)$f['function_id'];
                if (!isset($_POST['fn'][$id])) continue;
                $in = $_POST['fn'][$id];
                $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 100);
                $lvl = (string)($in['level'] ?? '');
                $act2 = !empty($in['active']) ? 1 : 0;
                if ($name === '' || !ctype_digit($lvl) || (int)$lvl > 19) { md_flash('Funktion ' . $f['code'] . ': Name und Level 0–19 angeben.', 'err'); continue; }
                if ($name !== $f['name'] || (int)$lvl !== (int)$f['sv_aktiv_level'] || $act2 !== (int)$f['active']) {
                    md_exec('UPDATE md_functions SET name = ?, sv_aktiv_level = ?, active = ? WHERE function_id = ?', [$name, (int)$lvl, $act2, $id]);
                    md_log_change($aid, 'settings', 'Funktion ' . $f['code'] . ': ' . $f['name'] . ', Level ' . $f['sv_aktiv_level'] . ((int)$f['active'] ? '' : ' (aus)'), $name . ', Level ' . (int)$lvl . ($act2 ? '' : ' (aus)'), $aid, 'self');
                    $n++;
                }
            }
            if ($n) md_flash("$n Funktion(en) geändert. Bereits zugeordnete bleiben gültig; abgewählte werden nur nicht mehr zur Auswahl angeboten. Der nächste Abgleich übernimmt geänderte Level in die Sitzungsverwaltung.");
            else md_flash('Keine Änderungen.');
        } elseif ($act === 'function_add') {
            $code = trim($_POST['code'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $lvl = (int)($_POST['level'] ?? 0);
            if (preg_match('/^[A-Za-z]{1,10}$/', $code) && $name !== '' && $lvl >= 0 && $lvl <= 19 && !md_one('SELECT 1 FROM md_functions WHERE LOWER(code) = ?', [strtolower($code)])) {
                md_exec('INSERT INTO md_functions (code, name, sv_aktiv_level, active) VALUES (?,?,?,1)', [$code, $name, $lvl]);
                md_flash("Funktion $code angelegt. Rechte dafür stehen in der Rechtetabelle.");
            } else md_flash('Code (1–10 Buchstaben, eindeutig), Name und Level 0–19 angeben.', 'err');
        } elseif ($act === 'group_save') {
            $n = 0;
            foreach (md_q('SELECT * FROM md_groups') as $g) {
                $id = (int)$g['group_id'];
                if (!isset($_POST['gr'][$id])) continue;
                $in = $_POST['gr'][$id];
                $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
                $kind = in_array($in['kind'] ?? '', ['region', 'interesse', 'projekt'], true) ? $in['kind'] : $g['kind'];
                $on = !empty($in['active']) ? 1 : 0;
                if ($name === '') { md_flash('Teilgruppe: Der Name darf nicht leer sein.', 'err'); continue; }
                if (md_one('SELECT 1 FROM md_groups WHERE LOWER(name) = ? AND group_id <> ?', [mb_strtolower($name), $id])) { md_flash("Es gibt schon eine Teilgruppe „$name“.", 'err'); continue; }
                if ($name !== $g['name'] || $kind !== $g['kind'] || $on !== (int)$g['active']) {
                    md_exec('UPDATE md_groups SET name = ?, kind = ?, active = ? WHERE group_id = ?', [$name, $kind, $on, $id]);
                    md_log_change($aid, 'settings', 'Teilgruppe ' . $g['name'] . ((int)$g['active'] ? '' : ' (aus)'), $name . ($on ? '' : ' (aus)'), $aid, 'self');
                    $n++;
                }
            }
            md_flash($n ? "$n Teilgruppe(n) geändert. Mitgliedschaften bleiben bestehen; abgewählte Gruppen werden nur nicht mehr zur Auswahl angeboten." : 'Keine Änderungen.');
        } elseif ($act === 'group_add') {
            $name = trim($_POST['name'] ?? '');
            $kind = in_array($_POST['kind'] ?? '', ['region', 'interesse', 'projekt'], true) ? $_POST['kind'] : 'interesse';
            if ($name !== '' && !md_one('SELECT 1 FROM md_groups WHERE LOWER(name) = ?', [mb_strtolower($name)])) { md_exec('INSERT INTO md_groups (name, kind, active) VALUES (?,?,1)', [$name, $kind]); md_flash('Teilgruppe angelegt.'); }
            else md_flash('Name fehlt oder Gruppe existiert schon.', 'err');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('md settings: ' . $e->getMessage());
        md_flash('Speichern fehlgeschlagen.', 'err');
    }
    header('Location: ' . md_url('admin/settings.php'));
    exit;
}

$matrix = md_permission_matrix();
$org = md_org();
$acc = fn(string $id, string $title, string $body, string $sub = '', bool $open = false) => '<details class="acc" id="' . $id . '"' . ($open ? ' open' : '') . '><summary>' . $title . ($sub !== '' ? '<span class="sub">' . $sub . '</span>' : '') . '</summary><div class="accbody">' . $body . '</div></details>';
$form = fn(string $action, string $inner, string $extra = '') => '<form method="post"' . $extra . '>' . md_csrf_field() . '<input type="hidden" name="action" value="' . $action . '">' . $inner . '</form>';
md_header('Administration', $me, 'settings');
md_show_flash();
echo '<h1>Administration</h1><p class="muted">Hier stehen alle Angaben, die in jedem Verein anders heißen oder geregelt sind. <a href="#" onclick="document.querySelectorAll(\'details.acc\').forEach(function(d){d.open=!d.open});return false">Alle ein-/ausklappen</a></p>';

// ---- Verein: Name, Anschrift, Impressum, Datenschutzerklärung, Fußzeile
ob_start();
echo '<p class="muted">Name und Anschrift erscheinen in der Titelzeile, auf Rechnungen und Mahnungen, in E-Mails und im Impressum. Für Spendenbescheinigungen gelten die getrennten Angaben weiter unten.</p><div class="addr">';
foreach (MD_ORG_FIELDS as $k => [$label, $req, $hint]) echo '<label>' . h($label) . ($req ? ' *' : '') . '<input name="org[' . $k . ']" value="' . h($org[$k]) . '" placeholder="' . h($hint) . '"></label>';
echo '</div><label class="block">Weitere Angaben im Impressum (z. B. Umsatzsteuer-ID, Aufsichtsbehörde)<textarea name="org[impressum_extra]" rows="3">' . h(md_setting('org.impressum_extra', '')) . '</textarea></label>'
    . '<label class="block">Datenschutzerklärung (Text; Absätze durch Leerzeilen trennen). Wird auf der öffentlichen Seite <a href="datenschutz.php" target="_blank">datenschutz.php</a> gezeigt.<textarea name="org[privacy_text]" rows="12">' . h(md_setting('org.privacy_text', '')) . '</textarea></label>'
    . '<h3>Fußzeile</h3><div class="addr"><label>Copyright-Zeile<input name="org[copyright]" value="' . h(md_footer_setting('footer.copyright')) . '" placeholder="© ' . h($org['name']) . '"></label>'
    . '<label>Impressum: eigene Adresse statt der Seite oben (optional)<input name="org[impressum_url]" value="' . h(md_footer_setting('footer.impressum_url')) . '" placeholder="https://…"></label>'
    . '<label>Datenschutz: eigene Adresse statt der Seite oben (optional)<input name="org[privacy_url]" value="' . h(md_footer_setting('footer.privacy_url')) . '" placeholder="https://…"></label></div>'
    . (defined('MD_FOOTER_COPYRIGHT') || defined('MD_FOOTER_IMPRESSUM_URL') || defined('MD_FOOTER_PRIVACY_URL') ? '<p class="muted">Die Felder sind aus der Voreinstellung der config.php vorbelegt. Leeres Feld = Voreinstellung.</p>' : '')
    . '<button>Speichern</button>';
$body = $form('org', ob_get_clean());
$body .= '<h3>Logo</h3><p class="muted">Erscheint links in der Titelzeile und auf der Anmeldeseite (PNG, JPEG, GIF oder WebP bis 2 MB, wird auf höchstens 400 px verkleinert).</p><p>' . md_logo_html() . ' ' . (md_setting('club.logo_file', '') !== '' ? 'eigenes Logo' : 'Standardzeichen (noch kein Logo hochgeladen)') . '</p>'
    . '<form method="post" enctype="multipart/form-data">' . md_csrf_field() . '<input type="hidden" name="action" value="logo"><input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" required> <button class="small">Hochladen</button></form>'
    . (md_setting('club.logo_file', '') !== '' ? '<form method="post" style="margin-top:6px">' . md_csrf_field() . '<input type="hidden" name="action" value="logo_remove"><button class="small danger">Logo entfernen</button></form>' : '');
echo $acc('verein', 'Verein, Impressum und Datenschutzerklärung', $body, h($org['name']), true);

// ---- Vereinskonten
ob_start();
$accts = md_q('SELECT * FROM md_bank_accounts ORDER BY account_id');
echo '<p class="muted">Ein Konto dient für Rechnungen, Mahnungen und Lastschrift („Beitragskonto“), eines kann als Spendenkonto gekennzeichnet sein. Der Kontoinhaber ist der Zahlungsempfänger auf Rechnungen und im Lastschriftlauf und darf vom Vereinsnamen abweichen.</p>';
foreach ($accts as $a) {
    echo '<form method="post" class="acctform">' . md_csrf_field() . '<input type="hidden" name="action" value="bank_save"><input type="hidden" name="account_id" value="' . (int)$a['account_id'] . '"><div class="addr">'
        . '<label>Bezeichnung<input name="label" value="' . h($a['label']) . '"></label><label>Kontoinhaber (Zahlungsempfänger)<input name="holder" value="' . h($a['holder']) . '"></label><label>IBAN<input name="iban" value="' . h($a['iban']) . '"></label><label>BIC<input name="bic" value="' . h($a['bic']) . '" size="12"></label><label>Bank<input name="bank" value="' . h($a['bank']) . '"></label></div>'
        . '<label class="check"><input type="checkbox" name="use_invoice" value="1"' . ($a['use_invoice'] ? ' checked' : '') . '> Beitragskonto (Rechnungen, Mahnungen, Lastschrift)</label> <label class="check"><input type="checkbox" name="use_donation" value="1"' . ($a['use_donation'] ? ' checked' : '') . '> Spendenkonto</label> '
        . '<label class="check"><input type="checkbox" name="active" value="1"' . ($a['active'] ? ' checked' : '') . '> aktiv</label> <button class="small">Speichern</button></form><hr>';
}
if (!$accts) echo '<p class="muted">Noch kein Konto eingetragen.</p>';
echo '<h3>Neues Konto</h3>' . $form('bank_save', '<div class="addr"><label>Bezeichnung<input name="label" placeholder="z. B. Girokonto"></label><label>Kontoinhaber<input name="holder"></label><label>IBAN<input name="iban"></label><label>BIC<input name="bic" size="12"></label><label>Bank<input name="bank"></label></div>'
    . '<label class="check"><input type="checkbox" name="use_invoice" value="1"' . ($accts ? '' : ' checked') . '> Beitragskonto</label> <label class="check"><input type="checkbox" name="use_donation" value="1"> Spendenkonto</label> <button class="small">Konto anlegen</button>');
echo $acc('konten', 'Vereinskonten', ob_get_clean(), count($accts) . ' Konto/Konten');

// ---- Spendenbescheinigungen: Zahlungsempfänger unabhängig vom Vereinsnamen
$club = md_club();
$cp = md_club_problems($club);
ob_start();
echo '<p class="muted">Diese Angaben stehen auf jeder Zuwendungsbestätigung und sind unabhängig vom Vereinsnamen oben (z. B. für einen gemeinnützigen Unterverein als Spendenempfänger). Sie werden bei der Ausstellung festgehalten; spätere Änderungen betreffen nur neue Bestätigungen. Wortlaut und Pflichtangaben folgen dem amtlichen Muster; bitte einmal vom Steuerberater prüfen lassen. '
    . 'Der Bescheid darf bei der Ausstellung nicht zu alt sein (Freistellung: 5 Jahre, Feststellung nach § 60a AO: 3 Jahre – nach meinem Kenntnisstand, bitte bestätigen lassen).</p>';
$don = md_bank_account('donation');
echo '<p>Spendenkonto: ' . ($don ? '<strong>' . h(($don['holder'] ?: $don['label']) . ', ' . md_iban_mask($don['iban'])) . '</strong>' : '<span class="muted">keines gekennzeichnet (unter „Vereinskonten“ festlegen)</span>') . '</p>';
if ($cp) { echo '<div class="flash warn"><ul>'; foreach ($cp as $x) echo '<li>' . h($x) . '</li>'; echo '</ul></div>'; } else echo '<div class="flash ok">Angaben vollständig, Bestätigungen können ausgestellt werden.</div>';
echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="club"><div class="addr">';
foreach (MD_CLUB_FIELDS as $k => [$label, $req, $hint]) {
    if ($k === 'exemption_kind') {
        echo '<label>' . h($label) . ' *<select name="club[exemption_kind]"><option value="feststellung"' . ($club[$k] === 'feststellung' ? ' selected' : '') . '>Feststellung nach § 60a AO</option><option value="freistellung"' . ($club[$k] === 'freistellung' ? ' selected' : '') . '>Freistellungsbescheid / Anlage zum KSt-Bescheid</option></select></label>';
        continue;
    }
    echo '<label>' . h($label) . ($req ? ' *' : '') . '<input name="club[' . $k . ']" value="' . h($club[$k]) . '" placeholder="' . h($hint) . '"></label>';
}
echo '</div><button>Speichern</button></form>';
echo $acc('club', 'Spendenbescheinigungen: Zahlungsempfänger und Bescheid', ob_get_clean(), $cp ? 'unvollständig' : 'vollständig');

// ---- Mitgliedsarten und Beiträge
ob_start();
echo '<p class="muted">Mitgliedsarten mit ihrem Beitrag. Änderungen wirken auf künftige Beitragsläufe; die Zuordnung zu Mitgliedern erfolgt im Dashboard des Mitglieds, der Beitragslauf unter Beiträge. „Online wählbar“: erscheint im Formular „Mitglied werden“.</p>';
$fg = md_q('SELECT g.*, (SELECT COUNT(*) FROM md_member_fees f WHERE f.group_id = g.group_id AND f.valid_to IS NULL) n FROM md_fee_groups g ORDER BY g.name');
$opt = function (array $list, string $cur) { $o = ''; foreach ($list as $k => $l) $o .= '<option value="' . $k . '"' . ($cur === $k ? ' selected' : '') . '>' . h($l) . '</option>'; return $o; };
foreach ($fg as $g) {
    echo '<form method="post" class="acctform">' . md_csrf_field() . '<input type="hidden" name="action" value="feegroup_save"><input type="hidden" name="group_id" value="' . (int)$g['group_id'] . '"><div class="addr">'
        . '<label>Mitgliedsart<input name="name" value="' . h($g['name']) . '"></label><label>Beitrag in €<input name="amount" size="8" value="' . h(number_format($g['amount_cents'] / 100, 2, ',', '')) . '"></label>'
        . '<label>Rhythmus<select name="rhythm">' . $opt(MD_RHYTHMS, $g['rhythm']) . '</select></label><label>Eintritt/Austritt<select name="entry_rule">' . $opt(MD_ENTRY_RULES, $g['entry_rule']) . '</select></label><label>Beschreibung<input name="description" value="' . h($g['description']) . '"></label></div>'
        . '<label class="check"><input type="checkbox" name="active" value="1"' . ($g['active'] ? ' checked' : '') . '> aktiv</label> <label class="check"><input type="checkbox" name="public" value="1"' . ($g['public'] ? ' checked' : '') . '> online wählbar</label> <span class="muted">' . (int)$g['n'] . ' Mitglieder</span> <button class="small">Speichern</button></form><hr>';
}
echo '<h3>Neue Mitgliedsart</h3>' . $form('feegroup_save', '<div class="addr"><label>Mitgliedsart<input name="name"></label><label>Beitrag in €<input name="amount" size="8" placeholder="0 = beitragsfrei"></label><label>Rhythmus<select name="rhythm">' . $opt(MD_RHYTHMS, 'yearly') . '</select></label><label>Eintritt/Austritt<select name="entry_rule">' . $opt(MD_ENTRY_RULES, 'prorated') . '</select></label><label>Beschreibung<input name="description"></label></div><input type="hidden" name="active" value="1"><label class="check"><input type="checkbox" name="public" value="1"> online wählbar</label> <button class="small">Anlegen</button>');
echo $acc('beitraege', 'Mitgliedsarten und Beiträge', ob_get_clean(), count($fg) . ' Mitgliedsarten');

// ---- Funktionen und Level
ob_start();
echo '<p class="muted">Nicht jeder Verein hat jede Funktion: Abgewählte Funktionen werden nicht mehr zur Auswahl angeboten und tauchen in der Rechtetabelle nicht mehr auf; Mitglieder, die sie schon haben, behalten sie. Das Level (0–19) ist der Standardwert für <code>svmembers.aktiv</code> in der Sitzungsverwaltung; je Zuordnung kann es abweichen.</p>';
echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="function_save"><table><thead><tr><th>Aktiv</th><th>Kürzel</th><th>Bezeichnung</th><th>Level</th><th class="r">Inhaber</th></tr></thead><tbody>';
foreach ($allFunctions as $f) {
    $cnt = (int)md_one('SELECT COUNT(*) c FROM md_member_functions WHERE function_id = ? AND (valid_to IS NULL OR valid_to >= ?)', [$f['function_id'], date('Y-m-d')])['c'];
    $id = (int)$f['function_id'];
    echo '<tr' . ((int)$f['active'] ? '' : ' class="muted"') . '><td><input type="checkbox" name="fn[' . $id . '][active]" value="1"' . ((int)$f['active'] ? ' checked' : '') . '></td><td>' . h($f['code']) . '</td><td><input name="fn[' . $id . '][name]" value="' . h($f['name']) . '" size="34"></td>'
        . '<td><input name="fn[' . $id . '][level]" size="3" value="' . (int)$f['sv_aktiv_level'] . '"></td><td class="r">' . $cnt . '</td></tr>';
}
echo '</tbody></table><p><button>Speichern</button></p></form>';
echo '<h3>Neue Funktion</h3>' . $form('function_add', '<div class="addr"><label>Kürzel (1–10 Buchstaben)<input name="code" maxlength="10"></label><label>Bezeichnung<input name="name"></label><label>Level<input name="level" value="9" size="3"></label></div><button class="small">Funktion anlegen</button>');
echo $acc('functions', 'Funktionen und Level', ob_get_clean(), count($functions) . ' aktiv');

// ---- Teilgruppen
ob_start();
echo '<p class="muted">Bezeichnungen frei änderbar. Abgewählte Teilgruppen werden nicht mehr zur Auswahl angeboten; bestehende Mitgliedschaften bleiben erhalten.</p>';
echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="group_save"><table><thead><tr><th>Aktiv</th><th>Bezeichnung</th><th>Art</th><th class="r">Mitglieder</th></tr></thead><tbody>';
$grp = md_q('SELECT g.*, (SELECT COUNT(*) FROM md_member_groups mg WHERE mg.group_id = g.group_id AND (mg.until IS NULL OR mg.until >= ?)) n FROM md_groups g ORDER BY g.name', [date('Y-m-d')]);
foreach ($grp as $g) {
    $id = (int)$g['group_id'];
    echo '<tr' . ((int)$g['active'] ? '' : ' class="muted"') . '><td><input type="checkbox" name="gr[' . $id . '][active]" value="1"' . ((int)$g['active'] ? ' checked' : '') . '></td><td><input name="gr[' . $id . '][name]" value="' . h($g['name']) . '" size="34"></td>'
        . '<td><select name="gr[' . $id . '][kind]">' . $opt(['region' => 'Region', 'interesse' => 'Interesse', 'projekt' => 'Projekt'], $g['kind']) . '</select></td><td class="r">' . (int)$g['n'] . '</td></tr>';
}
echo '</tbody></table><p><button>Speichern</button></p></form>';
echo '<h3>Neue Teilgruppe</h3>' . $form('group_add', '<div class="addr"><label>Bezeichnung<input name="name"></label><label>Art<select name="kind">' . $opt(['region' => 'Region', 'interesse' => 'Interesse', 'projekt' => 'Projekt'], 'interesse') . '</select></label></div><button class="small">Anlegen</button>');
echo $acc('groups', 'Teilgruppen', ob_get_clean(), count(array_filter($grp, fn($g) => (int)$g['active'])) . ' aktiv');

// ---- Rechtetabelle
ob_start();
echo '<p class="muted">Je Funktion und Bereich: keine, ansehen oder bearbeiten. Wirksam ist die höchste Stufe über alle aktuell gültigen Funktionen eines Mitglieds. '
    . 'Die Zeile „Alle Mitglieder“ gilt für jedes Mitglied. Administratoren (Flag <code>is_admin</code> in der Sitzungsverwaltung) dürfen immer alles und können sich nicht aussperren. '
    . 'Wer den Nutzungsbedingungen nicht zugestimmt hat, sieht unabhängig davon nur die eigenen Daten.</p>';
echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="permissions"><div style="overflow-x:auto"><table class="map"><thead><tr><th>Funktion</th>';
foreach (MD_AREAS as [$label]) echo '<th>' . h($label) . '</th>';
echo '</tr></thead><tbody>';
$subjects = [['*', 'Alle Mitglieder']];
foreach ($functions as $f) $subjects[] = [$f['code'], $f['code'] . ' – ' . $f['name']];
foreach ($subjects as [$code, $label]) {
    echo '<tr><td>' . h($label) . '</td>';
    foreach (MD_AREAS as $area => [, $allowed]) {
        $cur = $matrix[$code][$area] ?? 'none';
        echo '<td><select name="perm[' . h($code) . '][' . $area . ']">';
        foreach ($allowed as $lvl) echo '<option value="' . $lvl . '"' . ($cur === $lvl ? ' selected' : '') . '>' . h(MD_PERM_LEVELS[$lvl]) . '</option>';
        echo '</select></td>';
    }
    echo '</tr>';
}
echo '</tbody></table></div><p><button>Rechtetabelle speichern</button></p></form>';
echo $acc('perm', 'Rechte in MISS', ob_get_clean());

// ---- Standard-Sichtbarkeit
$defaults = [];
foreach (md_visibility_keys() as $k => [, $def]) $defaults[$k] = $def;
foreach (md_q('SELECT field_key, level FROM md_visibility_defaults') as $r) $defaults[$r['field_key']] = $r['level'];
ob_start();
echo '<p class="muted">Ob eine Angabe im Mitgliederverzeichnis sichtbar ist, solange das Mitglied nichts anderes gewählt hat. Wählt es später selbst, gilt seine Wahl. Die Verwaltung sieht ohnehin alles.</p><form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="visibility"><div class="addr">';
foreach (md_visibility_keys() as $k => [$label]) echo '<div>' . md_vis_toggle($k, $defaults, $label) . '</div>';
echo '</div><p><button>Standardwerte speichern</button></p></form>';
echo $acc('vis', 'Standard-Sichtbarkeit im Mitgliederverzeichnis', ob_get_clean());

// ---- Allgemein
ob_start();
echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="general"><div class="addr">';
echo '<label>Übungsleiter-Freibetrag pro Jahr in € (bitte jährlich prüfen)<input name="ueb" value="' . h(number_format((int)md_setting('ueb_freibetrag_cents', '330000') / 100, 2, ',', '.')) . '"></label>';
echo '<label>Level für Mitglieder ohne gültige Funktion (nur bei Mitgliedern mit Funktionszuordnung)<input name="default_aktiv" size="3" value="' . (int)md_setting('default_aktiv', '0') . '"></label></div><button>Speichern</button></form>';
echo $acc('general', 'Allgemein', ob_get_clean());
md_footer();
