<?php
declare(strict_types=1);

/**
 * MISS als Einstiegsportal: Das Adminteam trägt in der Administration die weiteren Anwendungen des Vereins ein (KONA, VERA, KAND oder eigene),
 * und jedes Mitglied sieht über dem Dashboard Knöpfe zu den Anwendungen, die für es bestimmt sind. Ohne Eintrag erscheint nichts.
 */

const MD_PORTAL_WHO = ['all' => 'alle Mitglieder', 'func' => 'Funktionsträger', 'staff' => 'nur Verwaltung'];

const MD_PORTAL_DEFAULTS = [
    ['key' => 'kona', 'name' => 'KONA', 'desc' => 'Sitzungen, Anträge und Beschlüsse', 'icon' => '🏛️', 'url' => '', 'on' => 0, 'who' => 'all'],
    ['key' => 'vera', 'name' => 'VERA', 'desc' => 'Mitgliederversammlung', 'icon' => '📋', 'url' => '', 'on' => 0, 'who' => 'all'],
    ['key' => 'kand', 'name' => 'KAND', 'desc' => 'Kandidaten und Wahlen', 'icon' => '🗳️', 'url' => '', 'on' => 0, 'who' => 'all'],
    ['key' => 'x1', 'name' => '', 'desc' => '', 'icon' => '🔗', 'url' => '', 'on' => 0, 'who' => 'all'],
    ['key' => 'x2', 'name' => '', 'desc' => '', 'icon' => '🔗', 'url' => '', 'on' => 0, 'who' => 'all'],
];

/** Alle Einträge (auch ausgeschaltete) in fester Reihenfolge: Vorgaben, überlagert mit den gespeicherten Werten. */
function md_portal_apps(): array
{
    $saved = json_decode(md_setting('portal.apps', '[]'), true);
    $saved = is_array($saved) ? $saved : [];
    $out = [];
    foreach (MD_PORTAL_DEFAULTS as $d) {
        $s = $saved[$d['key']] ?? [];
        $a = $d;
        foreach (['name', 'desc', 'url'] as $f) if (isset($s[$f])) $a[$f] = (string)$s[$f];
        if (isset($s['on'])) $a['on'] = (int)(bool)$s['on'];
        if (isset($s['who']) && isset(MD_PORTAL_WHO[$s['who']])) $a['who'] = (string)$s['who'];
        $out[] = $a;
    }
    return $out;
}

/** Gültige Adresse: http(s)://…, /Pfad oder ../Pfad. Gibt die bereinigte Adresse oder '' zurück. */
function md_portal_clean_url(string $u): string
{
    $u = trim($u);
    if ($u === '' || mb_strlen($u) > 300 || preg_match('/[\s<>"\']/', $u)) return '';
    return preg_match('~^(https?://[^/\s]+|/|\.\./)~i', $u) ? $u : '';
}

/** Speichert die Einträge aus dem Formular. Gibt eine Liste von Hinweisen zurück (leer = alles gespeichert). */
function md_portal_save(array $in, int $actor): array
{
    $notes = [];
    $store = [];
    foreach (MD_PORTAL_DEFAULTS as $d) {
        $row = $in[$d['key']] ?? [];
        $name = mb_substr(trim((string)($row['name'] ?? '')), 0, 40);
        $url = md_portal_clean_url((string)($row['url'] ?? ''));
        $on = !empty($row['on']) ? 1 : 0;
        if ($on && ($name === '' || $url === '')) {
            $notes[] = ($name !== '' ? $name : $d['key']) . ': Zum Einschalten bitte Name und eine gültige Adresse angeben (https://…, /Pfad oder ../Pfad).';
            $on = 0;
        }
        $store[$d['key']] = ['name' => $name, 'desc' => mb_substr(trim((string)($row['desc'] ?? '')), 0, 120), 'url' => $url, 'on' => $on,
            'who' => isset(MD_PORTAL_WHO[$row['who'] ?? '']) ? $row['who'] : 'all'];
    }
    $json = json_encode($store, JSON_UNESCAPED_UNICODE);
    $old = md_setting('portal.apps', '');
    if ($old !== $json) {
        if (md_one("SELECT 1 FROM md_settings WHERE setting_key = 'portal.apps'")) md_exec("UPDATE md_settings SET setting_value = ? WHERE setting_key = 'portal.apps'", [$json]);
        else md_exec("INSERT INTO md_settings (setting_key, setting_value, description) VALUES ('portal.apps', ?, 'Weitere Anwendungen über dem Dashboard (JSON)')", [$json]);
        md_log_change($actor, 'settings', 'Weitere Anwendungen', 'geändert', $actor, 'self');
    }
    return $notes;
}

/** Hat das Mitglied eine aktuell gültige Funktion mit Level über 0 (Ehemalige zählen nicht)? */
function md_has_active_function(int $member): bool
{
    $today = date('Y-m-d');
    return (bool)md_one('SELECT 1 FROM md_member_functions mf JOIN md_functions f ON f.function_id = mf.function_id
                         WHERE mf.member_id = ? AND f.active = 1 AND COALESCE(mf.aktiv_level, f.sv_aktiv_level, 0) > 0
                           AND (mf.valid_from IS NULL OR mf.valid_from <= ?) AND (mf.valid_to IS NULL OR mf.valid_to >= ?)', [$member, $today, $today]);
}

/** Die Anwendungen, die dieses Mitglied sehen soll. */
function md_portal_visible(array $me): array
{
    $out = [];
    foreach (md_portal_apps() as $a) {
        if (!$a['on'] || $a['name'] === '' || $a['url'] === '') continue;
        if ($a['who'] === 'func' && !md_has_active_function((int)$me['member_id']) && !md_is_privileged($me)) continue;
        if ($a['who'] === 'staff' && !md_is_privileged($me)) continue;
        $out[] = $a;
    }
    return $out;
}

/** Zeile „Weitere Anwendungen“ über dem Dashboard; leer, wenn nichts für die Person eingetragen ist. */
function md_portal_html(array $me): string
{
    $apps = md_portal_visible($me);
    if (!$apps) return '';
    $o = '<nav class="portal" aria-label="Weitere Anwendungen"><strong class="subname">Weitere Anwendungen:</strong>';
    foreach ($apps as $a) $o .= '<a class="portal-app" href="' . h($a['url']) . '"><span class="ico">' . h($a['icon']) . '</span><span><strong>' . h($a['name']) . '</strong>' . ($a['desc'] !== '' ? '<small>' . h($a['desc']) . '</small>' : '') . '</span></a>';
    return $o . '</nav>';
}
