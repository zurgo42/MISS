# Anmeldung

MISS hat eine **eigene Anmeldung** (`login.php`). Das Passwort liegt als Hash in `svmembers.password_hash` – derselben Spalte wie in der Sitzungsverwaltung. Die Sitzung gilt für beide Anwendungen (gleiche Domain), Funktionäre melden sich also nur einmal an.

> Voraussetzung: Die Sitzungsverwaltung prüft Passwörter ebenfalls mit `password_verify()` gegen `password_hash` und liest die Sitzungsvariable `member_id` (`MD_SESSION_KEY`). Das bitte beim ersten Test mit der echten Sitzungsverwaltung bestätigen.

## Ablauf

1. **Einladung:** Unter *E-Mail → Zugänge* „Alle einladen“ (alle ohne Passwort mit gültiger Adresse) oder im Mitgliedsdatensatz *Zugang → Einladung senden*. Das Mitglied erhält einen Link (7 Tage gültig, einmal verwendbar) und legt sein Passwort selbst fest. Die Verwaltung sieht Passwörter nie.
2. **Anmelden** mit E-Mail-Adresse oder Mitgliedsnummer.
3. **Passwort vergessen:** `forgot.php` sendet einen Link (2 Stunden gültig). Die Seite antwortet immer gleich, damit keine Adressen ausprobiert werden können; höchstens 3 Links je Stunde.
   Beim Festlegen des neuen Passworts wird zusätzlich der **Spitzname** (sonst das **Geburtsdatum**) abgefragt, sofern eins davon im Profil steht; fünf falsche Angaben machen den Link ungültig. Ohne hinterlegte Angabe genügt der Link allein, ebenso bei der ersten Einladung.
4. **Passwort ändern** und Zustellweg für Rechnungen: *Mein Zugang*.
5. **Anmeldelink (ohne Passwort):** Auf der Anmeldeseite unter „oder ohne Passwort“ Kennung eingeben und „Anmeldelink per E-Mail senden“. Der Link ist **15 Minuten** gültig und nur einmal verwendbar. Nach dem Klick fragt `magic.php` zur Sicherheit nach dem **Spitznamen**, ist keiner eingetragen, nach dem **Geburtsdatum** (TT.MM.JJJJ). Fünf falsche Angaben machen den Link ungültig.
   - Voraussetzung ist, dass Spitzname oder Geburtsdatum im Profil stehen. Fehlen beide, kommt statt des Links eine Mail mit dem Hinweis, wie man das nachträgt; die Seite antwortet immer gleich, damit man keine Konten ausprobieren kann.
   - Der Anmeldelink kann kein Passwort setzen und ein Passwort-Link keine Anmeldung auslösen (getrennte Arten); höchstens 3 Anforderungen je Stunde.

## Schutz

- Passwörter mindestens 8 Zeichen, nicht gleich Mailadresse, Mitgliedsnummer oder Namen, keine Wiederholungen oder gängigen einfachen Muster. Die Anforderungen stehen im Formular (*Passwort festlegen*, *Mein Zugang*) und werden beim Tippen abgehakt.
- Nach einer fehlerhaften Eingabe bleiben E-Mail-Adresse und Passwort im Formular stehen (mit dem Auge prüfbar); die Seiten werden nicht zwischengespeichert.
- 5 Fehlversuche je Kennung in 15 Minuten sperren die Anmeldung vorübergehend (gespeichert wird nur ein Hash der Kennung).
- Links werden nur als Hash gespeichert; ein neuer Link macht den vorherigen ungültig.
- Gesperrte Zugänge (`is_active = 0`), anonymisierte Datensätze und mehrdeutige Adressen können sich nicht anmelden. Beim Austritt (Stufe 1) werden Passwort und Links gelöscht.
- Zugriffe auf die Konten (Passwort gesetzt/geändert, Einladung) stehen im Änderungsprotokoll, nie das Passwort selbst.

## Links in E-Mails und Verzeichnisname

Die Adresse in Einladungs-, Passwort- und Anmeldelinks richtet sich nach der Adresse, unter der MISS aufgerufen wird (auch nach dem Umbenennen des Verzeichnisses). **Steht in der `config.php` `MD_PUBLIC_URL` (oder `MD_BASE_URL`), gilt dieser Wert und muss nach einem Umbenennen des Verzeichnisses angepasst werden** – sonst zeigen die Links auf den alten Namen. Ohne diese Einträge muss nichts geändert werden.

## Einstellung

`MD_LOGIN_URL` in `config.php` leer lassen (= eigene Anmeldung). Steht dort noch die Adresse der Sitzungsverwaltung, werden nicht angemeldete Besucher dorthin geschickt; `login.php` funktioniert trotzdem.
In der Demo gibt es weiter die Figurenauswahl; „Echte Anmeldung ausprobieren“ testet den Ablauf mit Passwort (die Links stehen in `storage/mail/*.eml`).
