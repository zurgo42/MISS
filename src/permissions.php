<?php
declare(strict_types=1);

/**
 * Rechte in der Mitgliederverwaltung (einstellbare Tabelle md_permissions).
 *
 *  - Je Funktion (oder '*' für alle Mitglieder) und Bereich eine Stufe: none < view < edit.
 *  - Wirksam ist die höchste Stufe über alle aktuell gültigen Funktionen (nicht nur über die mit dem höchsten Sitzungstool-Level).
 *  - svmembers.is_admin darf immer alles (Schutz vor Aussperrung).
 *  - Ohne Zustimmung zu den aktuellen Nutzungsbedingungen: nur eigene Daten sehen, nichts ändern (siehe md_consent_ok).
 */

const MD_AREAS = [
    'directory' => ['Mitgliederverzeichnis ansehen', ['none', 'view']],
    'members'   => ['Mitglieder: Daten, Gruppen, Funktionen', ['none', 'view', 'edit']],
    'finance'   => ['Finanzen: Buchungen aller Mitglieder', ['none', 'view', 'edit']],
    'terms'     => ['Nutzungsbedingungen und Zustimmungen', ['none', 'view', 'edit']],
    'fees'      => ['Beiträge: Gruppen, Beitragslauf, Mahnwesen', ['none', 'view', 'edit']],
    'receipts'  => ['Spendenbescheinigungen', ['none', 'view', 'edit']],
    'privacy'   => ['Datenschutz: Auskunft, Anfragen, Löschfristen', ['none', 'view', 'edit']],
    'import'    => ['CSV-Import Mitglieder', ['none', 'edit']],
    'settings'  => ['Einstellungen, Rechte, Standard-Sichtbarkeit', ['none', 'edit']],
];
const MD_PERM_LEVELS = ['none' => 'keine', 'view' => 'ansehen', 'edit' => 'bearbeiten'];
const MD_PERM_RANK = ['none' => 0, 'view' => 1, 'edit' => 2];

/** Voreinstellung der Rechtetabelle (nach der Installation im Admin änderbar). */
function md_default_permissions(): array
{
    return [
        ['*', 'directory', 'view'],
        ['Vo', 'members', 'view'], ['Vo', 'finance', 'view'], ['Vo', 'terms', 'view'],
        ['FVo', 'members', 'view'], ['FVo', 'finance', 'edit'], ['FVo', 'terms', 'view'],
        ['FVv', 'members', 'view'], ['FVv', 'finance', 'edit'], ['FVv', 'terms', 'view'],
        ['GF', 'members', 'edit'], ['GF', 'finance', 'view'], ['GF', 'terms', 'edit'], ['GF', 'import', 'edit'], ['GF', 'settings', 'edit'],
        ['Vo', 'receipts', 'view'], ['FVo', 'receipts', 'edit'], ['FVv', 'receipts', 'edit'], ['GF', 'receipts', 'view'], ['VA', 'receipts', 'view'], ['Ka', 'receipts', 'edit'], ['FP', 'receipts', 'view'],
        ['FVo', 'fees', 'edit'], ['FVv', 'fees', 'edit'], ['Ka', 'fees', 'edit'], ['GF', 'fees', 'view'], ['Vo', 'fees', 'view'], ['VA', 'fees', 'view'], ['FP', 'fees', 'view'],
        ['GF', 'privacy', 'edit'], ['Vo', 'privacy', 'view'], ['MB', 'privacy', 'view'], ['VA', 'privacy', 'view'],
        ['VA', 'members', 'view'], ['VA', 'finance', 'view'], ['VA', 'terms', 'view'],
        ['SV', 'members', 'edit'], ['SV', 'terms', 'view'],
        ['MB', 'members', 'edit'], ['MB', 'import', 'edit'], ['MB', 'terms', 'edit'],
        ['Ka', 'members', 'view'], ['Ka', 'finance', 'edit'],
        ['FP', 'members', 'view'], ['FP', 'finance', 'view'], ['FP', 'terms', 'view'],
        ['AD', 'members', 'view'], ['AD', 'settings', 'edit'],
    ];
}

