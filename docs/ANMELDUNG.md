# Anmeldung

Die Mitgliederverwaltung hat eine **eigene Anmeldung** (`login.php`). Das Passwort liegt als Hash in `svmembers.password_hash` – derselben Spalte wie in der Sitzungsverwaltung. Die Sitzung gilt für beide Anwendungen (gleiche Domain), Funktionäre melden sich also nur einmal an.

> Voraussetzung: Die Sitzungsverwaltung prüft Passwörter ebenfalls mit `password_verify()` gegen `password_hash` und liest die Sitzungsvariable `member_id` (`MD_SESSION_KEY`). Das bitte beim ersten Test mit der echten Sitzungsverwaltung bestätigen.

## Ablauf

1. **Einladung:** Unter *E-Mail → Zugänge* „Alle einladen“ (alle ohne Passwort mit gültiger Adresse) oder im Mitgliedsdatensatz *Zugang → Einladung senden*. Das Mitglied erhält einen Link (7 Tage gültig, einmal verwendbar) und legt sein Passwort selbst fest. Die Verwaltung sieht Passwörter nie.
2. **Anmelden** mit E-Mail-Adresse oder Mitgliedsnummer.
3. **Passwort vergessen:** `forgot.php` sendet einen Link (2 Stunden gültig). Die Seite antwortet immer gleich, damit keine Adressen ausprobiert werden können; höchstens 3 Links je Stunde.
4. **Passwort ändern** und Zustellweg für Rechnungen: *Mein Zugang*.

## Schutz

- Passwörter mindestens 10 Zeichen, nicht gleich Mailadresse, Mitgliedsnummer oder Namen, keine Wiederholungen.
- 5 Fehlversuche je Kennung in 15 Minuten sperren die Anmeldung vorübergehend (gespeichert wird nur ein Hash der Kennung).
- Links werden nur als Hash gespeichert; ein neuer Link macht den vorherigen ungültig.
- Gesperrte Zugänge (`is_active = 0`), anonymisierte Datensätze und mehrdeutige Adressen können sich nicht anmelden. Beim Austritt (Stufe 1) werden Passwort und Links gelöscht.
- Zugriffe auf die Konten (Passwort gesetzt/geändert, Einladung) stehen im Änderungsprotokoll, nie das Passwort selbst.

## Einstellung

`MD_LOGIN_URL` in `config.php` leer lassen (= eigene Anmeldung). Steht dort noch die Adresse der Sitzungsverwaltung, werden nicht angemeldete Besucher dorthin geschickt; `login.php` funktioniert trotzdem.
In der Demo gibt es weiter die Figurenauswahl; „Echte Anmeldung ausprobieren“ testet den Ablauf mit Passwort (die Links stehen in `storage/mail/*.eml`).
