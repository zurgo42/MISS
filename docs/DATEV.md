# DATEV-Import

Seite **DATEV-Import** (`admin/datev.php`, erreichbar über *Kasse*; Recht *Finanzen*, Ansehen genügt, Übernehmen braucht „bearbeiten“). Sie übernimmt Buchungen aus einem DATEV-Export in das Kassenbuch der Mitglieder: bezahlte Beiträge, Spenden, Erstattungen, Übungsleiterpauschalen.

## Export aus DATEV

*Buchungsstapel exportieren* im Format **CSV** (Dateikopf `EXTF`, Formatkategorie 21 „Buchungsstapel“). Die Datei ist in der Regel Windows-1252-kodiert (Voreinstellung beim Hochladen). Zum Ausprobieren: `php tools/datev_sample.php` erzeugt aus der Demo eine Beispieldatei (`storage/datev_beispiel.csv`).

Gelesen werden Betrag, Soll/Haben-Kennzeichen, Konto, Gegenkonto, Belegdatum (TTMM, das Jahr ergibt sich aus dem Zeitraum im Dateikopf), Belegfeld 1 und Buchungstext. Fremdwährungen, Nullbeträge und Generalumkehr-Buchungen werden nicht übernommen und in der Vorschau markiert.

## Einrichtung (einmalig)

1. **Personenkonten:** Welches Debitoren-/Kreditorenkonto gehört zu welchem Mitglied? Einzeln oder als Liste `Konto;Mitgliedsnummer` einfügen. Unbekannte Konten ordnest du in der Vorschau zu und lässt sie dabei „merken“.
   - *Debitor* (Konten ab 10000): Forderungen an das Mitglied – Beiträge, Spenden, Eintritte.
   - *Kreditor* (ab 70000): Zahlungen an das Mitglied – Erstattungen, Übungsleiterpauschale.
2. **Buchungsart nach Erlös-/Aufwandskonto:** z. B. 8100–8199 → Beitrag, 8200–8299 → Spende, 4100–4199 → Übungsleiterpauschale. Ohne passende Regel gilt die Voreinstellung der Vorschau.

## Ablauf

1. Datei hochladen. 2. Einstellungen wählen (Forderungen mit übernehmen? Voreinstellungen für die Art), *Vorschau*. 3. Prüfen, Häkchen setzen, *Markierte übernehmen*.

Was geschieht je Buchungszeile:

| Buchung | Ergebnis |
|---|---|
| Haben auf dem Debitor (Zahlungseingang) mit passender Rechnungsnummer im Belegfeld/Text und gleichem Betrag | offene Forderung wird als bezahlt vermerkt (**sicher**, vorangekreuzt) |
| … ohne Rechnungsnummer, aber genau eine offene Forderung mit gleichem Betrag | dieselbe Forderung als **Vorschlag** |
| … sonst | neue **bezahlte Buchung** (Art aus Kontenregel, sonst Voreinstellung) |
| Soll auf dem Debitor (Rechnung) | nur wenn „Forderungen übernehmen“ gewählt: neue offene Forderung (Rechnungen entstehen sonst in MISS) |
| Soll auf dem Kreditor | bezahlte Zahlung an das Mitglied (Art aus Kontenregel, sonst Voreinstellung) |
| Haben auf dem Kreditor | nicht übernommen (Verbindlichkeit, wird mit der Zahlung gebucht) |
| kein Personenkonto bekannt | Mitgliedsnummer eintragen oder – wenn eine Rechnungsnummer im Text steht – Vorschlag |

Die Seite des Personenkontos ergibt sich aus Soll/Haben und der Stellung als Konto oder Gegenkonto (Seitenumkehr wird berücksichtigt).

## Sicherheit

- **Nichts wird doppelt gebucht:** Jede Buchungszeile hat eine Prüfsumme; was schon übernommen wurde, erscheint als „schon übernommen“ (auch bei erneutem Hochladen derselben oder einer überlappenden Datei).
- Forderungen, die in einem Lastschriftlauf stehen, werden nicht über DATEV als bezahlt vermerkt (der Einzug bucht sie).
- Die Übernahme geschieht ganz oder gar nicht; Unsichere Zeilen sind nie vorangekreuzt. Jeder Import steht im Protokoll, jede Buchung im Änderungsprotokoll des Mitglieds.
- Ein Spendenbescheinigungs-Anspruch entsteht nur, wenn die Buchung die Art *Spende* hat – bitte die Kontenregeln darauf prüfen.

## Nicht enthalten

Export aus MISS nach DATEV (Buchungsstapel für den Steuerberater), Teilzahlungen, Übernahme von Stammdaten (Debitoren-/Kreditorenstamm). Das Format und die Kontenrahmen unterscheiden sich je nach Steuerberater: Bitte mit einem echten Export testen und die Spalten-/Kontenregeln anpassen.
