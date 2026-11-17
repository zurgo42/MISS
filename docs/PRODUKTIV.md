# Testsystem auf dem Server für externe Tester

Diese Anleitung bringt MISS als **Testsystem** auf deinen Webserver, damit externe Tester über das Internet damit arbeiten können – mit **erfundenen Daten**, einer **eigenen Datenbank** und Zugriffsschutz. Der spätere Echtbetrieb steht am Ende (Checkliste).

> **Wichtig:** Das Testsystem darf nie mit echten Mitgliedern in derselben Datenbank laufen, denn Tester könnten sie sehen. Läuft auf dem Server ohnehin nur eine Testinstanz der Sitzungsverwaltung mit Demo-Daten, kann MISS deren Datenbank mitbenutzen (Weg A); der Einrichter prüft, dass dort nur Demo-Figuren stehen. MISS läuft nur im Standalone-Betrieb der Sitzungsverwaltung; im Adapterbetrieb wird sie nicht gebraucht.

## 1. Voraussetzungen

| Was | Anforderung |
|---|---|
| PHP | 8.1 oder neuer (getestet mit 8.3) mit den Erweiterungen `pdo_mysql`, `mbstring`, `openssl`, `iconv`, `dom`, `gd`; `zip` ist optional (ZIP-Export der Auskunft) |
| Datenbank | MySQL/MariaDB (MariaDB 10.4+ bzw. MySQL 5.7+), Zeichensatz utf8mb4 |
| Webserver | Apache mit `.htaccess`-Auswertung (`AllowOverride`). Bei nginx sind die Sperren aus den `.htaccess`-Dateien selbst einzurichten. |
| HTTPS | Pflicht (Passwörter, Sitzung) |
| Zugang | FTP/SFTP; SSH ist hilfreich, aber nicht nötig (es gibt einen Einrichter im Browser) |

Ob die PHP-Erweiterungen vorhanden sind, zeigt im Hosting-Panel meist „PHP-Info“; sonst beim Hoster nachfragen.

## 2. Aufbau

- Eigenes Verzeichnis oder eine Subdomain, z. B. `https://dein-verein.de/MISS-Test/`.
- **Datenbank:** Es zählt nur, dass sie **ausschließlich Demo-Daten** enthält – das prüft der Einrichter und bricht sonst ab. Zwei Wege:
  - **A – gemeinsam mit der Sitzungsverwaltung (Testinstanz mit deren Demo):** MISS nutzt dieselbe Datenbank und legt nur ihre `md_`-Tabellen an. `svmembers`, `svressorts` und alles der Sitzungsverwaltung bleiben **unverändert** (auch beim Zurücksetzen); die Demo-Figuren DEMO0001–DEMO0012 sind dieselben, und die Anmeldung gilt für beide Anwendungen. Voraussetzung: die Demo der Sitzungsverwaltung ist geladen und `svmembers` hat die Spalte `password_hash`.
  - **B – eigene Datenbank:** Wenn es keine Testinstanz der Sitzungsverwaltung gibt. Der Einrichter legt dann eine einfache Mitgliedertabelle selbst an.
- **Zugriffsschutz** (Benutzername/Passwort des Webservers) vor dem gesamten Verzeichnis. Das verhindert, dass Suchmaschinen oder Unbefugte das Testsystem sehen.

## 3. Schritt für Schritt

### 3.1 Code holen und hochladen
1. Auf GitHub (Repository `zurgo42/MISS`, Zweig `main`): **Code → Download ZIP** und entpacken. (Mit SSH alternativ: `git clone https://github.com/zurgo42/MISS.git MISS-Test`.)
2. Den **Inhalt** des entpackten Ordners per FTP in das Zielverzeichnis hochladen (so, dass `index.php` direkt im Zielverzeichnis liegt).
3. Prüfen, dass die Sperren greifen – diese Adressen müssen **„Forbidden“/403** liefern:
   `…/config.example.php` · `…/src/db.php` · `…/sql/schema.sql` · `…/storage/` · `…/tools/install.php`
   Wenn eine davon Inhalt zeigt, ist `.htaccess` nicht aktiv: Hoster bitten, `AllowOverride All` für das Verzeichnis zu setzen, bevor du weitermachst.

