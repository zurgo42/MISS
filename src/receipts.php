<?php
declare(strict_types=1);

/**
 * Zuwendungsbestätigungen (Spendenquittungen) für Geldspenden.
 *
 *  - Einzelbestätigung für eine Spende oder Sammelbestätigung für alle bezahlten, noch nicht bescheinigten Spenden eines Zeitraums
 *    (Jahresbescheinigung, Standard: Vorjahr).
 *  - Jede Bestätigung hat eine Nummer, hält Verein, Spender und Einzelzuwendungen zum Zeitpunkt der Ausstellung fest und wird nie gelöscht.
 *    Falsche Bestätigungen werden mit Grund für ungültig erklärt; die Spenden werden dadurch wieder frei für eine neue Bestätigung.
 *  - Nicht unterstützt: Aufwandsspenden (Verzicht auf Erstattung), Sachspenden, Mitgliedsbeiträge.
 *  - Wortlaut nach dem amtlichen Muster des BMF; bitte einmalig vom Steuerberater gegen das aktuelle Muster prüfen lassen.
 */

/** Angaben des Vereins für die Bestätigung: Schlüssel => [Bezeichnung, Pflicht, Hinweis]. Gespeichert in md_settings als club.<Schlüssel>. */
const MD_CLUB_FIELDS = [
    'name'             => ['Name des Vereins', true, ''],
    'street'           => ['Straße, Nr.', true, ''],
    'zip'              => ['PLZ', true, ''],
    'city'             => ['Ort', true, ''],
    'tax_office'       => ['Finanzamt', true, 'z. B. Finanzamt Musterstadt'],
    'tax_number'       => ['Steuernummer', true, ''],
    'exemption_kind'   => ['Art des Bescheids', true, 'freistellung = Freistellungsbescheid/Anlage zum Körperschaftsteuerbescheid; feststellung = Feststellung nach § 60a AO'],
    'exemption_date'   => ['Datum des Bescheids', true, 'TT.MM.JJJJ'],
    'exemption_period' => ['Veranlagungszeitraum (nur bei Freistellung)', false, 'z. B. 2022 bis 2024'],
    'purposes'         => ['Begünstigte Zwecke', true, 'beginnend mit „Förderung …“, z. B. Förderung der Bildung und Erziehung'],
    'place'            => ['Ort für die Unterschrift', true, ''],
    'signer'           => ['Unterzeichner (Name, Funktion)', true, 'z. B. Max Mustermann, Finanzvorstand'],
];

function md_club(): array
{
    $c = [];
    foreach (array_keys(MD_CLUB_FIELDS) as $k) $c[$k] = md_setting('club.' . $k, '');
    if ($c['name'] === '' && defined('MD_CLUB_NAME')) $c['name'] = MD_CLUB_NAME;
    if ($c['exemption_kind'] === '') $c['exemption_kind'] = 'feststellung';
    return $c;
}

/** Fehlende oder abgelaufene Vereinsangaben. Leer = Bestätigungen dürfen ausgestellt werden. */
function md_club_problems(array $club, ?string $on = null): array
{
    $p = [];
    foreach (MD_CLUB_FIELDS as $k => [$label, $req]) {
        if ($req && trim((string)($club[$k] ?? '')) === '') $p[] = "$label fehlt";
    }
    if (!in_array($club['exemption_kind'] ?? '', ['freistellung', 'feststellung'], true)) $p[] = 'Art des Bescheids ungültig';
    $date = md_parse_date($club['exemption_date'] ?? '');
    if (($club['exemption_date'] ?? '') !== '' && !$date) $p[] = 'Datum des Bescheids ungültig';
    // Der Bescheid darf bei Ausstellung nicht zu alt sein: Freistellung 5 Jahre, Feststellung 3 Jahre (bitte vom Steuerberater bestätigen lassen)
    if ($date) {
        $years = ($club['exemption_kind'] ?? '') === 'freistellung' ? 5 : 3;
        if (strtotime("+$years years", strtotime($date)) < strtotime($on ?? date('Y-m-d'))) $p[] = "Der Bescheid vom " . md_date($date) . " ist älter als $years Jahre – ohne aktuellen Bescheid dürfen keine Bestätigungen ausgestellt werden";
    }
    if (trim((string)($club['purposes'] ?? '')) !== '' && stripos(trim((string)$club['purposes']), 'Förderung') !== 0) $p[] = 'Begünstigte Zwecke bitte in der Form „Förderung …“ angeben (z. B. Förderung der Bildung und Erziehung)';
    if (($club['exemption_kind'] ?? '') === 'freistellung' && trim((string)($club['exemption_period'] ?? '')) === '') $p[] = 'Veranlagungszeitraum fehlt (bei Freistellungsbescheid)';
    return $p;
}

