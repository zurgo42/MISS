<?php
require_once __DIR__ . '/../src/bootstrap.php';
if (!MD_DEMO_MODE) { header('Location: ' . MD_LOGIN_URL); exit; }
if (isset($_GET['as'])) {
    $m = md_one('SELECT member_id FROM svmembers WHERE member_id = ?', [(int)$_GET['as']]);
    if ($m) { session_regenerate_id(true); $_SESSION['md_member_id'] = (int)$m['member_id']; }
    header('Location: /index.php');
    exit;
}
md_header('Demo-Anmeldung');
echo '<h1>Demo-Anmeldung</h1><p class="muted">Wähle eine Figur. Es sind dieselben wie in der Sitzungsverwaltung.</p><div class="grid3">';
foreach (md_q('SELECT * FROM svmembers ORDER BY member_id') as $m) {
    echo '<a class="card pick" href="/login.php?as=' . (int)$m['member_id'] . '"><span class="avatar">' . h(md_initials($m)) . '</span><strong>'
        . h($m['first_name'] . ' ' . $m['last_name']) . '</strong><span class="muted">' . h($m['role']) . ($m['funktion'] ? ' · ' . h($m['funktion']) : '') . ($m['is_admin'] ? ' · Admin' : '') . '</span></a>';
}
echo '</div>';
md_footer();