### 3.2 Datenbank
- **Weg A:** Zugangsdaten der Datenbank der Sitzungsverwaltungs-Testinstanz verwenden (steht in deren `config.php`).
- **Weg B:** Im Hosting-Panel eine neue, leere MySQL-Datenbank mit Benutzer anlegen (Zeichensatz utf8mb4). Servername, Datenbankname, Benutzer und Passwort notieren.

### 3.3 `config.php` anlegen
`config.example.php` kopieren in `config.php` (im selben Verzeichnis) und anpassen:

```php
define('MD_DB_DRIVER', 'mysql');
define('MD_DB_HOST', 'localhost');          // laut Hoster
define('MD_DB_NAME', '…_mvtest');
define('MD_DB_USER', '…');
define('MD_DB_PASS', '…');
define('MD_DEMO_MODE', false);              // muss false sein (Anmeldung mit Passwort)
define('MD_TEST_INSTANCE', true);           // Testsystem: Banner, kein echter Mailversand
define('MD_SETUP_TOKEN', '…');              // zufällig, mindestens 20 Zeichen (nur für setup_test.php)
define('MD_ENCRYPTION_KEY', '…');           // 64 Hex-Zeichen
define('MD_SV_MODE', 'standalone');
define('MD_LOGIN_URL', '');                 // leer = eigene Anmeldung
define('MD_CLUB_NAME', 'Testverein');
define('MD_PUBLIC_URL', 'https://dein-verein.de/MISS-Test');
```

Den Schlüssel und das Token erzeugst du z. B. auf deinem PC mit `php tools/make_key.php` (liefert 64 Hex-Zeichen; für das Token reichen 24 Zeichen davon) oder mit `openssl rand -hex 32`. `config.php` wird nie ins Repository übernommen.

### 3.4 Zugriffsschutz einrichten
Im Hosting-Panel „Verzeichnisschutz“ für das Zielverzeichnis aktivieren (Benutzer + Passwort für die Tester). Alternativ in einer `.htaccess` im Zielverzeichnis (ersetze den Pfad zur Passwortdatei, die du mit dem Panel oder `htpasswd` erzeugst):

```apache
AuthType Basic
AuthName "Testsystem"
AuthUserFile /absoluter/pfad/zur/.htpasswd
Require valid-user
```

Hinweis: Die vorhandene `.htaccess` im Zielverzeichnis enthält bereits Sperren; füge die vier Zeilen **am Anfang** hinzu, ersetze die Datei nicht.

### 3.5 Einrichten
- **Mit SSH:** `php tools/test_setup.php` im Zielverzeichnis. Es legt die Tabellen an, lädt die 12 Testfiguren samt Beispieldaten und gibt die Zugangsdaten **einmalig** aus.
- **Ohne SSH:** `https://…/MISS-Test/setup_test.php?token=<MD_SETUP_TOKEN>` aufrufen → „Einrichten“. Die Zugangsdaten erscheinen **einmalig** auf der Seite (notieren!). Danach „Diese Datei jetzt löschen“ (oder per FTP entfernen).

Das Einrichten bricht ab, wenn die Datenbank Mitglieder enthält, die keine Demo-Figuren sind. Dadurch lässt sich eine Datenbank mit echten Daten nicht versehentlich überschreiben.

Im Browser-Einrichter stehen bei „Einrichten“ zwei Optionen: *Rollen und Admin-Kennzeichen der Demo-Figuren in svmembers angleichen* (bringt die Rollen der Sitzungsverwaltungs-Demo auf den Stand dieser Demo) und *andere Konten in svmembers unberührt lassen* (für eigene Testkonten wie das eines Entwicklers; sie bleiben unverändert, haben aber kein Profil). Auf der Kommandozeile entsprechen dem `--sync-sv` und `--ignore-others`.

