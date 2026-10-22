# Finanzen und Kasse

Das Dashboard zeigt jedem Mitglied seine eigenen Zahlen. Gepflegt werden sie auf der Seite **Finanzen** (Menü, Recht „Finanzen“ ansehen/bearbeiten, siehe `docs/RECHTE.md`).

## Buchungsliste

Eine Tabelle `md_ledger` für alles:

| Art | Richtung |
|---|---|
| Mitgliedsbeitrag, Eintritt Veranstaltung, Spende | Mitglied → Verein |
| Kostenerstattung, Übungsleiterpauschale | Verein → Mitglied |

- Beträge in Cent, immer positiv; die Richtung ergibt sich aus der Art.
- **Status:** offen (Forderung bzw. Auszahlung offen), bezahlt, storniert.
- **Nie löschen:** Fehlerhafte Buchungen werden mit Grund storniert (wer, wann, warum bleiben gespeichert).
- Jede Buchung, Zahlung und jeder Storno steht im Änderungsprotokoll des Mitglieds.

## Kassenseite

- Filter nach Mitglied/Beleg/Text, Art, Status und Jahr; Summenkacheln für die Auswahl.
- Neue Buchung einzeln erfassen, offene Posten als bezahlt vermerken, stornieren.
- Auswahl als CSV exportieren (Semikolon, UTF-8 mit BOM, Excel-tauglich).

## CSV-Import von Buchungen

*Finanzen → Buchungen aus CSV importieren.* Es gibt kein festes Format: Jeder Verein bringt seine Kontoauszüge oder Buchhaltungsexporte selbst in eine CSV, die Spalten ordnet die Kasse zu.

1. Upload (UTF-8, Windows-1252, ISO-8859-1; Trennzeichen automatisch).
2. Spaltenzuordnung mit automatischem Vorschlag. Zuordnung zum Mitglied über **Mitgliedsnummer** oder E-Mail.
   Beträge `1.234,56` oder `1234.56`; mehrdeutiges `1.234` wird abgelehnt. Fehlende Art/Status lassen sich als Standardwert setzen.
3. Vorschau mit Summe und Fehlerliste (unbekanntes Mitglied, ungültiger Betrag/Datum, **Dubletten**: gleiche Belegnummer je Mitglied, sonst gleiche Art/Betrag/Datum/Text, auch innerhalb der Datei).
4. Import in **einer Transaktion**; ein Fehler beim Schreiben bricht alles ab. Jeder Lauf steht in `md_import_runs`.
5. Die Zuordnung lässt sich als **Profil** speichern (je Quellsoftware), das spart die Arbeit beim nächsten Import.

**DATEV und Bankanbindung:** Größere Vereine, die mit DATEV und direkter Kontoanbindung arbeiten, bringen ihre Buchungen am besten über einen Export von dort in diese CSV-Schnittstelle. Ein fertiges DATEV-Profil ist noch nicht gebaut; sinnvoll erst mit einer echten Beispieldatei.

## Noch nicht enthalten

Beitragslauf (Rechnungen erzeugen), Mahnwesen, SEPA-Lastschrift, Spendenbescheinigung; Dokumente/Belege als Datei. Siehe `docs/KONZEPT_VEREINSVERWALTUNG.md` der Sitzungsverwaltung für den geplanten Umfang.
