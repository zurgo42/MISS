<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('wahl', 'view');
$edit = md_can($me, 'wahl', 'edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    if (!$edit) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
    $err = md_wabs_election_create((string)($_POST['title'] ?? ''), (string)($_POST['start_at'] ?? ''), (string)($_POST['end_at'] ?? ''), (int)$me['member_id']);
    md_flash($err ?: 'Wahl angelegt.', $err ? 'err' : 'ok');
    header('Location: ' . md_url('admin/wabs.php'));
    exit;
}

$elections = md_wabs_elections();

md_header('Wahl und Abstimmung', $me, 'wabs');
md_show_flash();
echo '<h1>Wahl und Abstimmung (WABS)</h1><p class="muted">Hier legt der Wahlausschuss eine Wahl mit Bezeichnung, Start und Ende an. Die Wahlaufforderung (Token per E-Mail/PDF) und die Übertragung zum Wahlserver folgen in einem späteren Schritt.</p>';

echo '<section class="card"><h2>Angelegte Wahlen</h2><table><thead><tr><th>Bezeichnung</th><th>Start</th><th>Ende</th><th>Status</th></tr></thead><tbody>';
foreach ($elections as $e) {
    echo '<tr><td>' . h($e['title']) . '</td><td>' . h(date('d.m.Y H:i', strtotime($e['start_at']))) . '</td><td>' . h(date('d.m.Y H:i', strtotime($e['end_at']))) . '</td><td>' . h($e['status']) . '</td></tr>';
}
if (!$elections) echo '<tr><td colspan="4" class="muted">Noch keine Wahl angelegt.</td></tr>';
echo '</tbody></table></section>';

if ($edit) {
    echo '<section class="card"><h2>Neue Wahl anlegen</h2><form method="post">' . md_csrf_field()
        . '<label class="block">Bezeichnung<input name="title" placeholder="z. B. Wahl des Vorstands und der Kassenprüfer" required style="width:100%" maxlength="200"></label>'
        . '<div class="addr"><label>Start<input type="datetime-local" name="start_at" required></label><label>Ende<input type="datetime-local" name="end_at" required></label></div>'
        . '<button>Anlegen</button></form></section>';
}
md_footer();
