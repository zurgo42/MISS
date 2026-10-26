# SEPA-Lastschrift

Der Verein zieht Beiträge per Basislastschrift (SEPA Core) ein. MISS führt die Mandate, bildet daraus Einzugsläufe und erzeugt die **pain.008-Datei** für das Online-Banking. Sie bucht nichts selbst bei der Bank: Die Datei wird im Banking hochgeladen.

## Einrichtung (einmalig)

1. **Schlüssel erzeugen:** `php tools/make_key.php` und die Ausgabe in `config.php` eintragen:
   `define('MD_ENCRYPTION_KEY', '<64 Hex-Zeichen>');`
   Die IBANs der Mandate werden damit verschlüsselt (AES-256-GCM) gespeichert. **Schlüssel sicher aufbewahren und mit sichern; ohne ihn sind die IBANs unlesbar. Nie ändern, solange Mandate existieren.** Die Demo trägt sich einen eigenen Demo-Schlüssel ein.
2. `php tools/install.php` (legt die Tabellen an, prüft den Schlüssel).
3. **Beiträge → Angaben für Rechnungen:** IBAN des Vereinskontos.
4. **SEPA → Einstellungen:** Gläubiger-Identifikationsnummer (bei der Bundesbank beantragen), optional BIC, Dateiformat, Vorlauf, Vorabankündigung.
5. Rechte: Bereich *sepa* in Einstellungen → Rechte (Voreinstellung: Finanzvorstand, Vorstand und Kassenprüfung ändern; Geschäftsführung und Finanzprüfung ansehen).

## Mandate

Das Mitglied unterschreibt das Mandat (Vordruck: SEPA → „Vordruck drucken“, mit Gläubiger-ID und vorgeschlagener Mandatsreferenz). Das Papier wird aufbewahrt; in der Verwaltung wird erfasst: Kontoinhaber, IBAN, ggf. BIC, **Datum der Unterschrift**, Hinweis zum Ablageort.

- Jedes Mandat hat eine Referenz (`M-<Mitgliedsnr>-NN`). Ein neues Mandat widerruft ein bisheriges aktives als „ersetzt“.
- Status: *aktiv*, *ausgesetzt* (z. B. nach Rücklastschrift, mit Grund), *widerrufen* (endgültig).
- Die vollständige IBAN sieht nur, wer das Recht hat und auf „IBAN anzeigen“ klickt; jeder Abruf wird protokolliert (Datenschutz-Protokoll).
- **36-Monats-Regel:** Ein Mandat, das 36 Monate nicht genutzt wurde, verfällt; es wird dann nicht mehr eingezogen.
- Mitglieder sehen auf dem Dashboard ihr Mandat (Referenz, letzte 4 IBAN-Stellen, Status).

## Einzugslauf

SEPA → *Lastschriftlauf*: Einzugsdatum wählen, die Vorschau zeigt je Mitglied mit Mandat die fälligen Forderungen (Beitrag und Eintritt, offen, nicht gestundet, nicht schon in einem Lauf).

- Pro Mitglied **eine** Lastschrift, die alle fälligen Forderungen zusammenfasst.
- Das Einzugsdatum muss ein **Bankarbeitstag (TARGET2)** sein und mindestens **Vorlauf** (Voreinstellung 2) TARGET-Tage in der Zukunft liegen.
- **Vorabankündigung:** Die Rechnung muss dem Mitglied vorher zugegangen sein (Voreinstellung 14 Tage Frist). Zu kurz angekündigte Posten sind gesperrt, außer die Ausnahme „Kurze Frist zulassen“ wird bewusst gewählt (nur wenn Frist vereinbart ist).
- **FRST/RCUR** wird automatisch gesetzt (erster erfolgreicher Einzug eines Mandats = FRST).
- Nicht einziehbar (mit Grund angezeigt): Mandat ausgesetzt/widerrufen/verfallen, Vorabankündigung zu kurz, vorheriger Einzug noch nicht bestätigt, IBAN nicht lesbar.
- Mitglieder ohne Mandat zahlen per Überweisung und kommen nicht in den Lauf. Rechnungen an Mitglieder mit Mandat tragen den Hinweis „Der Betrag wird am … von Ihrem Konto … eingezogen“ (Mandatsreferenz, Gläubiger-ID, IBAN maskiert).
- Forderungen im Lastschriftlauf werden **nicht gemahnt**.

### Ablauf

1. **Lauf erstellen** (Status *erzeugt*) → XML herunterladen und im Online-Banking hochladen. Solange nicht eingereicht, kann der Lauf mit Begründung **storniert** werden; die Forderungen werden wieder frei.
2. **Als eingereicht vermerken.**
3. Nach dem Einzug: **Einzug bestätigen** → Forderungen gelten als bezahlt (Zahltag = Einzugsdatum).
4. **Rücklastschrift** (pro Posten): Rückgabegrund (AM04, MD06, AC04 …) erfassen → Forderung wieder offen und im Mahnwesen; bei Gründen wie AC01, AC04, MD01, MD06, MS02 wird das Mandat ausgesetzt; optional wird die Rücklastschriftgebühr dem Mitglied als Forderung gestellt.

Das XML wird bei jedem Download aus den gespeicherten Posten neu erzeugt (Mandatsdaten sind je Posten als Momentaufnahme gespeichert), jeder Download wird im Ablauf des Laufs vermerkt. Formate: `pain.008.001.08` (Standard) oder `.02`.

## Test mit der Bank

Die Datei ist gegen die offiziellen Schemata der Deutschen Kreditwirtschaft/ISO geprüft (`tools/test_sepa.php`, wenn `tools/xsd/` die XSD-Dateien enthält). Manche Banken haben eigene Eigenheiten: Vor dem ersten echten Einzug eine **Testdatei** mit einem Cent-Betrag oder dem Prüfmodus der Banking-Software einreichen und Format (.08/.02) bestätigen lassen.

## Datenschutz

- Auskunft (Art. 15) enthält Mandate samt IBAN und alle Lastschriften des Mitglieds.
- Austritt, Stufe 1: aktive Mandate werden widerrufen. Stufe 2 (Anonymisierung nach Ablauf der Fristen): Mandate und Posten werden gelöscht.
- Die IBAN liegt nie im Klartext in der Datenbank, nicht im Änderungsprotokoll und nicht in der Datenschutz-Protokolltabelle.

## Offene Punkte (mit Bank/Steuerberater klären)

- Vorlauf und Vorabankündigungsfrist als Voreinstellung (2 Tage, 14 Tage) an die eigene Bank und die Satzung/Beitragsordnung anpassen.
- Aufbewahrungsfrist der Mandatsunterlagen (Papier) mit dem Steuerberater klären; die Software löscht die Mandate erst bei der Anonymisierung.
