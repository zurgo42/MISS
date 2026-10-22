<?php
declare(strict_types=1);

/** Angemeldetes Mitglied (Zeile aus svmembers) oder Weiterleitung zur Anmeldung. */
function md_require_login(): array
{
    md_require_schema();
    $id = $_SESSION[MD_DEMO_MODE ? 'md_member_id' : MD_SESSION_KEY] ?? null;
    $m = $id ? md_one('SELECT * FROM svmembers WHERE member_id = ?', [(int)$id]) : null;
    if (!$m) {
        header('Location: ' . (MD_DEMO_MODE ? md_url('login.php') : MD_LOGIN_URL));
        exit;
    }
    return $m;
}

function md_is_admin(array $m): bool
{
    return !empty($m['is_admin']);
}

/** Vorstand/GF-Rolle, Admins und alle mit Recht "Mitglieder ansehen" zählen für die Sichtbarkeitsstufe "Vorstand/Verwaltung". */
function md_is_privileged(array $m): bool
{
    return md_is_admin($m) || in_array(strtolower((string)($m['role'] ?? '')), ['vorstand', 'gf'], true) || md_can($m, 'members', 'view');
}

function md_csrf_token(): string
{
    if (empty($_SESSION['md_csrf'])) $_SESSION['md_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['md_csrf'];
}

function md_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(md_csrf_token()) . '">';
}

function md_csrf_check(): void
{
    if (!hash_equals($_SESSION['md_csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Ungültiges Formular (CSRF). Bitte Seite neu laden.');
    }
}

/**
 * Ziel-Mitglied einer Aktion: das eigene, oder ein fremdes (?member=ID) mit dem Recht "Mitglieder" (ansehen bzw. bearbeiten, je nach $need).
 * Ohne Zustimmung zu den Nutzungsbedingungen gibt es nur die eigenen Daten.
 * Gibt [Zielmitglied, Weg 'self'|'admin'] zurück.
 */
function md_target_member(array $actor, $requested, string $need = 'view'): array
{
    $target = (int)$requested ?: (int)$actor['member_id'];
    if ($target === (int)$actor['member_id']) return [$actor, 'self'];
    if (!md_consent_ok((int)$actor['member_id']) || !md_can($actor, 'members', $need)) {
        http_response_code(403);
        exit('Keine Berechtigung für dieses Mitglied.');
    }
    $m = md_one('SELECT * FROM svmembers WHERE member_id = ?', [$target]);
    if (!$m) {
        http_response_code(404);
        exit('Mitglied nicht gefunden.');
    }
    return [$m, 'admin'];
}
