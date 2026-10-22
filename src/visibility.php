<?php
declare(strict_types=1);

/** Wirksame Sichtbarkeit je Feld: Code-Standard < DB-Standard < Wahl des Mitglieds. */
function md_visibility_map(int $owner): array
{
    $map = [];
    foreach (md_visibility_keys() as $k => [, $def]) $map[$k] = $def;
    foreach (md_q('SELECT field_key, level FROM md_visibility_defaults') as $r) $map[$r['field_key']] = $r['level'];
    foreach (md_q('SELECT field_key, level FROM md_visibility WHERE member_id = ?', [$owner]) as $r) $map[$r['field_key']] = $r['level'];
    return $map;
}

function md_shares_group(int $a, int $b): bool
{
    return (bool)md_one('SELECT 1 FROM md_member_groups x JOIN md_member_groups y ON x.group_id = y.group_id
                         WHERE x.member_id = ? AND y.member_id = ? LIMIT 1', [$a, $b]);
}

/** Darf $viewer das Feld $key von $ownerId sehen? */
function md_can_see(array $viewer, int $ownerId, string $key, array $vmap): bool
{
    if ((int)$viewer['member_id'] === $ownerId) return true;
    if (!md_consent_ok((int)$viewer['member_id'])) return false;
    $level = $vmap[$key] ?? 'me';
    switch ($level) {
        case 'all':   return true;
        case 'group': return md_is_privileged($viewer) || md_shares_group((int)$viewer['member_id'], $ownerId);
        case 'admin': return md_is_privileged($viewer);
        default:      return false;
    }
}
