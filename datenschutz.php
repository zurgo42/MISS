<?php
// Öffentliche Seite: Inhalt kommt aus der Administration (Verein → Datenschutzerklärung).
require_once __DIR__ . '/src/bootstrap.php';
md_require_schema();
$text = trim(md_setting('org.privacy_text', ''));
$me = null;
$id = $_SESSION[MD_DEMO_MODE ? 'md_member_id' : MD_SESSION_KEY] ?? null;
if ($id) $me = md_one('SELECT * FROM svmembers WHERE member_id = ?', [(int)$id]) ?: null;
md_header('Datenschutzerklärung', $me);
echo '<h1>Datenschutzerklärung</h1>';
if ($text === '') echo '<p class="muted">Der Verein hat hierzu noch keine Angaben hinterlegt.</p>';
else echo '<div class="prose">' . implode('', array_map(fn($p) => '<p>' . nl2br(h(trim($p))) . '</p>', preg_split('/\n{2,}/', str_replace("\r", '', $text)))) . '</div>';
md_footer();
