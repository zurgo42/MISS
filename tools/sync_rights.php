<?php
/**
 * sync_rights.php – gleicht Funktionen mit Zeitraum täglich mit svmembers.aktiv/funktion/role ab
 * (Beginn und Ende von Ämtern wirken damit von selbst). Nur im Standalone-Betrieb.
 *
 * Aufruf (Cronjob, z. B. 00:10 Uhr): php tools/sync_rights.php [--dry-run]
 */
if (PHP_SAPI !== 'cli') exit('nur per Kommandozeile');
require_once dirname(__DIR__) . '/src/bootstrap.php';
$dry = in_array('--dry-run', $argv, true);
$changes = md_sync_all_rights(null, 'system', $dry);
foreach ($changes as $id => $c) {
    $m = md_one('SELECT first_name, last_name FROM svmembers WHERE member_id = ?', [$id]);
    printf("%s %s: aktiv %d→%d, Funktion %s→%s, Rolle %s→%s\n", $m['first_name'], $m['last_name'], $c['old']['aktiv'], $c['new']['aktiv'],
        $c['old']['funktion'] ?? '–', $c['new']['funktion'] ?? '–', $c['old']['role'], $c['new']['role']);
}
echo ($dry ? '[Probelauf] ' : '') . count($changes) . " Mitglied(er) " . ($dry ? 'würden angepasst' : 'angepasst') . ".\n";
