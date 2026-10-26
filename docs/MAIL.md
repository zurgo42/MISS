# E-Mail

Rechnungen, Mahnungen und Zugangs-Links gehen per E-Mail als **reiner Text im Mailkörper** (kein Anhang). Alles läuft über den Ausgang (`admin/mail.php`): Nachrichten werden vorgemerkt, geprüft und dann gesendet; der Ausgang bleibt als Nachweis erhalten.

## Einrichtung

In `config.php`:

```php
define('MD_MAIL_TRANSPORT', 'smtp');       // 'log' (Demo) | 'mail' (PHP mail()) | 'smtp'
define('MD_SMTP_HOST', 'smtp.example.org'); define('MD_SMTP_PORT', 587); define('MD_SMTP_SECURE', 'tls'); // tls | ssl | none
define('MD_SMTP_USER', '…');               define('MD_SMTP_PASS', '…');
define('MD_PUBLIC_URL', 'https://verein.example.org/MISS');   // für Links in Mails
```

- **Demo/lokal:** `log` schreibt die Nachrichten als `.eml`-Dateien nach `storage/mail/` (es wird nichts verschickt; zum Ansehen mit einem Mailprogramm öffnen).
- **XAMPP/Windows:** PHP `mail()` ist dort meist nicht eingerichtet, besser `smtp` mit den Zugangsdaten des Providers.
- `MD_PUBLIC_URL` unbedingt setzen; sonst wird der Host der Anfrage für Links genutzt.
- Absender, Absendername und Antwortadresse: Seite *E-Mail* (Absenderadresse ist Pflicht; sie sollte zur Domain des Mailservers gehören, sonst landen Nachrichten im Spam – SPF/DKIM beim Provider einrichten). Dort auch „Testnachricht an mich senden“.

## Rechnungen und Mahnungen

- Jedes Mitglied wählt unter **Mein Zugang**, ob es Rechnungen und Mahnungen **per Post** (Standard) oder **per E-Mail** erhält. Ohne gültige Adresse bleibt es bei Post.
- *Beiträge → bisherige Läufe → „per E-Mail vormerken“* stellt die Rechnungen des Laufs für alle Mitglieder mit Zustellweg E-Mail in den Ausgang.
- *Mahnwesen → Ausgestellte Schreiben* hat je Schreiben „per E-Mail senden“ und eine Sammelaktion für alle mit Zustellweg E-Mail. Der Text entspricht dem gedruckten Schreiben; die Druckansicht bleibt als Original für die Akte.
- Doppelversand wird verhindert (je Rechnung/Schreiben nur eine Nachricht, solange sie wartet oder versendet ist).
- Nachrichten sind erst „wartend“ und gehen mit *E-Mail → jetzt senden* hinaus; fehlgeschlagene lassen sich einzeln erneut senden oder abbrechen.
- Für Mahnungen per E-Mail: Ob ein bloßer Mailversand als Zustellnachweis genügt, hängt von Satzung und Beitragsordnung ab. Für die letzte Mahnstufe ist ein Brief sicherer (mit dem Verein/Steuerberater klären).

## Rechte, Datenschutz

- Bereich *mail* in der Rechtetabelle (Voreinstellung: Geschäftsführung, Finanzvorstand, Kasse ändern; Vorstand ansehen).
- Passwort-Links werden im Ausgang nach dem Versand unkenntlich gemacht.
- Die Auskunft (Art. 15) listet die E-Mails (Betreff, Art, Status, Datum); bei der Anonymisierung (Stufe 2) wird der Ausgang des Mitglieds gelöscht.
