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
