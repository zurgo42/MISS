<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('settings', 'edit');
$aid = (int)$me['member_id'];
$functions = md_q('SELECT * FROM md_functions ORDER BY sv_aktiv_level DESC, code');

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
            md_exec('DELETE FROM md_permissions');
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
                $lvl = (string)($_POST['vis_' . str_replace('.', '__', $k)] ?? '');
                if (!isset(MD_LEVELS[$lvl])) continue;
                $was = $cur[$k] ?? $defaults[$k];
                if ($lvl === $was) continue;
                md_exec('DELETE FROM md_visibility_defaults WHERE field_key = ?', [$k]);
                if ($lvl !== $defaults[$k]) md_exec('INSERT INTO md_visibility_defaults (field_key, level) VALUES (?,?)', [$k, $lvl]);
                md_log_change($aid, 'settings', 'Standard-Sichtbarkeit ' . $k . ': ' . MD_LEVELS[$was], MD_LEVELS[$lvl], $aid, 'self');
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
        } elseif ($act === 'function_level') {
            foreach ($functions as $f) {
                $v = $_POST['lvl'][$f['function_id']] ?? '';
                if (ctype_digit((string)$v) && (int)$v <= 19 && (int)$v !== (int)$f['sv_aktiv_level']) {
                    md_exec('UPDATE md_functions SET sv_aktiv_level = ? WHERE function_id = ?', [(int)$v, $f['function_id']]);
                    md_log_change($aid, 'settings', 'Level ' . $f['code'] . ': ' . $f['sv_aktiv_level'], (string)(int)$v, $aid, 'self');
                }
            }
            md_flash('Level der Funktionen gespeichert. Der nächste Abgleich (Tagesabgleich oder beim Zuordnen) übernimmt sie in die Sitzungsverwaltung.');
        } elseif ($act === 'function_add') {
            $code = trim($_POST['code'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $lvl = (int)($_POST['level'] ?? 0);
            if (preg_match('/^[A-Za-z]{1,10}$/', $code) && $name !== '' && $lvl >= 0 && $lvl <= 19 && !md_one('SELECT 1 FROM md_functions WHERE LOWER(code) = ?', [strtolower($code)])) {
                md_exec('INSERT INTO md_functions (code, name, sv_aktiv_level) VALUES (?,?,?)', [$code, $name, $lvl]);
                md_flash("Funktion $code angelegt. Rechte dafür stehen oben in der Rechtetabelle.");
            } else md_flash('Code (1–10 Buchstaben, eindeutig), Name und Level 0–19 angeben.', 'err');
        } elseif ($act === 'group_add') {
            $name = trim($_POST['name'] ?? '');
            $kind = in_array($_POST['kind'] ?? '', ['region', 'interesse', 'projekt'], true) ? $_POST['kind'] : 'interesse';
            if ($name !== '' && !md_one('SELECT 1 FROM md_groups WHERE LOWER(name) = ?', [mb_strtolower($name)])) { md_exec('INSERT INTO md_groups (name, kind) VALUES (?,?)', [$name, $kind]); md_flash('Teilgruppe angelegt.'); }
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
md_header('Einstellungen', $me, 'settings');
md_show_flash();
echo '<h1>Einstellungen</h1>';

// ---- Rechtetabelle
echo '<section class="card" id="perm"><h2>Rechte in der Mitgliederverwaltung</h2><p class="muted">Je Funktion und Bereich: keine, ansehen oder bearbeiten. Wirksam ist die höchste Stufe über alle aktuell gültigen Funktionen eines Mitglieds. '
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
echo '</tbody></table></div><p><button>Rechtetabelle speichern</button></p></form></section>';

// ---- Standard-Sichtbarkeit
$defaults = [];
foreach (md_visibility_keys() as $k => [, $def]) $defaults[$k] = $def;
foreach (md_q('SELECT field_key, level FROM md_visibility_defaults') as $r) $defaults[$r['field_key']] = $r['level'];
echo '<section class="card" id="vis"><h2>Standard-Sichtbarkeit</h2><p class="muted">Gilt für jedes Feld, solange das Mitglied nichts anderes gewählt hat. Wählt es später selbst, gilt seine Wahl.</p><form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="visibility"><div class="addr">';
foreach (md_visibility_keys() as $k => [$label]) echo '<label>' . h($label) . md_vis_select($k, $defaults) . '</label>';
echo '</div><button>Standardwerte speichern</button></form></section>';

// ---- Funktionen und Level
echo '<section class="card" id="functions"><h2>Funktionen und Level</h2><p class="muted">Das Level (0–19) ist der Standardwert für <code>svmembers.aktiv</code> in der Sitzungsverwaltung; je Zuordnung kann es abweichen. Siehe <code>docs/RECHTE.md</code>.</p>';
echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="function_level"><table><thead><tr><th>Code</th><th>Name</th><th>Level</th></tr></thead><tbody>';
foreach ($functions as $f) echo '<tr><td>' . h($f['code']) . '</td><td>' . h($f['name']) . '</td><td><input name="lvl[' . (int)$f['function_id'] . ']" size="3" value="' . (int)$f['sv_aktiv_level'] . '"></td></tr>';
echo '</tbody></table><p><button>Level speichern</button></p></form>';
echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="function_add"><div class="addr"><label>Neue Funktion: Code<input name="code" maxlength="10"></label><label>Name<input name="name"></label><label>Level<input name="level" value="9" size="3"></label></div><button>Funktion anlegen</button></form></section>';

// ---- Teilgruppen
echo '<section class="card" id="groups"><h2>Teilgruppen</h2><ul class="plain">';
foreach (md_q('SELECT g.*, (SELECT COUNT(*) FROM md_member_groups mg WHERE mg.group_id = g.group_id) n FROM md_groups g ORDER BY g.name') as $g) echo '<li>' . h($g['name']) . ' <span class="muted">(' . h($g['kind']) . ', ' . (int)$g['n'] . ' Mitglieder)</span></li>';
echo '</ul><form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="group_add"><div class="addr"><label>Neue Teilgruppe<input name="name"></label><label>Art<select name="kind"><option value="region">Region</option><option value="interesse">Interesse</option><option value="projekt">Projekt</option></select></label></div><button>Anlegen</button></form></section>';

// ---- Verein (Spendenbescheinigungen)
$club = md_club();
$cp = md_club_problems($club);
echo '<section class="card" id="club"><h2>Verein (für Spendenbescheinigungen)</h2>';
echo '<p class="muted">Diese Angaben stehen auf jeder Zuwendungsbestätigung und werden bei der Ausstellung festgehalten (spätere Änderungen betreffen nur neue Bestätigungen). Wortlaut und Pflichtangaben folgen dem amtlichen Muster; bitte einmal vom Steuerberater prüfen lassen. '
    . 'Der Bescheid darf bei der Ausstellung nicht zu alt sein (Freistellung: 5 Jahre, Feststellung nach § 60a AO: 3 Jahre – nach meinem Kenntnisstand, bitte bestätigen lassen).</p>';
if ($cp) { echo '<div class="flash warn"><ul>'; foreach ($cp as $x) echo '<li>' . h($x) . '</li>'; echo '</ul></div>'; } else echo '<div class="flash ok">Angaben vollständig, Bestätigungen können ausgestellt werden.</div>';
echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="club"><div class="addr">';
foreach (MD_CLUB_FIELDS as $k => [$label, $req, $hint]) {
    if ($k === 'exemption_kind') {
        echo '<label>' . h($label) . ' *<select name="club[exemption_kind]"><option value="feststellung"' . ($club[$k] === 'feststellung' ? ' selected' : '') . '>Feststellung nach § 60a AO</option><option value="freistellung"' . ($club[$k] === 'freistellung' ? ' selected' : '') . '>Freistellungsbescheid / Anlage zum KSt-Bescheid</option></select></label>';
        continue;
    }
    echo '<label>' . h($label) . ($req ? ' *' : '') . '<input name="club[' . $k . ']" value="' . h($club[$k]) . '" placeholder="' . h($hint) . '"></label>';
}
echo '</div><button>Vereinsangaben speichern</button></form></section>';

// ---- Allgemein
echo '<section class="card" id="general"><h2>Allgemein</h2><form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="general"><div class="addr">';
echo '<label>Übungsleiter-Freibetrag pro Jahr in € (bitte jährlich prüfen)<input name="ueb" value="' . h(number_format((int)md_setting('ueb_freibetrag_cents', '330000') / 100, 2, ',', '.')) . '"></label>';
echo '<label>Level für Mitglieder ohne gültige Funktion (nur bei Mitgliedern mit Funktionszuordnung)<input name="default_aktiv" size="3" value="' . (int)md_setting('default_aktiv', '0') . '"></label></div><button>Speichern</button></form></section>';
md_footer();
