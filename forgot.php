<?php
require_once __DIR__ . '/src/bootstrap.php';
md_require_schema();
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    $ident = trim((string)($_POST['ident'] ?? ''));
    // Antwort ist immer dieselbe, damit niemand Adressen ausprobieren kann
    if ($ident !== '' && !md_login_throttled('reset|' . $ident)) {
        md_login_fail('reset|' . $ident);
        md_login_request_reset($ident);
    }
    $done = true;
}
md_header('Passwort');
echo '<section class="card" style="max-width:26rem;margin:2rem auto"><h1>Passwort festlegen oder zurücksetzen</h1>';
if ($done) {
    echo '<div class="flash ok">Wenn zu dieser Angabe ein Zugang mit E-Mail-Adresse existiert, ist ein Link zum Festlegen des Passworts unterwegs (2 Stunden gültig). Bitte auch den Spam-Ordner prüfen.</div><p><a href="login.php">Zur Anmeldung</a></p>';
} else {
    echo '<form method="post">' . md_csrf_field() . '<label class="block">E-Mail-Adresse oder Mitgliedsnummer<input name="ident" required autofocus style="width:100%"></label><p><button>Link per E-Mail senden</button></p></form>'
        . '<p class="muted"><a href="login.php">Zurück zur Anmeldung</a></p>';
}
echo '</section>';
md_footer();
