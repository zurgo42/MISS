# Veranstaltungen

Jedes Mitglied (nach Zustimmung zu den Nutzungsbedingungen) kann **Veranstaltungen anlegen** und sich auf Veranstaltungen **anmelden**: `events.php` (Liste), `event.php` (Details, Anmeldung, Teilnehmerliste).

## Anlegen und verwalten

- Titel, Beschreibung, Ort, Beginn, Ende, Anmeldeschluss, Teilnehmerzahl (leer = unbegrenzt), Teilnahmegebühr (leer = kostenlos).
- Veranstalter ist, wer sie angelegt hat. Der Veranstalter ändert, sagt ab und sieht die **vollständige Teilnehmerliste** (alle Angemeldeten, Warteliste, Anmerkungen, Zahlungsstatus) mit CSV-Export. Er kann einzelne Anmeldungen entfernen.
- Die Verwaltung (Recht *events*, Voreinstellung Vorstand und Geschäftsführung) kann fremde Veranstaltungen ebenso verwalten.
- Die Gebühr lässt sich nicht mehr ändern, sobald es Anmeldungen gibt. Die Teilnehmerzahl nicht unter die Zahl der Angemeldeten senken.
- Absage nur mit Grund: Offene Gebühren werden storniert, alle Angemeldeten bekommen eine E-Mail. Bezahlte Gebühren bleiben stehen und werden im Protokoll als zu erstatten vermerkt.

## Anmelden

- Verbindliche Anmeldung bis zum Anmeldeschluss (sonst bis zum Beginn), Abmeldung bis zum Beginn.
- **Sichtbarkeit in der Teilnehmerliste:** Beim Anmelden (und später jederzeit) entscheidet jeder, ob andere Angemeldete ihn in der Liste sehen. Standard: **nein**. Der Veranstalter sieht immer alle.
- Wer nicht angemeldet ist, sieht keine Namen, nur die Zahl der Angemeldeten. Angemeldete sehen die Namen derer, die es erlaubt haben, sich selbst und die Zahl der übrigen.
- Ist die Veranstaltung ausgebucht, gibt es eine **Warteliste**. Wird ein Platz frei (Abmeldung, mehr Plätze), rückt der Nächste automatisch nach und wird per E-Mail benachrichtigt.

## Gebühren

Bei Teilnahmegebühr entsteht für jede Anmeldung (nicht für die Warteliste, erst beim Nachrücken) eine Forderung der Art *Eintritt* mit Nummer `EV<Veranstaltung>-<Anmeldung>`. Sie erscheint im Dashboard des Mitglieds und wird per Überweisung bezahlt; der Kontoauszugs-Import ordnet sie über die Nummer zu. **Veranstaltungsgebühren werden nicht per Lastschrift eingezogen** (das Mandat gilt für Beiträge). Offene Forderungen laufen ins normale Mahnwesen.

## Datenschutz

Die Auskunft enthält die eigenen Anmeldungen (mit Sichtbarkeitswahl) und die eigenen Veranstaltungen. Beim Austritt (Stufe 1) werden die Anmeldungen gelöscht, die Buchungen bleiben bis zur Anonymisierung.

## Noch nicht enthalten

Nachricht des Veranstalters an alle Angemeldeten (kommt mit den E-Mail-Verteilern), Begleitpersonen/mehrere Plätze je Anmeldung, wiederkehrende Veranstaltungen, Anmeldung durch die Verwaltung für andere.
