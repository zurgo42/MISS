<?php
declare(strict_types=1);

/** Zielfelder des CSV-Imports: key => [Anzeigename, Synonyme für die automatische Zuordnung]. */
const MD_IMPORT_TARGETS = [
    'membership_number' => ['Mitgliedsnummer', ['mitgliedsnummer', 'mitgliedsnr', 'mitglnr', 'mitglnr', 'nr', 'nummer', 'member id', 'id']],
    'first_name' => ['Vorname', ['vorname', 'first name', 'firstname']],
    'last_name'  => ['Nachname', ['nachname', 'name', 'familienname', 'last name', 'lastname']],
    'email'      => ['E-Mail (Anmeldung)', ['email', 'e-mail', 'mail', 'e-mail-adresse', 'emailadresse']],
    'salutation' => ['Anrede', ['anrede']],
    'title'      => ['Titel', ['titel', 'akad. grad']],
    'birthdate'  => ['Geburtsdatum', ['geburtsdatum', 'geburtstag', 'geb.', 'geb', 'gebdatum', 'geburtsdat', 'birthdate']],
    'join_date'  => ['Eintritt', ['eintritt', 'eintrittsdatum', 'mitglied seit', 'beitritt']],
    'leave_date' => ['Austritt', ['austritt', 'austrittsdatum']],
    'status'     => ['Status', ['status', 'mitgliedsstatus']],
    'street'     => ['Straße (Adresse 1)', ['strasse', 'straße', 'anschrift', 'street', 'str.']],
    'zip'        => ['PLZ (Adresse 1)', ['plz', 'postleitzahl', 'zip']],
    'city'       => ['Ort (Adresse 1)', ['ort', 'wohnort', 'stadt', 'city']],
    'country'    => ['Land (Adresse 1)', ['land', 'country']],
    'phone'      => ['Telefon', ['telefon', 'tel', 'tel.', 'festnetz', 'phone']],
    'mobile'     => ['Mobil', ['mobil', 'handy', 'mobiltelefon', 'mobile']],
    'occupation' => ['Beruf', ['beruf', 'berufsbezeichnung', 'occupation']],
    'employer'   => ['Arbeitgeber', ['arbeitgeber', 'firma', 'employer']],
    'groups'     => ['Teilgruppen (mehrere mit ; getrennt)', ['gruppe', 'gruppen', 'teilgruppe', 'abteilung']],
    'functions'  => ['Funktionen (Codes, mit ; getrennt)', ['funktion', 'funktionen', 'amt', 'ämter']],
];

/** Liest eine CSV (UTF-8) in [Kopfzeile, Zeilen]. Trennzeichen ';' ',' Tab oder '|'. */
function md_import_read(string $file, string $delim): array
{
    $fh = fopen($file, 'r');
    $rows = [];
    while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        if ($r === [null] || !array_filter($r, fn($c) => trim((string)$c) !== '')) continue;
        $rows[] = array_map(fn($c) => trim((string)$c), $r);
    }
    fclose($fh);
    $headers = array_shift($rows) ?? [];
    return [$headers, $rows];
}

function md_import_detect_delimiter(string $file): string
{
    $line = (string)strtok((string)file_get_contents($file, false, null, 0, 4096), "\n");
    $best = ';';
    $max = -1;
    foreach ([';', ',', "\t", '|'] as $d) {
        $c = substr_count($line, $d);
        if ($c > $max) { $max = $c; $best = $d; }
    }
    return $best;
}

/** Wandelt die hochgeladene Datei nach UTF-8 (ohne BOM) und legt sie unter storage/import ab. */
function md_import_store(string $tmp, string $encoding): string
{
    $raw = (string)file_get_contents($tmp);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    if ($encoding !== 'UTF-8') $raw = (string)iconv($encoding, 'UTF-8//TRANSLIT', $raw);
    elseif (!mb_check_encoding($raw, 'UTF-8')) $raw = (string)iconv('Windows-1252', 'UTF-8//TRANSLIT', $raw);
    $dir = MD_STORAGE_DIR . '/import';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $token = bin2hex(random_bytes(8));
    file_put_contents("$dir/$token.csv", $raw);
    return $token;
}

function md_import_path(string $token): ?string
{
    if (!preg_match('/^[a-f0-9]{16}$/', $token)) return null;
    $f = MD_STORAGE_DIR . "/import/$token.csv";
    return is_file($f) ? $f : null;
}

function md_import_guess_mapping(array $headers): array
{
    // Vergleich ohne Groß-/Kleinschreibung, Punkte, Bindestriche und Leerzeichen ("Mitgl.-Nr." = "mitglnr")
    $norm = fn(string $v) => preg_replace('/[^a-z0-9äöüß]/u', '', mb_strtolower($v));
    $map = [];
    foreach (MD_IMPORT_TARGETS as $key => [, $syn]) {
        $syn = array_map($norm, array_merge($syn, [MD_IMPORT_TARGETS[$key][0]]));
        foreach ($headers as $i => $hd) {
            if (in_array($norm($hd), $syn, true) && !in_array($i, $map, true)) { $map[$key] = $i; break; }
        }
    }
    return $map;
}

