<?php
require_once __DIR__ . '/src/bootstrap.php';
$me = md_require_login();
$mid = (int)$me['member_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    $act = $_POST['action'] ?? '';
    $err = '';
    if ($act === 'password') {
        $new = (string)($_POST['password'] ?? '');
        if (!empty($me['password_hash']) && !password_verify((string)($_POST['current'] ?? ''), $me['password_hash'])) $err = 'Das aktuelle Passwort stimmt nicht.';
        elseif ($new !== (string)($_POST['password2'] ?? '')) $err = 'Die beiden neuen Eingaben stimmen nicht überein.';
        else $err = md_password_set($mid, $new);
        md_flash($err ?: 'Passwort geändert.', $err ? 'err' : 'ok');
    } elseif ($act === 'channel') {
        $ch = ($_POST['letter_channel'] ?? 'post') === 'mail' ? 'mail' : 'post';
        if ($ch === 'mail' && !md_mail_address_of($mid)) { md_flash('Für den Versand per E-Mail wird eine gültige E-Mail-Adresse benötigt.', 'err'); }
        else {
            $old = md_one('SELECT letter_channel FROM md_profiles WHERE member_id = ?', [$mid])['letter_channel'] ?? 'post';
            if ($old !== $ch) {
                if (!md_one('SELECT 1 FROM md_profiles WHERE member_id = ?', [$mid])) md_exec('INSERT INTO md_profiles (member_id) VALUES (?)', [$mid]);
                md_exec('UPDATE md_profiles SET letter_channel = ? WHERE member_id = ?', [$ch, $mid]);
                md_log_change($mid, 'account', 'Rechnungen und Mahnungen', $ch === 'mail' ? 'per E-Mail' : 'per Post', $mid, 'self');
            }
            md_flash('Gespeichert.');
        }
    }
    header('Location: ' . md_url('account.php'));
    exit;
}
$ch = md_one('SELECT letter_channel FROM md_profiles WHERE member_id = ?', [$mid])['letter_channel'] ?? 'post';
md_header('Mein Zugang', $me);
md_show_flash();
echo '<h1>Mein Zugang</h1><section class="card"><h2>Passwort ändern</h2>';
if (empty($me['password_hash'])) echo '<p class="muted">Du hast noch kein eigenes Passwort. Du kannst jetzt eins festlegen.</p>';
echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="password">'
    . (empty($me['password_hash']) ? '' : '<label class="block">Aktuelles Passwort<input type="password" name="current" autocomplete="current-password" required></label>')
    . '<label class="block">Neues Passwort (mindestens ' . MD_PASSWORD_MIN . ' Zeichen)<input type="password" name="password" autocomplete="new-password" required></label>'
    . '<label class="block">Wiederholen<input type="password" name="password2" autocomplete="new-password" required></label><button>Passwort speichern</button></form></section>';
echo '<section class="card"><h2>Rechnungen und Mahnungen</h2><p class="muted">Wie sollen wir dir Rechnungen und Zahlungserinnerungen zustellen? Die E-Mail geht an ' . h($me['email']) . '. Die Unterlagen siehst du auch jederzeit im Dashboard.</p>'
    . '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="channel"><label class="block"><input type="radio" name="letter_channel" value="post"' . ($ch === 'post' ? ' checked' : '') . '> per Post</label>'
    . '<label class="block"><input type="radio" name="letter_channel" value="mail"' . ($ch === 'mail' ? ' checked' : '') . '> per E-Mail</label><button>Speichern</button></form></section>';
md_footer();
