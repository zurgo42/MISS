# Anleitung für Tester

Vielen Dank für deine Hilfe! Du testest die **MISS** (Mitglieder-Informationssystem) eines Vereins. Alles in diesem System ist **erfunden**: Namen, Adressen, Beträge. Bitte gib **keine echten personenbezogenen Daten** ein und behandle das System vertraulich.

## Anmelden
1. Adresse und Zugangsdaten des Verzeichnisschutzes bekommst du vom Projektleiter.
2. Auf der Anmeldeseite E-Mail-Adresse **oder** Mitgliedsnummer und dein Passwort eingeben.
3. Das erste Mal fragt das System nach der Zustimmung zu den Nutzungsbedingungen – ohne Zustimmung siehst du nur deine eigenen Daten und kannst nichts ändern (das ist so gewollt).
4. Unter „Mein Zugang“ kannst du dein Passwort ändern. E-Mails verschickt das Testsystem nicht.

## Deine Testfigur
| Figur | Rolle | gut geeignet für |
|---|---|---|
| Max Mustermann (DEMO0001) | Finanzvorstand, Admin | Finanzen, Beiträge, Mahnwesen, SEPA, DATEV, Spendenbescheinigungen |
| Erika Musterfrau (DEMO0002) | Finanzverwaltung | Beitragslauf, Rechnungen, Lastschrift |
| Lukas Beispiel (DEMO0003) | Vorstand | Mitgliederliste, Anmeldungen, Verteiler |
| Julia Sommer (DEMO0004) | Geschäftsführung, Admin | Einstellungen, Rechte, Datenschutz, E-Mail |
| Thomas Winter (DEMO0005), Sabine Herbst (DEMO0006) | Vorstandsassistenz bzw. Verwaltung, jeweils mit Admin-Recht | Mitgliederdaten, Rechte, Einstellungen |
| Hans Lehmann (DEMO0007), Anna Frühling (DEMO0008), Petra Nord (DEMO0009) | Mitglieder mit Funktion | Alltag eines Mitglieds, Veranstaltungen anlegen und buchen |
| Karl Prüfer (DEMO0010) | Finanzprüfung (nur Lesen) | Sicht eines Prüfers: sieht Finanzen, ändert nichts |
| Mia Technik (DEMO0011) | Technik-Admin, Mitglied mit offener Rechnung | Rechnungen, Lastschrift-Mandat, Admin-Sicht |
| Rolf Ehemals (DEMO0012) | ausgetretenes Mitglied | Verhalten nach dem Austritt |

## Aufgaben (Auswahl – gern mehr!)
**Als Mitglied**
- Dashboard ansehen; Profil, Anschriften, Fotos bearbeiten; Sichtbarkeit einzelner Felder ändern. Prüfen, dass andere nur sehen, was freigegeben ist (Mitgliederverzeichnis).
- Finanzen im Dashboard: offene Forderungen, bezahlte Beiträge, Rechnung ansehen.
- „Meine Daten“: Auskunft ansehen/herunterladen, Löschantrag stellen.
- „Mein Zugang“: Passwort ändern; Rechnungen per Post oder E-Mail; E-Mail-Verteiler abbestellen.
- **Veranstaltungen:** eine anlegen, sich anmelden und abmelden; Teilnehmerliste mit und ohne Freigabe prüfen; auf die Warteliste gehen; als Veranstalter absagen und den Angemeldeten schreiben.
- Online-Anmeldung eines neuen Mitglieds über `apply.php` (ohne Anmeldung aufrufbar): Antrag, Bestätigungslink (siehe unten).

**Mit Verwaltungsrechten**
- Mitglied anlegen/ändern; CSV-Import mit einer eigenen Beispieldatei.
- Beitragslauf: Vorschau, durchführen, Rechnungen drucken; Mahnwesen; Zahlungen aus einer CSV-Datei zuordnen.
- SEPA: Mandat anlegen, Lastschriftlauf erzeugen, Datei ansehen, Einzug bestätigen, Rücklastschrift erfassen.
- Spendenbescheinigung ausstellen und drucken (Original/Duplikat).
- DATEV-Import mit einer eigenen Beispieldatei (Format siehe Seite) – hier sind echte Beispiele aus eurer Buchhaltung (anonymisiert!) besonders wertvoll.
- E-Mail-Verteiler anlegen und eine Nachricht senden; Anträge prüfen und aufnehmen oder ablehnen.
- Datenschutz: Auskunft erteilen, Löschfristen ansehen.

**Das System ärgern**
- Leere Felder, Umlaute, sehr lange Texte, falsche Datumsformate, doppelt absenden, Zurück-Taste, zwei Browserfenster, Handy.

## Rückmeldungen
Bitte pro Feststellung kurz notieren:
1. **Wo?** Adresse der Seite (oben in der Browserzeile)
2. **Als wer?** Testfigur
3. **Was hast du gemacht?** (Schritte)
4. **Was habe ich erwartet / was ist passiert?**
5. **Screenshot** (mit Browserzeile), wenn möglich

Auch „unklar“, „umständlich“, „hier fehlt mir etwas“ und Verbesserungsvorschläge sind willkommen – nicht nur Fehler.

## Hinweise
- E-Mails werden nicht versendet. Einladungs- und Passwort-Links siehst du als Admin-Figur unter **E-Mail** (Ausgang).
- Das Testsystem kann jederzeit zurückgesetzt werden; dann gelten neue Passwörter.
- Rechnungen und Schreiben sind Druckansichten (Strg+P, „Als PDF speichern“).
