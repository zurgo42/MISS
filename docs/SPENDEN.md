# Spendenbescheinigungen (Zuwendungsbestätigungen)

Menü **Spendenbescheinigungen** (Recht „Spendenbescheinigungen“ ansehen/bearbeiten, siehe `docs/RECHTE.md`). Unterstützt werden **Geldspenden**.
Nicht enthalten: Aufwandsspenden (Verzicht auf Erstattung – auf der Bestätigung steht immer „Nein“), Sachspenden, Mitgliedsbeiträge.

> **Wichtig:** Wortlaut und Pflichtangaben folgen dem amtlichen Muster des Bundesfinanzministeriums, sind aber aus dem Gedächtnis des Entwicklers nachgebildet.
> Bitte vor dem ersten echten Einsatz einmal vom Steuerberater gegen das **aktuelle** Muster prüfen lassen (Wortlaut, Reihenfolge, Hinweis zur Haftung).
> Die Fristen zum Alter des Bescheids (Freistellung 5 Jahre, Feststellung nach § 60a AO 3 Jahre) sind ebenfalls zu bestätigen.

## Vereinsangaben (einmalig)

*Einstellungen → Verein (für Spendenbescheinigungen)*: Name und Anschrift des Vereins, Finanzamt, Steuernummer, Art und Datum des Bescheids
(Feststellung nach § 60a AO oder Freistellungsbescheid mit Veranlagungszeitraum), begünstigte Zwecke (in der Form „Förderung …“), Ort der Unterschrift und Unterzeichner.
Solange etwas fehlt oder der Bescheid zu alt ist, lassen sich keine Bestätigungen ausstellen; die Seite nennt den Grund.

## Einzelquittung („Spendenquittung ausstellen“)

- Beim **Buchen** einer bezahlten Spende: Haken *Spendenquittung ausstellen*.
- Bei einer vorhandenen bezahlten Spende in der Buchungsliste: Schaltfläche *Quittung*.
- Ergebnis: eine nummerierte Bestätigung (`2026-0001`, Jahr der Ausstellung und laufende Nummer) und die Druckansicht.

## Jahresbescheinigung für das Vorjahr

*Spendenbescheinigungen → Jahresbescheinigung für [Vorjahr]*:

1. Die Liste zeigt alle Mitglieder mit **bezahlten, noch nicht bescheinigten Spenden** im gewählten Jahr (Tag der Zuwendung = Zahltag). Bereits einzeln bescheinigte Spenden zählen nicht doppelt.
2. Mitglieder ohne vollständige Anschrift (Straße, PLZ, Ort) sind gesperrt und markiert.
3. **Entwurf ansehen** zeigt die Bestätigungen mit dem Stempel „ENTWURF – NICHT GÜLTIG“; nichts wird gespeichert.
4. **Ausgewählte ausstellen und drucken** erzeugt je Mitglied eine **Sammelbestätigung** mit Anlage (Aufstellung der Einzelzuwendungen) und öffnet die Druckansicht für alle (eine Bestätigung je Seite; im Browser „Drucken“ bzw. „Als PDF speichern“).

## Dokumentation

- Jede Bestätigung hält **Verein, Spender (Name und Anschrift) und alle Einzelzuwendungen zum Zeitpunkt der Ausstellung** fest; spätere Änderungen an Adresse oder Vereinsangaben verändern sie nicht.
- Gespeichert werden Nummer, Art, Zeitraum, Betrag, wer und wann ausgestellt hat und jeder Ausdruck (`md_receipts`, `md_receipt_events`); im Änderungsprotokoll des Mitglieds steht ein Eintrag.
- Die Spende in der Buchungsliste zeigt die Nummer der Bestätigung. Eine bescheinigte Spende kann nicht ein zweites Mal bescheinigt werden.
- **Original und Duplikat:** Der erste Ausdruck durch den Aussteller (Recht „bearbeiten“) ist das Original. Jeder weitere Ausdruck trägt den Stempel **DUPLIKAT**, Ausdrucke durch Mitglieder (im Dashboard unter *Meine Finanzen*) den Stempel **KOPIE**, ungültige Bestätigungen **UNGÜLTIG**.
- **Fehler korrigieren:** In der Liste *Ausgestellte Bestätigungen* mit Grund *ersetzen* (alte ungültig erklären und sofort neu ausstellen, verweist auf die alte) oder *nur ungültig* (die Spenden werden wieder frei). Bestätigungen werden nie gelöscht.
  Eine bereits ausgehändigte, nun ungültige Bestätigung muss zurückgefordert werden (Hinweis für die Praxis).

## Technik

Tabellen `md_receipts` (Bestätigungen mit Momentaufnahme als JSON) und `md_receipt_events` (ausgestellt, gedruckt, ungültig), Spalte `md_ledger.receipt_id`.
Nach einem Update einmal `php tools/install.php` ausführen (ergänzt Tabellen, Spalte und die Rechte-Voreinstellung für den neuen Bereich, ohne Bestehendes zu ändern).
Prüfungen: `php tools/test_receipts.php`.
