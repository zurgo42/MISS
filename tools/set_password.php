<?php
/**
 * set_password.php – setzt das Passwort eines Mitglieds (nur für die Testphase, nur auf Demo- und Testsystemen).
 *
 *   php tools/set_password.php DEMO0001              setzt ein zufälliges Passwort und zeigt es an
 *   php tools/set_password.php DEMO0001 "Mein-Passwort-123"
 *   php tools/set_password.php max@example.com        (E-Mail-Adresse statt Mitgliedsnummer)
 *
 * Hebt auch eine Anmeldesperre (zu viele Fehlversuche) für dieses Mitglied auf.
 * TODO nach der Testphase: diese Datei löschen (siehe docs/PRODUKTIV.md, Abschnitt 6).
 */
if (PHP_SAPI !== 'cli') exit('nur per Kommandozeile');
require_once dirname(__DIR__) . '/src/bootstrap.php';
if (!MD_DEMO_MODE && !md_is_test_instance()) exit("Abbruch: Dieses Skript läuft nur mit MD_DEMO_MODE=true oder MD_TEST_INSTANCE=true (nie auf einem Echtsystem).\n");
$ident = trim((string)($argv[1] ?? ''));
if ($ident === '') exit("Aufruf: php tools/set_password.php <Mitgliedsnummer oder E-Mail> [Passwort]\n");
$m = md_one('SELECT * FROM svmembers WHERE membership_number = ? OR LOWER(email) = LOWER(?)', [$ident, $ident]);
if (!$m) exit("Mitglied '$ident' nicht gefunden.\n");
if (!MD_DEMO_MODE && !preg_match('/^DEMO\d+$/', (string)$m['membership_number'])) exit("Abbruch: Auf Testsystemen nur für Demo-Figuren (DEMO…), nicht für {$m['membership_number']}.\n");
$pw = $argv[2] ?? '';
if ($pw === '') {
    $a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($i = 0; $i < 12; $i++) $pw .= $a[random_int(0, strlen($a) - 1)];
}
if ($err = md_password_set((int)$m['member_id'], $pw)) exit("$err\n");
foreach ([$m['email'], $m['membership_number']] as $k) md_exec('DELETE FROM md_login_attempts WHERE ident_hash = ?', [md_login_ident_hash((string)$k)]);
echo "Passwort für {$m['first_name']} {$m['last_name']} ({$m['membership_number']}, {$m['email']}) gesetzt:\n  $pw\n";
