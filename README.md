# Mitgliederverwaltung

Mitgliederdaten aus Sicht des Mitglieds. Ergänzt die [Sitzungsverwaltung](https://github.com/zurgo42/Sitzungsverwaltung):
gleiche Datenbank, gleiche Mitgliedertabelle (`svmembers`), gleiche Figuren in den Demo-Daten, gleiche Farben.

## Was schon da ist

| Bereich | Inhalt |
|---|---|
| **Dashboard** (`/index.php`) | Eigene Daten: 3 Fotos, 3 Adressen, Kontakt und Social Media, Beruf und Interessen, Vorstellung, „Was ich mir im Verein wünsche“. Dazu Vereinsdaten (Gruppen, Funktionen), Finanzen und Änderungsprotokoll. |
| **Sichtbarkeit** | Je Feld: Nur ich · Vorstand/Verwaltung · Meine Teilgruppen · Alle Mitglieder. Standardwerte im Code (`src/fields.php`) und überschreibbar in `md_visibility_defaults`. Fotos werden nur über `photo.php` mit Rechteprüfung ausgeliefert. |
| **Protokoll** | Jede Änderung (Feld, alt, neu, wer, wann, Weg: selbst/Admin/Import) in `md_changelog`. |
| **Rechte** (`admin/settings.php`) | Einstellbare Tabelle: je Funktion und Bereich keine/ansehen/bearbeiten (Verzeichnis, Mitglieder, Finanzen, Nutzungsbedingungen, Import, Einstellungen). Höchste Stufe über alle gültigen Funktionen. Siehe [docs/RECHTE.md](docs/RECHTE.md). |
| **Kasse** (`admin/finance.php`) | Buchungen pflegen (einzeln und per CSV-Import mit Spaltenzuordnung, Vorschau, Dublettenprüfung), Zahlung vermerken, Storno mit Grund, CSV-Export. Siehe [docs/FINANZEN.md](docs/FINANZEN.md). |
| **Beiträge und Mahnwesen** (`admin/fees.php`, `admin/dunning.php`) | Beitragsgruppen (Rhythmus, anteilig/voll, Abweichung mit Grund), Beitragslauf mit Vorschau und Dublettenschutz, nummerierte Rechnungen (Druckansicht), Zahlungszuordnung aus dem Kontoauszug (CSV), dreistufiges Mahnwesen mit Gebühr, Stundung und dokumentierten Mahnschreiben. Siehe [docs/BEITRAEGE.md](docs/BEITRAEGE.md). |
| **SEPA-Lastschrift** (`admin/sepa.php`) | Mandate (IBAN verschlüsselt, Vordruck zum Drucken), Einzugsläufe mit TARGET2-Prüfung, FRST/RCUR, Vorabankündigung, pain.008-Datei (.08/.02), Einzug bestätigen, Rücklastschriften, Storno. Siehe [docs/SEPA.md](docs/SEPA.md). |
| **Spendenbescheinigungen** (`admin/receipts.php`) | Einzelquittung (beim Buchen oder später) und **Jahresbescheinigung für das Vorjahr** als Sammelbestätigung mit Anlage, nummeriert und dokumentiert, Duplikat-/Kopie-Kennzeichnung, ungültig erklären mit Grund, Druckansicht. Siehe [docs/SPENDEN.md](docs/SPENDEN.md). |
| **Datenschutz** (`admin/privacy.php`, Dashboard „Meine Daten“) | Auskunft je Mitglied als HTML, JSON und ZIP mit Fotos (jeder Abruf protokolliert), Anfragen mit Monatsfrist, Löschung nach Austritt in zwei Stufen (Profildaten, dann Anonymisierung) mit Fristen, Löschsperre und Protokoll. Siehe [docs/DATENSCHUTZ.md](docs/DATENSCHUTZ.md). |
| **Zustimmung als Voraussetzung** | Ohne Zustimmung zur aktuellen Fassung der Nutzungsbedingungen: nur eigene Daten sehen, nichts ändern. |
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
Die Demo-Daten entstehen deterministisch aus `tools/demo_build.php` (Datumsangaben beziehen sich auf den Tag des Aufrufs); die erzeugte Datei `tools/demo_data.json` liegt nicht im Repo (`.gitignore`) und wird bei Bedarf neu erzeugt. So sehen wir beide dieselben Fälle, ohne dass beim Pull Konflikte entstehen.
Bewusste Sonderfälle: Lukas (alte Fassung der Bedingungen, offene Mahnung), Mia (keine Zustimmung, offene Erstattung, E-Mail fehlt), Karl (schriftliche Zustimmung), Rolf (ausgetreten, Zustimmung widerrufen), Hans/Anna (Übungsleiterpauschale).

## Einbindung in die Sitzungsverwaltung

- Tabellen mit Präfix `md_`, Schema in `sql/schema.sql` (MySQL/MariaDB). `md_*.member_id` verweist auf `svmembers.member_id`.
- Produktiv: `config.php` mit `MD_DB_DRIVER='mysql'`, `MD_DEMO_MODE=false`; die Anmeldung kommt aus der Session der Sitzungsverwaltung (`MD_SESSION_KEY`).
- Der Demo-Stub `sql/sv_stub.sql` gehört **nicht** in die Produktivdatenbank.
- **Funktionen und Rechte:** `md_member_functions` (mit Zeitraum, mehrere gleichzeitig möglich) wird nach `svmembers.aktiv/funktion/role` abgeglichen, höchstes Level gewinnt. Läuft nur im Standalone-Betrieb (`MD_SV_MODE`). Details: [docs/RECHTE.md](docs/RECHTE.md).

## Offen / nächste Schritte

- Abgleich der Spalten von `svmembers` in der echten Datenbank (der Import legt Mitglieder nur mit Nummer, Name und E-Mail an). Der Rechteabgleich prüft vorab, ob `aktiv`, `funktion` und `role` existieren.
- SEPA-Lastschrift, E-Mail-Versand der Schreiben, DATEV-Importprofil (später, mit echter Beispieldatei).
- Übungsleiter-Freibetrag steht in `md_settings.ueb_freibetrag_cents` (Voreinstellung 3.300 €); bitte jährlich prüfen.
- Datenschutz: Löschkonzept mit dem Verein abstimmen (Fristen, Sicherungen, Daten in der Sitzungsverwaltung), siehe docs/DATENSCHUTZ.md.
