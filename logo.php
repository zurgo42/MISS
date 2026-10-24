<?php
// Vereinslogo (öffentlich, damit es auch auf der Anmeldeseite erscheint). Hochgeladen unter Einstellungen → Verein, sonst ein Standardzeichen.
require_once __DIR__ . '/src/bootstrap.php';
$f = md_setting('club.logo_file', '');
$path = $f !== '' ? MD_STORAGE_DIR . '/' . basename($f) : '';
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');
if ($path !== '' && is_file($path)) {
    $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    $mime = $types[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    if ($mime) { header('Content-Type: ' . $mime); readfile($path); exit; }
}
$club = md_club();
$ini = mb_strtoupper(mb_substr(trim($club['name'] ?: MD_CLUB_NAME) ?: 'V', 0, 1));
header('Content-Type: image/svg+xml');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><path d="M32 3 59 13v21c0 13-11 23-27 27C16 57 5 47 5 34V13z" fill="#FAAA00"/><path d="M32 8 54 16v18c0 10-8 18-22 22-14-4-22-12-22-22V16z" fill="#fff" opacity=".18"/>'
    . '<text x="32" y="43" font-family="Georgia,serif" font-size="30" font-weight="700" text-anchor="middle" fill="#005596">' . htmlspecialchars($ini) . '</text></svg>';
