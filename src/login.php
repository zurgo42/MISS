<?php
declare(strict_types=1);

/**
 * Eigene Anmeldung der Mitgliederverwaltung. Das Passwort steht (wie in der Sitzungsverwaltung) in svmembers.password_hash,
 * die Anmeldung gilt also für beide Anwendungen (gleiche Domain, gleiche Sitzung). Anmeldung mit E-Mail-Adresse oder Mitgliedsnummer.
 * Passwort setzen und vergessen laufen über Einmal-Links per E-Mail (nur der Hash des Links wird gespeichert).
 */

const MD_LOGIN_MAX_FAILS = 5;        // Fehlversuche je Kennung ...
const MD_LOGIN_WINDOW_MIN = 15;      // ... innerhalb dieser Minuten
const MD_PASSWORD_MIN = 10;

function md_login_ident_hash(string $ident): string
{
    return hash('sha256', 'md-login|' . mb_strtolower(trim($ident)));
}

function md_login_throttled(string $ident): bool
{
    $since = date('Y-m-d H:i:s', time() - MD_LOGIN_WINDOW_MIN * 60);
    return (int)md_one('SELECT COUNT(*) c FROM md_login_attempts WHERE ident_hash = ? AND attempt_at >= ?', [md_login_ident_hash($ident), $since])['c'] >= MD_LOGIN_MAX_FAILS;
}

function md_login_fail(string $ident): void
{
    md_exec('INSERT INTO md_login_attempts (ident_hash, attempt_at) VALUES (?,?)', [md_login_ident_hash($ident), md_now()]);
    md_exec('DELETE FROM md_login_attempts WHERE attempt_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
}

/** Mitglied zu E-Mail-Adresse oder Mitgliedsnummer; gesperrte Zugänge (is_active = 0) und anonymisierte Datensätze zählen nicht. */
function md_login_find(string $ident): ?array
{
    $ident = trim($ident);
    if ($ident === '') return null;
    $rows = md_q('SELECT * FROM svmembers WHERE LOWER(email) = LOWER(?) OR membership_number = ?', [$ident, $ident]);
    $rows = array_values(array_filter($rows, fn($m) => (int)($m['is_active'] ?? 1) !== 0 && !str_starts_with((string)$m['membership_number'], 'DEL-')));
    return count($rows) === 1 ? $rows[0] : null;   // mehrdeutig (gleiche Adresse bei mehreren) → nicht anmelden
}

/** Prüft Kennung und Passwort. Gibt [Mitglied|null, Fehlertext] zurück; der Text verrät nicht, ob die Kennung existiert. */
function md_login_check(string $ident, string $password): array
{
    if (md_login_throttled($ident)) return [null, 'Zu viele Fehlversuche. Bitte in ' . MD_LOGIN_WINDOW_MIN . ' Minuten erneut versuchen oder das Passwort zurücksetzen.'];
    $m = md_login_find($ident);
    $hash = $m['password_hash'] ?? null;
    // immer eine Prüfung durchführen, damit die Antwortzeit nichts verrät
    $ok = password_verify($password, $hash ?: '$2y$10$usesomesillystringforsalt0u7c6mO1L0GmQz2v5oQz0n3H0e1iS');
    if (!$m || !$hash || !$ok) {
        md_login_fail($ident);
        return [null, 'Anmeldung nicht möglich. Bitte Eingaben prüfen oder das Passwort zurücksetzen.'];
    }
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) md_exec('UPDATE svmembers SET password_hash = ? WHERE member_id = ?', [password_hash($password, PASSWORD_DEFAULT), $m['member_id']]);
    md_exec('DELETE FROM md_login_attempts WHERE ident_hash = ?', [md_login_ident_hash($ident)]);
    return [$m, ''];
}

function md_login_start(int $member): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $_SESSION['md_member_id'] = $member;
    $_SESSION[MD_SESSION_KEY] = $member;
}

