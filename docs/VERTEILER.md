# E-Mail-Verteiler

Seite **Verteiler** (`admin/lists.php`, Recht *mail*). Ein Verteiler ist eine **Teilgruppe**, eine **Funktion** (z. B. alle Vorstände) oder **alle Mitglieder**. Die Empfänger ergeben sich laufend aus den Mitgliedsdaten; wer die Gruppe verlässt oder austritt, ist automatisch draußen.

## Nachricht senden

Verteiler wählen → Betreff und Text → *Senden*. Jede Nachricht geht **einzeln** an jeden Empfänger (keine offene Adressliste). Platzhalter: `{anrede}` `{vorname}` `{name}`. Die Fußzeile mit Hinweis und Abmeldelink wird automatisch angefügt.

- Mit „gleich senden“ gehen bis zu 100 Nachrichten sofort hinaus, der Rest steht im E-Mail-Ausgang (*E-Mail → jetzt senden*, 100 je Klick). Ohne Häkchen wird nur vorgemerkt.
- Ausgeschlossen werden Mitglieder ohne gültige Adresse, ausgetretene, gesperrte und anonymisierte sowie – bei abbestellbaren Verteilern – Abbesteller. Die Übersicht zeigt, wie viele es jeweils sind.
- Identische Nachrichten innerhalb von 10 Minuten werden als Doppelklick abgewiesen; jede Nachricht wird unter *Bisherige Nachrichten* mit Empfängerzahl festgehalten.

## Abbestellen

- **Abbestellbar** (Standard): Die Fußzeile enthält einen Abmeldelink (die Mail trägt außerdem die Kopfzeile `List-Unsubscribe`). Der Link zeigt erst einen Bestätigungsknopf, damit Mail-Scanner nichts abbestellen. Das Mitglied kann sich unter *Mein Zugang → E-Mail-Verteiler* wieder anmelden.
- **Verbindlich** (z. B. Verteiler der Vorstände): kein Abmeldelink, nicht abbestellbar. Nur für Mitteilungen verwenden, die zur Funktion gehören.
- Werbliche Rundmails an Mitglieder brauchen eine Rechtsgrundlage (Vereinszweck/Einwilligung); im Zweifel mit dem Datenschutzbeauftragten klären.

## Nachricht an Angemeldete einer Veranstaltung

Der Veranstalter (und die Verwaltung) schreibt auf der Veranstaltungsseite allen Angemeldeten (optional auch der Warteliste). Antworten gehen an die E-Mail-Adresse des Absenders; höchstens 3 Nachrichten je Veranstaltung und Tag. Die Nachricht wird sofort gesendet und unter *Bisherige Nachrichten* aufgeführt.

## Datenschutz

Die Auskunft nennt abbestellte Verteiler; beim Austritt werden Abbestellungen mit den übrigen Mail-Daten gelöscht. Der Schlüssel für die Abmeldelinks liegt in den Einstellungen (`mail.secret`) und wird bei Bedarf selbst erzeugt; wer ihn ändert, macht ausgesandte Abmeldelinks ungültig.