/** Anschrift des Mitglieds (Adresse 1, sonst die erste vorhandene mit Straße, PLZ, Ort). Null, wenn keine vollständige Anschrift da ist. */
function md_donor(int $member): ?array
{
    $m = md_one('SELECT first_name, last_name FROM svmembers WHERE member_id = ?', [$member]);
    $p = md_load_profile($member);
    if (!$m) return null;
    foreach (md_load_addresses($member) as $a) {
        if (trim((string)$a['street']) !== '' && trim((string)$a['zip']) !== '' && trim((string)$a['city']) !== '') {
            $name = trim(trim((string)($p['title'] ?? '') . ' ' . $m['first_name'] . ' ' . $m['last_name']));
            return ['name' => $name, 'lines' => array_values(array_filter([$a['street'], trim($a['zip'] . ' ' . $a['city']), ($a['country'] && strcasecmp($a['country'], 'Deutschland') !== 0) ? $a['country'] : null]))];
        }
    }
    return null;
}

/** Bezahlte, nicht stornierte, noch nicht bescheinigte Geldspenden im Zeitraum (Tag der Zuwendung = Zahltag). */
function md_receipt_open_entries(int $member, string $from, string $to): array
{
    return md_q("SELECT * FROM md_ledger WHERE member_id = ? AND kind = 'spende' AND status = 'paid' AND receipt_id IS NULL AND paid_date >= ? AND paid_date <= ?
                 ORDER BY paid_date, entry_id", [$member, $from, $to]);
}

function md_receipt_event(int $receiptId, string $event, ?int $by, ?string $note = null): void
{
    md_exec('INSERT INTO md_receipt_events (receipt_id, event, event_at, event_by, note) VALUES (?,?,?,?,?)', [$receiptId, $event, md_now(), $by, $note]);
}

function md_receipt_describe(array $r): string
{
    return 'Zuwendungsbestätigung ' . $r['receipt_no'] . ' (' . md_money((int)$r['total_cents']) . ')';
}

/**
 * Prüft Vereinsangaben, Anschrift und Spenden und stellt die Angaben der Bestätigung zusammen (ohne etwas zu speichern).
 * Gibt [Angaben|null, Fehlertext] zurück; Angaben: entries, from, to, total, snapshot (JSON), receipt (Zeile für die Anzeige, ohne Nummer).
 */
function md_receipt_prepare(int $member, string $kind, array $range): array
{
    $club = md_club();
    if ($problems = md_club_problems($club)) return [null, 'Vereinsangaben unvollständig: ' . implode('; ', $problems) . '. (Einstellungen → Verein)'];
    $donor = md_donor($member);
    if (!$donor) return [null, 'Für dieses Mitglied fehlt eine vollständige Anschrift (Straße, PLZ, Ort).'];
    if ($kind === 'single') {
        $e = md_one('SELECT * FROM md_ledger WHERE entry_id = ? AND member_id = ?', [(int)($range['entry_id'] ?? 0), $member]);
        if (!$e || $e['kind'] !== 'spende' || $e['status'] !== 'paid') return [null, 'Nur bezahlte Spenden können bescheinigt werden.'];
        if ($e['receipt_id']) return [null, 'Für diese Spende gibt es schon eine Bestätigung.'];
        $entries = [$e];
        $from = $to = $e['paid_date'];
    } else {
        $from = md_parse_date($range['from'] ?? '');
        $to = md_parse_date($range['to'] ?? '');
        if (!$from || !$to || $from > $to) return [null, 'Zeitraum ungültig.'];
        $entries = md_receipt_open_entries($member, $from, $to);
        if (!$entries) return [null, 'Keine bezahlten, noch nicht bescheinigten Spenden im Zeitraum.'];
    }
    $total = array_sum(array_map(fn($x) => (int)$x['amount_cents'], $entries));
    $snapshot = json_encode(['club' => $club, 'donor' => $donor,
        'entries' => array_map(fn($x) => ['entry_id' => (int)$x['entry_id'], 'date' => $x['paid_date'], 'cents' => (int)$x['amount_cents'], 'text' => $x['description'], 'reference' => $x['reference']], $entries)], JSON_UNESCAPED_UNICODE);
    return [['entries' => $entries, 'from' => $from, 'to' => $to, 'total' => $total, 'snapshot' => $snapshot,
             'receipt' => ['receipt_no' => 'ENTWURF', 'kind' => $kind, 'period_from' => $from, 'period_to' => $to, 'total_cents' => $total, 'issued_at' => md_now(), 'snapshot' => $snapshot]], ''];
}

/**
 * Stellt eine Bestätigung aus: $kind 'single' (genau eine Spende) oder 'annual' (alle freien Spenden im Zeitraum).
 * Gibt [receipt_id|null, Fehlertext] zurück.
 */
function md_receipt_issue(int $member, string $kind, array $range, int $actor, ?int $replaces = null): array
{
    [$prep, $err] = md_receipt_prepare($member, $kind, $range);
    if (!$prep) return [null, $err];
    $entries = $prep['entries'];
    $from = $prep['from'];
    $to = $prep['to'];
    $total = $prep['total'];
    $snapshot = $prep['snapshot'];
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $year = date('Y');
        $last = md_one("SELECT receipt_no FROM md_receipts WHERE receipt_no LIKE ? ORDER BY receipt_no DESC LIMIT 1", ["$year-%"]);
        $seq = $last ? (int)substr($last['receipt_no'], 5) + 1 : 1;
        $no = sprintf('%s-%04d', $year, $seq);
        md_exec('INSERT INTO md_receipts (receipt_no, member_id, kind, period_from, period_to, total_cents, status, issued_at, issued_by, replaces_id, snapshot) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$no, $member, $kind, $from, $to, $total, 'issued', md_now(), $actor, $replaces, $snapshot]);
        $rid = (int)$pdo->lastInsertId();
        $ids = array_map(fn($x) => (int)$x['entry_id'], $entries);
        $n = md_exec('UPDATE md_ledger SET receipt_id = ? WHERE receipt_id IS NULL AND entry_id IN (' . implode(',', $ids) . ')', [$rid]);
        if ($n !== count($ids)) throw new RuntimeException('Eine Spende wurde inzwischen schon bescheinigt. Bitte neu laden.');
        md_receipt_event($rid, 'issued', $actor, $replaces ? 'Ersatz für Nr. ' . (md_one('SELECT receipt_no FROM md_receipts WHERE receipt_id = ?', [$replaces])['receipt_no'] ?? '?') : null);
        md_log_change($member, 'receipt', null, md_receipt_describe(['receipt_no' => $no, 'total_cents' => $total]) . ($kind === 'annual' ? ' – Sammelbestätigung ' . md_date($from) . '–' . md_date($to) : ' – Einzelbestätigung'), $actor, 'admin');
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return [null, $e->getMessage()];
    }
    return [$rid, ''];
}

/** Erklärt eine Bestätigung für ungültig; die Spenden werden frei. Eine neue Bestätigung ersetzt sie dann. */
function md_receipt_void(int $receiptId, string $reason, int $actor): string
{
    $r = md_one('SELECT * FROM md_receipts WHERE receipt_id = ?', [$receiptId]);
    if (!$r || $r['status'] !== 'issued') return 'Bestätigung nicht gefunden oder schon ungültig.';
    $reason = trim($reason);
    if ($reason === '') return 'Bitte einen Grund angeben.';
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        md_exec("UPDATE md_receipts SET status = 'void', void_reason = ?, voided_at = ?, voided_by = ? WHERE receipt_id = ?", [mb_substr($reason, 0, 255), md_now(), $actor, $receiptId]);
        md_exec('UPDATE md_ledger SET receipt_id = NULL WHERE receipt_id = ?', [$receiptId]);
        md_receipt_event($receiptId, 'voided', $actor, $reason);
        md_log_change((int)$r['member_id'], 'receipt', md_receipt_describe($r), 'für ungültig erklärt: ' . $reason, $actor, 'admin');
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return 'Fehler: ' . $e->getMessage();
    }
    return '';
}

