<?php
require_once __DIR__ . '/src/bootstrap.php';
md_require_schema();
$m = (int)($_GET['m'] ?? $_POST['m'] ?? 0);
$l = (int)($_GET['l'] ?? $_POST['l'] ?? 0);
$t = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$list = md_list_get($l);
$valid = $list && $m && hash_equals(md_unsub_token($m, $l), $t);
$done = false;
$err = '';
if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {   // erst per Klick, damit Mail-Scanner nichts abbestellen
    md_csrf_check();
    $err = md_list_set_optout($m, $l, true, $m);
    $done = $err === '';
}
md_header('Verteiler abbestellen');
echo '<section class="card" style="max-width:30rem;margin:2rem auto"><h1>Verteiler abbestellen</h1>';
if (!$valid) echo '<div class="flash err">Der Link ist ungültig.</div>';
elseif ($done) echo '<div class="flash ok">Du erhältst Nachrichten des Verteilers „' . h($list['name']) . '“ nicht mehr. Wieder anmelden kannst du dich jederzeit unter „Mein Zugang“.</div>';
else {
    if ($err) echo '<div class="flash err">' . h($err) . '</div>';
    echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="m" value="' . $m . '"><input type="hidden" name="l" value="' . $l . '"><input type="hidden" name="t" value="' . h($t) . '"><p>Verteiler „' . h($list['name']) . '“ nicht mehr erhalten?</p><button>Abbestellen</button></form>';
}
echo '</section>';
md_footer();
