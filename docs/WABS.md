# Wahl und Abstimmung (WABS)

Mitglieder können sicher und anonym online wählen und abstimmen. Der Gesamtentwurf ist in mehrere Phasen aufgeteilt; dieses Dokument wird mit jeder Phase erweitert.

## Phase 1: Wahlen anlegen (fertig)

- Neue Funktion **Wahlausschuss** (Code `WA`) im Funktionen-/Rechte-Modell (siehe [RECHTE.md](RECHTE.md)), neuer Bereich *wahl* mit Stufen keine/ansehen/bearbeiten. Voreinstellung: Wahlausschuss bekommt *bearbeiten*.
- `admin/wabs.php`: listet alle angelegten Wahlen und bietet (mit Recht *bearbeiten*) ein Formular für Bezeichnung, Start und Ende (Tag und Uhrzeit). Tabelle `md_wabs_elections`.
- Solange eine Wahl läuft (Start ≤ jetzt ≤ Ende), erscheint auf dem Dashboard jedes Mitglieds ein Hinweisbanner mit Bezeichnung und Enddatum.
- Bestehende Installationen bekommen die neue Funktion beim nächsten `php tools/install.php` additiv ergänzt (kein Neuanlegen nötig, nichts wird gelöscht).

## Phase 2: Token-Erzeugung und Mailversand (fertig)

- Pro aktivem Mitglied mit (gültiger) E-Mail-Adresse, das für diese Wahl noch nicht berücksichtigt wurde, erzeugt „Wahlaufforderungen erzeugen" ein kryptografisch sicheres Token (`bin2hex(random_bytes(24))`). In der dauerhaften, anonymen Token-Liste (`md_wabs_tokens`) steht **nur der SHA-256-Hash**, nie der Klartext und nie ein Mitgliedsbezug.
- Die Wahlaufforderung (Link mit Klartext-Token) kommt in eine eigene Warteschlange (`md_wabs_mail_queue`, bewusst nicht die bestehende `md_mail_outbox`, die als Versandnachweis gedacht ist). „Jetzt senden" verschickt bis zu 100 Zeilen je Klick über den vorhandenen Mail-Transport (log/mail/SMTP, siehe [MAIL.md](MAIL.md)) und **löscht jede Zeile sofort nach dem Versandversuch** – egal ob er erfolgreich war oder nicht.
- Erfolgreich versendet: Token bleibt gültig in der Liste, `md_wabs_recipients` (election_id, member_id, status – ohne Token/Hash) merkt sich nur, dass dieses Mitglied bereits berücksichtigt wurde (verhindert Doppelversand).
- Fehlgeschlagen: das zugehörige Token wird sofort aus der Liste gelöscht (war nie zustellbar) und das Mitglied erscheint in einer Liste „Fehlgeschlagen – bitte einzeln erneut anstoßen". Es gibt **keinen automatischen Wiederholungsversuch** – der Wahlausschuss stößt je Mitglied gezielt ein komplett neues Token an.
- Jede Wahl hat eine eigene Link-Basis-URL zum (künftigen) Wahlserver (`md_wabs_elections.vote_base_url`), die vor dem Erzeugen eingetragen werden muss.
- Mitglieder ohne E-Mail-Adresse werden gezählt, aber noch nicht berücksichtigt – das folgt mit Phase 3.

## Phase 3: PDF-Sammeldruck mit QR-Code (fertig)

- Für aktive Mitglieder ohne E-Mail-Adresse, aber mit hinterlegter Postanschrift, erzeugt „PDF-Sammeldruck erzeugen" je ein Token (gleiche sichere Erzeugung wie bei Phase 2) und eine Seite in einer gemeinsamen PDF-Datei (Anschrift, Erklärtext mit Link, QR-Code des Links). Mitglieder ohne jede Erreichbarkeit (weder E-Mail noch Anschrift) werden nur gezählt ("nicht erreichbar") und tauchen in keiner Liste zum Versand auf.
- **FPDF und phpqrcode liegen bewusst nicht im Repo**, sondern als fertige Dateien zwei Verzeichnisebenen über der Anwendung (`<Serverwurzel>/fpdf/fpdf.php` und `<Serverwurzel>/phpqrcode/qrlib.php`, gemeinsam nutzbar mit den Geschwisteranwendungen VERA/KONA) - ohne sie meldet „PDF-Sammeldruck erzeugen" einen klaren Fehler statt eines Serverfehlers. phpqrcode braucht außerdem ein beschreibbares Unterverzeichnis `cache/` neben `qrlib.php`.
- **Wichtig für eigene Erweiterungen:** FPDFs Kernschriften verstehen kein UTF-8, nur CP1252 (Windows-1252) - jeder Text, der an FPDF übergeben wird, muss vorher durch `md_wabs_pdf_text()` (intern `iconv('UTF-8', 'CP1252//TRANSLIT', …)`), sonst werden Umlaute/Anführungszeichen zu Mojibake.
- Die fertige Datei liegt in `storage/wabs/` (kein Direktzugriff über die URL, Auslieferung nur über `admin/wabs_pdf.php` mit Rechteprüfung) und wird erst neu erzeugt, wenn die vorherige über „Gedruckt und verschickt – PDF-Datei jetzt löschen" **endgültig von der Festplatte entfernt** wurde (Pflicht-Löschung laut Anforderung). Erzeugen und Löschen werden im Änderungsprotokoll (`md_changelog`, Feld `wabs.pdf_generated`/`wabs.pdf_deleted`) vermerkt - ohne Namen der betroffenen Mitglieder, nur Dateiname und Seitenzahl.
- Mitglieder, die per PDF berücksichtigt wurden, stehen (wie bei Mail) in `md_wabs_recipients`, hier mit `channel = 'pdf'`.

## Noch nicht enthalten (geplante Phasen)

- **Übertragung zum Wahlserver** (Phase 4): bei gleichem Server direkte Übertragung, bei externem Server CSV-Export/Import. Danach Löschung der Tokenliste in MISS.
- **WABS-Grundgerüst** (Phase 5, eigenständige Anwendung unter `mv/WABS` im VERA/MV-Repo): eigenes schlankes Login nur für die beiden vorher festgelegten Wahlausschuss-Mitglieder, kein SSO zu MISS.
- **Wahl-/Abstimmungsinhalte** (Phase 6): Kategorie Wahl (Ämter mit Kandidaten) und Kategorie Abstimmung (Anträge mit Ja/Nein/Enthaltung).
- **Token-Einlösung und Abstimmen** (Phase 7): 2 Sekunden Pause mit Hinweis bei jedem Aufruf (Brute-Force-Bremse, unabhängig von der Token-Gültigkeit). Abstimmen löscht das Token (einmalig), Bestätigung ohne Votenanzeige, optionales Kontroll-Token.
- **Kontrollabruf** (Phase 8) und **Auszählung/Ergebnis** (Phase 9): Ergebnis zunächst nur für die beiden Wahlausschuss-Mitglieder; bei gleichem Server einstellbar auch für MISS-Mitglieder abrufbar (keine Geheimhaltung mehr nötig, aber ggf. erst mit anderen Abstimmungswegen zusammenzuführen).
