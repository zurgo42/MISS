<?php
declare(strict_types=1);

function md_current_terms(string $type = 'nutzungsbedingungen'): ?array
{
    return md_one('SELECT * FROM md_terms_versions WHERE doc_type = ? AND valid_from <= ? ORDER BY valid_from DESC, terms_id DESC LIMIT 1',
        [$type, date('Y-m-d')]);
}

/** Aktuellste nicht widerrufene Zustimmung des Mitglieds (zu irgendeiner Version). */
function md_last_consent(int $member, string $type = 'nutzungsbedingungen'): ?array
{
    return md_one('SELECT c.*, v.version_label, v.valid_from FROM md_terms_consents c
                   JOIN md_terms_versions v ON v.terms_id = c.terms_id
                   WHERE c.member_id = ? AND v.doc_type = ? AND c.revoked_at IS NULL
                   ORDER BY v.valid_from DESC, c.consented_at DESC LIMIT 1', [$member, $type]);
}

/** Status: ok (aktuelle Version zugestimmt), outdated (ältere Version), missing (nie). */
function md_terms_status(int $member): array
{
    $cur = md_current_terms();
    $last = md_last_consent($member);
    if (!$cur) return ['state' => 'none', 'current' => null, 'consent' => $last];
    if ($last && (int)$last['terms_id'] === (int)$cur['terms_id']) return ['state' => 'ok', 'current' => $cur, 'consent' => $last];
    if ($last && !(int)$cur['requires_consent']) return ['state' => 'ok', 'current' => $cur, 'consent' => $last];
    return ['state' => $last ? 'outdated' : 'missing', 'current' => $cur, 'consent' => $last];
}

function md_truncate_ip(?string $ip): ?string
{
    if (!$ip) return null;
    if (strpos($ip, ':') !== false) return implode(':', array_slice(explode(':', $ip), 0, 3)) . '::';
    $p = explode('.', $ip);
    return count($p) === 4 ? "$p[0].$p[1].$p[2].0" : null;
}

function md_record_consent(int $member, int $termsId, string $channel, ?int $recordedBy, ?string $ip = null, ?string $note = null, ?string $when = null): void
{
    md_exec('INSERT INTO md_terms_consents (member_id, terms_id, consented_at, channel, recorded_by, ip_truncated, note) VALUES (?,?,?,?,?,?,?)',
        [$member, $termsId, $when ?? md_now(), $channel, $recordedBy, $channel === 'self' ? md_truncate_ip($ip) : null, $note]);
    $label = md_one('SELECT version_label FROM md_terms_versions WHERE terms_id = ?', [$termsId])['version_label'] ?? '?';
    md_log_change($member, 'consent', null, "Version $label ($channel)", $recordedBy ?? $member, $channel === 'self' ? 'self' : 'admin');
}

function md_revoke_consent(int $member, int $consentId, int $actor): void
{
    md_exec('UPDATE md_terms_consents SET revoked_at = ? WHERE consent_id = ? AND member_id = ? AND revoked_at IS NULL', [md_now(), $consentId, $member]);
    md_log_change($member, 'consent', 'Zustimmung', 'widerrufen', $actor, $actor === $member ? 'self' : 'admin');
}