function md_logout(): void
{
    unset($_SESSION['md_member_id'], $_SESSION[MD_SESSION_KEY], $_SESSION['md_csrf']);
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
}

function md_password_error(string $pw, array $m): string
{
    if (mb_strlen($pw) < MD_PASSWORD_MIN) return 'Das Passwort muss mindestens ' . MD_PASSWORD_MIN . ' Zeichen lang sein.';
    if (mb_strlen($pw) > 200) return 'Das Passwort ist zu lang.';
    $l = mb_strtolower($pw);
    foreach ([$m['email'] ?? '', $m['membership_number'] ?? '', ($m['first_name'] ?? '') . ($m['last_name'] ?? '')] as $bad) {
        if ($bad !== '' && $l === mb_strtolower((string)$bad)) return 'Das Passwort darf nicht mit E-Mail-Adresse, Mitgliedsnummer oder Namen übereinstimmen.';
    }
    if (preg_match('/^(.)\1+$/u', $pw) || in_array($l, ['passwort123', 'password123', '1234567890', 'qwertzuiop', 'abcdefghij'], true)) return 'Das Passwort ist zu einfach.';
    return '';
}

function md_password_set(int $member, string $pw, ?int $by = null): string
{
    $m = md_one('SELECT * FROM svmembers WHERE member_id = ?', [$member]);
    if (!$m) return 'Mitglied nicht gefunden.';
    if (!array_key_exists('password_hash', $m)) return 'In svmembers fehlt die Spalte password_hash (tools/install.php ausführen).';
    if ($err = md_password_error($pw, $m)) return $err;
    md_exec('UPDATE svmembers SET password_hash = ? WHERE member_id = ?', [password_hash($pw, PASSWORD_DEFAULT), $member]);
    md_log_change($member, 'account', 'Passwort', $by && $by !== $member ? 'von der Verwaltung gesetzt' : 'geändert', $by ?? $member, $by && $by !== $member ? 'admin' : 'self');
    return '';
}

// ---------------------------------------------------------------- Einmal-Links

/** Erzeugt einen Link-Token (Klartext nur hier; gespeichert wird der Hash). Frühere offene Token des Mitglieds werden ungültig. */
function md_token_create(int $member, string $kind, int $hours): string
{
    md_exec('UPDATE md_login_tokens SET used_at = ? WHERE member_id = ? AND used_at IS NULL', [md_now(), $member]);
    $t = bin2hex(random_bytes(24));
    md_exec('INSERT INTO md_login_tokens (member_id, token_hash, kind, created_at, expires_at) VALUES (?,?,?,?,?)',
        [$member, hash('sha256', $t), $kind, md_now(), date('Y-m-d H:i:s', time() + $hours * 3600)]);
    return $t;
}

/** Gültiger, unbenutzter Token oder null. */
function md_token_find(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) return null;
    $r = md_one('SELECT * FROM md_login_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at >= ?', [hash('sha256', $token), md_now()]);
    return $r ?: null;
}

function md_public_url(string $path): string
{
    if (defined('MD_PUBLIC_URL') && trim((string)MD_PUBLIC_URL) !== '') return rtrim((string)MD_PUBLIC_URL, '/') . '/' . ltrim($path, '/');
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.-]+(:\d+)?$/', $host)) $host = 'localhost';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    return ($https ? 'https' : 'http') . '://' . $host . md_url($path);
}

