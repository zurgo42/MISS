# Mitgliederverwaltung

Mitgliederdaten aus Sicht des Mitglieds. Ergänzt die [Sitzungsverwaltung](https://github.com/zurgo42/Sitzungsverwaltung):
gleiche Datenbank, gleiche Mitgliedertabelle (`svmembers`), gleiche Figuren in den Demo-Daten, gleiche Farben.

## Was schon da ist

| Bereich | Inhalt |
|---|---|
| **Dashboard** (`/index.php`) | Eigene Daten: 3 Fotos, 3 Adressen, Kontakt und Social Media, Beruf und Interessen, Vorstellung, „Was ich mir im Verein wünsche“. Dazu Vereinsdaten (Gruppen, Funktionen), Finanzen und Änderungsprotokoll. |
| **Sichtbarkeit** | Je Feld: Nur ich · Vorstand/Verwaltung · Meine Teilgruppen · Alle Mitglieder. Standardwerte im Code (`src/fields.php`) und überschreibbar in `md_visibility_defaults`. Fotos werden nur über `photo.php` mit Rechteprüfung ausgeliefert. |
| **Protokoll** | Jede Änderung (Feld, alt, neu, wer, wann, Weg: selbst/Admin/Import) in `md_changelog`. |
| **Admin** (`/admin/`) | Mitgliederliste, Bearbeiten im Namen des Mitglieds (`/index.php?member=ID`), Status, Eintritt/Austritt, Teilgruppen, Funktionen mit Zeitraum. |
| **Nutzungsbedingungen** | Versionen mit Gültigkeit (`md_terms_versions`), dokumentierte Zustimmung je Mitglied (`md_terms_consents`: Version, Zeitpunkt, Weg online/schriftlich/Verwaltung/Import, erfassende Person, gekürzte IP, Widerruf). Neue Version mit „Zustimmung nötig“ setzt den Status zurück. |
| **Finanzen** | Eine Buchungsliste `md_ledger`: Beitrag, Eintritt, Spende (Mitglied → Verein), Kostenerstattung, Übungsleiterpauschale (Verein → Mitglied). Das Dashboard summiert daraus, inkl. Fortschritt zum Freibetrag. |
| **CSV-Import** (`/admin/import.php`) | Upload (UTF-8/Windows-1252, Trennzeichen automatisch), Spalten zuordnen (mit automatischem Vorschlag), speicherbare Profile je Quellsoftware, Vorschau mit Fehlerliste und Dublettenprüfung, Import in einer Transaktion, Lauf-Protokoll. |
| **Mitgliederverzeichnis** | Zeigt jedem Betrachter nur die für ihn freigegebenen Felder. |

## Lokal einrichten (Windows/XAMPP, gleiche Datenbank wie die Sitzungsverwaltung)

Schritt für Schritt in [docs/XAMPP.md](docs/XAMPP.md). Kurzfassung:

```
cd C:\xampp\htdocs
git clone https://github.com/zurgo42/Mitgliederverwaltung.git Mitgliederverwaltung
cd Mitgliederverwaltung
copy config.example.php config.php        (Datenbankzugang eintragen)
C:\xampp\php\php.exe tools\install.php --check
C:\xampp\php\php.exe tools\install.php
C:\xampp\php\php.exe tools\demo_load.php      (nur wenn die Demo-Mitglieder der Sitzungsverwaltung in der Datenbank sind)
```
Dann `http://localhost/Mitgliederverwaltung/login.php`.

## Demo starten (ohne MySQL, nur SQLite)

```
php tools/demo_setup.php            # legt config.php an, baut SQLite-Demo mit Demo-Daten und Platzhalter-Fotos
php -S localhost:8080
```

Dann `http://localhost:8080/login.php` und eine der 12 Figuren wählen (Max Mustermann … Rolf Ehemals, wie in der Sitzungsverwaltung).
`tools/demo_data.json` ist der erzeugte Datenstand und wird mit eingecheckt, damit wir beide über dieselben Fälle sprechen.
Bewusste Sonderfälle: Lukas (alte Fassung der Bedingungen, offene Mahnung), Mia (keine Zustimmung, offene Erstattung, E-Mail fehlt), Karl (schriftliche Zustimmung), Rolf (ausgetreten, Zustimmung widerrufen), Hans/Anna (Übungsleiterpauschale).

## Einbindung in die Sitzungsverwaltung

- Tabellen mit Präfix `md_`, Schema in `sql/schema.sql` (MySQL/MariaDB). `md_*.member_id` verweist auf `svmembers.member_id`.
- Produktiv: `config.php` mit `MD_DB_DRIVER='mysql'`, `MD_DEMO_MODE=false`; die Anmeldung kommt aus der Session der Sitzungsverwaltung (`MD_SESSION_KEY`).
- Der Demo-Stub `sql/sv_stub.sql` gehört **nicht** in die Produktivdatenbank.
- **Funktionen und Rechte:** `md_member_functions` (mit Zeitraum, mehrere gleichzeitig möglich) wird nach `svmembers.aktiv/funktion/role` abgeglichen, höchstes Level gewinnt. Läuft nur im Standalone-Betrieb (`MD_SV_MODE`). Details: [docs/RECHTE.md](docs/RECHTE.md).

## Offen / nächste Schritte

- Abgleich der Spalten von `svmembers` in der echten Datenbank (der Import legt Mitglieder nur mit Nummer, Name und E-Mail an). Der Rechteabgleich prüft vorab, ob `aktiv`, `funktion` und `role` existieren.
- Admin-Oberfläche für Standard-Sichtbarkeiten und Teilgruppen/Funktionen anlegen.
- Finanz-Eingabe durch die Kasse, Beitragslauf, Spendenbescheinigung (später).
- Übungsleiter-Freibetrag steht in `md_settings.ueb_freibetrag_cents` (Voreinstellung 3.300 €); bitte jährlich prüfen.
- Datenschutz: Aufbewahrungs-/Löschfristen nach Austritt, Auskunftsexport je Mitglied.
