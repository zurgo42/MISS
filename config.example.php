<?php
/**
 * Vorlage: nach config.php kopieren und anpassen. config.php wird nicht eingecheckt.
 * Diese Vorlage ist auf die lokale Demo (SQLite, Demo-Login) eingestellt.
 */

// Datenbank: 'sqlite' (nur Demo/Entwicklung) oder 'mysql' (Produktion, gleiche DB wie die Sitzungsverwaltung)
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
define('MD_LOGIN_URL',   '/login.php');

// Ablage für Fotos und Importdateien (außerhalb des Web-Roots empfohlen)
define('MD_STORAGE_DIR', __DIR__ . '/storage');

// Vereinsname für Kopfzeile
define('MD_CLUB_NAME', 'Musterverein e. V.');