/** Einladung (neuer Zugang) oder Zurücksetzen: Mail mit Link senden. Gibt Fehlertext oder '' zurück. */
function md_login_send_link(int $member, string $kind, ?int $by = null): string
{
    $m = md_one('SELECT * FROM svmembers WHERE member_id = ?', [$member]);
    $to = md_mail_address_of($member);
    if (!$m || !$to) return 'Keine gültige E-Mail-Adresse beim Mitglied.';
    $club = md_org();
    $name = $club['name'] ?: MD_CLUB_NAME;
    $hours = $kind === 'invite' ? 168 : 2;
    $token = md_token_create($member, $kind, $hours);
    $link = md_public_url('reset.php?token=' . $token);
    if ($kind === 'invite') {
        $subj = "Dein Zugang zu den Mitgliederdaten – $name";
        $body = "Hallo {$m['first_name']},\n\nfür dich wurde ein Zugang zu den Mitgliederdaten von $name eingerichtet. Mit diesem Link legst du dein Passwort fest (7 Tage gültig, nur einmal verwendbar):\n\n$link\n\nDanach meldest du dich mit deiner E-Mail-Adresse oder Mitgliedsnummer ({$m['membership_number']}) an.\n";
    } else {
        $subj = "Passwort zurücksetzen – $name";
        $body = "Hallo {$m['first_name']},\n\nfür dein Konto wurde ein neues Passwort angefordert. Mit diesem Link legst du es fest (2 Stunden gültig, nur einmal verwendbar):\n\n$link\n\nWenn du das nicht angefordert hast, kannst du diese Nachricht ignorieren; dein bisheriges Passwort bleibt dann gültig.\n";
    }
    $full = $body . md_mail_footer();
    $err = md_mail_now($member, $to, $subj, $full, $kind, null, $by);
    // der Link steht nur in der gesendeten Nachricht; im Ausgang bleibt er nicht lesbar
    if (!md_is_test_instance() || $err) md_exec("UPDATE md_mail_outbox SET body = ?" . ($err ? ", status = 'cancelled'" : '') . " WHERE member_id = ? AND kind = ? AND body LIKE ?", [str_replace($link, '[Link entfernt]', $full), $member, $kind, '%' . $token . '%']);
    if (!$err) md_log_change($member, 'account', $kind === 'invite' ? 'Einladung' : 'Passwort-Link', 'per E-Mail gesendet', $by ?? $member, $by && $by !== $member ? 'admin' : 'self');
    return $err;
}

/** „Passwort vergessen“: antwortet immer gleich; höchstens 3 Links je Stunde und Mitglied. */
function md_login_request_reset(string $ident): void
{
    $m = md_login_find($ident);
    if (!$m || !md_mail_address_of((int)$m['member_id'])) return;
    $recent = (int)md_one('SELECT COUNT(*) c FROM md_login_tokens WHERE member_id = ? AND created_at >= ?', [$m['member_id'], date('Y-m-d H:i:s', time() - 3600)])['c'];
    if ($recent >= 3) return;
    md_login_send_link((int)$m['member_id'], 'reset');
}

/** Passwort mit Einmal-Link setzen. Gibt [member|null, Fehlertext] zurück. */
function md_login_use_token(string $token, string $pw): array
{
    $t = md_token_find($token);
    if (!$t) return [null, 'Der Link ist ungültig oder abgelaufen. Bitte einen neuen anfordern.'];
    if ($err = md_password_set((int)$t['member_id'], $pw)) return [null, $err];
    md_exec('UPDATE md_login_tokens SET used_at = ? WHERE member_id = ? AND used_at IS NULL', [md_now(), $t['member_id']]);
    md_exec('DELETE FROM md_login_attempts WHERE ident_hash IN (?, ?)', [md_login_ident_hash((string)md_one('SELECT email FROM svmembers WHERE member_id = ?', [$t['member_id']])['email']), md_login_ident_hash((string)md_one('SELECT membership_number FROM svmembers WHERE member_id = ?', [$t['member_id']])['membership_number'])]);
    return [(int)$t['member_id'], ''];
}

/** Mitglieder ohne Passwort, mit gültiger Adresse und aktivem Zugang. */
function md_login_uninvited(): array
{
    return array_values(array_filter(md_q("SELECT * FROM svmembers WHERE (password_hash IS NULL OR password_hash = '') AND COALESCE(is_active, 1) <> 0 AND membership_number NOT LIKE 'DEL-%' ORDER BY last_name, first_name"),
        fn($m) => md_mail_address_of((int)$m['member_id']) !== null));
}
