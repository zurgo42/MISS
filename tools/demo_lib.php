<?php
/** Gemeinsame Hilfen für demo_setup.php (SQLite) und demo_load.php (MySQL, vorhandene Demo-Mitglieder). */

const DEMO_MD_TABLES = ['md_functions', 'md_groups', 'md_profiles', 'md_addresses', 'md_contacts', 'md_visibility', 'md_member_groups', 'md_member_functions',
                        'md_fee_groups', 'md_member_fees', 'md_ledger', 'md_terms_versions', 'md_terms_consents', 'md_changelog', 'md_privacy_requests', 'md_privacy_log', 'md_import_profiles', 'md_settings'];

function demo_insert(PDO $pdo, string $table, array $rows, array $idmap = []): void
{
    foreach ($rows as $r) {
        foreach (['member_id', 'changed_by', 'recorded_by', 'started_by'] as $k) if (isset($r[$k], $idmap[$r[$k]])) $r[$k] = $idmap[$r[$k]];
        $cols = array_keys($r);
        $pdo->prepare("INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($r));
    }
    echo str_pad($table, 22) . count($rows) . "\n";
}

/** Demo-Fotos: Zeichnungen aus assets/demo (Porträt, Gruppenbild, Urlaubsmotiv; Herkunft und Lizenz in assets/demo/LIZENZ.md). Anzahl Fotoplätze je Figur wie im Demo-Stand. */
function demo_photos(PDO $pdo, array $svmembers, array $idmap = []): void
{
    $dir = MD_STORAGE_DIR . '/photos';
    $src = dirname(__DIR__) . '/assets/demo';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    foreach (glob("$dir/*_demo.svg") ?: [] as $f) unlink($f);
    $counts = [1 => 3, 2 => 2, 3 => 3, 4 => 1, 5 => 1, 6 => 2, 7 => 3, 8 => 2, 9 => 3, 10 => 1, 11 => 0, 12 => 0];
    $kinds = [1 => 'portrait', 2 => 'treffen', 3 => 'urlaub'];
    foreach ($svmembers as $m) {
        $demoId = $m['member_id'];
        $id = $idmap[$demoId] ?? $demoId;
        for ($s = 1; $s <= $counts[$demoId]; $s++) {
            $from = "$src/{$kinds[$s]}_$demoId.svg";
            if (!is_file($from)) continue;
            $name = sprintf('%d_%d_demo.svg', $id, $s);
            copy($from, "$dir/$name");
            $pdo->prepare('INSERT INTO md_photos (member_id, slot, file_name, mime, caption) VALUES (?,?,?,?,?)')
                ->execute([$id, $s, $name, 'image/svg+xml', $s === 1 ? 'Portrait' : ($s === 2 ? 'Beim Jahrestreffen' : 'Im Urlaub')]);
        }
    }
    echo "Fotos angelegt.\n";
}

/** Stellt für die Demo eine Einzelbestätigung aus (Julias Spende), damit die Liste der Bestätigungen nicht leer ist. Braucht src/bootstrap.php. */
function demo_issue_receipts(array $idmap = []): void
{
    $julia = $idmap[4] ?? 4;
    $max = $idmap[1] ?? 1;
    $e = md_one("SELECT entry_id FROM md_ledger WHERE member_id = ? AND kind = 'spende' AND status = 'paid' AND receipt_id IS NULL ORDER BY entry_id LIMIT 1", [$julia]);
    if (!$e) return;
    [$rid, $err] = md_receipt_issue($julia, 'single', ['entry_id' => (int)$e['entry_id']], $max);
    echo $rid ? "Demo-Spendenquittung ausgestellt.\n" : "Demo-Spendenquittung nicht ausgestellt: $err\n";
}

/** Demo: Verschlüsselungsschlüssel anlegen, falls in config.php keiner steht (nur Demo, der Schlüssel schützt hier nichts Echtes). */
function demo_ensure_key(string $root): void
{
    if (defined('MD_ENCRYPTION_KEY') && preg_match('/^[0-9a-fA-F]{64}$/', (string)MD_ENCRYPTION_KEY)) return;
    $cfg = file_get_contents("$root/config.php");
    $key = bin2hex(random_bytes(32));
    $cfg = preg_replace("/^\\s*define\\('MD_ENCRYPTION_KEY'.*$/m", '', $cfg);
    $cfg = rtrim($cfg) . "\n\ndefine('MD_ENCRYPTION_KEY', '$key');   // Demo-Schlüssel\n";
    file_put_contents("$root/config.php", $cfg);
    if (defined('MD_ENCRYPTION_KEY')) {   // leer definiert: neu starten, damit die Konstante den Schlüssel trägt
        echo "Demo-Schlüssel in config.php eingetragen, starte neu …\n";
        passthru(escapeshellarg(PHP_BINARY) . ' ' . implode(' ', array_map('escapeshellarg', $GLOBALS['argv'])), $rc);
        exit($rc);
    }
    define('MD_ENCRYPTION_KEY', $key);
}

/** Demo-Lastschriftmandate (gültige Beispiel-IBANs). Ergebnis: Anzahl. */
function demo_seed_sepa(array $idmap = []): int
{
    $id = fn(int $n) => $idmap[$n] ?? $n;
    $actor = $id(1);
    $set = [   // Nr, IBAN, unterschrieben vor Monaten, Status, Grund
        [2, 'DE89370400440532013000', 20, 'active', null],
        [3, 'DE02500105170137075030', 14, 'active', null],
        [11, 'DE91100000000123456789', 2, 'active', null],
        [7, 'DE02120300000000202051', 30, 'revoked', 'Widerruf des Mitglieds'],
        [8, 'DE89370400440532013000', 12, 'suspended', 'Rücklastschrift AC04'],
        [6, 'DE02500105170137075030', 48, 'active', null],   // abgelaufen (36-Monats-Regel)
    ];
    $n = 0;
    foreach ($set as [$nr, $iban, $months, $status, $why]) {
        $m = $id($nr);
        if (md_one('SELECT 1 FROM md_sepa_mandates WHERE member_id = ?', [$m])) continue;
        $row = md_one('SELECT first_name, last_name FROM svmembers WHERE member_id = ?', [$m]);
        [$mid, $err] = md_sepa_mandate_add($m, ['iban' => $iban, 'signed_on' => date('d.m.Y', strtotime("-$months months")), 'debtor_name' => $row['first_name'] . ' ' . $row['last_name'], 'document_note' => 'Demo: Papiermandat im Ordner'], $actor);
        if (!$mid) { echo "Demo-Mandat $nr nicht angelegt: $err\n"; continue; }
        if ($status !== 'active') md_sepa_mandate_set_status((int)$mid, $status, $why, $actor);
        $n++;
    }
    echo "Demo-Lastschriftmandate: $n\n";
    return $n;
}

/** Demo: zwei Mitglieder wünschen Rechnungen und Mahnungen per E-Mail. */
function demo_seed_mail(array $idmap = []): void
{
    foreach ([3, 11] as $n) {
        $m = $idmap[$n] ?? $n;
        if (md_one('SELECT 1 FROM md_profiles WHERE member_id = ?', [$m])) md_exec("UPDATE md_profiles SET letter_channel = 'mail' WHERE member_id = ?", [$m]);
    }
    if (!md_one("SELECT 1 FROM md_settings WHERE setting_key = 'mail.from_email'")) md_exec("INSERT INTO md_settings (setting_key, setting_value, description) VALUES ('mail.from_email', 'mitglieder@musterverein.example', 'Demo-Absender')");
}

/** Demo-Veranstaltungen: ausgebucht mit Warteliste, kostenpflichtiger Workshop, vergangene Veranstaltung. */
function demo_seed_events(array $idmap = []): void
{
    if (md_one('SELECT 1 FROM md_events')) return;
    $id = fn(int $n) => $idmap[$n] ?? $n;
    $d = fn(int $days, string $t) => date('Y-m-d', strtotime("+$days days")) . 'T' . $t;
    [$sf] = md_event_save(null, ['title' => 'Sommerfest im Vereinsgarten', 'description' => "Grillen, Musik und Spiele für alle.\nBitte etwas für das Buffet mitbringen.", 'location' => 'Vereinsgarten, Vereinsstraße 1', 'starts_at' => $d(30, '15:00'), 'ends_at' => $d(30, '21:00'), 'reg_deadline' => $d(25, '18:00'), 'capacity' => '3'], $id(2));
    md_event_register($sf, $id(1), true, null, $id(1));
    md_event_register($sf, $id(4), false, 'Vegetarisch, bitte', $id(4));
    md_event_register($sf, $id(5), true, null, $id(5));
    md_event_register($sf, $id(3), false, null, $id(3));   // Warteliste
    [$ws] = md_event_save(null, ['title' => 'Workshop Kalligrafie', 'description' => 'Einführung in die Federschrift, Material wird gestellt.', 'location' => 'Vereinsheim', 'starts_at' => $d(14, '10:00'), 'capacity' => '10', 'price' => '15,00'], $id(1));
    md_event_register($ws, $id(2), false, null, $id(2));
    md_event_register($ws, $id(9), true, null, $id(9));
    $past = date('Y-m-d H:i:s', strtotime('-60 days 18:00'));
    md_exec("INSERT INTO md_events (organizer_id, title, description, location, starts_at, capacity, price_cents, status, created_at) VALUES (?,?,?,?,?,?,?,?,?)", [$id(1), 'Neujahrsempfang', 'Rückblick und Ausblick.', 'Vereinsheim', $past, 40, 0, 'open', date('Y-m-d H:i:s', strtotime('-90 days'))]);
    echo "Demo-Veranstaltungen: 3\n";
}

/** Demo: Verteiler (aus Teilgruppen und Funktionen) und zwei Online-Anträge. Die Testuser bleiben unverändert. */
function demo_seed_lists_apply(array $idmap = []): void
{
    $id = fn(int $n) => $idmap[$n] ?? $n;
    if (!md_one('SELECT 1 FROM md_mail_lists')) {
        $defs = [['Alle Mitglieder', 'Vereinsnachrichten an alle', ['source_kind' => 'all', 'optional' => 1]], ['Vorstand', 'Interner Verteiler der Vorstände', ['source_kind' => 'function', 'function_code' => 'Vo', 'optional' => 0]]];
        foreach (md_q('SELECT group_id, name FROM md_groups ORDER BY group_id LIMIT 3') as $g) $defs[] = [$g['name'], 'Neuigkeiten der Teilgruppe', ['source_kind' => 'group', 'group_id' => $g['group_id'], 'optional' => 1]];
        foreach ($defs as [$name, $desc, $x]) md_list_save(null, array_merge(['name' => $name, 'description' => $desc, 'active' => 1], $x));
        $all = md_one("SELECT list_id FROM md_mail_lists WHERE name = 'Alle Mitglieder'");
        if ($all) md_list_set_optout($id(4), (int)$all['list_id'], true, $id(4));
    }
    if (!md_one('SELECT 1 FROM md_applications')) {
        $fg = md_one("SELECT group_id FROM md_fee_groups WHERE public = 1 ORDER BY group_id LIMIT 1");
        $row = fn($status, $first, $last, $email, $conf) => md_exec('INSERT INTO md_applications (status, created_at, confirmed_at, salutation, first_name, last_name, email, birthdate, street, zip, city, phone, fee_group_id, message, ack_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$status, md_now(), $conf ? md_now() : null, 'Frau', $first, $last, $email, '1991-06-02', 'Lindenallee 4', '30159', 'Hannover', '0511 123456', $fg['group_id'] ?? null, 'Ich möchte bei der Wandergruppe mitmachen.', md_now()]);
        $row('new', 'Fiona', 'Neumann', 'fiona.neumann@example.org', true);
        $row('unconfirmed', 'Bernd', 'Wartend', 'bernd.wartend@example.org', false);
    }
    echo "Demo-Verteiler und Online-Anträge angelegt.\n";
}

/** Demo: Personenkonten (Debitor 10001… = Mitglied Nr. 1…) und Kontenregeln für den DATEV-Import. */
function demo_seed_datev(array $idmap = []): void
{
    if (md_one('SELECT 1 FROM md_datev_accounts')) return;
    foreach (range(1, 12) as $n) md_datev_account_save((string)(10000 + $n), $idmap[$n] ?? $n, 'debitor');
    foreach ([7, 8] as $n) md_datev_account_save((string)(70000 + $n), $idmap[$n] ?? $n, 'kreditor');
    md_datev_kind_rule_save('8100', '8199', 'beitrag');
    md_datev_kind_rule_save('8200', '8299', 'spende');
    md_datev_kind_rule_save('8300', '8399', 'eintritt');
    md_datev_kind_rule_save('4100', '4199', 'uebungsleiter');
    md_datev_kind_rule_save('4200', '4299', 'erstattung');
    echo "Demo-Personenkonten für den DATEV-Import angelegt.\n";
}

/**
 * Gleicht Rolle, Admin-Kennzeichen, Funktion und Stufe der Demo-Figuren in svmembers an die Demo-Daten an
 * (nur bei Mitgliedertabellen der Sitzungsverwaltung nötig, die schon Demo-Figuren mit anderen Rollen enthalten). Ändert nur diese Spalten, nur für DEMO-Konten.
 */
function demo_sync_sv(array $svmembers, array $idmap = []): int
{
    $cols = md_sv_columns();
    $n = 0;
    foreach ($svmembers as $m) {
        $id = $idmap[$m['member_id']] ?? $m['member_id'];
        $set = [];
        $par = [];
        foreach (['is_admin' => $m['is_admin'], 'is_active' => $m['is_active'], 'aktiv' => $m['aktiv'], 'funktion' => $m['funktion'], 'role' => md_sv_role_value(strtolower((string)$m['role']))] as $c => $v) {
            if (in_array($c, $cols, true)) { $set[] = "$c = ?"; $par[] = $v; }
        }
        if (!$set) continue;
        $par[] = $id;
        $par[] = sprintf('DEMO%04d', $m['member_id']);
        $n += md_exec('UPDATE svmembers SET ' . implode(', ', $set) . " WHERE member_id = ? AND membership_number = ?", $par);
    }
    echo "Rollen und Admin-Kennzeichen von $n Demo-Konten in svmembers angeglichen.\n";
    return $n;
}
