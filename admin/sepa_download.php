<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('sepa', 'edit');
$b = md_one('SELECT * FROM md_sepa_batches WHERE batch_id = ?', [(int)($_GET['id'] ?? 0)]);
if (!$b || $b['status'] === 'void') { http_response_code(404); exit('Lauf nicht gefunden.'); }
try { $xml = md_sepa_xml((int)$b['batch_id']); } catch (Throwable $e) { http_response_code(500); exit('Datei konnte nicht erzeugt werden: ' . $e->getMessage()); }
md_sepa_event((int)$b['batch_id'], 'downloaded', (int)$me['member_id'], 'XML ' . $b['format']);
header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="lastschrift-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $b['batch_no']) . '.xml"');
header('Cache-Control: private, no-store');
echo $xml;
