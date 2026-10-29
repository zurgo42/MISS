# Datenschutz: Auskunft, Anfragen, Löschfristen

Menü **Datenschutz** (Recht „Datenschutz“ ansehen/bearbeiten, siehe `docs/RECHTE.md`) und im Dashboard der Kasten **Meine Daten**.

> **Hinweis:** Die Fristen und Abläufe sind technische Voreinstellungen, keine Rechtsberatung. Aufbewahrungsfristen (z. B. für Buchungsbelege und Spendenbescheinigungen),
> Auskunftsinhalte und Löschkonzept bitte mit Steuerberater und Datenschutzbeauftragten des Vereins abstimmen und danach unter *Datenschutz → Fristen* einstellen.

## Einwilligung zum Mitgliederverzeichnis und zur Datenverwendung

Das Dashboard hat einen gemeinsamen Knopf **Alle Eingaben speichern** (Persönliche Daten, Beruf & Interessen, Vorstellung, Adressen, Kontakt). Direkt davor steht die Einwilligung (Konstante `MD_DIRECTORY_CONSENT` in `src/fields.php`): Anrede, Titel, Vorname und Name erscheinen im Mitgliederverzeichnis, die eingegebenen Daten werden für satzungsgemäße Zwecke verwendet, und Angaben mit freigegebener Sichtbarkeit erscheinen im jeweils aktuellen Mitgliederverzeichnis.

**Warum es zusätzlich einen Hinweis beim Login gibt:** Die Einwilligung wird beim Speichern erteilt. Daten können aber schon vorliegen, ohne dass das Mitglied sie je selbst gespeichert hat, zum Beispiel aus einer **Datenübernahme** (Import aus einer früheren Mitgliederverwaltung) oder weil die Verwaltung sie erfasst hat. Für diese Daten fehlt die Einwilligung zum aktuellen Wortlaut. Deshalb zeigt das Dashboard jedem Mitglied einen Hinweis mit dem Grund und dem Knopf **Einwilligung bestätigen**, bis sie vorliegt. Der Hinweis erscheint auch bei Mitgliedern, die den Nutzungsbedingungen noch nicht zugestimmt haben, weil Daten aus der Übernahme schon vorhanden sein können. Er erscheint nicht, wenn die Verwaltung Daten eines Mitglieds ansieht (die Einwilligung kann nur das Mitglied selbst geben).

Nachweis: Zeitpunkt und Wortlaut-Kennung (Hash des Textes) stehen im Änderungsprotokoll (`md_changelog`, Feld `consent.directory`). Wird der Text `MD_DIRECTORY_CONSENT` geändert, ändert sich die Kennung, und alle Mitglieder werden erneut gefragt.

## Auskunft (Art. 15 DSGVO)

Jedes Mitglied kann seine Daten jederzeit selbst abrufen (Dashboard → *Meine Daten*), auch ohne Zustimmung zu den Nutzungsbedingungen:

- **Ansehen und drucken** (HTML), **JSON** (maschinenlesbar, Datenübertragbarkeit nach Art. 20) und **ZIP** mit JSON, HTML und den Fotos.
- Der Export enthält: Stammdaten aus der Sitzungsverwaltung (ohne Zugangsdaten), Profil (inkl. Verwaltungsnotiz), Adressen, Kontakt, Fotos, Sichtbarkeit, Teilgruppen, Funktionen,
  Buchungen, Spendenbescheinigungen, Zustimmungen, Änderungsprotokoll, Datenschutz-Anfragen und -Vorgänge sowie die Pflichtinformationen (Zweck, Kategorien, Empfänger, Speicherdauer, Rechte).
  Nicht enthalten sind Daten der Sitzungsverwaltung (Sitzungen, Protokolle, Anträge, Beschlüsse); darauf weist der Export hin.
- Mit dem Recht „Datenschutz“ (ansehen) erzeugt die Verwaltung die Auskunft auch für andere Mitglieder. **Jeder Abruf wird protokolliert** (wer, wann, Format).

## Anfragen und Fristen

