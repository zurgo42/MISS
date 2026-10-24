<?php
declare(strict_types=1);

/**
 * Online-Anmeldung neuer Mitglieder (läuft über die Mitgliederverwaltung, nicht über die Sitzungsverwaltung).
 * Ablauf: Antrag ausfüllen → E-Mail-Adresse bestätigen (Link, 48 Stunden) → Vorstand prüft → Aufnahme (legt das Mitglied an und lädt es ein) oder Ablehnung.
 * Die Seite verrät nicht, ob eine Adresse schon bekannt ist. Unbestätigte Anträge werden nach 6 Wochen gelöscht, entschiedene nach "apply.keep_days" (Standard 90).
 */

const MD_APPLY_STATUS = ['unconfirmed' => 'E-Mail nicht bestätigt', 'new' => 'neu', 'approved' => 'aufgenommen', 'rejected' => 'abgelehnt'];

function md_apply_enabled(): bool
{
    return md_setting('apply.enabled', '0') === '1';
}

/** Zähler für Missbrauchsschutz: wie oft wurde $key in den letzten $seconds Sekunden gezählt? */
function md_rate_count(string $key, int $seconds): int
{
    return (int)md_one('SELECT COUNT(*) c FROM md_login_attempts WHERE ident_hash = ? AND attempt_at >= ?', [md_login_ident_hash($key), date('Y-m-d H:i:s', time() - $seconds)])['c'];
}

function md_rate_hit(string $key): void
{
    md_login_fail($key);
}

/** Signierter Zeitstempel im Formular: zu schnell (< 3 s) oder zu alt (> 2 h) abgesendete Formulare sind meist Automaten. */
function md_apply_form_token(?int $t = null): string
{
    $t = $t ?? time();
    return $t . '.' . substr(hash_hmac('sha256', "apply|$t", md_mail_secret()), 0, 24);
}

function md_apply_form_token_ok(string $tok, ?int $now = null): bool
{
    $now = $now ?? time();
    if (!preg_match('/^(\d{9,11})\.([0-9a-f]{24})$/', $tok, $m)) return false;
    if (!hash_equals(substr(hash_hmac('sha256', "apply|{$m[1]}", md_mail_secret()), 0, 24), $m[2])) return false;
    $age = $now - (int)$m[1];
    return $age >= 3 && $age <= 7200;
}

function md_apply_public_groups(): array
{
    return md_q('SELECT * FROM md_fee_groups WHERE active = 1 AND public = 1 ORDER BY name');
}

/**
 * Nimmt einen Antrag entgegen. Gibt [angenommen(bool), Fehlertext] zurück; "angenommen" auch dann, wenn nur eine Hinweis-Mail ging.
 * Fehlertexte betreffen nur die Eingaben, nie den Bestand.
 */
