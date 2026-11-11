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
    $counts = [1 => 3, 2 => 2, 3 => 3, 4 => 1, 5 => 1, 6 => 2, 7 => 3, 8 => 0, 9 => 3, 10 => 1, 11 => 0, 12 => 0];
    $kinds = [1 => 'portrait', 2 => 'treffen', 3 => 'urlaub'];
    foreach ($svmembers as $m) {
        $demoId = (int)$m['member_id'];
        $id = $idmap[$demoId] ?? $demoId;
        if ($demoId <= 12) $slots = $counts[$demoId] ? range(1, $counts[$demoId]) : [];
        else $slots = $demoId <= 52 ? array_values(array_filter([1, $demoId % 3 === 0 ? 2 : 0, $demoId % 5 === 0 ? 3 : 0])) : [];   // 13–52 aktiv mit Foto, 53–72 ohne
        foreach ($slots as $s) {
            $n = $demoId <= 12 ? $demoId : ($demoId % 12) + 1;
            $from = $s === 1 ? "$src/portrait_$demoId.svg" : "$src/{$kinds[$s]}_$n.svg";
            if (!is_file($from)) continue;
            $name = sprintf('%d_%d_demo.svg', $id, $s);
            copy($from, "$dir/$name");
            $pdo->prepare('INSERT INTO md_photos (member_id, slot, file_name, mime, caption) VALUES (?,?,?,?,?)')
                ->execute([$id, $s, $name, 'image/svg+xml', $s === 1 ? 'Portrait' : ($s === 2 ? 'Beim Jahrestreffen' : 'Im Urlaub')]);
        }
    }
    echo "Fotos angelegt.\n";
}

/**
 * Ergänzt fehlende Demo-Mitglieder (DEMO0013–DEMO0072) in einer vorhandenen Mitgliedertabelle (Sitzungsverwaltung) und erweitert $idmap.
 * Nur INSERT, nur Nummern DEMO…, nichts Vorhandenes wird verändert.
 */
