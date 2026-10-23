<?php
/**
 * test_setup.php – richtet ein Testsystem für externe Tester ein (siehe docs/PRODUKTIV.md).
 *
 *   php tools/test_setup.php            einrichten (nur in einer leeren Testdatenbank)
 *   php tools/test_setup.php --reset    alle Testdaten löschen und neu aufbauen
 *   php tools/test_setup.php --update   nach einem Software-Update: Tabellen ergänzen, nichts löschen
 */
if (PHP_SAPI !== 'cli') exit('nur per Kommandozeile');
$root = dirname(__DIR__);
require_once "$root/src/bootstrap.php";
require_once __DIR__ . '/test_setup_lib.php';
if (in_array('--update', $argv, true)) { echo implode("\n", md_test_update()) . "\n"; exit; }
[$log, $creds] = md_test_setup(in_array('--reset', $argv, true));
echo implode("\n", $log) . "\n";
if ($creds) {
    echo "\nZugangsdaten der Testfiguren (Anmeldung mit E-Mail oder Mitgliedsnummer):\n";
    foreach ($creds as $c) printf("  %-22s %-9s %-34s %-22s %s\n", $c['name'], $c['number'], $c['email'], $c['role'], $c['password']);
    echo "\nAnmeldung: " . md_url('login.php') . "\n";
}