function md_apply_submit(array $d, string $ip, ?int $now = null): array
{
    if (!md_apply_enabled()) return [false, 'Die Online-Anmeldung ist zurzeit nicht freigeschaltet.'];
    if (trim((string)($d['website'] ?? '')) !== '') return [true, ''];            // Köderfeld ausgefüllt: so tun, als wäre alles gut
    if (!md_apply_form_token_ok((string)($d['ft'] ?? ''), $now)) return [false, 'Das Formular war zu lange offen oder wurde zu schnell abgeschickt. Bitte noch einmal absenden.'];
    if (md_rate_count('apply|ip|' . $ip, 3600) >= 5 || md_rate_count('apply|all', 3600) >= 40) return [false, 'Zu viele Anträge in kurzer Zeit. Bitte später noch einmal versuchen.'];
    $first = trim((string)($d['first_name'] ?? ''));
    $last = trim((string)($d['last_name'] ?? ''));
    $email = trim((string)($d['email'] ?? ''));
    if ($first === '' || $last === '' || mb_strlen($first) > 100 || mb_strlen($last) > 100) return [false, 'Bitte Vor- und Nachnamen angeben.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) return [false, 'Bitte eine gültige E-Mail-Adresse angeben.'];
    $street = trim((string)($d['street'] ?? ''));
    $zip = trim((string)($d['zip'] ?? ''));
    $city = trim((string)($d['city'] ?? ''));
    if ($street === '' || $zip === '' || $city === '') return [false, 'Bitte die vollständige Anschrift angeben (für Rechnungen und Post des Vereins).'];
    $birth = null;
    if (trim((string)($d['birthdate'] ?? '')) !== '') {
        $birth = md_parse_date($d['birthdate']);
        if (!$birth || $birth > date('Y-m-d') || $birth < '1900-01-01') return [false, 'Geburtsdatum ungültig (TT.MM.JJJJ).'];
    }
    $gid = (int)($d['fee_group_id'] ?? 0);
    if ($gid && !md_one('SELECT 1 FROM md_fee_groups WHERE group_id = ? AND active = 1 AND public = 1', [$gid])) return [false, 'Bitte eine der angebotenen Mitgliedsarten wählen.'];
    if (empty($d['ack_privacy']) || empty($d['ack_statute'])) return [false, 'Bitte bestätige die Datenschutzhinweise und die Kenntnisnahme der Satzung.'];
    $msg = trim((string)($d['message'] ?? ''));
    if (mb_strlen($msg) > 1000) return [false, 'Die Nachricht ist zu lang (höchstens 1000 Zeichen).'];
    $sal = in_array($d['salutation'] ?? '', ['Frau', 'Herr'], true) ? $d['salutation'] : null;
    md_rate_hit('apply|ip|' . $ip);
    md_rate_hit('apply|all');
    $club = md_org();
    $name = $club['name'] ?: MD_CLUB_NAME;
    // Adresse ist schon einem Mitglied zugeordnet: kein neuer Antrag, aber ein freundlicher Hinweis an die Adresse (verrät dem Besucher nichts)
    $known = md_one('SELECT member_id FROM svmembers WHERE LOWER(email) = LOWER(?)', [$email]);
    if ($known) {
        md_mail_now((int)$known['member_id'], $email, "Deine Anmeldung bei $name", "Hallo $first,\n\nzu dieser E-Mail-Adresse gibt es bei uns schon einen Zugang. Falls du dein Passwort nicht mehr kennst, kannst du hier ein neues festlegen lassen:\n" . md_public_url('forgot.php') . "\n\nWenn du dich nicht angemeldet hast, kannst du diese Nachricht ignorieren.\n" . md_mail_footer(), 'apply');
        return [true, ''];
    }
    $token = bin2hex(random_bytes(24));
    $vals = [$sal, $first, $last, $email, $birth, $street, $zip, $city, trim((string)($d['phone'] ?? '')) ?: null, $gid ?: null, $msg ?: null];
    $pend = md_one("SELECT app_id FROM md_applications WHERE LOWER(email) = LOWER(?) AND status IN ('unconfirmed','new')", [$email]);
    if ($pend) {
        md_exec("UPDATE md_applications SET salutation=?, first_name=?, last_name=?, email=?, birthdate=?, street=?, zip=?, city=?, phone=?, fee_group_id=?, message=?, token_hash=?, token_expires=?, status = CASE WHEN status = 'new' THEN 'new' ELSE 'unconfirmed' END WHERE app_id = ?",
            array_merge($vals, [hash('sha256', $token), date('Y-m-d H:i:s', time() + 48 * 3600), $pend['app_id']]));
        $appId = (int)$pend['app_id'];
    } else {
        md_exec('INSERT INTO md_applications (salutation, first_name, last_name, email, birthdate, street, zip, city, phone, fee_group_id, message, status, created_at, token_hash, token_expires, ack_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array_merge($vals, ['unconfirmed', md_now(), hash('sha256', $token), date('Y-m-d H:i:s', time() + 48 * 3600), md_now()]));
        $appId = (int)md_db()->lastInsertId();
    }
    $link = md_public_url('apply_confirm.php?token=' . $token);
    $body = "Hallo $first,\n\nvielen Dank für dein Interesse an $name! Bitte bestätige deine E-Mail-Adresse mit diesem Link (48 Stunden gültig):\n\n$link\n\nDanach prüft der Vorstand deinen Antrag und meldet sich bei dir.\nWenn du dich nicht angemeldet hast, kannst du diese Nachricht ignorieren; dann passiert nichts.\n" . md_mail_footer();
    md_mail_now(null, $email, "Bitte bestätige deine Anmeldung – $name", $body, 'apply');
    if (!md_is_test_instance()) md_exec("UPDATE md_mail_outbox SET body = ? WHERE kind = 'apply' AND to_email = ? AND body LIKE ?", [str_replace($link, '[Link entfernt]', $body), $email, '%' . $token . '%']);
    return [true, ''];
}

/** Bestätigt die E-Mail-Adresse. Gibt [ok, Fehlertext] zurück. */
function md_apply_confirm(string $token): array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) return [false, 'Der Link ist ungültig.'];
    $a = md_one("SELECT * FROM md_applications WHERE token_hash = ? AND status IN ('unconfirmed','new')", [hash('sha256', $token)]);
    if (!$a || ($a['token_expires'] && $a['token_expires'] < md_now())) return [false, 'Der Link ist ungültig oder abgelaufen. Bitte den Antrag noch einmal ausfüllen.'];
    if ($a['status'] === 'new') return [true, ''];
    md_exec("UPDATE md_applications SET status = 'new', confirmed_at = ?, token_hash = NULL, token_expires = NULL WHERE app_id = ?", [md_now(), $a['app_id']]);
    if (($to = trim(md_setting('apply.notify_email', ''))) !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
        md_mail_now(null, $to, 'Neue Mitgliedsanmeldung: ' . $a['first_name'] . ' ' . $a['last_name'], "Es liegt ein neuer, bestätigter Antrag vor:\n" . $a['first_name'] . ' ' . $a['last_name'] . "\n\nBearbeiten: " . md_public_url('admin/applications.php') . "\n", 'apply');
    }
    return [true, ''];
}

