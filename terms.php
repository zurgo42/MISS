<?php
require_once __DIR__ . '/src/bootstrap.php';
$me = md_require_login();
$id = (int)($_GET['id'] ?? 0);
$t = $id ? md_one('SELECT * FROM md_terms_versions WHERE terms_id = ?', [$id]) : md_current_terms();
md_header('Nutzungsbedingungen', $me);
if (!$t) { echo '<p>Keine Nutzungsbedingungen hinterlegt.</p>'; md_footer(); exit; }
echo '<h1>' . h($t['title']) . '</h1><p class="muted">Version ' . h($t['version_label']) . ' · gültig ab ' . h(md_date($t['valid_from'])) . '</p>';
echo '<div class="card prose">' . nl2br(h($t['body'])) . '</div><p><a href="index.php">← zurück zum Dashboard</a></p>';
md_footer();
