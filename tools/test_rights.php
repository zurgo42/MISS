<?php
/**
 * test_rights.php – prüft den Abgleich Funktionen -> svmembers an der Demo-Datenbank.
 * Alles läuft in einer Transaktion, die am Ende zurückgerollt wird.
 * Aufruf: php tools/test_rights.php   (nach php tools/demo_setup.php)
 */
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__) . '/src/bootstrap.php';
if (!MD_DEMO_MODE) exit("nur mit Demo-Daten\n");
$fail = 0;
$check = function (string $name, $got, $want) use (&$fail) {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("%s %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $ok ? '' : ' – erwartet ' . json_encode($want) . ', war ' . json_encode($got));
};
$row = fn(int $id) => md_one('SELECT aktiv, funktion, role, is_admin, is_confidential FROM svmembers WHERE member_id = ?', [$id]);
$fid = fn(string $code) => (int)md_one('SELECT function_id FROM md_functions WHERE code = ?', [$code])['function_id'];

// 1. Ausgangslage der Demo deckt sich mit der Sitzungsverwaltung
$check('Demo-Stand ist bereits konsistent', md_sync_all_rights(null, 'system', true), []);

md_db()->beginTransaction();
// 2. Höchstes Level gewinnt: Thomas (VA 17, MB 9) + Funktion mit höherem Level
md_exec('INSERT INTO md_member_functions (member_id, function_id, valid_from) VALUES (5, ?, ?)', [$fid('Vo'), date('Y-m-d', strtotime('-1 day'))]);
$check('Vo (19) schlägt VA (17) und MB (9)', array_slice(md_sync_member_rights(5, 1, 'admin')['new'], 0, 3), ['aktiv' => 19, 'funktion' => 'Vo', 'role' => 'vorstand']);
$check('weitere Funktionen bleiben dokumentiert', count(md_load_functions(5)), 3);
// 3. Zukünftiger Beginn wirkt noch nicht
md_exec('INSERT INTO md_member_functions (member_id, function_id, valid_from) VALUES (6, ?, ?)', [$fid('GF'), date('Y-m-d', strtotime('+10 days'))]);
$check('zukünftige Funktion wirkt nicht', md_sync_member_rights(6, 1, 'admin'), null);
$check('… aber am Stichtag', md_sync_member_rights(6, 1, 'system', date('Y-m-d', strtotime('+10 days')), true)['new']['role'], 'gf');
// 4. Ende der Funktion: zurück auf die nächsthöhere
md_exec('UPDATE md_member_functions SET valid_to = ? WHERE member_id = 5 AND function_id = ?', [date('Y-m-d', strtotime('-1 day')), $fid('Vo')]);
$check('nach Ende der Vo-Funktion wieder VA 17', array_slice(md_sync_member_rights(5, null, 'system')['new'], 0, 3), ['aktiv' => 17, 'funktion' => 'VA', 'role' => 'assistenz']);
// 5. Alle Funktionen beendet: Standardlevel, Flags unberührt
md_exec('UPDATE md_member_functions SET valid_to = ? WHERE member_id = 5', [date('Y-m-d', strtotime('-1 day'))]);
$r = md_sync_member_rights(5, null, 'system');
$check('ohne Funktion: Standardlevel, keine Funktion', [$r['new']['aktiv'], $r['new']['funktion'], $r['new']['role']], [0, null, 'mitglied']);
$check('is_admin/is_confidential unverändert', [$row(5)['is_admin'], $row(5)['is_confidential']], [1, 1]);
// 6. Nicht verwaltete Mitglieder bleiben unangetastet
md_exec("UPDATE svmembers SET aktiv = 7, funktion = 'XX' WHERE member_id = 8");
md_exec('DELETE FROM md_member_functions WHERE member_id = 8');
$check('Mitglied ohne Zuordnung wird nicht angefasst', [md_sync_member_rights(8, 1, 'admin'), $row(8)['aktiv'], $row(8)['funktion']], [null, 7, 'XX']);
// 7. Protokoll
$check('Änderung ist protokolliert', (int)md_one("SELECT COUNT(*) c FROM md_changelog WHERE member_id = 5 AND field_key = 'rights'")['c'] >= 3, true);
// 8. Rollenableitung wie mapRole der Sitzungsverwaltung
$check('role 19', md_role_for(19, 'Vo'), 'vorstand');
$check('role 18 vor Funktion', md_role_for(18, 'RL'), 'gf');
$check('role RL 15', md_role_for(15, 'RL'), 'fuehrungsteam');
$check('role AD/FP/Vx → mitglied', [md_role_for(10, 'AD'), md_role_for(3, 'FP'), md_role_for(0, 'Vx')], ['mitglied', 'mitglied', 'mitglied']);
md_db()->rollBack();
exit($fail ? 1 : 0);
