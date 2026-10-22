<?php
declare(strict_types=1);

function md_ensure_profile(int $member): void
{
    if (!md_one('SELECT 1 FROM md_profiles WHERE member_id = ?', [$member])) {
        md_exec('INSERT INTO md_profiles (member_id) VALUES (?)', [$member]);
    }
}

function md_load_profile(int $member): array
{
    return md_one('SELECT * FROM md_profiles WHERE member_id = ?', [$member]) ?? ['member_id' => $member, 'status' => 'aktiv'];
}

function md_load_addresses(int $member): array
{
    $out = [];
    foreach (md_q('SELECT * FROM md_addresses WHERE member_id = ?', [$member]) as $r) $out[(int)$r['slot']] = $r;
    return $out;
}

function md_load_photos(int $member): array
{
    $out = [];
    foreach (md_q('SELECT * FROM md_photos WHERE member_id = ?', [$member]) as $r) $out[(int)$r['slot']] = $r;
    return $out;
}

function md_load_contacts(int $member): array
{
    $out = [];
    foreach (md_q('SELECT kind, value FROM md_contacts WHERE member_id = ?', [$member]) as $r) $out[$r['kind']] = $r['value'];
    return $out;
}

function md_load_groups(int $member): array
{
    return md_q('SELECT g.*, mg.since FROM md_member_groups mg JOIN md_groups g ON g.group_id = mg.group_id
                 WHERE mg.member_id = ? AND (mg.until IS NULL OR mg.until >= ?) ORDER BY g.name', [$member, date('Y-m-d')]);
}

function md_load_functions(int $member): array
{
    return md_q('SELECT f.code, f.name, mf.valid_from, mf.valid_to FROM md_member_functions mf
                 JOIN md_functions f ON f.function_id = mf.function_id
                 WHERE mf.member_id = ? ORDER BY mf.valid_from DESC', [$member]);
}

/** Speichert die Profilfelder; protokolliert jede Änderung einzeln. Gibt Anzahl geänderter Felder zurück. */
function md_save_profile(int $member, array $in, int $actor, string $via, array $fields = null): int
{
    md_ensure_profile($member);
    $old = md_load_profile($member);
    $fields = $fields ?? array_keys(MD_PROFILE_FIELDS);
    $set = [];
    $params = [];
    $n = 0;
    foreach ($fields as $f) {
        if (!array_key_exists($f, $in)) continue;
        $type = MD_PROFILE_FIELDS[$f][1] ?? 'text';
        $val = trim((string)$in[$f]);
        if ($type === 'date') $val = md_parse_date($val) ?? '';
        if ($type === 'select' && !in_array($val, MD_PROFILE_FIELDS[$f][4] ?? [], true)) continue;
        $new = $val === '' ? null : mb_substr($val, 0, $type === 'textarea' ? 4000 : 150);
        if ((string)($old[$f] ?? '') === (string)$new) continue;
        $set[] = "$f = ?";
        $params[] = $new;
        md_log_change($member, $f, $old[$f] ?? null, $new, $actor, $via);
        $n++;
    }
    if ($set) {
        $params[] = md_now();
        $params[] = $member;
        md_exec('UPDATE md_profiles SET ' . implode(', ', $set) . ', updated_at = ? WHERE member_id = ?', $params);
    }
    return $n;
}

/** Vereinsdaten (nur Verwaltung): Status, Eintritt, Austritt, Notiz. */
function md_save_club_data(int $member, array $in, int $actor, string $via): int
{
    md_ensure_profile($member);
    $old = md_load_profile($member);
    $n = 0;
    foreach (['status', 'join_date', 'leave_date', 'admin_note'] as $f) {
        if (!array_key_exists($f, $in)) continue;
        $val = trim((string)$in[$f]);
        if (in_array($f, ['join_date', 'leave_date'], true)) $val = md_parse_date($val) ?? '';
        if ($f === 'status' && !isset(MD_STATUS[$val])) continue;
        $new = $val === '' ? null : $val;
        if ((string)($old[$f] ?? '') === (string)$new) continue;
        md_exec("UPDATE md_profiles SET $f = ?, updated_at = ? WHERE member_id = ?", [$new, md_now(), $member]);
        md_log_change($member, $f, $old[$f] ?? null, $new, $actor, $via);
        $n++;
    }
    return $n;
}

function md_save_addresses(int $member, array $in, int $actor, string $via, array $slots = MD_SLOTS): int
{
    $existing = md_load_addresses($member);
    $n = 0;
    foreach ($slots as $s) {
        $new = [];
        foreach (array_keys(MD_ADDRESS_FIELDS) as $f) {
            $v = trim((string)($in["address_{$s}_$f"] ?? ''));
            $new[$f] = $v === '' ? null : mb_substr($v, 0, 150);
        }
        $old = $existing[$s] ?? null;
        $empty = !array_filter($new);
        if ($empty && !$old) continue;
        foreach ($new as $f => $v) {
            $before = $old[$f] ?? null;
            if ((string)$before !== (string)$v) {
                md_log_change($member, "address.$s.$f", $before, $v, $actor, $via);
                $n++;
            }
        }
        if ($empty) {
            md_exec('DELETE FROM md_addresses WHERE member_id = ? AND slot = ?', [$member, $s]);
        } elseif ($old) {
            md_exec('UPDATE md_addresses SET label=?, street=?, zip=?, city=?, country=? WHERE member_id=? AND slot=?',
                [$new['label'], $new['street'], $new['zip'], $new['city'], $new['country'], $member, $s]);
        } else {
            md_exec('INSERT INTO md_addresses (member_id, slot, label, street, zip, city, country) VALUES (?,?,?,?,?,?,?)',
                [$member, $s, $new['label'], $new['street'], $new['zip'], $new['city'], $new['country']]);
        }
    }
    return $n;
}

function md_save_contacts(int $member, array $in, int $actor, string $via, ?array $only = null): int
{
    $existing = md_load_contacts($member);
    $n = 0;
    foreach ($only ?? array_keys(MD_CONTACT_KINDS) as $k) {
        $v = trim((string)($in["contact_$k"] ?? ''));
        $v = $v === '' ? null : mb_substr($v, 0, 255);
        $before = $existing[$k] ?? null;
        if ((string)$before === (string)$v) continue;
        if ($v === null) md_exec('DELETE FROM md_contacts WHERE member_id = ? AND kind = ?', [$member, $k]);
        elseif ($before === null) md_exec('INSERT INTO md_contacts (member_id, kind, value) VALUES (?,?,?)', [$member, $k, $v]);
        else md_exec('UPDATE md_contacts SET value = ? WHERE member_id = ? AND kind = ?', [$v, $member, $k]);
        md_log_change($member, "contact.$k", $before, $v, $actor, $via);
        $n++;
    }
    return $n;
}

function md_save_visibility(int $member, array $in, int $actor, string $via): int
{
    $current = md_visibility_map($member);
    $defaults = [];
    foreach (md_visibility_keys() as $k => [, $def]) $defaults[$k] = $def;
    foreach (md_q('SELECT field_key, level FROM md_visibility_defaults') as $r) $defaults[$r['field_key']] = $r['level'];
    $n = 0;
    foreach (array_keys(md_visibility_keys()) as $k) {
        $post = 'vis_' . str_replace('.', '__', $k);
        $lvl = (string)($in[$post] ?? '');
        if (!isset(MD_LEVELS[$lvl]) || $lvl === ($current[$k] ?? '')) continue;
        md_exec('DELETE FROM md_visibility WHERE member_id = ? AND field_key = ?', [$member, $k]);
        if ($lvl !== $defaults[$k]) md_exec('INSERT INTO md_visibility (member_id, field_key, level) VALUES (?,?,?)', [$member, $k, $lvl]);
        md_log_change($member, "visibility.$k", MD_LEVELS[$current[$k]] ?? null, MD_LEVELS[$lvl], $actor, $via);
        $n++;
    }
    return $n;
}

/** Foto hochladen: prüft Typ, skaliert auf max. 900 px und kodiert neu (entfernt EXIF/GPS). */
function md_save_photo(int $member, int $slot, array $file, ?string $caption, int $actor, string $via): string
{
    if (!in_array($slot, MD_SLOTS, true)) return 'Ungültiger Fotoplatz.';
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return 'Upload fehlgeschlagen.';
    if ($file['size'] > 8 * 1024 * 1024) return 'Datei größer als 8 MB.';
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) return 'Nur JPEG, PNG, GIF oder WebP.';
    $src = @imagecreatefromstring((string)file_get_contents($file['tmp_name']));
    if (!$src) return 'Bild nicht lesbar.';
    [$w, $h] = [imagesx($src), imagesy($src)];
    $scale = min(1, 900 / max($w, $h));
    $dst = imagecreatetruecolor((int)round($w * $scale), (int)round($h * $scale));
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
    $dir = MD_STORAGE_DIR . '/photos';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = sprintf('%d_%d_%s.jpg', $member, $slot, bin2hex(random_bytes(4)));
    imagejpeg($dst, "$dir/$name", 85);
    $old = md_load_photos($member)[$slot] ?? null;
    if ($old) {
        @unlink("$dir/" . $old['file_name']);
        md_exec('UPDATE md_photos SET file_name=?, mime=?, caption=?, uploaded_at=? WHERE member_id=? AND slot=?',
            [$name, 'image/jpeg', $caption, md_now(), $member, $slot]);
    } else {
        md_exec('INSERT INTO md_photos (member_id, slot, file_name, mime, caption, uploaded_at) VALUES (?,?,?,?,?,?)',
            [$member, $slot, $name, 'image/jpeg', $caption, md_now()]);
    }
    md_log_change($member, "photo.$slot", $old ? 'Foto ersetzt' : null, 'Foto hochgeladen', $actor, $via);
    return '';
}

function md_delete_photo(int $member, int $slot, int $actor, string $via): void
{
    $old = md_load_photos($member)[$slot] ?? null;
    if (!$old) return;
    @unlink(MD_STORAGE_DIR . '/photos/' . $old['file_name']);
    md_exec('DELETE FROM md_photos WHERE member_id = ? AND slot = ?', [$member, $slot]);
    md_log_change($member, "photo.$slot", 'Foto vorhanden', null, $actor, $via);
}
