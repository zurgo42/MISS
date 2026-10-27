# Administration

Seite **Administration** (`admin/settings.php`, früher „Einstellungen“; erreichbar über das Dashboard unter „Sonderfunktionen“). Hier stehen alle Angaben, die in jedem Verein anders heißen oder geregelt sind. Jeder Bereich ist ein ausklappbarer Abschnitt.

| Bereich | Inhalt |
|---|---|
| **Verein, Impressum und Datenschutzerklärung** | Vereinsname (erscheint in der Titelzeile, auf Rechnungen und Mahnungen, in E-Mails), Anschrift, Unterzeichner, Kontakt, Vereinsregister und Vorstand für das Impressum, weitere Impressumsangaben, Text der Datenschutzerklärung, Fußzeile (Copyright, eigene Adressen für Impressum/Datenschutz), Logo. Impressum und Datenschutzerklärung stehen auf den öffentlichen Seiten `impressum.php` und `datenschutz.php` und sind in der Fußzeile verlinkt. |
| **Vereinskonten** | Mehrere Konten mit Kontoinhaber (Zahlungsempfänger), IBAN, BIC, Bank. Ein Konto ist das **Beitragskonto** (Rechnungen, Mahnungen, Lastschrift; sein Kontoinhaber ist der Zahlungsempfänger im Lastschriftlauf), eines das **Spendenkonto**. |
| **Spendenbescheinigungen** | Zahlungsempfänger der Zuwendungsbestätigung samt Steuerangaben, **unabhängig vom Vereinsnamen oben** (z. B. gemeinnütziger Unterverein). |
| **Mitgliedsarten und Beiträge** | Bezeichnung, Beitrag, Rhythmus, Regel für Ein- und Austritt, „online wählbar“ für das Formular „Mitglied werden“. |
| **Funktionen und Level** | Bezeichnungen editierbar, Funktionen **an/abwählbar** (nicht jeder Verein hat eine Geschäftsführung), Level 0–19, neue Funktionen. Abgewählte werden nicht mehr zur Auswahl angeboten und fehlen in der Rechtetabelle; wer sie schon hat, behält sie. |
| **Teilgruppen** | Bezeichnung und Art editierbar, **an/abwählbar**, neue Teilgruppen. Bestehende Mitgliedschaften bleiben. |
| **Rechte** | Rechtetabelle je Funktion und Bereich (keine/ansehen/bearbeiten). |
| **Standard-Sichtbarkeit** | Ob eine Angabe im Mitgliederverzeichnis zunächst sichtbar ist (ja/nein). |
| **Allgemein** | Freibetrag Übungsleiterpauschale, Level ohne Funktion. |

Hinweise
- Ohne eigene Angaben fällt das System für Name und Anschrift auf die früheren Angaben für Spendenbescheinigungen zurück; ein Beitragskonto in der Administration hat Vorrang vor den älteren Feldern unter *Beiträge → Angaben für Rechnungen*.
- Die Datenschutzerklärung der Demo ist nur ein Beispieltext und muss vom Datenschutzbeauftragten des Vereins geprüft werden.

## Voreinstellung der Fußzeile

Copyright-Zeile und die Adressen für Impressum und Datenschutz lassen sich in der `config.php` vorbelegen (`MD_FOOTER_COPYRIGHT`, `MD_FOOTER_IMPRESSUM_URL`, `MD_FOOTER_PRIVACY_URL`, siehe `config.example.php`). Die Felder unter *Verein → Fußzeile* zeigen dann diese Werte; was dort gespeichert wird, hat Vorrang, ein leeres Feld bedeutet wieder die Voreinstellung. Ohne alles steht dort „© Vereinsname“ mit den eigenen Seiten Impressum und Datenschutz.

## Weitere Anwendungen (MISS als Einstieg)

Unter *Administration → Weitere Anwendungen* trägt das Adminteam die anderen Anwendungen des Vereins ein (KONA, VERA, KAND oder eigene Links): Name, Beschreibung, Adresse (`/KAND/`, `../KAND/` oder `https://…`), ein Haken „an“ und für wen der Knopf erscheint (alle Mitglieder, Funktionsträger mit gültiger Funktion, nur Verwaltung). Die Knöpfe stehen über dem Dashboard. Ohne Eintrag erscheint nichts; MISS läuft ohne die anderen Anwendungen.

Damit die Anmeldung mitgenommen wird, müssen die Anwendungen unter derselben Domain laufen. Alle teilen die Sitzung: Cookie für die ganze Domain, Laufzeit 15 Tage (`MD_SESSION_DAYS` in der `config.php`; KONA und KAND nutzen denselben Wert, damit keine Anwendung die Sitzungen der anderen früher abräumt).
