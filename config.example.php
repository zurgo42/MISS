<?php
/**
 * Vorlage: nach config.php kopieren und anpassen. config.php wird nicht eingecheckt.
 * Diese Vorlage ist auf die lokale Demo (SQLite, Demo-Login) eingestellt.
 */

// Datenbank: 'sqlite' (nur Demo/Entwicklung ohne MySQL) oder 'mysql' (gleiche Datenbank wie die Sitzungsverwaltung).
// XAMPP: MD_DB_DRIVER 'mysql', MD_DB_HOST 'localhost', MD_DB_USER 'root', MD_DB_PASS '' und MD_DB_NAME wie in der Sitzungsverwaltung.
define('MD_DB_DRIVER',   'sqlite');
define('MD_SQLITE_FILE', __DIR__ . '/storage/demo.sqlite');
define('MD_DB_HOST',     'localhost');
define('MD_DB_NAME',     '');
define('MD_DB_USER',     '');
define('MD_DB_PASS',     '');

// Demo-Modus: Anmeldung per Auswahl einer Demo-Figur. In Produktion UNBEDINGT false.
define('MD_DEMO_MODE', true);

// Produktiv: Mitglied kommt aus der Sitzung der Sitzungsverwaltung.
define('MD_SESSION_KEY', 'member_id');
define('MD_LOGIN_URL',   '');   // leer = eigene Anmeldung (login.php); sonst Adresse einer anderen Anmeldeseite

// Basis-Pfad der Anwendung, z. B. '/Mitgliederverwaltung'. Wird automatisch erkannt; nur bei Problemen von Hand setzen:
// define('MD_BASE_URL', '/Mitgliederverwaltung');

// Betriebsart der Sitzungsverwaltung. Die Mitgliederverwaltung ist nur im Standalone-Betrieb aktiv
// ('standalone': svmembers ist führend). Im Adapterbetrieb ('adapter', Tabelle "berechtigte" führend) bleibt sie gesperrt.
define('MD_SV_MODE', 'standalone');

// Schlüssel zur Verschlüsselung der IBAN in den SEPA-Mandaten (AES-256). 64 Hex-Zeichen. Erzeugen: php tools/make_key.php
// WICHTIG: Schlüssel sicher aufbewahren und sichern; ohne ihn sind die gespeicherten IBANs nicht mehr lesbar. Nie ändern, solange Mandate existieren.
// define('MD_ENCRYPTION_KEY', '<64 Hex-Zeichen>');   // erst einkommentieren, wenn der Schlüssel erzeugt ist (die Demo trägt sich selbst einen ein)

// Ablage für Fotos und Importdateien (außerhalb des Web-Roots empfohlen)
define('MD_STORAGE_DIR', __DIR__ . '/storage');

// Vereinsname für Kopfzeile
define('MD_CLUB_NAME', 'Musterverein e. V.');

// E-Mail-Versand: 'log' (Demo: speichert .eml-Dateien in storage/mail), 'mail' (PHP mail()) oder 'smtp'
define('MD_MAIL_TRANSPORT', 'log');
// define('MD_SMTP_HOST', 'smtp.example.org'); define('MD_SMTP_PORT', 587); define('MD_SMTP_SECURE', 'tls'); // tls | ssl | none
// define('MD_SMTP_USER', ''); define('MD_SMTP_PASS', '');
// Öffentliche Adresse dieser Anwendung für Links in E-Mails (empfohlen, sonst wird der Host der Anfrage verwendet):
// define('MD_PUBLIC_URL', 'https://verein.example.org/Mitgliederverwaltung');

// Testsystem für externe Tester (nur auf einer eigenen Testdatenbank mit erfundenen Daten!). Siehe docs/PRODUKTIV.md.
define('MD_TEST_INSTANCE', false);
// Nur für den Web-Einrichter setup_test.php (zufälliger Wert, mindestens 20 Zeichen; Datei nach dem Einrichten löschen):
// define('MD_SETUP_TOKEN', '');

// Adresse der Sitzungsverwaltung (für den Link auf der Demo-Startseite, optional)
// define('MD_SV_URL', '/Sitzungsverwaltung/');
