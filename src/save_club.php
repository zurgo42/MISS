<?php
// Vereinsdaten, Teilgruppen und Funktionen (nur Verwaltung). Eingebunden aus public/save.php.
switch ($section) {
    case 'club':
        $n = md_save_club_data($mid, $_POST, $aid, $via);
        $msg = $n ? "$n Änderung(en) gespeichert." : 'Keine Änderungen.';
        break;
    case 'group_add':
        $g = md_one('SELECT * FROM md_groups WHERE group_id = ?', [(int)($_POST['group_id'] ?? 0)]);
        if ($g && !md_one('SELECT 1 FROM md_member_groups WHERE member_id = ? AND group_id = ?', [$mid, $g['group_id']])) {
            md_exec('INSERT INTO md_member_groups (member_id, group_id, since) VALUES (?,?,?)', [$mid, $g['group_id'], md_parse_date($_POST['since'] ?? '') ?? date('Y-m-d')]);
            md_log_change($mid, 'group', null, $g['name'], $aid, $via);
        }
        $msg = 'Teilgruppe zugeordnet.';
        break;
    case 'group_remove':
        $g = md_one('SELECT * FROM md_groups WHERE group_id = ?', [(int)($_POST['group_id'] ?? 0)]);
        if ($g) {
            md_exec('DELETE FROM md_member_groups WHERE member_id = ? AND group_id = ?', [$mid, $g['group_id']]);
            md_log_change($mid, 'group', $g['name'], null, $aid, $via);
        }
        $msg = 'Teilgruppe entfernt.';
        break;
    case 'func_add':
        $f = md_one('SELECT * FROM md_functions WHERE function_id = ?', [(int)($_POST['function_id'] ?? 0)]);
        if ($f) {
            md_exec('INSERT INTO md_member_functions (member_id, function_id, valid_from, valid_to) VALUES (?,?,?,?)',
                [$mid, $f['function_id'], md_parse_date($_POST['valid_from'] ?? '') ?? date('Y-m-d'), md_parse_date($_POST['valid_to'] ?? '')]);
            md_log_change($mid, 'function', null, $f['code'] . ' ' . $f['name'], $aid, $via);
        }
        $msg = 'Funktion zugeordnet.';
        break;
    case 'func_remove':
        $r = md_one('SELECT mf.*, f.code, f.name FROM md_member_functions mf JOIN md_functions f ON f.function_id = mf.function_id
                     WHERE mf.member_function_id = ? AND mf.member_id = ?', [(int)($_POST['member_function_id'] ?? 0), $mid]);
        if ($r) {
            md_exec('UPDATE md_member_functions SET valid_to = ? WHERE member_function_id = ?', [date('Y-m-d'), $r['member_function_id']]);
            md_log_change($mid, 'function', $r['code'] . ' ' . $r['name'], 'beendet', $aid, $via);
        }
        $msg = 'Funktion beendet.';
        break;
}
