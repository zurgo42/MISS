<?php
require_once __DIR__ . '/../src/bootstrap.php';
$viewer = md_require_login();
$p = md_one('SELECT * FROM md_photos WHERE photo_id = ?', [(int)($_GET['id'] ?? 0)]);
if (!$p) { http_response_code(404); exit; }
$owner = (int)$p['member_id'];
$allowed = md_is_admin($viewer) || md_can_see($viewer, $owner, 'photo.' . $p['slot'], md_visibility_map($owner));
$file = MD_STORAGE_DIR . '/photos/' . basename($p['file_name']);
if (!$allowed || !is_file($file)) { http_response_code(403); exit; }
header('Content-Type: ' . $p['mime']);
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'');
header('Cache-Control: private, max-age=300');
readfile($file);