function md_apply_open_count(): int
{
    return (int)md_one("SELECT COUNT(*) c FROM md_applications WHERE status = 'new'")['c'];
}

/**
 * Nimmt einen Antrag auf: legt das Mitglied an (Rolle Mitglied, Eintritt, Anschrift, Beitragsgruppe) und lädt es per E-Mail ein.
 * $o: join_date (TT.MM.JJJJ, Standard heute), fee_group_id, group_ids[].
 * Gibt [member_id|null, Fehlertext, Hinweis] zurück.
 */
function md_apply_approve(int $appId, array $o, int $actor): array
{
    $a = md_one('SELECT * FROM md_applications WHERE app_id = ?', [$appId]);
    if (!$a || $a['status'] !== 'new') return [null, 'Antrag nicht gefunden oder schon entschieden.', ''];
    if (md_one('SELECT 1 FROM svmembers WHERE LOWER(email) = LOWER(?)', [$a['email']])) return [null, 'Zu dieser E-Mail-Adresse gibt es schon ein Mitglied.', ''];
    $join = trim((string)($o['join_date'] ?? '')) === '' ? date('Y-m-d') : md_parse_date($o['join_date']);
    if (!$join) return [null, 'Eintrittsdatum ungültig (TT.MM.JJJJ).', ''];
    $fg = (int)($o['fee_group_id'] ?? 0);
    if ($fg && !md_one('SELECT 1 FROM md_fee_groups WHERE group_id = ?', [$fg])) return [null, 'Beitragsgruppe nicht gefunden.', ''];
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $nr = md_import_next_number();
        while (md_one('SELECT 1 FROM svmembers WHERE membership_number = ?', [$nr])) $nr = (string)((int)$nr + 1);
        md_exec('INSERT INTO svmembers (membership_number, first_name, last_name, email, role) VALUES (?,?,?,?,?)', [$nr, $a['first_name'], $a['last_name'], $a['email'], md_sv_role_value('mitglied')]);
        $id = (int)$pdo->lastInsertId();
        md_log_change($id, 'created', null, 'Mitglied per Online-Anmeldung aufgenommen (Antrag #' . $appId . ')', $actor, 'admin');
        md_ensure_profile($id);
        $prof = array_filter(['salutation' => $a['salutation'], 'birthdate' => $a['birthdate'] ? md_date($a['birthdate']) : ''], fn($v) => $v !== null && $v !== '');
        if ($prof) md_save_profile($id, $prof, $actor, 'admin', array_keys($prof));
        md_save_club_data($id, ['status' => 'aktiv', 'join_date' => md_date($join)], $actor, 'admin');
        md_save_addresses($id, ['address_1_street' => $a['street'], 'address_1_zip' => $a['zip'], 'address_1_city' => $a['city'], 'address_1_label' => 'Privat'], $actor, 'admin', [1]);
        if ($a['phone']) md_save_contacts($id, ['contact_phone' => $a['phone']], $actor, 'admin', ['phone']);
        foreach (array_filter(array_map('intval', (array)($o['group_ids'] ?? []))) as $gid) {
            if (md_one('SELECT 1 FROM md_groups WHERE group_id = ?', [$gid])) {
                md_exec('INSERT INTO md_member_groups (member_id, group_id, since) VALUES (?,?,?)', [$id, $gid, $join]);
                md_log_change($id, 'group', null, md_one('SELECT name FROM md_groups WHERE group_id = ?', [$gid])['name'], $actor, 'admin');
            }
        }
        if ($fg && ($err = md_fee_assign($id, $fg, ['valid_from' => md_date($join)], $actor))) throw new RuntimeException('Beitragsgruppe: ' . $err);
        md_exec("UPDATE md_applications SET status = 'approved', decided_at = ?, decided_by = ?, member_id = ?, token_hash = NULL WHERE app_id = ?", [md_now(), $actor, $id, $appId]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return [null, $e->getMessage(), ''];
    }
    $note = '';
    $club = md_org();
    if ($err = md_login_send_link($id, 'invite', $actor)) $note = 'Die Einladung konnte nicht gesendet werden (' . $err . '). Bitte im Mitgliedsdatensatz unter Zugang erneut senden.';
    return [$id, '', $note];
}

