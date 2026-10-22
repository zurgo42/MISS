# Lokale Einrichtung unter Windows mit XAMPP

Voraussetzung: XAMPP mit Apache und MySQL läuft, die Sitzungsverwaltung liegt in `C:\xampp\htdocs\Sitzungsverwaltung`, Git ist installiert.
Die Mitgliederverwaltung kommt **daneben** und nutzt **dieselbe Datenbank**:

```
C:\xampp\htdocs\Sitzungsverwaltung
C:\xampp\htdocs\Mitgliederverwaltung      <- neu
```

## 1. Code holen

```
cd C:\xampp\htdocs
git clone https://github.com/zurgo42/Mitgliederverwaltung.git Mitgliederverwaltung
```
(Wenn der Ordner schon Dateien enthält: den Ordner umbenennen und neu klonen, die eigenen Dateien danach prüfen.)

## 2. Konfiguration

```
cd Mitgliederverwaltung
copy config.example.php config.php
```
In `config.php` eintragen:

| Einstellung | Wert unter XAMPP |
|---|---|
| `MD_DB_DRIVER` | `'mysql'` |
| `MD_DB_HOST` / `MD_DB_USER` / `MD_DB_PASS` | `'localhost'` / `'root'` / `''` (XAMPP-Standard) |
| `MD_DB_NAME` | derselbe Datenbankname wie in der Sitzungsverwaltung |
| `MD_DEMO_MODE` | `true` (Anmeldung per Figurenauswahl) – **auf einem echten System immer `false`** |
| `MD_SV_MODE` | `'standalone'` |
| `MD_LOGIN_URL` | `'/Sitzungsverwaltung/login.php'` |

`config.php` steht in `.gitignore` und wird nie hochgeladen.

## 3. Datenbank vorbereiten

Immer zuerst nur prüfen (schreibt nichts):

```
C:\xampp\php\php.exe tools\install.php --check
```
Das Skript prüft `svmembers` (Spalten, `role`-ENUM mit `'Mitglied'`) und zeigt, was fehlt. Wenn der Befund sauber ist:

```
C:\xampp\php\php.exe tools\install.php
```
Es legt nur Tabellen mit Präfix `md_` an (bestehende Tabellen bleiben unberührt), trägt die Standard-Funktionen und Einstellungen ein.
Funktioniert ein Fremdschlüssel nicht (andere Engine/Collation), legt es die Tabelle ohne Fremdschlüssel an und meldet das.

## 4. Demo-Daten laden (optional, nur in einer Demo-Datenbank)

Wenn die Datenbank die Demo-Mitglieder der Sitzungsverwaltung enthält (Mitgliedsnummern `DEMO0001`–`DEMO0012`):

```
C:\xampp\php\php.exe tools\demo_load.php
```
Das Skript bricht ab, sobald `svmembers` auch andere Mitglieder enthält, und verändert `svmembers` nie. Mit `--reset` leert es vorher nur die `md_`-Tabellen.

## 5. Öffnen

`http://localhost/Mitgliederverwaltung/login.php` – Figur wählen. Das Dashboard liegt unter `index.php`.

## Anmeldung ohne Demo-Modus

Mit `MD_DEMO_MODE=false` liest die Mitgliederverwaltung `$_SESSION['member_id']` aus der Sitzung der Sitzungsverwaltung. Beide Anwendungen
müssen dafür auf derselben Domain laufen und denselben Session-Cookie teilen (`session.cookie_path = '/'`, siehe `docs/session-cookie-configuration.md`
der Sitzungsverwaltung). Erst in der Sitzungsverwaltung anmelden, dann `http://localhost/Mitgliederverwaltung/` öffnen.

## Nach einem Update

Nach `git pull` einmal `D:\xampp\php\php.exe tools\install.php` ausführen. Das ergänzt nur Neues (Tabellen, Spalten, Rechtetabelle), löscht nichts und
ändert nichts an `svmembers`. Ohne diesen Schritt weist die Anwendung mit einer Meldung darauf hin.

## Häufige Stolpersteine

- **PHP-Erweiterungen:** `gd`, `mbstring`, `pdo_mysql` müssen in der `php.ini` aktiv sein (bei XAMPP standardmäßig der Fall). Benötigt PHP 8.1 oder neuer.
- **`.htaccess`:** Apache muss `AllowOverride` für `htdocs` erlauben (XAMPP-Standard). Die Ordner `src`, `sql`, `tools`, `storage`, `docs` sind damit von außen gesperrt.
- **Fotos:** Liegen in `storage\photos` und werden nur über `photo.php` mit Rechteprüfung ausgeliefert.
- **Rechteabgleich:** Läuft nur für Mitglieder mit Funktionszuordnung (siehe `docs/RECHTE.md`). `php tools\sync_rights.php --dry-run` zeigt vorab, was sich ändern würde.