function demo_ensure_extra_members(array $svmembers, array &$idmap): int
{
    $n = 0;
    foreach ($svmembers as $m) {
        $num = (int)$m['member_id'];
        if ($num <= 12 || isset($idmap[$num])) continue;
        $nr = sprintf('DEMO%04d', $num);
        $row = md_one('SELECT member_id FROM svmembers WHERE membership_number = ?', [$nr]);
        if (!$row) {
            md_exec('INSERT INTO svmembers (membership_number, first_name, last_name, email, role) VALUES (?,?,?,?,?)', [$nr, $m['first_name'], $m['last_name'], $m['email'], md_sv_role_value('mitglied')]);
            $row = ['member_id' => (int)md_db()->lastInsertId()];
            $n++;
        }
        $idmap[$num] = (int)$row['member_id'];
    }
    return $n;
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

/** Demo: zwei Vereinskonten – Beitragskonto des Vereins und ein Spendenkonto des gemeinnützigen Fördervereins (anderer Zahlungsempfänger). */
function demo_seed_org(): void
{
    if (md_one('SELECT 1 FROM md_bank_accounts')) return;
    md_bank_account_save(null, ['label' => 'Girokonto', 'holder' => 'Musterverein e. V.', 'iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001', 'bank' => 'Deutsche Kreditbank', 'use_invoice' => 1]);
    md_bank_account_save(null, ['label' => 'Spendenkonto', 'holder' => 'Förderverein Musterverein e. V.', 'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'bank' => 'Commerzbank', 'use_donation' => 1]);
    echo "Demo-Vereinskonten angelegt.\n";
}

/**
 * Setzt die Demo (MD_DEMO_MODE=true, SQLite) auf einen jungfräulichen Stand zurück -
 * direkt aus dem laufenden Admin-Bereich (admin/demo_reset.php), ohne Kommandozeile.
 *
 * Anders als tools/demo_setup.php wird die SQLite-Datei NICHT gelöscht/neu angelegt
 * (das würde die bereits offene PDO-Verbindung des laufenden Requests verwaisen
 * lassen) - stattdessen werden alle betroffenen Tabellen in der bestehenden
 * Verbindung geleert und neu befüllt.
 *
 * Welche Tabellen geleert werden, wird zur Laufzeit ermittelt (svmembers,
 * svressorts, alles mit Präfix md_) statt als feste Liste gepflegt zu werden -
 * damit künftig hinzukommende Tabellen (wie die der WABS-Funktion) hier nie
 * vergessen werden und von selbst in einen jungfräulichen Zustand kommen (auch
 * ohne eigene Demo-Inhalte in demo_build()).
 *
 * Gibt die mitgeschriebene Textausgabe der aufgerufenen Demo-Hilfsfunktionen
 * zurück (die schreiben traditionell per echo). Wirft eine RuntimeException bei
 * einem Abbruchgrund (z. B. falscher Modus) - der Aufrufer zeigt sie an.
 */
function demo_reset_inplace(string $anchor = ''): string
{
    if (MD_DB_DRIVER !== 'sqlite' || !MD_DEMO_MODE) {
        throw new RuntimeException('Nur im Demo-Modus möglich (MD_DB_DRIVER=sqlite, MD_DEMO_MODE=true) - das schützt echte Daten.');
    }
    $root = dirname(__DIR__);
    require_once "$root/tools/demo_build.php";
    $anchor = $anchor !== '' ? $anchor : date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) throw new RuntimeException('Ungültiger Bezugstag.');

    ob_start();
    $pdo = md_db();
    $data = demo_build($anchor);

    $tables = array_column(md_q("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"), 'name');
    $tables = array_values(array_filter($tables, fn($t) => $t === 'svmembers' || $t === 'svressorts' || str_starts_with($t, 'md_')));
    $pdo->exec('PRAGMA foreign_keys = OFF');
    foreach ($tables as $t) $pdo->exec("DELETE FROM `$t`");
    // SQLites AUTOINCREMENT-Zaehler (sqlite_sequence) bleibt nach DELETE stehen - ohne
    // diesen Reset wuerden neu vergebene IDs nicht mehr bei 1 beginnen, was die in
    // demo_build() fest einprogrammierten Bezuege (z. B. Mitgliedsnummer <-> ID) durcheinanderbringt.
    if (md_one("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'")) $pdo->exec('DELETE FROM sqlite_sequence');
    $pdo->exec('PRAGMA foreign_keys = ON');
    echo count($tables) . " Tabelle(n) geleert.\n";

    foreach (glob(MD_STORAGE_DIR . '/photos/*') ?: [] as $f) @unlink($f);
    foreach (glob(MD_STORAGE_DIR . '/wabs/*') ?: [] as $f) @unlink($f);
    foreach (glob(MD_STORAGE_DIR . '/mail/*') ?: [] as $f) @unlink($f);

    $pdo->beginTransaction();
    foreach (['svmembers', 'svressorts'] as $t) demo_insert($pdo, $t, $data[$t]);
    foreach (DEMO_MD_TABLES as $t) demo_insert($pdo, $t, $data[$t]);
    $pdo->commit();

    demo_photos($pdo, $data['svmembers']);
    echo 'md_permissions         ' . md_seed_permissions() . "\n";
    md_dunning_seed();
    demo_seed_sepa();
    demo_seed_mail();
    demo_seed_events();
    demo_seed_lists_apply();
    demo_seed_datev();
    demo_seed_org();
    demo_issue_receipts();
    echo 'VERA-Status: ' . vera_status_alle_berechnen(null, 'demo') . " Mitglied(er) berechnet.\n";
    echo "Fertig (Bezugstag $anchor).\n";
    return ob_get_clean();
}

/**
 * Setzt die Demo (MD_DEMO_MODE=true, MySQL - gemeinsame Datenbank mit der
 * Sitzungsverwaltung, "Weg A" in docs/DEMO.md) auf einen jungfräulichen Stand
 * zurück - direkt aus dem laufenden Admin-Bereich, ohne Kommandozeile.
 *
 * Entspricht inhaltlich tools/demo_load.php --reset, aber als aufrufbare
 * Funktion für admin/demo_reset.php statt CLI-Skript. svmembers und
 * svressorts werden NICHT verändert (gehören der Sitzungsverwaltung) - nur
 * fehlende Demo-Mitglieder DEMO0013 ff. werden ergänzt, genau wie beim
 * ursprünglichen Laden. md_functions und md_settings werden wie bei
 * demo_load.php bewusst NICHT geleert (bleiben über mehrere Resets stabil -
 * eigene Einstellungen/Funktionsänderungen während einer Ausprobierphase
 * gehen so nicht bei jedem Reset verloren). Alle anderen md_-Tabellen
 * (inklusive WABS, dort gibt es keine Demo-Inhalte - sie bleiben nach dem
 * Reset einfach leer) werden per information_schema ermittelt statt als
 * feste Liste gepflegt, damit künftig hinzukommende Tabellen nie vergessen
 * werden.
 */
function demo_reset_inplace_mysql(string $anchor = ''): string
{
    if (MD_DB_DRIVER !== 'mysql' || !MD_DEMO_MODE) {
        throw new RuntimeException('Nur im Demo-Modus möglich (MD_DEMO_MODE=true) - das schützt echte Daten.');
    }
    $root = dirname(__DIR__);
    require_once "$root/tools/demo_build.php";
    $anchor = $anchor !== '' ? $anchor : date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) throw new RuntimeException('Ungültiger Bezugstag.');

    $pdo = md_db();
    $others = md_q("SELECT membership_number, first_name, last_name FROM svmembers WHERE membership_number NOT LIKE 'DEMO%' OR membership_number IS NULL ORDER BY member_id");
    if ($others) {
        throw new RuntimeException('svmembers enthält ' . count($others) . ' Mitglied(er) außerhalb der Demo (DEMO%) - zur Sicherheit abgebrochen. '
            . 'Das ist nur die Demo der Sitzungsverwaltung (12+ Figuren DEMO0001 ff.)? Dann bei ihr zuerst "Alles löschen und neu aufbauen" ausführen.');
    }
    $idmap = [];
    foreach (md_q("SELECT member_id, membership_number FROM svmembers WHERE membership_number LIKE 'DEMO%'") as $r) {
        $idmap[(int)substr($r['membership_number'], 4)] = (int)$r['member_id'];
    }
    if (count($idmap) < 12) throw new RuntimeException('Es fehlen Demo-Mitglieder (' . count($idmap) . ' von 12 gefunden) - zuerst die Demo der Sitzungsverwaltung laden.');
    if (!(int)md_one('SELECT COUNT(*) c FROM md_functions')['c']) throw new RuntimeException('md_functions ist leer - zuerst php tools/install.php ausführen.');

    // Fehlende Funktionen additiv ergaenzen (z. B. eine neu hinzugekommene wie "WA" fuer
    // WABS), genau wie tools/install.php es taete - ohne dass dafuer extra die
    // Kommandozeile noetig waere. Bestehende Funktionen (auch umbenannte/deaktivierte)
    // bleiben unangetastet.
    $newFunctions = 0;
    foreach (MD_DEFAULT_FUNCTIONS as [$code, $name, $lvl]) {
        if (md_one('SELECT 1 FROM md_functions WHERE code = ?', [$code])) continue;
        md_exec('INSERT INTO md_functions (code, name, sv_aktiv_level) VALUES (?,?,?)', [$code, $name, $lvl]);
        $newFunctions++;
    }

    ob_start();
    if ($newFunctions) echo "$newFunctions neue Funktion(en) ergänzt.\n";
    $data = demo_build($anchor);
    $added = demo_ensure_extra_members($data['svmembers'], $idmap);
    echo $added ? "$added fehlende(s) Demo-Mitglied(er) in svmembers ergänzt.\n" : "svmembers vollständig, nichts ergänzt.\n";

    // demo_build() nimmt an, dass md_functions.function_id genau der Position in
    // MD_DEFAULT_FUNCTIONS entspricht (1, 2, 3, ...) - das stimmt nur auf einer frisch
    // installierten Tabelle. Da md_functions hier (anders als bei der SQLite-Demo)
    // bewusst nicht geleert/neu angelegt wird, kann die tatsächliche ID (gewachsene
    // Installation, Funktionen über die Administration hinzugefügt, ...) abweichen -
    // deshalb hier auf die wirklichen IDs aus der Datenbank umgerechnet. Code, der dort
    // (trotz obiger Ergänzung) nicht existiert, wird übersprungen statt die Fremdschlüssel-
    // Prüfung scheitern zu lassen.
    $codeByAssumedId = array_column(MD_DEFAULT_FUNCTIONS, 0);
    $realIdByCode = array_column(md_q('SELECT function_id, code FROM md_functions'), 'function_id', 'code');
    $data['md_member_functions'] = array_values(array_filter(array_map(function ($mf) use ($codeByAssumedId, $realIdByCode) {
        $code = $codeByAssumedId[$mf['function_id'] - 1] ?? null;
        if ($code === null || !isset($realIdByCode[$code])) return null;
        $mf['function_id'] = (int)$realIdByCode[$code];
        return $mf;
    }, $data['md_member_functions'])));

    $real = [];
    try {
        foreach (md_q('SELECT ID, Ressort FROM svressorts') as $r) $real[mb_strtolower($r['Ressort'])] = (int)$r['ID'];
    } catch (Throwable $e) {
        // keine Ressortliste vorhanden - Ressortbezug bleibt dann leer
    }
    $demoRes = array_column($data['svressorts'], 'Ressort', 'ID');
    foreach ($data['md_member_functions'] as &$mf) $mf['ressort_id'] = $mf['ressort_id'] ? ($real[mb_strtolower($demoRes[$mf['ressort_id']] ?? '')] ?? null) : null;
    unset($mf);

    $all = array_column(md_q("SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'md\\_%'"), 'TABLE_NAME');
    $tables = array_values(array_diff($all, ['md_functions', 'md_settings']));
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $t) $pdo->exec("DELETE FROM `$t`");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo count($tables) . " Tabelle(n) geleert (md_functions/md_settings bleiben erhalten).\n";

    foreach (glob(MD_STORAGE_DIR . '/photos/*') ?: [] as $f) @unlink($f);
    foreach (glob(MD_STORAGE_DIR . '/wabs/*') ?: [] as $f) @unlink($f);
    foreach (glob(MD_STORAGE_DIR . '/mail/*') ?: [] as $f) @unlink($f);

    $pdo->beginTransaction();
    foreach (array_diff(DEMO_MD_TABLES, ['md_functions', 'md_settings']) as $t) demo_insert($pdo, $t, $data[$t], $idmap);
    $pdo->commit();

    demo_photos($pdo, $data['svmembers'], $idmap);
    foreach ($data['md_settings'] as $st) {
        if (!md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', [$st['setting_key']])) {
            md_exec('INSERT INTO md_settings (setting_key, setting_value, description) VALUES (?,?,?)', [$st['setting_key'], $st['setting_value'], $st['description']]);
        }
    }
    // md_permissions ist eine md_-Tabelle wie jede andere und wurde oben mitgeleert (Rechte sollen beim
    // Zuruecksetzen wieder dem sauberen Default entsprechen) - deshalb hier neu saeen, anders als bei
    // md_functions/md_settings, die bewusst unangetastet bleiben. md_seed_permissions() merkt sich bereits
    // gesaete Bereiche in md_settings ("perm_areas_seeded") - genau dieser Schluessel bleibt (wie alle
    // md_settings) beim Zuruecksetzen unangetastet und wuerde sonst eine erneute Seedung verhindern, obwohl
    // md_permissions gerade geleert wurde. Deshalb hier explizit zuruecksetzen.
    md_exec("DELETE FROM md_settings WHERE setting_key = 'perm_areas_seeded'");
    echo 'md_permissions         ' . md_seed_permissions() . "\n";
    md_dunning_seed();
    demo_seed_sepa($idmap);
    demo_seed_mail($idmap);
    demo_seed_events($idmap);
    demo_seed_lists_apply($idmap);
    demo_seed_datev($idmap);
    demo_seed_org();
    demo_issue_receipts($idmap);
    echo 'VERA-Status: ' . vera_status_alle_berechnen(null, 'demo') . " Mitglied(er) berechnet.\n";
    echo "Fertig (Bezugstag $anchor).\n";
    return ob_get_clean();
}
