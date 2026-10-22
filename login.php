<?php
require_once __DIR__ . '/src/bootstrap.php';
md_require_schema();

// Demo: Figur wählen (nur im Demo-Modus); die echte Anmeldung lässt sich mit ?echt=1 ausprobieren
if (MD_DEMO_MODE && isset($_GET['as'])) {
    $m = md_one('SELECT member_id FROM svmembers WHERE member_id = ?', [(int)$_GET['as']]);
    if ($m) md_login_start((int)$m['member_id']);
    header('Location: ' . md_url('index.php'));
    exit;
}
$err = '';
$ident = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    $ident = trim((string)($_POST['ident'] ?? ''));
    [$m, $err] = md_login_check($ident, (string)($_POST['password'] ?? ''));
    if ($m) {
        md_login_start((int)$m['member_id']);
        header('Location: ' . md_url('index.php'));
        exit;
    }
}
if (MD_DEMO_MODE && !isset($_GET['echt']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    md_header('Demo-Anmeldung');
    echo '<h1>Demo-Anmeldung</h1><p class="muted">Wähle eine Figur. Es sind dieselben wie in der Sitzungsverwaltung. <a href="login.php?echt=1">Echte Anmeldung mit Passwort ausprobieren</a></p><div class="grid3">';
    foreach (md_q('SELECT * FROM svmembers ORDER BY member_id') as $m) {
        echo '<a class="card pick" href="login.php?as=' . (int)$m['member_id'] . '"><span class="avatar">' . h(md_initials($m)) . '</span><strong>'
            . h($m['first_name'] . ' ' . $m['last_name']) . '</strong><span class="muted">' . h($m['role']) . (($fkt = $m['funktion'] ?? $m['Funktion'] ?? '') ? ' · ' . h($fkt) : '') . (!empty($m['is_admin']) ? ' · Admin' : '') . '</span></a>';
    }
    echo '</div>';
    md_footer();
    exit;
}
md_header('Anmeldung');
echo '<section class="card" style="max-width:26rem;margin:2rem auto"><h1>Anmeldung</h1>';
if ($err) echo '<div class="flash err">' . h($err) . '</div>';
md_show_flash();
echo '<form method="post" action="login.php' . (isset($_GET['echt']) ? '?echt=1' : '') . '" autocomplete="on">' . md_csrf_field()
    . '<label class="block">E-Mail-Adresse oder Mitgliedsnummer<input name="ident" value="' . h($ident) . '" autocomplete="username" required autofocus style="width:100%"></label>'
    . '<label class="block">Passwort<input type="password" name="password" autocomplete="current-password" required style="width:100%"></label>'
    . '<p><button>Anmelden</button></p></form>'
    . '<p class="muted"><a href="forgot.php">Passwort vergessen oder noch keins?</a></p></section>';
md_footer();
