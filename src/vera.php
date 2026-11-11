<?php
declare(strict_types=1);

/**
 * vera.php - Stimmberechtigung für VERA (Versammlungsorganisation, eigenständige
 * Anwendung der Produktfamilie MISS/KONA/VERA). VERA liest svmembers.status rein
 * lesend, um am Einlass der Mitgliederversammlung zu entscheiden, wer eine
 * Stimmkarte bekommt (siehe dortige Admin-Installationsanleitung.md, Abschnitt
 * 10e). MISS berechnet und schreibt diesen Wert - VERA selbst kennt weder
 * Beiträge noch Geburtsdaten.
 *
 * svmembers.status ist IMMER genau einer dieser sieben Werte (siehe
 * VERA_STATUS_WERTE) oder NULL (noch nie berechnet):
 *
 *   Gast, Aufnahme beantragt, Vorläufig aufgenommen,
 *   Teilnahmeberechtigt (ohne Stimmrecht), Stimmberechtigt, Gesperrt, Ausgetreten
 *
 * Herleitung aus dem MISS-eigenen Mitgliedsstatus (md_profiles.status, siehe
 * MD_STATUS in fields.php) plus Geburtsdatum und offenen Beitragsforderungen:
 *
 *   ausgetreten    -> Ausgetreten
 *   interessent    -> Aufnahme beantragt   (MISS unterscheidet "Gast" und
 *                                           "Vorläufig aufgenommen" nicht -
 *                                           beide VERA-Werte bleiben für eine
 *                                           manuelle Übersteuerung nutzbar,
 *                                           siehe vera_overrides())
 *   ruhend         -> Gesperrt
 *   ehrenmitglied  -> Stimmberechtigt      (typischerweise beitragsfrei, siehe
 *                                           Beitragsgruppe "Ehrenmitglied" -
 *                                           keine Beitragsprüfung)
 *   aktiv (Default) -> je nach Alter am Stichtag (Vorgabe "Mindestalter") und
 *                       offener, überfälliger Beitragsforderung (Vorgabe
 *                       "Stichtag"):
 *                       zu jung                -> Teilnahmeberechtigt (ohne Stimmrecht)
 *                       Beitrag überfällig     -> Gesperrt
 *                       sonst                  -> Stimmberechtigt
 *
 * Eine manuelle Übersteuerung (md_profiles.vera_override) hat immer Vorrang
 * vor dieser Automatik und wird von vera_status_alle_berechnen() nicht
 * angetastet - für die drei Fälle, die MISS nicht automatisch unterscheiden
 * kann (Gast, Vorläufig aufgenommen, oder ein Einzelfall mit abweichender
 * Satzungsregel).
 *
 * Wird NICHT laufend bei jeder Änderung neu berechnet (keine Trigger/Hooks
 * auf Zahlungen oder Statuswechsel), sondern über einen expliziten Knopf im
 * Admin-Bereich (admin/vera.php) - passend zum Anwendungsfall "Stichtag z. B.
 * der Tag vor der Versammlung": der Vorstand stößt das kurz vor dem Termin
 * gezielt an, statt dass es im Hintergrund laufend mitläuft.
 */

const VERA_STATUS_WERTE = [
    'Gast', 'Aufnahme beantragt', 'Vorläufig aufgenommen',
    'Teilnahmeberechtigt (ohne Stimmrecht)', 'Stimmberechtigt', 'Gesperrt', 'Ausgetreten',
];

function vera_stichtag(): string
{
    $v = trim(md_setting('vera.stichtag', ''));
    return $v !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : date('Y-m-d');
}

/** null = keine Altersgrenze (alle aktiven, beitragstreuen Mitglieder sind stimmberechtigt). */
function vera_mindestalter(): ?int
{
    $v = trim(md_setting('vera.mindestalter', ''));
    return $v === '' || !ctype_digit($v) ? null : (int)$v;
}

/** Manuelle Übersteuerungen: member_id => einer der VERA_STATUS_WERTE. */
function vera_overrides(): array
{
    return array_column(
        md_q("SELECT member_id, vera_override FROM md_profiles WHERE vera_override IS NOT NULL AND vera_override <> ''"),
        'vera_override', 'member_id'
    );
}

/** Alter in vollen Jahren am Stichtag, oder null ohne Geburtsdatum. */
function vera_alter_am_stichtag(?string $birthdate, string $stichtag): ?int
{
    if (!$birthdate) return null;
    $b = date_create($birthdate);
    $s = date_create($stichtag);
    if (!$b || !$s || $b > $s) return null;
    return $b->diff($s)->y;
}