Bei Weg A bekommen nur Figuren ohne Passwort ein neues; vorhandene Passwörter aus der Demo der Sitzungsverwaltung bleiben und gelten auch hier. Bei Weg B erhalten alle zwölf neue Passwörter.

### 3.6 Prüfen
1. `…/login.php` aufrufen, mit einer Testfigur anmelden (E-Mail oder Mitgliedsnummer + Passwort). Oben steht das Banner „Testsystem – alle Daten sind erfunden“.
2. Mit der Admin-Figur (Max Mustermann): Seiten *Mitglieder, Finanzen, Beiträge, SEPA, E-Mail, Verteiler, Anmeldungen* öffnen – es dürfen keine Fehlermeldungen erscheinen.
3. „Passwort vergessen“ ausprobieren: Die E-Mail wird nicht verschickt; den Link siehst du als Admin unter *E-Mail* im Ausgang.

### 3.7 Tester ausstatten
- Jedem Tester **eine oder zwei Testfiguren** zuteilen (siehe `docs/TESTER.md`: Rollen und Testaufgaben) und das Passwort über einen **anderen Kanal** als die Adresse des Systems schicken. Die Tester können ihr Passwort unter *Mein Zugang* ändern.
- Zusätzlich die Zugangsdaten des Verzeichnisschutzes mitgeben.
- `docs/TESTER.md` als Anleitung weitergeben (Datei ist wegen der Webserver-Sperre nur per FTP/E-Mail verfügbar, nicht im Browser).
- Die Tester über den Rahmen informieren: erfundene Daten, keine echten personenbezogenen Daten eingeben, Vertraulichkeit.

## 4. Betrieb während der Testphase

| Aufgabe | So geht’s |
|---|---|
| **Neue Version einspielen** | Neue Dateien per FTP drüberkopieren, **`config.php` und `storage/` behalten**; danach `php tools/test_setup.php --update` bzw. im Browser `setup_test.php` → „Datenbank aktualisieren“ (legt nur Neues an, löscht nichts). Wenn `setup_test.php` gelöscht wurde, neu hochladen. Eine Meldung „Datenbank nicht auf dem neuesten Stand“ (503) bedeutet, dass dieser Schritt fehlt. |
| **Testdaten zurücksetzen** | `php tools/test_setup.php --reset` bzw. im Browser „Alles löschen und neu aufbauen“. Löscht alle Testdaten von MISS. Weg A: Die Tabellen der Sitzungsverwaltung bleiben unangetastet. Weg B: neue Passwörter. |
| **E-Mails** | Das Testsystem versendet keine Mails (`MD_TEST_INSTANCE`). Alle Nachrichten stehen unter *E-Mail* im Ausgang (nur für Admin-Figuren sichtbar). Einladungs- und Passwort-Links sind dort ebenfalls lesbar. |
| **Fehlermeldungen** | Leere Seite/500: PHP-Fehlerprotokoll des Hosters prüfen. Häufige Ursachen: fehlende PHP-Erweiterung, falsche Zugangsdaten in `config.php`. |
| **Rückmeldungen sammeln** | Tester melden: Adresse der Seite, Testfigur, Schritte, erwartet/erlebt, Screenshot (siehe `docs/TESTER.md`). |
| **Abbau nach dem Test** | Verzeichnis per FTP löschen, Verzeichnisschutz und Tester-Zugänge beenden; bei Weg B die Datenbank löschen, bei Weg A die `md_`-Tabellen entfernen (oder zurücksetzen). |

## 5. Sicherheitshinweise für das Testsystem

- `MD_DEMO_MODE` bleibt **false**: Im Demo-Modus kann sich jeder ohne Passwort als beliebige Figur anmelden.
- `setup_test.php` nach dem Einrichten löschen; das Token nirgends weitergeben.
- Verzeichnisschutz nicht weglassen; das Testsystem ist für Suchmaschinen gesperrt (`noindex`), aber nicht geheim.
- `config.php` enthält Datenbankzugang und Schlüssel: nur per FTP/SFTP bearbeiten, nie in ein Ticket oder eine Mail kopieren.

