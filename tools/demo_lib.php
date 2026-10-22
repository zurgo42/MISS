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

/** Platzhalter-Fotos (SVG mit Initialen); Anzahl Fotoplätze je Figur wie im Demo-Stand. */
function demo_photos(PDO $pdo, array $svmembers, array $idmap = []): void
{
    $dir = MD_STORAGE_DIR . '/photos';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    foreach (glob("$dir/*_demo.svg") ?: [] as $f) unlink($f);
    $hues = [210, 30, 150, 280, 0, 180, 50, 320, 100, 240, 20, 200];
    $counts = [1 => 3, 2 => 2, 3 => 3, 4 => 1, 5 => 1, 6 => 2, 7 => 3, 8 => 2, 9 => 3, 10 => 1, 11 => 0, 12 => 0];
    foreach ($svmembers as $m) {
        $demoId = $m['member_id'];
        $id = $idmap[$demoId] ?? $demoId;
        for ($s = 1; $s <= $counts[$demoId]; $s++) {
            $ini = mb_strtoupper(mb_substr($m['first_name'], 0, 1) . mb_substr($m['last_name'], 0, 1));
            $hue = ($hues[$demoId - 1] + ($s - 1) * 25) % 360;
            $name = sprintf('%d_%d_demo.svg', $id, $s);
            file_put_contents("$dir/$name", '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><rect width="200" height="200" fill="hsl(' . $hue . ',55%,78%)"/>'
                . '<text x="100" y="118" font-family="sans-serif" font-size="70" text-anchor="middle" fill="hsl(' . $hue . ',50%,25%)">' . htmlspecialchars($ini) . '</text>'
                . '<text x="100" y="180" font-family="sans-serif" font-size="14" text-anchor="middle" fill="hsl(' . $hue . ',50%,25%)">Demo-Foto ' . $s . '</text></svg>');
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
