<?php
/**
 * admin/wabs_csv.php - liefert die aktuelle Token-CSV einer Wahl zum
 * Herunterladen für den manuellen Import auf einem externen Wahlserver aus
 * (siehe admin/wabs.php). Kein eigenes Layout, da reine Dateiauslieferung.
 */
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('wahl', 'view');

$election = md_wabs_election_get((int)($_GET['election'] ?? 0));
if (!$election || empty($election['csv_file']) || !empty($election['csv_deleted_at'])) {
    http_response_code(404);
    exit('Keine Token-CSV vorhanden.');
}
$path = md_wabs_storage_dir() . '/' . $election['csv_file'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Die Datei wurde nicht gefunden.');
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $election['csv_file'] . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
