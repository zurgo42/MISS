<?php
/**
 * demo_setup.php – baut die lokale Demo (SQLite) auf: config.php, Schema, Demo-Daten, Platzhalter-Fotos.
 * Aufruf: php tools/demo_setup.php [--anchor=YYYY-MM-DD]
 * Danach:  php -S localhost:8080
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

require_once "$root/tools/demo_lib.php";
$pdo->beginTransaction();
foreach (['svmembers', 'svressorts'] as $t) demo_insert($pdo, $t, $data[$t]);
foreach (DEMO_MD_TABLES as $t) demo_insert($pdo, $t, $data[$t]);
$pdo->commit();
require_once "$root/src/fields.php";
demo_photos($pdo, $data['svmembers']);
require_once "$root/src/bootstrap.php";
echo 'md_permissions        ' . md_seed_permissions() . "\n";
md_dunning_seed();
demo_ensure_key($root);
demo_seed_sepa();
demo_seed_mail();
demo_issue_receipts();
echo "Start: php -S localhost:8080  →  http://localhost:8080/login.php\n";
