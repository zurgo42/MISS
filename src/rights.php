<?php
declare(strict_types=1);

/**
 * Funktionen (md_member_functions) -> Rechte im Sitzungstool (svmembers.aktiv, funktion, role).
 *
 * Regeln:
 *  - Das Mitglied darf mehrere Funktionen gleichzeitig haben; alle stehen in der Dokumentation.
 *  - Für die Rechte gilt: die aktuell gültige Funktion mit dem höchsten Level gewinnt.
 *  - Level = md_member_functions.aktiv_level, sonst md_functions.sv_aktiv_level.
 *  - Nur Mitglieder mit mindestens einer Funktionszuordnung werden abgeglichen (alle anderen bleiben unangetastet).
 *  - svmembers.is_admin und is_confidential werden nie verändert (von Hand gepflegt).
 *  - Rolle wird wie in adapters/MemberAdapter.php (mapRole) der Sitzungsverwaltung abgeleitet.
 */

const MD_FUNCTION_ROLES = [
    'Vo' => 'vorstand', 'FVo' => 'vorstand', 'FVv' => 'vorstand',
    'GF' => 'gf',
    'VA' => 'assistenz', 'SV' => 'assistenz', 'MB' => 'assistenz', 'Ka' => 'assistenz', 'Orga' => 'assistenz',
    'RL' => 'fuehrungsteam', 'PL' => 'fuehrungsteam', 'JT' => 'fuehrungsteam', 'TM' => 'fuehrungsteam',
    'AD' => 'mitglied', 'FP' => 'mitglied', 'Rx' => 'mitglied', 'Vx' => 'mitglied', 'Xx' => 'mitglied',
];

/** Schreibweise in svmembers.role (ENUM 'vorstand','gf','assistenz','fuehrungsteam','Mitglied'). */
function md_sv_role_value(string $role): string
{
    return $role === 'mitglied' ? 'Mitglied' : $role;
}

function md_role_for(int $aktiv, ?string $funktion): string
{
    if ($aktiv === 19) return 'vorstand';
    if ($aktiv === 18) return 'gf';
    return MD_FUNCTION_ROLES[(string)$funktion] ?? 'mitglied';
}

/** Alle am Stichtag gültigen Funktionen des Mitglieds, absteigend nach Level. */
function md_active_functions(int $member, ?string $on = null): array
{
    $on = $on ?? date('Y-m-d');
    return md_q('SELECT f.code, f.name, mf.ressort_id, COALESCE(mf.aktiv_level, f.sv_aktiv_level, 0) AS level
                 FROM md_member_functions mf JOIN md_functions f ON f.function_id = mf.function_id
                 WHERE mf.member_id = ? AND (mf.valid_from IS NULL OR mf.valid_from <= ?) AND (mf.valid_to IS NULL OR mf.valid_to >= ?)
                 ORDER BY COALESCE(mf.aktiv_level, f.sv_aktiv_level, 0) DESC, f.function_id ASC', [$member, $on, $on]);
}

function md_effective_function(int $member, ?string $on = null): ?array
{
    return md_active_functions($member, $on)[0] ?? null;
}

function md_member_is_managed(int $member): bool
{
    return (bool)md_one('SELECT 1 FROM md_member_functions WHERE member_id = ? LIMIT 1', [$member]);
}

/** Spalten von svmembers (zur Absicherung gegen abweichende Installationen). */
function md_sv_columns(): array
{
    static $cols = null;
    if ($cols !== null) return $cols;
    $rows = MD_DB_DRIVER === 'sqlite' ? md_q('PRAGMA table_info(svmembers)') : md_q('SHOW COLUMNS FROM svmembers');
    return $cols = array_map('strtolower', array_column($rows, MD_DB_DRIVER === 'sqlite' ? 'name' : 'Field'));
}

function md_sv_supports_rights(): bool
{
    return !array_diff(['aktiv', 'funktion', 'role'], md_sv_columns());
}

/** Name des Ressorts aus der Ressortliste der Sitzungsverwaltung (svressorts), falls vorhanden. */
function md_ressorts(): array
{
    static $list = null;
    if ($list !== null) return $list;
    try {
        $list = [];
        foreach (md_q('SELECT ID, Ressort FROM svressorts WHERE aktiv = 1 ORDER BY Reihenfolge, ID') as $r) $list[(int)$r['ID']] = $r['Ressort'];
    } catch (Throwable $e) {
        $list = [];
    }
    return $list;
}

/**
 * Gleicht ein Mitglied ab. Gibt null zurück (nicht verwaltet/unverändert) oder ['old' => ..., 'new' => ...].
 * $dry: nur berechnen, nichts schreiben.
 */
function md_sync_member_rights(int $member, ?int $actor, string $via, ?string $on = null, bool $dry = false): ?array
{
    if (!md_sv_supports_rights() || !md_member_is_managed($member)) return null;
    $m = md_one('SELECT aktiv, funktion, role FROM svmembers WHERE member_id = ?', [$member]);
    if (!$m) return null;
    $eff = md_effective_function($member, $on);
    $aktiv = $eff ? (int)$eff['level'] : (int)md_setting('default_aktiv', '0');
    $fkt = $eff ? $eff['code'] : null;
    $role = md_role_for($aktiv, $fkt);
    $old = ['aktiv' => (int)$m['aktiv'], 'funktion' => $m['funktion'] ?: null, 'role' => strtolower((string)$m['role'])];
    $new = ['aktiv' => $aktiv, 'funktion' => $fkt, 'role' => $role];
    if ($old === $new) return null;
    if (!$dry) {
        md_exec('UPDATE svmembers SET aktiv = ?, funktion = ?, role = ? WHERE member_id = ?', [$aktiv, $fkt, md_sv_role_value($role), $member]);
        $fmt = fn(array $x) => 'aktiv ' . $x['aktiv'] . ', Funktion ' . ($x['funktion'] ?? '–') . ', Rolle ' . $x['role'];
        md_log_change($member, 'rights', $fmt($old), $fmt($new), $actor, $via);
    }
    return ['old' => $old, 'new' => $new];
}

/** Alle verwalteten Mitglieder abgleichen (Cronjob). Gibt [member_id => Änderung] zurück. */
function md_sync_all_rights(?int $actor, string $via = 'system', bool $dry = false): array
{
    $out = [];
    foreach (md_q('SELECT DISTINCT member_id FROM md_member_functions') as $r) {
        if ($c = md_sync_member_rights((int)$r['member_id'], $actor, $via, null, $dry)) $out[(int)$r['member_id']] = $c;
    }
    return $out;
}
