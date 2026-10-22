# Demo-Datenbank mit den 12 Figuren (Windows/XAMPP)

Ziel: Eine **eigene** Datenbank `md_demo` mit der Demo der Sitzungsverwaltung (12 Figuren, Passwort `test123`) plus den Demo-Daten der Mitgliederverwaltung.
Die bestehende Entwicklungsdatenbank (`k126904_div`) bleibt unberührt.

> **Achtung:** `tools/demo_import.php` der Sitzungsverwaltung **löscht alle Daten der Datenbank**, auf die ihre `config.php` zeigt.
> Deshalb läuft die Demo in einer **eigenen Kopie** der Sitzungsverwaltung mit eigener `config.php`. Vor dem Import immer Schritt 3 (Kontrolle) ausführen.
> Zur Sicherheit vorher in phpMyAdmin `k126904_div` exportieren (Reiter „Exportieren“).

## 1. Leere Datenbank anlegen

phpMyAdmin (`http://localhost/phpmyadmin`) → „Neu“ → Name `md_demo`, Kollation `utf8mb4_unicode_ci` → Anlegen.

## 2. Zweite Kopie der Sitzungsverwaltung

Ordner `D:\xampp\htdocs\Sitzungsverwaltung` komplett kopieren nach `D:\xampp\htdocs\Sitzungsverwaltung_demo`.
In `Sitzungsverwaltung_demo\config.php` ändern:

- Datenbankname: `md_demo` (in der lokalen Zweigstelle der Datei steht bisher `define('DB_NAME', 'k126904_div');`)
- `define('DEMO_MODE_ENABLED', true);`

## 3. Kontrolle: zeigt die Kopie wirklich auf `md_demo`?

```
cd /d D:\xampp\htdocs\Sitzungsverwaltung_demo
D:\xampp\php\php.exe -r "require 'config.php'; echo DB_NAME;"
```
Die Ausgabe muss `md_demo` lauten. **Steht dort etwas anderes, nicht weitermachen** – sonst wird diese Datenbank beim Import geleert.

## 4. Tabellen anlegen und Demo-Daten der Sitzungsverwaltung laden

```
D:\xampp\php\php.exe tools\init-db.php
D:\xampp\php\php.exe tools\demo_build.php
```
Dann im Browser `http://localhost/Sitzungsverwaltung_demo/tools/demo_import.php`, System-Admin-Passwort eingeben und bestätigen.
Ergebnis: 12 Mitglieder `DEMO0001` bis `DEMO0012` (E-Mail `max@example.com` usw., Passwort `test123`). Test: `http://localhost/Sitzungsverwaltung_demo/` mit `max@example.com` anmelden.

## 5. Zweite Kopie der Mitgliederverwaltung

```
cd /d D:\xampp\htdocs
git clone https://github.com/zurgo42/Mitgliederverwaltung.git Mitgliederverwaltung_demo
copy Mitgliederverwaltung\config.php Mitgliederverwaltung_demo\config.php
```
In `Mitgliederverwaltung_demo\config.php` ändern: `MD_DB_NAME` = `'md_demo'`, `MD_LOGIN_URL` = `'/Sitzungsverwaltung_demo/login.php'`, `MD_DEMO_MODE` = `true`, `MD_SV_MODE` = `'standalone'`.

## 6. Mitgliederverwaltung einrichten und Demo-Daten laden

```
cd /d D:\xampp\htdocs\Mitgliederverwaltung_demo
D:\xampp\php\php.exe tools\install.php --check
D:\xampp\php\php.exe tools\install.php
D:\xampp\php\php.exe tools\demo_load.php
D:\xampp\php\php.exe tools\sync_rights.php --dry-run
```
Die letzte Zeile muss `0 Mitglied(er) würden angepasst` melden: Die Demo-Funktionen und die Rechte der Sitzungsverwaltung stimmen überein.

## 7. Ansehen

`http://localhost/Mitgliederverwaltung_demo/login.php` → Figur wählen (Max Mustermann, Hans Lehmann, Mia Technik …).
Admins sind Max, Julia, Thomas, Sabine und Mia; sie sehen im Dashboard den Bearbeitungsmodus für andere Mitglieder.

Zurücksetzen: `D:\xampp\php\php.exe tools\demo_load.php --reset` leert nur die `md_`-Tabellen und lädt sie neu.
