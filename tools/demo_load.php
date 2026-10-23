<?php
/**
 * demo_load.php – lädt die md_*-Demo-Daten in eine MySQL-Datenbank, in der die Demo-Mitglieder der Sitzungsverwaltung
 * (Mitgliedsnummern DEMO0001–DEMO0012) bereits vorhanden sind. svmembers und svressorts werden NICHT verändert.
 *
 *   php tools/demo_load.php            laden (md_-Tabellen müssen leer sein)
 *   php tools/demo_load.php --reset    vorher alle md_-Tabellen leeren (nur md_, nie sv*)
 *   php tools/demo_load.php --ignore-others   auch laden, wenn svmembers weitere (Test-)Konten enthält; diese bleiben unverändert
 *
 * Schutz: Bricht ab (und listet die Konten), wenn svmembers andere Mitglieder als die Demo-Figuren enthält
 * (außer mit --ignore-others) oder MD_DEMO_MODE=false ist.
 */
if (PHP_SAPI !== 'cli') exit('nur per Kommandozeile');
$root = dirname(__DIR__);
require_once "$root/src/bootstrap.php";
require_once "$root/tools/demo_build.php";
require_once "$root/tools/demo_lib.php";
if (MD_DB_DRIVER !== 'mysql' || !MD_DEMO_MODE) exit("Nur mit MD_DB_DRIVER='mysql' und MD_DEMO_MODE=true.\n");
$pdo = md_db();

$others = md_q("SELECT member_id, membership_number, first_name, last_name, email, role FROM svmembers WHERE membership_number NOT LIKE 'DEMO%' OR membership_number IS NULL ORDER BY member_id");
if ($others && !in_array('--ignore-others', $argv, true)) {
    echo 'Abbruch: svmembers enthält ' . count($others) . " Mitglied(er) außerhalb der Demo (DEMO%):\n";
    foreach ($others as $o) printf("  #%d  Nr. %s  %s %s  <%s>  (%s)\n", $o['member_id'], $o['membership_number'] ?? '–', $o['first_name'], $o['last_name'], $o['email'], $o['role']);
    exit("\nSind das Testkonten, die stehen bleiben sollen? Dann mit --ignore-others starten: Es werden nur die Demo-Figuren befüllt,\nsvmembers und diese Konten bleiben unverändert (die Konten haben danach ein leeres Profil).\n");
}
$idmap = [];
foreach (md_q("SELECT member_id, membership_number FROM svmembers WHERE membership_number LIKE 'DEMO%'") as $r) $idmap[(int)substr($r['membership_number'], 4)] = (int)$r['member_id'];
if (count($idmap) < 12) exit('Abbruch: Es fehlen Demo-Mitglieder (' . count($idmap) . " von 12 gefunden). Zuerst die Demo der Sitzungsverwaltung importieren.\n");
if (!(int)md_one('SELECT COUNT(*) c FROM md_functions')['c']) exit("Abbruch: md_functions ist leer. Zuerst php tools/install.php ausführen.\n");

if (in_array('--reset', $argv, true)) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (array_merge(['md_dunning_letters', 'md_fee_runs', 'md_receipt_events', 'md_receipts', 'md_photos', 'md_sepa_events', 'md_sepa_items', 'md_sepa_batches', 'md_sepa_mandates', 'md_mail_outbox', 'md_mail_campaigns', 'md_mail_optout', 'md_mail_lists', 'md_login_tokens', 'md_login_attempts', 'md_event_regs', 'md_events', 'md_applications', 'md_datev_rows', 'md_datev_imports', 'md_datev_accounts', 'md_datev_kinds'], array_diff(DEMO_MD_TABLES, ['md_functions', 'md_settings'])) as $t) $pdo->exec("DELETE FROM $t");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo "md_-Tabellen geleert.\n";
}
if ((int)md_one('SELECT COUNT(*) c FROM md_profiles')['c'] > 0) exit("Abbruch: md_profiles ist nicht leer. Mit --reset die md_-Tabellen leeren.\n");

$anchor = date('Y-m-d');
$data = demo_build($anchor);
// Ressorts der echten Ressortliste über den Namen zuordnen (falls vorhanden)
$real = [];
try { foreach (md_q('SELECT ID, Ressort FROM svressorts') as $r) $real[mb_strtolower($r['Ressort'])] = (int)$r['ID']; } catch (Throwable $e) {}
$demoRes = array_column($data['svressorts'], 'Ressort', 'ID');
foreach ($data['md_member_functions'] as &$mf) $mf['ressort_id'] = $mf['ressort_id'] ? ($real[mb_strtolower($demoRes[$mf['ressort_id']] ?? '')] ?? null) : null;
unset($mf);

$pdo->beginTransaction();
foreach (array_diff(DEMO_MD_TABLES, ['md_functions', 'md_settings']) as $t) demo_insert($pdo, $t, $data[$t], $idmap);
$pdo->commit();
demo_photos($pdo, $data['svmembers'], $idmap);
if (function_exists('md_seed_permissions')) { md_seed_permissions(); md_dunning_seed(); }
// Vereinsangaben (md_settings) ergänzen, soweit noch nicht gesetzt
foreach ($data['md_settings'] as $st) if (!md_one('SELECT 1 FROM md_settings WHERE setting_key = ?', [$st['setting_key']])) md_exec('INSERT INTO md_settings (setting_key, setting_value, description) VALUES (?,?,?)', [$st['setting_key'], $st['setting_value'], $st['description']]);
demo_ensure_key($root);
demo_seed_sepa($idmap);
demo_seed_mail($idmap);
demo_seed_events($idmap);
demo_seed_lists_apply($idmap);
demo_seed_datev($idmap);
demo_issue_receipts($idmap);
echo "\nFertig. Demo-Anmeldung (MD_DEMO_MODE): " . md_url('login.php') . "\n";
