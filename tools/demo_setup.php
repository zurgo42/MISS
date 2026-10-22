<?php
/**
 * demo_setup.php – baut die lokale Demo (SQLite) auf: config.php, Schema, Demo-Daten, Platzhalter-Fotos.
 * Aufruf: php tools/demo_setup.php [--anchor=YYYY-MM-DD]
 * Danach:  php -S localhost:8080 -t public
 */
$root = dirname(__DIR__);
if (!is_file("$root/config.php")) {
    copy("$root/config.example.php", "$root/config.php");
    echo "config.php aus config.example.php angelegt (Demo-Einstellungen)\n";
}
require_once "$root/config.php";
if (MD_DB_DRIVER !== 'sqlite' || !MD_DEMO_MODE) {
    fwrite(STDERR, "Abbruch: demo_setup.php läuft nur mit MD_DB_DRIVER=sqlite und MD_DEMO_MODE=true (schützt echte Daten).\n");
    exit(1);
}
require_once "$root/tools/demo_build.php";
require_once "$root/src/db.php";

$anchor = date('Y-m-d');
foreach ($argv as $a) if (preg_match('/^--anchor=(\d{4}-\d{2}-\d{2})$/', $a, $m)) $anchor = $m[1];
$data = demo_build($anchor);
file_put_contents("$root/tools/demo_data.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

if (!is_dir(MD_STORAGE_DIR)) mkdir(MD_STORAGE_DIR, 0775, true);
if (is_file(MD_SQLITE_FILE)) unlink(MD_SQLITE_FILE);
foreach (glob(MD_STORAGE_DIR . '/photos/*') ?: [] as $f) unlink($f);
$pdo = md_db();
md_run_sql_file($pdo, "$root/sql/sv_stub.sql");
md_run_sql_file($pdo, "$root/sql/schema.sql");

$insert = function (string $table, array $rows) use ($pdo) {
    foreach ($rows as $r) {
        $cols = array_keys($r);
        $st = $pdo->prepare("INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
        $st->execute(array_values($r));
    }
    echo str_pad($table, 22) . count($rows) . "\n";
};
$pdo->beginTransaction();
foreach (['svmembers', 'md_functions', 'md_groups', 'md_profiles', 'md_addresses', 'md_contacts', 'md_visibility', 'md_member_groups', 'md_member_functions',
          'md_ledger', 'md_terms_versions', 'md_terms_consents', 'md_changelog', 'md_import_profiles', 'md_settings'] as $t) $insert($t, $data[$t]);
$pdo->commit();

// Platzhalter-Fotos (SVG mit Initialen); Fotoplätze je Figur
require_once "$root/src/fields.php";
$dir = MD_STORAGE_DIR . '/photos';
if (!is_dir($dir)) mkdir($dir, 0775, true);
$hues = [210, 30, 150, 280, 0, 180, 50, 320, 100, 240, 20, 200];
$counts = [1 => 3, 2 => 2, 3 => 3, 4 => 1, 5 => 1, 6 => 2, 7 => 3, 8 => 2, 9 => 3, 10 => 1, 11 => 0, 12 => 0];
foreach ($data['svmembers'] as $m) {
    $id = $m['member_id'];
    for ($s = 1; $s <= $counts[$id]; $s++) {
        $ini = mb_strtoupper(mb_substr($m['first_name'], 0, 1) . mb_substr($m['last_name'], 0, 1));
        $hue = ($hues[$id - 1] + ($s - 1) * 25) % 360;
        $name = sprintf('%d_%d_demo.svg', $id, $s);
        file_put_contents("$dir/$name", '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><rect width="200" height="200" fill="hsl(' . $hue . ',55%,78%)"/>'
            . '<text x="100" y="118" font-family="sans-serif" font-size="70" text-anchor="middle" fill="hsl(' . $hue . ',50%,25%)">' . htmlspecialchars($ini) . '</text>'
            . '<text x="100" y="180" font-family="sans-serif" font-size="14" text-anchor="middle" fill="hsl(' . $hue . ',50%,25%)">Demo-Foto ' . $s . '</text></svg>');
        $pdo->prepare('INSERT INTO md_photos (member_id, slot, file_name, mime, caption) VALUES (?,?,?,?,?)')
            ->execute([$id, $s, $name, 'image/svg+xml', $s === 1 ? 'Portrait' : ($s === 2 ? 'Beim Jahrestreffen' : 'Im Urlaub')]);
    }
}
echo "Fotos angelegt.\nStart: php -S localhost:8080 -t public  →  http://localhost:8080/login.php\n";
