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
define('MD_LOGIN_URL',   '/Sitzungsverwaltung/login.php');   // Anmeldeseite der Sitzungsverwaltung (gleiche Domain)

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
