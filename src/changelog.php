<?php
declare(strict_types=1);

function md_log_change(int $member, string $key, ?string $old, ?string $new, ?int $by, string $via): void
{
    if ((string)$old === (string)$new) return;
    md_exec('INSERT INTO md_changelog (member_id, field_key, old_value, new_value, changed_by, changed_via, changed_at) VALUES (?,?,?,?,?,?,?)',
        [$member, $key, $old, $new, $by, $via, md_now()]);
}

function md_recent_changes(int $member, int $limit = 15): array
{
    return md_q('SELECT c.*, s.first_name, s.last_name FROM md_changelog c
                 LEFT JOIN svmembers s ON s.member_id = c.changed_by
                 WHERE c.member_id = ? ORDER BY c.change_id DESC LIMIT ' . (int)$limit, [$member]);
}

function md_field_label(string $key): string
{
    if (isset(MD_PROFILE_FIELDS[$key])) return MD_PROFILE_FIELDS[$key][0];
    if (preg_match('/^address\.(\d)\.(\w+)$/', $key, $m)) return 'Adresse ' . $m[1] . ' – ' . (MD_ADDRESS_FIELDS[$m[2]] ?? $m[2]);
    if (preg_match('/^contact\.(\w+)$/', $key, $m)) return MD_CONTACT_KINDS[$m[1]] ?? $key;
    if (preg_match('/^photo\.(\d)$/', $key, $m)) return 'Foto ' . $m[1];
    if (preg_match('/^visibility\.(.+)$/', $key, $m)) return 'Sichtbarkeit: ' . (md_visibility_keys()[$m[1]][0] ?? $m[1]);
    $static = ['status' => 'Status', 'join_date' => 'Eintritt', 'leave_date' => 'Austritt', 'admin_note' => 'Verwaltungsnotiz',
        'consent' => 'Zustimmung Nutzungsbedingungen', 'created' => 'Mitglied', 'rights' => 'Rechte im Sitzungstool', 'permissions' => 'Rechtetabelle (Einstellungen)', 'settings' => 'Einstellung', 'ledger' => 'Buchung', 'receipt' => 'Spendenbescheinigung', 'privacy' => 'Datenschutz', 'group' => 'Teilgruppe', 'function' => 'Funktion'];
    return $static[$key] ?? $key;
}