/**
 * Trägt die Voreinstellung für Bereiche ein, die noch nie eingetragen wurden (Merker in md_settings "perm_areas_seeded").
 * So bekommen auch bestehende Installationen neue Bereiche, ohne dass bewusst geleerte Bereiche wiederkehren.
 * Gibt die Anzahl eingetragener Zeilen zurück.
 */
function md_seed_permissions(): int
{
    $seeded = array_filter(explode(',', md_setting('perm_areas_seeded', '')));
    $legacy = $seeded === [] && (int)md_one('SELECT COUNT(*) c FROM md_permissions')['c'] > 0;   // Tabelle aus Version ohne Merker
    if ($legacy) $seeded = array_diff(array_keys(MD_AREAS), ['receipts', 'privacy', 'fees']);
    $n = 0;
    foreach (array_keys(MD_AREAS) as $area) {
        if (in_array($area, $seeded, true)) continue;
        foreach (md_default_permissions() as [$subject, $a, $level]) {
            if ($a !== $area) continue;
            md_exec('INSERT INTO md_permissions (subject, area, level) VALUES (?,?,?)', [$subject, $a, $level]);
            $n++;
        }
        $seeded[] = $area;
    }
    $val = implode(',', $seeded);
    if (md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', ['perm_areas_seeded'])) md_exec('UPDATE md_settings SET setting_value = ? WHERE setting_key = ?', [$val, 'perm_areas_seeded']);
    else md_exec('INSERT INTO md_settings (setting_key, setting_value, description) VALUES (?,?,?)', ['perm_areas_seeded', $val, 'Bereiche, für die die Rechte-Voreinstellung schon eingetragen wurde (nicht ändern)']);
    return $n;
}

function md_permission_matrix(): array
{
    $m = [];
    foreach (md_q('SELECT subject, area, level FROM md_permissions') as $r) $m[$r['subject']][$r['area']] = $r['level'];
    return $m;
}

/** Zwischenspeicher je Anfrage; md_perm_cache(true) leert ihn (nach Änderungen an Funktionen oder Rechtetabelle). */
function &md_perm_cache(bool $reset = false): array
{
    static $cache = [];
    if ($reset) $cache = [];
    return $cache;
}

/** Stufe ('none'|'view'|'edit') des Mitglieds im Bereich. */
function md_perm(array $member, string $area): string
{
    $cache = &md_perm_cache();
    $id = (int)$member['member_id'];
    if (!empty($member['is_admin'])) return 'edit';
    if (isset($cache[$id][$area])) return $cache[$id][$area];
    $codes = ['*'];
    foreach (md_q('SELECT f.code FROM md_member_functions mf JOIN md_functions f ON f.function_id = mf.function_id
                   WHERE mf.member_id = ? AND (mf.valid_from IS NULL OR mf.valid_from <= ?) AND (mf.valid_to IS NULL OR mf.valid_to >= ?)',
        [$id, date('Y-m-d'), date('Y-m-d')]) as $r) $codes[] = $r['code'];
    $matrix = md_permission_matrix();
    foreach (array_keys(MD_AREAS) as $a) {
        $best = 'none';
        foreach ($codes as $c) {
            $lvl = $matrix[$c][$a] ?? 'none';
            if (MD_PERM_RANK[$lvl] > MD_PERM_RANK[$best]) $best = $lvl;
        }
        $cache[$id][$a] = $best;
    }
    return $cache[$id][$area] ?? 'none';
}

function md_can(array $member, string $area, string $need = 'view'): bool
{
    return MD_PERM_RANK[md_perm($member, $area)] >= MD_PERM_RANK[$need];
}

/** Seite nur für Mitglieder mit der Stufe $need im Bereich $area (und Zustimmung zu den Nutzungsbedingungen). */
function md_require_perm(string $area, string $need = 'view'): array
{
    $m = md_require_login();
    if (!md_consent_ok((int)$m['member_id'])) {
        md_flash('Bitte stimme zuerst den Nutzungsbedingungen zu, bis dahin sind nur deine eigenen Daten sichtbar.', 'warn');
        header('Location: ' . md_url('index.php#terms'));
        exit;
    }
    if (!md_can($m, $area, $need)) {
        http_response_code(403);
        exit('Dafür fehlt die Berechtigung.');
    }
    return $m;
}
