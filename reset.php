<?php
require_once __DIR__ . '/src/bootstrap.php';
md_require_schema();
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$t = md_token_find($token);
$err = '';
if ($t && $_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    $pw = (string)($_POST['password'] ?? '');
    if ($pw !== (string)($_POST['password2'] ?? '')) $err = 'Die beiden Eingaben stimmen nicht überein.';
    else {
        [$mid, $err] = md_login_use_token($token, $pw);
        if ($mid) {
            md_login_start($mid);
            md_flash('Das Passwort ist gespeichert. Du bist angemeldet.');
            header('Location: ' . md_url('index.php'));
            exit;
        }
    }
}
md_header('Passwort festlegen');
echo '<section class="card" style="max-width:26rem;margin:2rem auto"><h1>Passwort festlegen</h1>';
if (!$t) {
    echo '<div class="flash err">Der Link ist ungültig, abgelaufen oder schon verwendet.</div><p><a href="forgot.php">Neuen Link anfordern</a></p>';
} else {
    if ($err) echo '<div class="flash err">' . h($err) . '</div>';
    echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="token" value="' . h($token) . '">'
        . '<label class="block">Neues Passwort (mindestens ' . MD_PASSWORD_MIN . ' Zeichen)<input type="password" name="password" autocomplete="new-password" required autofocus style="width:100%"></label>'
        . '<label class="block">Wiederholen<input type="password" name="password2" autocomplete="new-password" required style="width:100%"></label><p><button>Passwort speichern</button></p></form>';
}
echo '</section>';
md_footer();
