# Online-Anmeldung neuer Mitglieder

Interessierte melden sich über **`apply.php`** (öffentlich, ohne Anmeldung) – ausdrücklich nicht über die Sitzungsverwaltung. Der Vorstand entscheidet unter **Anmeldungen** (`admin/applications.php`, Recht *Mitglieder*; Entscheiden braucht „bearbeiten“).

## Ablauf

1. **Antrag:** Name, E-Mail, Anschrift (Pflicht), optional Geburtsdatum, Telefon, Mitgliedsart und Nachricht. Pflicht-Häkchen für Datenschutzhinweise und Satzung (Links in den Einstellungen).
2. **E-Mail bestätigen:** Der Antragsteller bekommt einen Link (48 Stunden), den er mit einem Klick bestätigt. Erst dann ist der Antrag „neu“ beim Vorstand (optional mit Benachrichtigung per E-Mail).
3. **Entscheidung:**
   - *Aufnehmen und einladen:* legt das Mitglied an (nächste Mitgliedsnummer, Rolle Mitglied, Status aktiv, Eintrittsdatum, Anschrift, Telefon, Anrede/Geburtsdatum), ordnet die Beitragsgruppe und gewählte Teilgruppen zu und sendet die Einladung zum Festlegen des Passworts. Der erste Beitrag kommt mit dem nächsten Beitragslauf (anteilig, je nach Beitragsgruppe). Die Nutzungsbedingungen bestätigt das neue Mitglied bei der ersten Anmeldung.
   - *Ablehnen:* mit optionaler Begründung; der Antragsteller wird per E-Mail informiert.

## Einrichtung

Unter *Anmeldungen → Einstellungen*: Freischalten (Standard: aus), Einleitungstext, Adressen von Datenschutzhinweisen und Satzung, Benachrichtigungsadresse, Aufbewahrungsdauer. Die wählbaren Mitgliedsarten legst du unter *Beiträge* fest („bei der Online-Anmeldung wählbar“). Der E-Mail-Versand muss eingerichtet sein (siehe [MAIL.md](MAIL.md)).

## Missbrauchsschutz und Datenschutz

- Köderfeld, Zeitprüfung des Formulars (3 s bis 2 h), höchstens 5 Anträge je Absender und 40 je Stunde insgesamt.
- Die Seite verrät nicht, ob eine Adresse schon Mitglied ist: Bekannte Adressen erhalten nur einen Hinweis mit Passwort-Link, es entsteht kein Antrag.
- Unbestätigte Anträge werden nach 3 Tagen gelöscht, entschiedene nach 90 Tagen (einstellbar, 7–730). Bereinigt wird beim Öffnen der Seite *Anmeldungen* und durch `tools/retention.php`. Bei Aufnahme gehören die Daten ab dann zum Mitgliedsdatensatz.
- Abgelehnte Anträge enthalten personenbezogene Daten; die Aufbewahrung sollte mit dem Datenschutzbeauftragten abgestimmt werden.
