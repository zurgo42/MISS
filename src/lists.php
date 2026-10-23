<?php
declare(strict_types=1);

/**
 * E-Mail-Verteiler: Empfänger ergeben sich laufend aus der Teilgruppe, der Funktion oder allen Mitgliedern.
 * Jede Nachricht geht einzeln an jeden Empfänger (keine offenen Adresslisten), mit Abmeldelink bei optionalen Verteilern.
 * Veranstalter können außerdem allen Angemeldeten ihrer Veranstaltung schreiben (md_event_message).
 */

const MD_LIST_SOURCES = ['all' => 'alle Mitglieder', 'group' => 'Teilgruppe', 'function' => 'Funktion'];
const MD_LIST_MAX_RECIPIENTS = 2000;

function md_mail_secret(): string
{
    $s = md_setting('mail.secret', '');
    if (strlen($s) < 32) {
        $s = bin2hex(random_bytes(32));
        if (md_one("SELECT 1 FROM md_settings WHERE setting_key = 'mail.secret'")) md_exec("UPDATE md_settings SET setting_value = ? WHERE setting_key = 'mail.secret'", [$s]);
        else md_exec("INSERT INTO md_settings (setting_key, setting_value, description) VALUES ('mail.secret', ?, 'Schlüssel für Abmeldelinks (nicht ändern)')", [$s]);
    }
    return $s;
}

function md_unsub_token(int $member, int $list): string
{
    return substr(hash_hmac('sha256', "unsub|$member|$list", md_mail_secret()), 0, 32);
}

function md_unsub_url(int $member, int $list): string
{
    return md_public_url('unsubscribe.php?m=' . $member . '&l=' . $list . '&t=' . md_unsub_token($member, $list));
}

function md_list_get(int $id): ?array
{
    return md_one('SELECT * FROM md_mail_lists WHERE list_id = ?', [$id]) ?: null;
}

