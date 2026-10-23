<?php
/**
 * login_check.php – erklärt, warum eine Anmeldung nicht klappt (zeigt nichts Geheimes: nur die Art des Passwort-Hashes).
 *
 *   php tools/login_check.php dr.hm@gmx.de            Konto prüfen
 *   php tools/login_check.php dr.hm@gmx.de "Passwort" zusätzlich das Passwort prüfen
 */
if (PHP_SAPI !== 'cli') exit('nur per Kommandozeile');
require_once dirname(__DIR__) . '/src/bootstrap.php';
$ident = trim((string)($argv[1] ?? ''));
if ($ident === '') exit("Aufruf: php tools/login_check.php <E-Mail oder Mitgliedsnummer> [Passwort]\n");
echo 'Modus: ' . (MD_DEMO_MODE ? 'Demo (Figurenauswahl, echte Anmeldung nur über login.php?echt=1)' : (md_is_test_instance() ? 'Testsystem' : 'Echtbetrieb')) . "\n";
$cols = md_sv_columns();
echo 'svmembers.password_hash: ' . (in_array('password_hash', $cols, true) ? 'Spalte vorhanden' : 'SPALTE FEHLT – ohne sie ist keine Anmeldung mit Passwort möglich') . "\n";
$rows = md_q('SELECT * FROM svmembers WHERE LOWER(email) = LOWER(?) OR membership_number = ?', [$ident, $ident]);
echo count($rows) . " Treffer für '$ident'\n";
if (!$rows) {
    echo "→ Grund: Kein Mitglied mit dieser E-Mail-Adresse oder Mitgliedsnummer. Vorhandene Konten (erste 25):\n";
    foreach (md_q('SELECT member_id, membership_number, first_name, last_name, email FROM svmembers ORDER BY member_id LIMIT 25') as $m) printf("  #%-3d %-10s %-24s %s\n", $m['member_id'], $m['membership_number'], $m['first_name'] . ' ' . $m['last_name'], $m['email']);
    exit("Mit einer dieser E-Mail-Adressen oder Mitgliedsnummern erneut prüfen.\n");
}
foreach ($rows as $m) {
    $h = (string)($m['password_hash'] ?? '');
    $scheme = $h === '' ? 'LEER (noch kein Passwort)' : (preg_match('/^\$(2[aby]|argon2id?)\$/', $h, $x) ? 'password_hash()-Format ' . $x[1] . ', Länge ' . strlen($h) : (preg_match('/^[0-9a-f]{32}$/i', $h) ? 'sieht nach MD5 aus (nicht kompatibel)' : (preg_match('/^[0-9a-f]{40}$/i', $h) ? 'sieht nach SHA-1 aus (nicht kompatibel)' : (preg_match('/^[0-9a-f]{64}$/i', $h) ? 'sieht nach SHA-256 aus (nicht kompatibel)' : 'unbekanntes Format, Länge ' . strlen($h)))));
    printf("  #%d %s %s, Nr. %s, E-Mail %s\n    Rolle %s, is_active=%s, Passwort-Hash: %s\n", $m['member_id'], $m['first_name'], $m['last_name'], $m['membership_number'], $m['email'], $m['role'] ?? '?', $m['is_active'] ?? '(Spalte fehlt)', $scheme);
    if (isset($argv[2]) && $h !== '') echo '    Passwort stimmt: ' . (password_verify($argv[2], $h) ? 'JA' : 'NEIN') . "\n";
}
echo 'Mehrdeutig (mehrere Treffer, dann keine Anmeldung): ' . (count($rows) > 1 ? 'JA' : 'nein') . "\n";
echo 'Gesperrt durch Fehlversuche: ' . (md_login_throttled($ident) ? 'JA (15 Minuten warten oder tools/set_password.php)' : 'nein') . "\n";
$f = md_login_find($ident);
echo 'Anmeldung grundsätzlich möglich: ' . ($f ? ($f['password_hash'] ? 'ja' : 'NEIN – kein Passwort gesetzt') : 'NEIN – Konto gesperrt (is_active=0), anonymisiert oder mehrdeutig') . "\n";
if (!$f || empty($f['password_hash'])) echo "→ Lösung: php tools/set_password.php $ident   (setzt ein Passwort; nur auf Demo-/Testsystemen)\n";
elseif (!preg_match('/^\$(2[aby]|argon2id?)\$/', (string)$f['password_hash'])) echo "→ Der Hash kommt nicht von password_hash(): die Sitzungsverwaltung nutzt ein anderes Verfahren. Mit tools/set_password.php ein neues Passwort setzen (gilt dann für beide, falls die Sitzungsverwaltung password_verify nutzt).\n";
