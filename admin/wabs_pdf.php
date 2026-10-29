<?php
/**
 * admin/wabs_pdf.php - liefert den aktuellen PDF-Sammeldruck einer Wahl zum
 * Herunterladen/Drucken aus (siehe admin/wabs.php). Kein eigenes Layout, da
 * reine Dateiauslieferung.
 */
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('wahl', 'view');

$election = md_wabs_election_get((int)($_GET['election'] ?? 0));
if (!$election || empty($election['pdf_file']) || !empty($election['pdf_deleted_at'])) {
    http_response_code(404);
    exit('Kein PDF-Sammeldruck vorhanden.');
}
$path = md_wabs_storage_dir() . '/' . $election['pdf_file'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Die Datei wurde nicht gefunden.');
}
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $election['pdf_file'] . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