/** Hat dieses Mitglied am Stichtag eine offene (nicht bezahlte), bereits fällige Beitragsforderung? */
function vera_beitrag_ueberfaellig(int $memberId, string $stichtag): bool
{
    $r = md_one(
        "SELECT 1 FROM md_ledger WHERE member_id = ? AND kind = 'beitrag' AND direction = 'to_club'
         AND status = 'open' AND COALESCE(due_date, entry_date) <= ? LIMIT 1",
        [$memberId, $stichtag]
    );
    return (bool)$r;
}

/**
 * Berechnet den VERA-Status eines Mitglieds (reine Funktion, speichert nichts) -
 * siehe Dateikopf für die Herleitung. $profile darf fehlende Felder (status,
 * birthdate) als null/fehlend enthalten - fehlt das Profil ganz, gilt wie
 * überall in MISS der Default 'aktiv' (siehe MD_STATUS-Verwendung in index.php
 * u. a.).
 */
function vera_status_berechnen(int $memberId, array $profile): string
{
    $mdStatus = $profile['status'] ?? 'aktiv';
    if ($mdStatus === 'ausgetreten') return 'Ausgetreten';
    if ($mdStatus === 'interessent') return 'Aufnahme beantragt';
    if ($mdStatus === 'ruhend') return 'Gesperrt';

    $stichtag = vera_stichtag();
    $mindestalter = vera_mindestalter();
    $alter = vera_alter_am_stichtag($profile['birthdate'] ?? null, $stichtag);
    if ($mindestalter !== null && $alter !== null && $alter < $mindestalter) {
        return 'Teilnahmeberechtigt (ohne Stimmrecht)';
    }
    if ($mdStatus === 'ehrenmitglied') return 'Stimmberechtigt'; // beitragsfrei, siehe Dateikopf

    return vera_beitrag_ueberfaellig($memberId, $stichtag) ? 'Gesperrt' : 'Stimmberechtigt';
}

/**
 * Berechnet und speichert svmembers.status für alle Mitglieder ohne manuelle
 * Übersteuerung (siehe vera_overrides()) - mit Änderungsprotokoll je
 * geändertem Mitglied. $by: wer hat ausgelöst (null bei einem Demo-Aufbau
 * ohne Anmeldung). Gibt die Anzahl tatsächlich geänderter Mitglieder zurück.
 */
function vera_status_alle_berechnen(?int $by, string $via = 'admin'): int
{
    $overrides = vera_overrides();
    $rows = md_q('SELECT s.member_id, s.status AS aktueller_vera_status, p.status, p.birthdate
                  FROM svmembers s LEFT JOIN md_profiles p ON p.member_id = s.member_id');
    $n = 0;
    foreach ($rows as $r) {
        $mid = (int)$r['member_id'];
        if (isset($overrides[$mid])) continue;
        $neu = vera_status_berechnen($mid, $r);
        $alt = $r['aktueller_vera_status'];
        if ($alt !== $neu) {
            md_exec('UPDATE svmembers SET status = ? WHERE member_id = ?', [$neu, $mid]);
            md_log_change($mid, 'vera_status', $alt, $neu, $by, $via);
            $n++;
        }
    }
    return $n;
}

/** Setzt oder löscht die manuelle Übersteuerung eines einzelnen Mitglieds und schreibt svmembers.status sofort. */
function vera_override_setzen(int $memberId, ?string $wert, int $by): void
{
    if ($wert !== null && !in_array($wert, VERA_STATUS_WERTE, true)) {
        throw new InvalidArgumentException('Ungültiger VERA-Status.');
    }
    $alt = md_one('SELECT vera_override FROM md_profiles WHERE member_id = ?', [$memberId])['vera_override'] ?? null;
    md_exec('UPDATE md_profiles SET vera_override = ? WHERE member_id = ?', [$wert, $memberId]);
    md_log_change($memberId, 'vera_override', $alt ?: null, $wert, $by, 'admin');
    if ($wert !== null) {
        $altStatus = md_one('SELECT status FROM svmembers WHERE member_id = ?', [$memberId])['status'] ?? null;
        md_exec('UPDATE svmembers SET status = ? WHERE member_id = ?', [$wert, $memberId]);
        md_log_change($memberId, 'vera_status', $altStatus, $wert, $by, 'admin');
    } else {
        // Übersteuerung aufgehoben: sofort automatisch neu berechnen, statt bis
        // zum nächsten "Jetzt berechnen" auf einem veralteten Wert sitzen zu bleiben.
        $p = md_one('SELECT status, birthdate FROM md_profiles WHERE member_id = ?', [$memberId]) ?: [];
        $neu = vera_status_berechnen($memberId, $p);
        $altStatus = md_one('SELECT status FROM svmembers WHERE member_id = ?', [$memberId])['status'] ?? null;
        if ($altStatus !== $neu) {
            md_exec('UPDATE svmembers SET status = ? WHERE member_id = ?', [$neu, $memberId]);
            md_log_change($memberId, 'vera_status', $altStatus, $neu, $by, 'admin');
        }
    }
}
