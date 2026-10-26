<?php
// Anmeldung mit dem Link aus der E-Mail (ohne Passwort): zweiter Faktor ist der Spitzname, sonst das Geburtsdatum.
require_once __DIR__ . '/src/bootstrap.php';
md_require_schema();
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$t = md_token_find($token, ['magic']);
$err = '';
$answer = '';
if ($t && $_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    $answer = trim((string)($_POST['answer'] ?? ''));
    [$m, $err] = md_magic_verify($token, $answer);
    if ($m) {
        md_login_start((int)$m['member_id']);
        header('Location: ' . md_url('index.php'));
        exit;
    }
    $t = md_token_find($token, ['magic']);   // nach zu vielen Fehlversuchen ist der Link ungültig
}
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
md_header('Anmeldung');
echo '<section class="card" style="max-width:26rem;margin:2rem auto"><h1>Anmeldung</h1>';
if (!$t) {
    if ($err) echo '<div class="flash err">' . h($err) . '</div>';
    else echo '<div class="flash err">Der Link ist ungültig, abgelaufen oder schon verwendet.</div>';
    echo '<p><a href="login.php?echt=1">Zur Anmeldung, dort kann ein neuer Link angefordert werden</a></p>';
} else {
    [$kind] = md_magic_factor((int)$t['member_id']);
    if ($err) echo '<div class="flash err">' . h($err) . '</div>';
    if ($kind === null) {
        echo '<div class="flash err">Für dieses Konto ist die Anmeldung ohne Passwort nicht möglich, weil weder Spitzname noch Geburtsdatum hinterlegt sind. Bitte mit Passwort anmelden.</div><p><a href="login.php?echt=1">Zur Anmeldung</a></p>';
    } else {
        $label = $kind === 'nickname' ? 'Dein Spitzname' : 'Dein Geburtsdatum (TT.MM.JJJJ)';
        echo '<p class="muted">Zur Sicherheit noch eine Angabe, dann bist du angemeldet.</p><form method="post">' . md_csrf_field() . '<input type="hidden" name="token" value="' . h($token) . '">'
            . '<label class="block">' . h($label) . '<input name="answer" value="' . h($answer) . '" required autofocus autocomplete="off" style="width:100%"></label><p><button>Anmelden</button></p></form>';
    }
}
echo '</section>';
md_footer();