/** Lehnt einen Antrag ab; der Antragsteller bekommt eine kurze E-Mail (mit Begründung, wenn angegeben). */
function md_apply_reject(int $appId, string $reason, int $actor): string
{
    $a = md_one('SELECT * FROM md_applications WHERE app_id = ?', [$appId]);
    if (!$a || $a['status'] !== 'new') return 'Antrag nicht gefunden oder schon entschieden.';
    $reason = trim($reason);
    md_exec("UPDATE md_applications SET status = 'rejected', decided_at = ?, decided_by = ?, decision_note = ?, token_hash = NULL WHERE app_id = ?", [md_now(), $actor, $reason !== '' ? mb_substr($reason, 0, 255) : null, $appId]);
    $club = md_org();
    $name = $club['name'] ?: MD_CLUB_NAME;
    md_mail_now(null, $a['email'], "Deine Anmeldung bei $name", "Hallo {$a['first_name']},\n\nleider können wir deinen Antrag auf Mitgliedschaft nicht annehmen." . ($reason !== '' ? "\n\n$reason" : '') . "\n\nBei Fragen melde dich gern beim Vorstand.\n" . md_mail_footer(), 'apply');
    return '';
}

/** Löscht unbestätigte Anträge nach 3 Tagen und entschiedene nach apply.keep_days (Standard 90). Gibt die Zahl gelöschter Anträge zurück. */
function md_apply_purge(?string $now = null): int
{
    $now = $now ?? date('Y-m-d H:i:s');
    $keep = max(7, (int)md_setting('apply.keep_days', '90'));
    $n = md_exec("DELETE FROM md_applications WHERE status = 'unconfirmed' AND created_at < ?", [date('Y-m-d H:i:s', strtotime('-42 days', strtotime($now)))]);
    $n += md_exec("DELETE FROM md_applications WHERE status IN ('approved','rejected') AND decided_at < ?", [date('Y-m-d H:i:s', strtotime("-$keep days", strtotime($now)))]);
    return (int)$n;
}
