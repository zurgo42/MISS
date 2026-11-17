# Demo mit den 12 Figuren (Windows/XAMPP)

Es gibt zwei Wege. Beide laden die Demo der Sitzungsverwaltung (12 Figuren `DEMO0001`–`DEMO0012`, Passwort `test123`) und danach die Demo-Daten von MISS.

| | Weg A: vorhandene Datenbank | Weg B: eigene Demo-Datenbank |
|---|---|---|
| Datenbank | die bestehende Entwicklungsdatenbank (z. B. `k126904_go`) | neue Datenbank `md_demo` |
| Sitzungsverwaltung | die vorhandene Installation | eine zweite Kopie mit eigener `config.php` |
| Vorhandene Daten | werden durch den Demo-Import der Sitzungsverwaltung **gelöscht** (Meeting-Daten und Mitglieder, ausgenommen einzelne Testkonten) | bleiben unberührt |
| Aufwand | gering | höher |
| Geeignet für | reine Entwicklungsdatenbank ohne wichtige Daten | wenn die bestehende Datenbank erhalten bleiben soll |

Ob in der Datenbank etwas Wichtiges liegt, entscheidest du. Wenn ja: Weg B oder vorher einen Export (phpMyAdmin → Exportieren).

Nach der ersten Einrichtung lässt sich MISS danach jederzeit im Browser zurücksetzen (Administration → „🎭 Demo-Daten zurücksetzen", nur bei `MD_DEMO_MODE=true` sichtbar) - ohne Kommandozeile, inhaltlich wie `php tools/demo_load.php --reset`: `svmembers`/`svressorts` und damit die 12 Figuren der Sitzungsverwaltung bleiben unangetastet, nur die Daten von MISS selbst (Profile, Finanzen, Wahl und Abstimmung, …) werden geleert und neu befüllt. Bricht sicherheitshalber ab, wenn `svmembers` Mitglieder außerhalb der Demo (`DEMO%`) enthält.

---

# Weg A: vorhandene Datenbank verwenden

Voraussetzung: Die Datenbank ist eine reine Entwicklungs-/Testdatenbank, ein Export liegt vor.

## A1. Demo der Sitzungsverwaltung laden

In der vorhandenen Sitzungsverwaltung (z. B. `D:\xampp\htdocs\Sitzungsverwaltung`):
- In `config.php`: `define('DEMO_MODE_ENABLED', true);`
- `D:\xampp\php\php.exe tools\demo_build.php` (erzeugt `tools\demo_data.json`)
- Im Browser `http://localhost/Sitzungsverwaltung/tools/demo_import.php`, System-Admin-Passwort, bestätigen. **Das löscht die Daten dieser Datenbank.**

Danach stehen die 12 Figuren in `svmembers` (z. B. `max@example.com` / `test123`). Einzelne Testkonten außerhalb der Demo (z. B. ein Super-Admin) können stehen bleiben.

## A2. MISS einrichten (im Ordner `D:\xampp\htdocs\MISS`)

`config.php`: `MD_DB_DRIVER` `'mysql'`, `MD_DB_HOST` `'localhost'`, `MD_DB_USER` `'root'`, `MD_DB_PASS` `''`, `MD_DB_NAME` wie in der Sitzungsverwaltung,
`MD_DEMO_MODE` `true`, `MD_SV_MODE` `'standalone'`, `MD_LOGIN_URL` `'/Sitzungsverwaltung/login.php'`.

```
cd /d D:\xampp\htdocs\MISS
D:\xampp\php\php.exe tools\install.php --check
D:\xampp\php\php.exe tools\install.php
D:\xampp\php\php.exe tools\demo_load.php
```

## A3. Wenn `demo_load.php` Konten außerhalb der Demo meldet

Das Skript listet sie mit Nummer, Name, E-Mail und Rolle und bricht ab. Das ist ein Schutz davor, dass Demo-Daten in echte Mitgliedsdaten geraten.
Sind es nur Testkonten, die stehen bleiben sollen:

```
D:\xampp\php\php.exe tools\demo_load.php --ignore-others
```
Es werden dann nur die 12 Demo-Figuren befüllt. `svmembers` und die anderen Konten bleiben unverändert und haben danach ein leeres Profil;
der Rechteabgleich fasst sie nicht an, weil sie keine Funktionszuordnung haben. **Bei echten Personen diesen Weg nicht gehen.**

## A4. Kontrolle und Ansehen

```
D:\xampp\php\php.exe tools\sync_rights.php --dry-run
```
muss `0 Mitglied(er) würden angepasst` melden: Die Demo-Funktionen passen zu den Rechten (`aktiv`, `funktion`, `role`) der Sitzungsverwaltung.
Vor `demo_load.php` ist dieser Test noch nicht aussagekräftig, weil es noch keine Funktionszuordnungen gibt.

Dann `http://localhost/MISS/login.php` und eine Figur wählen. Zurücksetzen: `tools\demo_load.php --reset` (mit `--ignore-others`, falls nötig) leert nur die `md_`-Tabellen und lädt sie neu.

---

# Weg B: eigene Demo-Datenbank `md_demo`

Die bestehende Entwicklungsdatenbank (`k126904_go`) bleibt unberührt.

> **Achtung:** `tools/demo_import.php` der Sitzungsverwaltung **löscht alle Daten der Datenbank**, auf die ihre `config.php` zeigt.
> Deshalb läuft die Demo in einer **eigenen Kopie** der Sitzungsverwaltung mit eigener `config.php`. Vor dem Import immer Schritt 3 (Kontrolle) ausführen.
> Zur Sicherheit vorher in phpMyAdmin `k126904_go` exportieren (Reiter „Exportieren“).

## 1. Leere Datenbank anlegen

phpMyAdmin (`http://localhost/phpmyadmin`) → „Neu“ → Name `md_demo`, Kollation `utf8mb4_unicode_ci` → Anlegen.

## 2. Zweite Kopie der Sitzungsverwaltung

Ordner `D:\xampp\htdocs\Sitzungsverwaltung` komplett kopieren nach `D:\xampp\htdocs\Sitzungsverwaltung_demo`.
In `Sitzungsverwaltung_demo\config.php` ändern:

- Datenbankname: `md_demo` (in der lokalen Zweigstelle der Datei steht bisher `define('DB_NAME', 'k126904_go');`)
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

## 5. Zweite Kopie von MISS

```
cd /d D:\xampp\htdocs
git clone https://github.com/zurgo42/MISS.git MISS_demo
copy MISS\config.php MISS_demo\config.php
```
In `MISS_demo\config.php` ändern: `MD_DB_NAME` = `'md_demo'`, `MD_LOGIN_URL` = `'/Sitzungsverwaltung_demo/login.php'`, `MD_DEMO_MODE` = `true`, `MD_SV_MODE` = `'standalone'`.

## 6. MISS einrichten und Demo-Daten laden

```
cd /d D:\xampp\htdocs\MISS_demo
D:\xampp\php\php.exe tools\install.php --check
D:\xampp\php\php.exe tools\install.php
D:\xampp\php\php.exe tools\demo_load.php
D:\xampp\php\php.exe tools\sync_rights.php --dry-run
```
Die letzte Zeile muss `0 Mitglied(er) würden angepasst` melden: Die Demo-Funktionen und die Rechte der Sitzungsverwaltung stimmen überein.

## 7. Ansehen

`http://localhost/MISS_demo/login.php` → Figur wählen (Max Mustermann, Hans Lehmann, Mia Technik …).
Admins sind Max, Julia, Thomas, Sabine und Mia; sie sehen im Dashboard den Bearbeitungsmodus für andere Mitglieder.

Zurücksetzen: `D:\xampp\php\php.exe tools\demo_load.php --reset` leert nur die `md_`-Tabellen und lädt sie neu.


## Demo-Figuren und weitere Mitglieder

- **Wählbar** sind auf der Demo-Startseite elf Figuren mit ihren Rollen (Finanzvorstand, Vorstand, Geschäftsführung, Assistenz, Ressortleitung, Finanzprüfung, Technik-Admin, Mitglied aktiv, Mitglied passiv). Die Startseite ist wie die der Sitzungsverwaltung aufgebaut (Leistungen oben, Demo-Zugang darunter).
- **Weitere 60 einfache Mitglieder** (DEMO0013–DEMO0072) füllen Mitgliederverzeichnis und Verwaltungsseiten: 40 „aktive“ mit Foto, unterschiedlich vielen Inhalten, Beitragsgruppe und akzeptierten Nutzungsbedingungen (13–52), 20 „passive“ nur mit Grunddaten (53–72). Sie sind nicht als Demo-Zugang wählbar.
- Bei einer vorhandenen Demo der Sitzungsverwaltung ergänzt `php tools/demo_load.php --reset --sync-sv` die fehlenden Demo-Mitglieder in `svmembers` (nur neue Zeilen DEMO0013 ff.) und gleicht Rollen/Admin der zwölf Figuren an.
- Die Bilder (gezeichnete Porträts, CC0) liegen in `assets/demo/`, siehe dort `LIZENZ.md`.
