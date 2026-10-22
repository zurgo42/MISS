<?php
require_once __DIR__ . '/src/bootstrap.php';
$me = md_require_login();
$mid = (int)$me['member_id'];
$target = (int)($_GET['member'] ?? 0) ?: $mid;
// Die eigene Auskunft steht jedem Mitglied immer zu (auch ohne Zustimmung zu den Nutzungsbedingungen); für andere braucht es das Recht "Datenschutz".
if ($target !== $mid && !(md_consent_ok($mid) && md_can($me, 'privacy', 'view'))) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
if (!md_one('SELECT 1 FROM svmembers WHERE member_id = ?', [$target])) { http_response_code(404); exit('Mitglied nicht gefunden.'); }
$format = in_array($_GET['format'] ?? '', ['json', 'html', 'zip'], true) ? $_GET['format'] : 'html';
if ($format === 'zip' && !class_exists('ZipArchive')) { http_response_code(501); exit('Die PHP-Erweiterung "zip" ist nicht aktiv. Bitte JSON oder HTML verwenden.'); }

$data = md_privacy_export_data($target, $mid);
md_privacy_log($target, 'export', $mid, strtoupper($format) . ($target === $mid ? ' (selbst)' : ''));
$base = 'auskunft-' . ($data['stammdaten_sitzungsverwaltung']['membership_number'] ?? $target) . '-' . date('Y-m-d');
$base = preg_replace('/[^A-Za-z0-9_-]/', '_', $base);
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $base . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} elseif ($format === 'zip') {
    $tmp = tempnam(sys_get_temp_dir(), 'md');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('auskunft.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $zip->addFromString('auskunft.html', md_privacy_export_html($data));
    foreach (md_load_photos($target) as $ph) {
        $f = MD_STORAGE_DIR . '/photos/' . basename($ph['file_name']);
        if (is_file($f)) $zip->addFile($f, 'fotos/foto-' . (int)$ph['slot'] . '.' . pathinfo($f, PATHINFO_EXTENSION));
    }
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $base . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo md_privacy_export_html($data);
}