function md_lists(bool $onlyActive = false): array
{
    return md_q('SELECT * FROM md_mail_lists' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY name');
}

function md_list_source_label(array $l): string
{
    if ($l['source_kind'] === 'group') { $g = md_one('SELECT name FROM md_groups WHERE group_id = ?', [(int)$l['source_ref']]); return 'Teilgruppe ' . ($g['name'] ?? '(gelöscht)'); }
    if ($l['source_kind'] === 'function') { $f = md_one('SELECT code, name FROM md_functions WHERE code = ?', [$l['source_ref']]); return 'Funktion ' . ($f ? $f['code'] . ' – ' . $f['name'] : $l['source_ref'] . ' (unbekannt)'); }
    return MD_LIST_SOURCES['all'];
}

function md_list_save(?int $id, array $d): string
{
    $name = trim((string)($d['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) return 'Name fehlt oder ist zu lang.';
    $kind = array_key_exists($d['source_kind'] ?? '', MD_LIST_SOURCES) ? $d['source_kind'] : 'group';
    $ref = null;
    if ($kind === 'group') {
        $ref = (string)(int)($d['group_id'] ?? 0);
        if (!md_one('SELECT 1 FROM md_groups WHERE group_id = ?', [(int)$ref])) return 'Bitte eine Teilgruppe wählen.';
    } elseif ($kind === 'function') {
        $ref = (string)($d['function_code'] ?? '');
        if (!md_one('SELECT 1 FROM md_functions WHERE code = ?', [$ref])) return 'Bitte eine Funktion wählen.';
    }
    if (md_one('SELECT 1 FROM md_mail_lists WHERE LOWER(name) = ? AND list_id <> ?', [mb_strtolower($name), (int)$id])) return 'Es gibt schon einen Verteiler mit diesem Namen.';
    $vals = [$name, trim((string)($d['description'] ?? '')) ?: null, $kind, $ref, !empty($d['optional']) ? 1 : 0, !empty($d['active']) ? 1 : 0];
    if ($id) md_exec('UPDATE md_mail_lists SET name = ?, description = ?, source_kind = ?, source_ref = ?, optional = ?, active = ? WHERE list_id = ?', array_merge($vals, [$id]));
    else md_exec('INSERT INTO md_mail_lists (name, description, source_kind, source_ref, optional, active, created_at) VALUES (?,?,?,?,?,?,?)', array_merge($vals, [md_now()]));
    return '';
}

/**
 * Empfänger eines Verteilers: ['send' => [Mitglieder mit gültiger Adresse], 'no_mail' => n, 'opted_out' => n].
 * Ausgeschlossen: gesperrte, ausgetretene und anonymisierte Mitglieder.
 */
function md_list_recipients(array $list, ?string $today = null): array
{
    $today = $today ?? date('Y-m-d');
    $base = "SELECT DISTINCT s.* FROM svmembers s LEFT JOIN md_profiles p ON p.member_id = s.member_id";
    $where = " WHERE COALESCE(s.is_active, 1) <> 0 AND s.membership_number NOT LIKE 'DEL-%' AND COALESCE(p.status, 'aktiv') <> 'ausgetreten' AND (p.leave_date IS NULL OR p.leave_date >= ?)";
    $par = [$today];
    if ($list['source_kind'] === 'group') {
        $base .= ' JOIN md_member_groups g ON g.member_id = s.member_id';
        $where .= ' AND g.group_id = ? AND (g.until IS NULL OR g.until >= ?)';
        array_push($par, (int)$list['source_ref'], $today);
    } elseif ($list['source_kind'] === 'function') {
        $base .= ' JOIN md_member_functions mf ON mf.member_id = s.member_id JOIN md_functions f ON f.function_id = mf.function_id';
        $where .= ' AND f.code = ? AND (mf.valid_from IS NULL OR mf.valid_from <= ?) AND (mf.valid_to IS NULL OR mf.valid_to >= ?)';
        array_push($par, $list['source_ref'], $today, $today);
    }
    $rows = md_q($base . $where . ' ORDER BY s.last_name, s.first_name', $par);
    $out = ['send' => [], 'no_mail' => 0, 'opted_out' => 0];
    $out_ = array_column(md_q('SELECT member_id FROM md_mail_optout WHERE list_id = ?', [(int)$list['list_id']]), 'member_id');
    $opt = array_flip(array_map('intval', $out_));
    foreach ($rows as $m) {
        if (!md_mail_address_of((int)$m['member_id'])) { $out['no_mail']++; continue; }
        if ((int)$list['optional'] === 1 && isset($opt[(int)$m['member_id']])) { $out['opted_out']++; continue; }
        $out['send'][] = $m;
    }
    return $out;
}

/** Verteiler, in denen das Mitglied (zurzeit) Empfänger wäre, mit Abbestell-Status. */
function md_lists_of_member(int $member): array
{
    $res = [];
    foreach (md_lists(true) as $l) {
        foreach (md_list_recipients($l)['send'] as $m) {
            if ((int)$m['member_id'] === $member) { $l['opted_out'] = false; $res[] = $l; continue 2; }
        }
        // abbestellt: nicht in 'send', aber Mitglied der Quelle
        if ((int)$l['optional'] === 1 && md_one('SELECT 1 FROM md_mail_optout WHERE member_id = ? AND list_id = ?', [$member, $l['list_id']])) {
            $probe = $l; $probe['optional'] = 0;
            foreach (md_list_recipients($probe)['send'] as $m) if ((int)$m['member_id'] === $member) { $l['opted_out'] = true; $res[] = $l; break; }
        }
    }
    return $res;
}

function md_list_set_optout(int $member, int $list, bool $out, int $actor): string
{
    $l = md_list_get($list);
    if (!$l) return 'Verteiler nicht gefunden.';
    if ((int)$l['optional'] !== 1) return 'Dieser Verteiler ist verbindlich und kann nicht abbestellt werden.';
    $has = md_one('SELECT 1 FROM md_mail_optout WHERE member_id = ? AND list_id = ?', [$member, $list]);
    if ($out && !$has) md_exec('INSERT INTO md_mail_optout (member_id, list_id, opted_at) VALUES (?,?,?)', [$member, $list, md_now()]);
    elseif (!$out && $has) md_exec('DELETE FROM md_mail_optout WHERE member_id = ? AND list_id = ?', [$member, $list]);
    else return '';
    md_log_change($member, 'mail', 'Verteiler ' . $l['name'], $out ? 'abbestellt' : 'wieder abonniert', $actor, $actor === $member ? 'self' : 'admin');
    return '';
}

function md_mail_personalize(string $text, array $m): string
{
    $p = md_load_profile((int)$m['member_id']);
    $anrede = match ($p['salutation'] ?? '') { 'Frau' => 'Liebe Frau ' . $m['last_name'], 'Herr' => 'Lieber Herr ' . $m['last_name'], default => 'Hallo ' . $m['first_name'] };
    return strtr($text, ['{vorname}' => $m['first_name'], '{name}' => $m['first_name'] . ' ' . $m['last_name'], '{anrede}' => $anrede]);
}

/** Nachricht an einen Verteiler. Gibt [campaign_id|null, Empfängerzahl, Fehlertext] zurück. Die Nachrichten stehen danach im Ausgang. */
function md_list_send(int $listId, string $subject, string $body, int $actor): array
{
    $l = md_list_get($listId);
    if (!$l || !(int)$l['active']) return [null, 0, 'Verteiler nicht gefunden oder nicht aktiv.'];
    $subject = md_mail_clean_header($subject);
    if ($subject === '' || mb_strlen($subject) > 200 || trim($body) === '') return [null, 0, 'Betreff und Text dürfen nicht leer sein.'];
    $rec = md_list_recipients($l)['send'];
    if (!$rec) return [null, 0, 'Der Verteiler hat zurzeit keine Empfänger mit gültiger Adresse.'];
    if (count($rec) > MD_LIST_MAX_RECIPIENTS) return [null, 0, 'Zu viele Empfänger (höchstens ' . MD_LIST_MAX_RECIPIENTS . ').'];
    if (md_one('SELECT 1 FROM md_mail_campaigns WHERE list_id = ? AND subject = ? AND body = ? AND created_at >= ?', [$listId, $subject, $body, date('Y-m-d H:i:s', time() - 600)])) return [null, 0, 'Diese Nachricht wurde vor Kurzem schon an den Verteiler gesendet.'];
    $club = md_club();
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        md_exec('INSERT INTO md_mail_campaigns (list_id, subject, body, recipients, created_at, created_by) VALUES (?,?,?,?,?,?)', [$listId, mb_substr($subject, 0, 255), $body, count($rec), md_now(), $actor]);
        $cid = (int)$pdo->lastInsertId();
        foreach ($rec as $m) {
            $mid = (int)$m['member_id'];
            $foot = "\n--\nDu erhältst diese Nachricht als Mitglied des Verteilers „{$l['name']}“ von " . ($club['name'] ?: MD_CLUB_NAME) . '.';
            $url = null;
            if ((int)$l['optional'] === 1) { $url = md_unsub_url($mid, $listId); $foot .= "\nNicht mehr erhalten? Abbestellen: $url"; }
            md_mail_queue($mid, md_mail_address_of($mid), $subject, md_mail_personalize($body, $m) . "\n" . $foot, 'list', 'K' . $cid, $actor, null, $url);
        }
        md_log_change($actor, 'mail', 'Verteiler ' . $l['name'], 'Nachricht „' . mb_substr($subject, 0, 60) . '“ an ' . count($rec) . ' Empfänger', $actor, 'self');
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return [null, 0, $e->getMessage()];
    }
    return [$cid, count($rec), ''];
}

/** Nachricht des Veranstalters an alle Angemeldeten (Antworten gehen an seine Adresse). Sendet sofort. Gibt [Empfängerzahl, Fehlertext] zurück. */
function md_event_message(int $eventId, string $subject, string $body, bool $withWaitlist, array $actor): array
{
    $ev = md_event_get($eventId);
    if (!$ev) return [0, 'Veranstaltung nicht gefunden.'];
    if (!md_event_can_manage($ev, $actor)) return [0, 'Nur der Veranstalter darf Nachrichten senden.'];
    $subject = md_mail_clean_header($subject);
    if ($subject === '' || mb_strlen($subject) > 200 || trim($body) === '') return [0, 'Betreff und Text dürfen nicht leer sein.'];
    $since = date('Y-m-d H:i:s', time() - 86400);
    if (!md_can($actor, 'events', 'edit') && (int)md_one('SELECT COUNT(*) c FROM md_mail_campaigns WHERE event_id = ? AND created_at >= ?', [$eventId, $since])['c'] >= 3) return [0, 'Es sind höchstens 3 Nachrichten je Veranstaltung und Tag möglich.'];
    $conf = md_mail_conf();
    if ($p = md_mail_conf_problems($conf)) return [0, 'E-Mail-Versand ist noch nicht eingerichtet: ' . implode('; ', $p) . '.'];
    $rows = md_q("SELECT s.* FROM md_event_regs r JOIN svmembers s ON s.member_id = r.member_id WHERE r.event_id = ? AND r.status IN (" . ($withWaitlist ? "'registered','waitlist'" : "'registered'") . ') ORDER BY r.reg_id', [$eventId]);
    $rows = array_values(array_filter($rows, fn($m) => md_mail_address_of((int)$m['member_id']) !== null));
    if (!$rows) return [0, 'Keine Empfänger mit gültiger E-Mail-Adresse.'];
    $reply = md_mail_address_of((int)$actor['member_id']);
    md_exec('INSERT INTO md_mail_campaigns (event_id, subject, body, recipients, created_at, created_by) VALUES (?,?,?,?,?,?)', [$eventId, mb_substr($subject, 0, 255), $body, count($rows), md_now(), (int)$actor['member_id']]);
    $cid = (int)md_db()->lastInsertId();
    $n = 0;
    foreach ($rows as $m) {
        $mid = (int)$m['member_id'];
        $foot = "\n--\nNachricht von " . $actor['first_name'] . ' ' . $actor['last_name'] . ' als Veranstalter von „' . $ev['title'] . '“ (' . md_event_dt($ev['starts_at']) . '). Du erhältst sie, weil du angemeldet bist.' . ($reply ? "\nAntworten gehen direkt an den Veranstalter." : '');
        [$id] = md_mail_queue($mid, md_mail_address_of($mid), $subject, md_mail_personalize($body, $m) . "\n" . $foot, 'list', 'K' . $cid, (int)$actor['member_id'], $reply);
        if ($id && md_mail_send((int)$id) === '') $n++;
    }
    md_log_change((int)$actor['member_id'], 'mail', 'Veranstaltung ' . $ev['title'], 'Nachricht an ' . count($rows) . ' Angemeldete', (int)$actor['member_id'], 'self');
    return [$n, $n < count($rows) ? (count($rows) - $n) . ' Nachricht(en) konnten nicht gesendet werden (siehe E-Mail-Ausgang).' : ''];
}