/** Betrag in Buchstaben: 12050 -> "einhundertzwanzig Euro und fünfzig Cent". */
function md_amount_in_words(int $cents): string
{
    $euro = intdiv($cents, 100);
    $c = $cents % 100;
    $out = md_number_words($euro) . ' Euro';
    if ($c > 0) $out .= ' und ' . md_number_words($c) . ' Cent';
    return $out;
}

function md_number_words(int $n): string
{
    static $u = ['null', 'ein', 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht', 'neun', 'zehn', 'elf', 'zwölf', 'dreizehn', 'vierzehn', 'fünfzehn', 'sechzehn', 'siebzehn', 'achtzehn', 'neunzehn'];
    static $t = [2 => 'zwanzig', 3 => 'dreißig', 4 => 'vierzig', 5 => 'fünfzig', 6 => 'sechzig', 7 => 'siebzig', 8 => 'achtzig', 9 => 'neunzig'];
    if ($n < 20) return $u[$n];
    if ($n < 100) return ($n % 10 ? $u[$n % 10] . 'und' : '') . $t[intdiv($n, 10)];
    if ($n < 1000) return $u[intdiv($n, 100)] . 'hundert' . ($n % 100 ? md_number_words($n % 100) : '');
    if ($n < 1000000) return md_number_words(intdiv($n, 1000)) . 'tausend' . ($n % 1000 ? md_number_words($n % 1000) : '');
    $m = intdiv($n, 1000000);
    return ($m === 1 ? 'eine Million' : md_number_words($m) . ' Millionen') . ($n % 1000000 ? ' ' . md_number_words($n % 1000000) : '');
}

/** HTML der Bestätigung aus der gespeicherten Momentaufnahme. $mark: '' (Original), 'DUPLIKAT', 'ENTWURF', 'UNGÜLTIG'. */
function md_receipt_html(array $r, string $mark = ''): string
{
    $s = json_decode($r['snapshot'], true);
    $club = $s['club'];
    $donor = $s['donor'];
    $annual = $r['kind'] === 'annual';
    $purposes = h($club['purposes']);
    $faName = preg_replace('/^Finanzamt\s+/iu', '', trim($club['tax_office']));   // "Finanzamt X" oder nur "X"
    $fa = h('Finanzamt ' . $faName);
    $faGen = h('Finanzamtes ' . $faName);
    $stnr = h($club['tax_number']);
    $date = h(md_date(md_parse_date($club['exemption_date'])));
    $o = '<article class="zb">';
    if ($mark !== '') $o .= '<div class="mark">' . h($mark) . '</div>';
    $o .= '<p class="issuer"><strong>' . h($club['name']) . '</strong><br>' . h($club['street']) . ', ' . h($club['zip'] . ' ' . $club['city']) . '<br><span class="small">Name und Anschrift der steuerbegünstigten Einrichtung</span></p>';
    $o .= '<h2>' . ($annual ? 'Sammelbestätigung über Geldzuwendungen' : 'Bestätigung über Geldzuwendungen') . '</h2>';
    $o .= '<p class="small center">im Sinne des § 10b des Einkommensteuergesetzes an eine der in § 5 Abs. 1 Nr. 9 des Körperschaftsteuergesetzes bezeichneten Körperschaften, Personenvereinigungen oder Vermögensmassen</p>';
    $o .= '<p class="no">Bestätigungsnummer: <strong>' . h($r['receipt_no']) . '</strong></p>';
    $o .= '<table class="box"><tr><td class="lab">Name und Anschrift des Zuwendenden</td><td>' . h($donor['name']) . '<br>' . implode('<br>', array_map('h', $donor['lines'])) . '</td></tr>';
    $o .= '<tr><td class="lab">' . ($annual ? 'Gesamtsumme der Zuwendungen' : 'Betrag der Zuwendung') . '</td><td><strong>' . h(md_money((int)$r['total_cents'])) . '</strong> &nbsp;<span class="small">– in Ziffern –</span><br>'
        . h(md_amount_in_words((int)$r['total_cents'])) . ' &nbsp;<span class="small">– in Buchstaben –</span></td></tr>';
    $o .= '<tr><td class="lab">' . ($annual ? 'Zeitraum der Sammelbestätigung' : 'Tag der Zuwendung') . '</td><td>' . ($annual ? h(md_date($r['period_from'])) . ' bis ' . h(md_date($r['period_to'])) : h(md_date($r['period_from']))) . '</td></tr>';
    $o .= '<tr><td class="lab">Verzicht auf Erstattung von Aufwendungen</td><td>☐ Ja &nbsp; ☒ Nein</td></tr></table>';
    if ($club['exemption_kind'] === 'freistellung') {
        $o .= '<p>Wir sind wegen ' . $purposes . ' nach dem Freistellungsbescheid bzw. nach der Anlage zum Körperschaftsteuerbescheid des ' . $faGen . ', StNr. ' . $stnr . ', vom ' . $date
            . ' für den letzten Veranlagungszeitraum ' . h($club['exemption_period']) . ' nach § 5 Abs. 1 Nr. 9 des Körperschaftsteuergesetzes von der Körperschaftsteuer und nach § 3 Nr. 6 des Gewerbesteuergesetzes von der Gewerbesteuer befreit.</p>';
    } else {
        $o .= '<p>Die Einhaltung der satzungsmäßigen Voraussetzungen nach den §§ 51, 59, 60 und 61 AO wurde vom ' . $fa . ', StNr. ' . $stnr . ', mit Bescheid vom ' . $date
            . ' nach § 60a AO gesondert festgestellt. Wir fördern nach unserer Satzung: ' . $purposes . '.</p>';
    }
    $o .= '<p>Es wird bestätigt, dass die Zuwendung nur zur ' . $purposes . ' verwendet wird.</p>';
    if ($annual) {
        $o .= '<p class="small">Anlage: Aufstellung der einzelnen Zuwendungen</p><table class="list"><thead><tr><th>Tag der Zuwendung</th><th>Art</th><th class="r">Betrag</th></tr></thead><tbody>';
        foreach ($s['entries'] as $e) $o .= '<tr><td>' . h(md_date($e['date'])) . '</td><td>Geldzuwendung' . ($e['reference'] ? ' (' . h($e['reference']) . ')' : '') . '</td><td class="r">' . h(md_money($e['cents'])) . '</td></tr>';
        $o .= '<tr><td colspan="2"><strong>Summe</strong></td><td class="r"><strong>' . h(md_money((int)$r['total_cents'])) . '</strong></td></tr></tbody></table>';
    }
    $o .= '<p class="sign">' . h($club['place']) . ', den ' . h(md_date(substr($r['issued_at'], 0, 10))) . '<br><br><br><span class="line"></span><br><span class="small">(Ort, Datum und Unterschrift des Zuwendungsempfängers)</span><br>' . h($club['signer']) . '</p>';
    $o .= '<p class="small">Hinweis: Wer vorsätzlich oder grob fahrlässig eine unrichtige Zuwendungsbestätigung erstellt oder veranlasst, dass Zuwendungen nicht zu den in der Zuwendungsbestätigung angegebenen steuerbegünstigten Zwecken verwendet werden, haftet für die entgangene Steuer (§ 10b Abs. 4 EStG, § 9 Abs. 3 KStG, § 9 Nr. 5 GewStG).</p>';
    return $o . '</article>';
}