function md_import_status(string $v): ?string
{
    $v = mb_strtolower(trim($v));
    foreach (MD_STATUS as $k => $l) if ($v === $k || $v === mb_strtolower($l)) return $k;
    $alias = ['mitglied' => 'aktiv', 'ordentlich' => 'aktiv', 'ausgeschieden' => 'ausgetreten', 'gekündigt' => 'ausgetreten', 'passiv' => 'ruhend', 'ehren' => 'ehrenmitglied'];
    return $alias[$v] ?? null;
}

function md_import_find_member(array $d): ?array
{
    if (($d['membership_number'] ?? '') !== '' && ($m = md_one('SELECT * FROM svmembers WHERE membership_number = ?', [$d['membership_number']]))) return $m;
    if (($d['email'] ?? '') !== '' && ($m = md_one('SELECT * FROM svmembers WHERE LOWER(email) = ?', [mb_strtolower($d['email'])]))) return $m;
    if (($d['birthdate'] ?? '') !== '' && ($d['first_name'] ?? '') !== '') {
        return md_one('SELECT s.* FROM svmembers s JOIN md_profiles p ON p.member_id = s.member_id
                       WHERE LOWER(s.first_name) = ? AND LOWER(s.last_name) = ? AND p.birthdate = ?',
            [mb_strtolower($d['first_name']), mb_strtolower($d['last_name'] ?? ''), $d['birthdate']]);
    }
    return null;
}

/**
 * Prüft alle Zeilen und liefert den Plan. opts: update_existing (bool), placeholder_email (bool).
 * Plan-Eintrag: row, action (create|update|error), data, member_id, errors[], warnings[]
 */
function md_import_plan(array $rows, array $map, array $opts): array
{
    $plan = [];
    $seenKeys = [];
    foreach ($rows as $i => $cells) {
        $d = [];
        foreach ($map as $key => $col) $d[$key] = $col === '' || $col === null ? '' : trim((string)($cells[(int)$col] ?? ''));
        $errors = [];
        $warn = [];
        foreach (['birthdate', 'join_date', 'leave_date'] as $f) {
            if (($d[$f] ?? '') === '') continue;
            $iso = md_parse_date($d[$f]);
            if ($iso === null) { $warn[] = MD_IMPORT_TARGETS[$f][0] . ' „' . $d[$f] . '“ nicht lesbar – ignoriert'; $d[$f] = ''; } else $d[$f] = $iso;
        }
        if (($d['status'] ?? '') !== '') {
            $st = md_import_status($d['status']);
            if ($st === null) { $warn[] = 'Status „' . $d['status'] . '“ unbekannt – ignoriert'; $d['status'] = ''; } else $d['status'] = $st;
        }
        if (($d['salutation'] ?? '') !== '' && !in_array($d['salutation'], MD_PROFILE_FIELDS['salutation'][4], true)) {
            $warn[] = 'Anrede „' . $d['salutation'] . '“ unbekannt – ignoriert';
            $d['salutation'] = '';
        }
        if (($d['email'] ?? '') !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'E-Mail ungültig: ' . $d['email']; }
        $existing = md_import_find_member($d);
        if (!$existing) {
            if (($d['first_name'] ?? '') === '' || ($d['last_name'] ?? '') === '') $errors[] = 'Vor- und Nachname erforderlich';
            if (($d['email'] ?? '') === '') {
                if (!empty($opts['placeholder_email'])) $d['email'] = 'ohne-mail-' . ($d['membership_number'] ?: ($i + 2)) . '@example.invalid';
                else $errors[] = 'E-Mail fehlt (für neue Mitglieder nötig)';
            }
        }
        $dupKey = $existing ? 'm' . $existing['member_id'] : (($d['membership_number'] ?? '') !== '' ? 'n' . $d['membership_number'] : 'e' . mb_strtolower($d['email'] ?? ''));
        if (isset($seenKeys[$dupKey])) $errors[] = 'Doppelt in der Datei (wie Zeile ' . $seenKeys[$dupKey] . ')';
        else $seenKeys[$dupKey] = $i + 2;
        if ($existing && empty($opts['update_existing'])) { $errors[] = 'Existiert bereits (' . $existing['first_name'] . ' ' . $existing['last_name'] . ') – Aktualisieren ist aus'; }
        if (!$existing && ($d['email'] ?? '') !== '' && md_one('SELECT 1 FROM svmembers WHERE LOWER(email) = ?', [mb_strtolower($d['email'])])) $errors[] = 'E-Mail bereits vergeben';
        $plan[] = ['row' => $i + 2, 'action' => $errors ? 'error' : ($existing ? 'update' : 'create'), 'data' => $d,
                   'member_id' => $existing ? (int)$existing['member_id'] : null, 'errors' => $errors, 'warnings' => $warn];
    }
    return $plan;
}

