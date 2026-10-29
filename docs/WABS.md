# Wahl und Abstimmung (WABS)

Mitglieder können sicher und anonym online wählen und abstimmen. Der Gesamtentwurf ist in mehrere Phasen aufgeteilt; dieses Dokument wird mit jeder Phase erweitert.

## Phase 1: Wahlen anlegen (fertig)

- Neue Funktion **Wahlausschuss** (Code `WA`) im Funktionen-/Rechte-Modell (siehe [RECHTE.md](RECHTE.md)), neuer Bereich *wahl* mit Stufen keine/ansehen/bearbeiten. Voreinstellung: Wahlausschuss bekommt *bearbeiten*.
- `admin/wabs.php`: listet alle angelegten Wahlen und bietet (mit Recht *bearbeiten*) ein Formular für Bezeichnung, Start und Ende (Tag und Uhrzeit). Tabelle `md_wabs_elections`.
- Solange eine Wahl läuft (Start ≤ jetzt ≤ Ende), erscheint auf dem Dashboard jedes Mitglieds ein Hinweisbanner mit Bezeichnung und Enddatum.
- Bestehende Installationen bekommen die neue Funktion beim nächsten `php tools/install.php` additiv ergänzt (kein Neuanlegen nötig, nichts wird gelöscht).

## Noch nicht enthalten (geplante Phasen)

- **Token-Erzeugung und Mailversand** (Phase 2): pro Mitglied mit E-Mail-Adresse ein zufälliges, kryptografisch sicheres Token, Reihenfolge gemischt. Eigene, nach erfolgreichem Versand *löschende* Warteschlange (bewusst nicht die bestehende `md_mail_outbox`, die als Versandnachweis gedacht ist). Fehlgeschlagener Versand wird nicht automatisch wiederholt – der Wahlausschuss stößt bei Bedarf ein neues Token für das betroffene Mitglied manuell an.
- **PDF-Sammeldruck mit QR-Code** (Phase 3) für Mitglieder ohne E-Mail-Adresse, mit FPDF und phpqrcode (beide als fertige Dateien im Serververzeichnis, kein Composer). Pflicht-Löschung der PDF-Datei nach Druck/Versand, protokolliert.
- **Übertragung zum Wahlserver** (Phase 4): bei gleichem Server direkte Übertragung, bei externem Server CSV-Export/Import. Danach Löschung der Tokenliste in MISS.
- **WABS-Grundgerüst** (Phase 5, eigenständige Anwendung unter `mv/WABS` im VERA/MV-Repo): eigenes schlankes Login nur für die beiden vorher festgelegten Wahlausschuss-Mitglieder, kein SSO zu MISS.
- **Wahl-/Abstimmungsinhalte** (Phase 6): Kategorie Wahl (Ämter mit Kandidaten) und Kategorie Abstimmung (Anträge mit Ja/Nein/Enthaltung).
- **Token-Einlösung und Abstimmen** (Phase 7): 2 Sekunden Pause mit Hinweis bei jedem Aufruf (Brute-Force-Bremse, unabhängig von der Token-Gültigkeit). Abstimmen löscht das Token (einmalig), Bestätigung ohne Votenanzeige, optionales Kontroll-Token.
- **Kontrollabruf** (Phase 8) und **Auszählung/Ergebnis** (Phase 9): Ergebnis zunächst nur für die beiden Wahlausschuss-Mitglieder; bei gleichem Server einstellbar auch für MISS-Mitglieder abrufbar (keine Geheimhaltung mehr nötig, aber ggf. erst mit anderen Abstimmungswegen zusammenzuführen).
