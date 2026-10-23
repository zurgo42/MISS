<?php
require_once __DIR__ . '/src/bootstrap.php';
md_require_schema();
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$res = null;
// Bestätigung erst per Klick (POST), damit Mail-Scanner den Link nicht „abholen“
if ($_SERVER['REQUEST_METHOD'] === 'POST') { md_csrf_check(); $res = md_apply_confirm($token); }
md_header('Anmeldung bestätigen');
echo '<section class="card" style="max-width:30rem;margin:2rem auto"><h1>Anmeldung bestätigen</h1>';
if ($res) {
    echo $res[0] ? '<div class="flash ok">Danke, deine Adresse ist bestätigt. Der Vorstand prüft deinen Antrag und meldet sich bei dir.</div>' : '<div class="flash err">' . h($res[1]) . '</div>';
} else {
    echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="token" value="' . h($token) . '"><p>Mit einem Klick bestätigst du deine E-Mail-Adresse und schickst den Antrag an den Vorstand.</p><button>E-Mail-Adresse bestätigen</button></form>';
}
echo '</section>';
md_footer();
