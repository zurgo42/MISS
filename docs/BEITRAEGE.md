# Beiträge, Rechnungen, Mahnwesen

Menüs **Beiträge** und **Mahnwesen** (Recht „Beiträge“ ansehen/bearbeiten, siehe `docs/RECHTE.md`). Zahlungen werden unter *Beiträge → Zahlungen zuordnen* verbucht.
Nicht enthalten: SEPA-Lastschrift (eigener Baustein), E-Mail-Versand (die Schreiben werden im Browser gedruckt oder als PDF gespeichert).

## Ablauf im Jahr

1. **Einmalig:** Beitragsgruppen anlegen, allen Mitgliedern eine Gruppe zuordnen, Angaben für Rechnungen (IBAN, Zahlungsziel, Steuerhinweis) und die Vereinsangaben (Name, Anschrift, Unterzeichner) eintragen.
2. **Beitragslauf** (*Beiträge → Beitragslauf*): Zeitraum wählen, Vorschau prüfen, „Rechnungen erzeugen“, Druckansicht öffnet sich.
3. **Zahlungen zuordnen:** Kontoauszug als CSV einlesen, Zuordnungen bestätigen.
4. **Mahnlauf** (*Mahnwesen*): fällige Mahnungen prüfen, ausstellen und drucken. Gestundetes bleibt außen vor.

## Beitragsgruppen und Zuordnung

- Eine Gruppe hat einen **Betrag je Abrechnungszeitraum**, einen **Rhythmus** (jährlich, halbjährlich, vierteljährlich, monatlich) und eine **Eintrittsregel**:
  *voll* (voller Betrag, wenn im Zeitraum Mitglied) oder *anteilig* (Monate ab Eintrittsmonat bis Austrittsmonat, jeweils voll gezählt).
- Je Mitglied gilt die am Zeitraum gültige Zuordnung (Dashboard → *Beitrag*; bei ausgetretenen oder neuen Mitgliedern mit Beginn und Ende). Eine neue Zuordnung beendet die alte am Vortag.
- **Abweichungen** (anderer Betrag *oder* Prozent-Ermäßigung) gehen nur mit **Grund** und Angabe, wer bewilligt hat; sie stehen im Änderungsprotokoll des Mitglieds und in der Vorschau des Beitragslaufs.
- Beitragsfreie Mitglieder (z. B. Ehrenmitglieder) bekommen eine Gruppe mit 0 €. Mitglieder **ohne Gruppe** werden im Lauf nicht berücksichtigt und in der Vorschau als Warnung gezeigt.

## Beitragslauf

- Zeitraum: Jahr (`2027`), Halbjahr (`2027-H1`), Quartal (`2027-Q2`) oder Monat (`2027-03`). Berücksichtigt werden Mitglieder, deren Gruppe diesen Rhythmus hat.
- Die **Vorschau** zeigt je Mitglied Gruppe, Betrag und Hinweise (Abweichung, anteilig, schon berechnet, beitragsfrei, ausgetreten). **Pro Mitglied und Zeitraum wird nie doppelt berechnet**; ein zweiter Lauf berechnet nur, was noch fehlt (z. B. Neueintritte).
- Das Erzeugen ist nur nach Ansicht der Vorschau möglich und läuft in einer Transaktion. Jede Rechnung ist eine Forderung (Art Beitrag) mit **Rechnungsnummer `R<Jahr>-0001`** (laufend je Kalenderjahr) und Fälligkeitsdatum.
- Jeder Lauf wird dokumentiert (Zeitraum, wer, wann, Anzahl, Summe, Vermerk) und lässt sich jederzeit erneut drucken. Fehlerhafte Rechnungen werden wie alle Buchungen mit Grund storniert (*Finanzen*).
- **Rechnung:** Druckansicht A4 mit Absender, Anschrift, Nummer, Datum, Zahlungsziel, Betrag, Verwendungszweck (Rechnungs- und Mitgliedsnummer), Bankverbindung und Steuerhinweis. Mitglieder drucken ihre Rechnungen im Dashboard selbst.

## Zahlungen zuordnen

CSV-Kontoauszug mit Spaltenzuordnung (Datum, Betrag, Verwendungszweck, optional Auftraggeber).

- **Sicher:** Rechnungs- oder Mahnschreibennummer im Verwendungszweck (egal ob mit Bindestrich oder Leerzeichen; Treffer mitten in längeren Nummern zählen nicht) und Betrag stimmt mit der Summe der gefundenen Forderungen.
- **Vorschlag:** Mitgliedsnummer im Verwendungszweck und genau eine offene Forderung mit demselben Betrag. Muss einzeln bestätigt werden.
- **Betrag weicht ab, nicht zugeordnet, Ausgang:** werden nur angezeigt; Teilzahlungen bucht die Kasse von Hand (*Finanzen*).
- Gebucht wird erst nach Bestätigung, in einer Transaktion; jede Zahlung steht im Protokoll des Mitglieds.

## Mahnwesen

- **Mahnfähig:** offene Forderungen an Mitglieder der Art Beitrag und Eintritt mit Fälligkeitsdatum. Spenden werden nicht gemahnt.
- **Drei einstellbare Stufen** (Voreinstellung: Zahlungserinnerung nach 14 Tagen, 1. Mahnung 21 Tage danach mit 5 € Gebühr, 2. Mahnung 21 Tage danach mit 10 € Gebühr; je 14 bzw. 10 Tage Zahlungsfrist). Stufe 1 zählt ab Fälligkeit, Stufe 2 und 3 ab der vorherigen Mahnung. Text, Tage, Frist und Gebühr sind frei einstellbar (Platzhalter `{anrede}`, `{name}`, `{total}`, `{fee}`, `{deadline}`).
- **Ein Schreiben je Mitglied** fasst alle gerade fälligen Forderungen zusammen; Text und Gebühr folgen der höchsten Stufe darin. Nummer `M<Jahr>-0001`. Noch offene frühere Mahngebühren stehen mit im Schreiben.
- **Mahngebühr** wird als eigene Forderung gebucht (nicht erneut gemahnt, aber im nächsten Schreiben aufgeführt). *Mahngebühren sind nur zulässig, wenn die Satzung oder Beitragsordnung sie vorsieht und müssen angemessen sein – bitte vom Verein prüfen.*
- **Stundung:** Forderung bis zu einem Tag stunden (mit Grund); bis dahin kein Mahnschreiben. Aufheben jederzeit.
- **Dokumentation:** Jedes Schreiben hält Anschrift, Text, Positionen und Beträge zum Zeitpunkt der Ausstellung fest. Storno mit Grund setzt Mahnstufen und Fristen der Forderungen zurück und storniert die Gebühr (nicht, wenn sie schon bezahlt ist).
- **Nach der letzten Stufe** gibt es keine automatische Eskalation; die Liste „Letzte Mahnstufe erreicht“ zeigt, wo der Vorstand über weitere Schritte nach der Satzung entscheidet.
- Mitglieder sehen Mahnstufe und Mahnschreiben im Dashboard (*Meine Finanzen*).

## Technik

Tabellen `md_fee_groups`, `md_member_fees`, `md_fee_runs`, `md_dunning_levels`, `md_dunning_letters`; Spalten `md_ledger.run_id/fee_period/dunning_level/last_dunned_at/deferred_until/deferred_reason`; neue Buchungsart „Mahngebühr“.
Nach einem Update einmal `php tools/install.php` (ergänzt Tabellen, Spalten, Rechte und die Standard-Mahnstufen). Prüfung: `php tools/test_fees.php`.
