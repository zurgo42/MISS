<?php
/**
 * retention.php – listet die Löschfristen aller ausgetretenen Mitglieder (nur Anzeige, löscht nichts).
 * Aufruf: php tools/retention.php   (z. B. als monatliche Erinnerung per Cronjob; Ausgabe per Mail an die Verwaltung leiten)
 * Einzige Ausnahme: abgelaufene Online-Anmeldeanträge (unbestätigt nach 6 Wochen, entschieden nach apply.keep_days) werden hier gelöscht.
 * Löschen führt die Verwaltung bewusst von Hand auf der Seite "Datenschutz" aus.
 */
if (PHP_SAPI !== 'cli') exit('nur per Kommandozeile');
require_once dirname(__DIR__) . '/src/bootstrap.php';
$today = date('Y-m-d');
$due1 = $due2 = $blocked = 0;
$rows = md_q("SELECT s.member_id, s.first_name, s.last_name, s.membership_number FROM svmembers s JOIN md_profiles p ON p.member_id = s.member_id
              WHERE p.status = 'ausgetreten' AND p.anonymized_at IS NULL ORDER BY p.leave_date");
foreach ($rows as $r) {
    $st = md_privacy_state((int)$r['member_id']);
    if ($st['stage'] === 0 && md_privacy_can_purge($st) === '') { $due1++; printf("Stufe 1 fällig: %s %s (%s), Austritt %s\n", $r['first_name'], $r['last_name'], $r['membership_number'], md_date($st['leave_date'])); }
    elseif ($st['stage'] === 1 && md_privacy_can_anonymize($st) === '') { $due2++; printf("Stufe 2 fällig: %s %s (%s), Aufbewahrungsfrist bis %s\n", $r['first_name'], $r['last_name'], $r['membership_number'], md_date($st['anon_due'])); }
    elseif ($st['blockers'] && (($st['purge_due'] ?? '9999') <= $today)) { $blocked++; printf("Frist erreicht, aber gesperrt: %s %s – %s\n", $r['first_name'], $r['last_name'], implode('; ', $st['blockers'])); }
}
$overdue = md_q("SELECT COUNT(*) c FROM md_privacy_requests WHERE status = 'open'")[0]['c'];
$late = 0;
foreach (md_q("SELECT * FROM md_privacy_requests WHERE status = 'open'") as $q) if (md_privacy_request_deadline($q) < $today) $late++;
$nApply = md_apply_purge();
echo "$nApply abgelaufene Anmeldeanträge gelöscht.\n";
echo "$due1 Mitglied(er) für Stufe 1, $due2 für Stufe 2 fällig, $blocked gesperrt; $overdue offene Anfrage(n), davon $late überfällig.\n";