## 6. Später: Echtbetrieb (Checkliste)

Das ist ein eigener Schritt mit anderen Voraussetzungen; die Testinstanz wird dafür nicht „umgeschaltet“, sondern neu und sauber aufgesetzt.

1. **Datenbank:** die der Sitzungsverwaltung (Standalone-Betrieb; `svmembers` mit der Spalte `password_hash`). Vorher vollständige Sicherung.
2. `config.php` mit `MD_DEMO_MODE=false`, `MD_TEST_INSTANCE=false`, `MD_ENCRYPTION_KEY` (neu erzeugen, **sicher aufbewahren und mitsichern, später nie ändern**), `MD_PUBLIC_URL`, SMTP-Zugang (`MD_MAIL_TRANSPORT='smtp'`).
3. `php tools/install.php --check`, dann `php tools/install.php` (SSH nötig; ohne SSH bitte Bescheid geben, dann ergänze ich einen Einrichter für den Echtbetrieb). Keine Demo-Daten laden!
4. Mitglieder per CSV-Import einspielen (*Import*), Funktionen/Rechte, Beitragsgruppen, Vereinsdaten, Gläubiger-ID, Absenderadresse einrichten.
5. Einladungen verschicken (*E-Mail → Zugänge*).
6. Vorab klären: Satzung/Beitragsordnung (Mahngebühren, Lastschrift), Datenschutzhinweise und Nutzungsbedingungen, Auftragsverarbeitungsvertrag mit dem Hoster, Verzeichnis der Verarbeitungstätigkeiten (siehe `docs/DATENSCHUTZ.md`), Steuerberater für Spendenbescheinigungen und DATEV.
7. **Sicherung** einrichten: Datenbank täglich, `storage/` (Fotos) und `config.php` regelmäßig.
8. Jeden Schritt vorher am Testsystem proben.
9. **Aufräumen nach der Testphase:** `tools/set_password.php` (setzt Passwörter ohne E-Mail, nur für Demo-/Testsysteme gedacht) auf dem Server **löschen**, ebenso `setup_test.php`, falls noch vorhanden, und `MD_TEST_INSTANCE` nicht in die Echt-Konfiguration übernehmen.

## Verzeichnis umbenennen (z. B. von `Mitgliederverwaltung-Test` nach `MISS`)

1. **Zuerst** in der `config.php` prüfen: Steht dort `MD_BASE_URL` oder `MD_PUBLIC_URL` (oder `MD_SV_URL`) mit dem alten Verzeichnisnamen, auf den neuen ändern. Ohne `MD_BASE_URL` erkennt MISS den Pfad selbst.
2. Verzeichnis auf dem Server umbenennen (FTP, Dateimanager oder `mv Mitgliederverwaltung-Test MISS`). `config.php`, Fotos und Logo (liegen in der Datenbank) und alle Daten bleiben unverändert, es muss nichts neu eingerichtet werden.
3. Einen vorhandenen **Verzeichnisschutz** (Passwortschutz im Hosting-Panel oder `.htaccess` mit `AuthUserFile`) für den neuen Namen neu setzen: Das Panel koppelt ihn oft an den Verzeichnisnamen.
4. Cronjobs (z. B. `tools/sync_rights.php`) auf den neuen Pfad ändern.
5. Neue Adresse an die Tester geben: `https://…/MISS/login.php`. Alte Lesezeichen und Mails mit Links auf den alten Namen laufen ins Leere. Wer will, legt am alten Ort eine `.htaccess` mit `Redirect 301 /Mitgliederverwaltung-Test /MISS` an.
6. Falls der Link zu MISS in der Sitzungsverwaltung oder auf der Vereinsseite steht, dort anpassen.

