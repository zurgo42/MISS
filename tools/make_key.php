<?php
/**
 * make_key.php – erzeugt einen zufälligen Schlüssel für MD_ENCRYPTION_KEY (IBAN-Verschlüsselung der SEPA-Mandate).
 * Aufruf: php tools/make_key.php   – die Ausgabe in config.php eintragen: define('MD_ENCRYPTION_KEY', '...');
 * Den Schlüssel sicher aufbewahren und mit sichern. Ohne ihn sind gespeicherte IBANs nicht lesbar. Nie ändern, solange Mandate existieren.
 */
if (PHP_SAPI !== 'cli') exit('nur per Kommandozeile');
echo bin2hex(random_bytes(32)) . "\n";