- Mitglieder stellen einen **Löschantrag** im Dashboard; die Verwaltung kann auch Anfragen erfassen, die per E-Mail, Brief oder Telefon eingehen (*Anfrage erfassen*).
- Die Antwortfrist beträgt **einen Monat** ab Eingang (Art. 12 Abs. 3 DSGVO); überfällige Anfragen sind rot markiert.
- Bearbeiten: *erledigt* (mit Vermerk) oder *ablehnen* (Begründung ist Pflicht). Alles steht im Protokoll.

## Löschung nach Austritt in zwei Stufen

Nur für Mitglieder mit Status **ausgetreten** (und eingetragenem Austrittsdatum).

| Stufe | Wann | Was passiert |
|---|---|---|
| **1 – Profildaten löschen** | `Austritt + X Monate` (Voreinstellung 12) oder vorzeitig auf Antrag | Adressen, Kontakte, Fotos (auch die Dateien), Sichtbarkeit, Teilgruppen, Profiltexte, Geburtsdatum, Beruf u. a., Verwaltungsnotiz und die Änderungshistorie der Profilfelder werden gelöscht; der Zugang wird gesperrt, die E-Mail entfernt. **Bleiben:** Name, Mitgliedsnummer, Eintritt/Austritt, Funktionen, Buchungen, Spendenbescheinigungen, Zustimmungsnachweise |
| **2 – Anonymisieren** | `Ende des Kalenderjahres der letzten Buchung, Bestätigung, Funktion oder Zustimmung + Y Jahre` (Voreinstellung 10) | Buchungen, Bestätigungen, Zustimmungen, Funktionen, Anfragen und Protokolle des Mitglieds werden gelöscht; Name und Mitgliedsnummer in `svmembers` werden durch „Gelöscht (anonymisiert)“ und `DEL-<ID>` ersetzt |

- **Gesperrt** sind beide Stufen bei einer **Löschsperre** (mit Grund, z. B. laufendes Verfahren), bei **offenen Buchungen** und bei Mitgliedern, die nicht ausgetreten sind.
- Stufe 2 ist erst nach Stufe 1 möglich und nur nach Ablauf der Aufbewahrungsfrist; ein vorzeitiges Anonymisieren gibt es nicht.
- Gelöscht wird nie automatisch: Die Verwaltung führt jede Stufe ausdrücklich aus (Bestätigungsfrage). Die Seite zeigt, wo Fristen erreicht sind.
- Das **Protokoll** (`md_privacy_log`) bleibt als Nachweis erhalten und enthält keine Klarnamen, nur Mitglieds-ID, Vorgang, Zeitpunkt und ausführende Person.

## Grenzen

- **Sitzungsverwaltung:** Die Anonymisierung ändert nur die Identität in `svmembers`. Namen, die in Protokollen, Anträgen oder Beschlüssen der Sitzungsverwaltung stehen (soweit dort als Text gespeichert),
  bleiben unberührt. Ob und wie lange sie aufbewahrt werden, ist eine Entscheidung des Vereins (Vereinshistorie, Aufbewahrungspflichten) und gehört in dessen Löschkonzept.
- **Sicherungen** (Datenbank-Backups) enthalten gelöschte Daten bis zu ihrem Ablauf; das gehört ins Löschkonzept.
- Fotos werden aus `storage/photos` entfernt; Kopien in Backups oder bei Mitgliedern lassen sich nicht erfassen.
- Die Erweiterung PHP `zip` ist für den ZIP-Export nötig (bei XAMPP in der Regel aktiv); ohne sie bleiben HTML und JSON.

## Technik

Tabellen `md_privacy_requests` (Anfragen) und `md_privacy_log` (Protokoll, ohne Fremdschlüssel), Spalten `md_profiles.retention_hold/hold_reason/purged_at/anonymized_at`,
Einstellungen `privacy.profile_months` und `privacy.ledger_years`. Nach einem Update einmal `php tools/install.php`. Prüfung: `php tools/test_privacy.php`.
Übersicht der fälligen Fälle auf der Kommandozeile: `php tools/retention.php`.