function md_import_next_number(): string
{
    $max = 0;
    foreach (md_q('SELECT membership_number FROM svmembers') as $r) if (ctype_digit($r['membership_number'])) $max = max($max, (int)$r['membership_number']);
    return (string)($max + 1);
}

/** Schreibt den Plan in die Datenbank (in einer Transaktion). Gibt [angelegt, aktualisiert, übersprungen] zurück. */
function md_import_apply(array $plan, int $actor): array
{
    $pdo = md_db();
    $pdo->beginTransaction();
    $created = $updated = $skipped = 0;
    try {
        foreach ($plan as $e) {
            if ($e['action'] === 'error') { $skipped++; continue; }
            $d = $e['data'];
            $id = $e['member_id'];
            if ($e['action'] === 'create') {
                $nr = ($d['membership_number'] ?? '') !== '' ? $d['membership_number'] : md_import_next_number();
                md_exec('INSERT INTO svmembers (membership_number, first_name, last_name, email) VALUES (?,?,?,?)', [$nr, $d['first_name'], $d['last_name'], $d['email']]);
                $id = (int)$pdo->lastInsertId();
                md_log_change($id, 'created', null, 'Mitglied per Import angelegt', $actor, 'import');
                $created++;
            } else {
                $upd = array_filter(array_intersect_key($d, array_flip(['first_name', 'last_name'])), fn($v) => $v !== '');
                foreach ($upd as $f => $v) md_exec("UPDATE svmembers SET $f = ? WHERE member_id = ?", [$v, $id]);
                $updated++;
            }
            md_ensure_profile($id);
            md_save_profile($id, array_filter($d, fn($v) => $v !== ''), $actor, 'import', ['salutation', 'title', 'birthdate', 'occupation', 'employer']);
            md_save_club_data($id, array_filter(array_intersect_key($d, array_flip(['status', 'join_date', 'leave_date'])), fn($v) => $v !== ''), $actor, 'import');
            // Adresse 1: vorhandene Werte behalten, nur gefüllte Zellen überschreiben
            $a = md_load_addresses($id)[1] ?? [];
            $in = [];
            foreach (['street', 'zip', 'city', 'country'] as $f) $in["address_1_$f"] = ($d[$f] ?? '') !== '' ? $d[$f] : ($a[$f] ?? '');
            $in['address_1_label'] = $a['label'] ?? 'Privat';
            if (array_filter(array_intersect_key($d, array_flip(['street', 'zip', 'city', 'country'])))) md_save_addresses($id, $in, $actor, 'import', [1]);
            $cin = [];
            foreach (['phone', 'mobile'] as $k) if (($d[$k] ?? '') !== '') $cin["contact_$k"] = $d[$k];
            if ($cin) md_save_contacts($id, $cin, $actor, 'import', array_map(fn($k) => substr($k, 8), array_keys($cin)));
            foreach (preg_split('/\s*;\s*/', (string)($d['groups'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $gname) {
                $g = md_one('SELECT * FROM md_groups WHERE LOWER(name) = ?', [mb_strtolower($gname)]);
                if (!$g) { md_exec('INSERT INTO md_groups (name) VALUES (?)', [$gname]); $g = md_one('SELECT * FROM md_groups WHERE name = ?', [$gname]); }
                if (!md_one('SELECT 1 FROM md_member_groups WHERE member_id = ? AND group_id = ?', [$id, $g['group_id']])) {
                    md_exec('INSERT INTO md_member_groups (member_id, group_id, since) VALUES (?,?,?)', [$id, $g['group_id'], date('Y-m-d')]);
                    md_log_change($id, 'group', null, $g['name'], $actor, 'import');
                }
            }
            foreach (preg_split('/\s*;\s*/', (string)($d['functions'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $code) {
                $f = md_one('SELECT * FROM md_functions WHERE LOWER(code) = ?', [mb_strtolower($code)]);
                if ($f && !md_one('SELECT 1 FROM md_member_functions WHERE member_id = ? AND function_id = ? AND valid_to IS NULL', [$id, $f['function_id']])) {
                    md_exec('INSERT INTO md_member_functions (member_id, function_id, valid_from) VALUES (?,?,?)', [$id, $f['function_id'], date('Y-m-d')]);
                    md_log_change($id, 'function', null, $f['code'] . ' ' . $f['name'], $actor, 'import');
                }
            }
            md_sync_member_rights($id, $actor, 'import');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$created, $updated, $skipped];
}
