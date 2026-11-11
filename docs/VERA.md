# Stimmberechtigung für VERA

Menü **Mitglieder → Stimmberechtigung (VERA)** (Recht „Mitglieder" bearbeiten, siehe `docs/RECHTE.md`). VERA (Versammlungsorganisation, dritte Anwendung der Produktfamilie neben KONA und MISS) liest am Einlass der Mitgliederversammlung `svmembers.status`, um zu entscheiden, wer eine Stimmkarte bekommt - siehe dort `Admin-Installationsanleitung.md`, Abschnitt 10e. MISS berechnet und schreibt diesen Wert; VERA selbst kennt weder Beiträge noch Geburtsdaten und schreibt die Spalte nie.

## Die sieben Werte

`svmembers.status` ist immer genau einer dieser Werte (oder `NULL`, solange noch nie berechnet):

Gast · Aufnahme beantragt · Vorläufig aufgenommen · Teilnahmeberechtigt (ohne Stimmrecht) · Stimmberechtigt · Gesperrt · Ausgetreten

## Herleitung

Aus dem MISS-eigenen Mitgliedsstatus (`md_profiles.status`, Dashboard-Feld „Status") plus Geburtsdatum und offenen Beitragsforderungen (`md_ledger`):

| MISS-Status | VERA-Status |
|---|---|
| `ausgetreten` | Ausgetreten |
| `interessent` | Aufnahme beantragt |
| `ruhend` | Gesperrt |
| `ehrenmitglied` | Stimmberechtigt (beitragsfrei, keine Beitragsprüfung) |
| `aktiv` (Default) | siehe unten |

Für `aktiv` entscheidet zuerst das **Alter am Stichtag** (nur falls die Vorgabe „Mindestalter" gesetzt ist - sonst keine Altersgrenze): zu jung → *Teilnahmeberechtigt (ohne Stimmrecht)*. Sonst entscheidet die **Beitragszahlung**: eine offene (nicht bezahlte) Beitragsforderung, deren Fälligkeit am Stichtag bereits erreicht ist → *Gesperrt*, sonst → *Stimmberechtigt*.

MISS unterscheidet „Gast" und „Vorläufig aufgenommen" nicht automatisch von „Aufnahme beantragt" (nur ein `interessent`-Status) - für diese Fälle sowie satzungsbedingte Einzelfälle gibt es die manuelle Übersteuerung.

## Einstellungen

- **Stichtag** - zu welchem Tag Alter und Beitragszahlung geprüft werden. Leer = heute. Für eine echte Versammlung typischerweise ein Tag kurz davor (z. B. „Tag vor der Versammlung").
- **Mindestalter für Stimmrecht** - leer = keine Altersgrenze.

## Manuelle Übersteuerung

Je Mitglied in der Tabelle unten per Auswahlfeld: einer der sieben Werte, oder „(automatisch)". Eine gesetzte Übersteuerung wirkt sofort auf `svmembers.status` und bleibt auch beim nächsten „Jetzt berechnen" unangetastet - bis sie wieder auf „(automatisch)" gestellt wird (dann sofort neu berechnet).

## Jetzt berechnen

Läuft **nicht** automatisch im Hintergrund (keine Trigger auf Zahlungen oder Statuswechsel) - ein Knopf auf der Seite berechnet und schreibt den Status für alle Mitglieder ohne manuelle Übersteuerung neu, mit Änderungsprotokoll je geändertem Mitglied. Passend zum Stichtag kurz vor der Versammlung anstoßen, nicht laufend.

## Demo-Daten

`tools/demo_setup.php`, `tools/demo_load.php` und die Demo-Zurücksetzen-Funktion im Admin-Bereich berechnen den VERA-Status am Ende automatisch mit (`vera_status_alle_berechnen()`), damit er sofort nach dem Laden/Zurücksetzen der Demo zu den übrigen Demo-Daten passt - u. a.:

- Lukas (Mitglied 3): überfällige offene Beitragsforderung → **Gesperrt**.
- Mia (Mitglied 11): offene Beitragsforderung, aber erst in der Zukunft fällig → **Stimmberechtigt**.
- Rolf (Mitglied 12): MISS-Status `ausgetreten` → **Ausgetreten**.
- Alle übrigen: bezahlter Beitrag, MISS-Status `aktiv` → **Stimmberechtigt**.

Keine der 12 Demo-Figuren ist minderjährig - um den Fall „Teilnahmeberechtigt (ohne Stimmrecht)" zu sehen, testweise ein hohes Mindestalter eintragen (z. B. 99) und neu berechnen; das betrifft dann vorübergehend alle. Für den Fall „Status unbekannt" (VERA-seitig, falls `svmembers.status` noch nie berechnet wurde) einfach ein frisch angelegtes Testmitglied verwenden, bevor „Jetzt berechnen" das erste Mal läuft.
