<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_admin();
$q = trim((string)($_GET['q'] ?? ''));
$rows = md_q("SELECT s.*, p.status, p.join_date, (SELECT COUNT(*) FROM md_ledger l WHERE l.member_id = s.member_id AND l.status = 'open' AND l.direction = 'to_club') AS open_claims
              FROM svmembers s LEFT JOIN md_profiles p ON p.member_id = s.member_id ORDER BY s.last_name, s.first_name");
md_header('Verwaltung', $me, 'admin');
md_show_flash();
echo '<h1>Mitglieder verwalten</h1><form class="search" method="get"><input name="q" value="' . h($q) . '" placeholder="Name, E-Mail, Mitgliedsnr."><button>Suchen</button></form>';
echo '<table><thead><tr><th>Nr.</th><th>Name</th><th>E-Mail</th><th>Status</th><th>Eintritt</th><th>Zustimmung</th><th>Offene Forderungen</th><th></th></tr></thead><tbody>';
foreach ($rows as $r) {
    if ($q !== '' && mb_stripos($r['first_name'] . ' ' . $r['last_name'] . ' ' . $r['email'] . ' ' . $r['membership_number'], $q) === false) continue;
    $t = md_terms_status((int)$r['member_id']);
    $tl = ['ok' => ['ok', 'ja'], 'outdated' => ['warn', 'alte Version'], 'missing' => ['bad', 'fehlt'], 'none' => ['', '–']][$t['state']];
    echo '<tr><td>' . h($r['membership_number']) . '</td><td>' . h($r['first_name'] . ' ' . $r['last_name']) . '</td><td>' . h($r['email']) . '</td><td>' . h(MD_STATUS[$r['status'] ?? 'aktiv'] ?? '') . '</td><td>' . h(md_date($r['join_date'])) . '</td>';
    echo '<td><span class="chip ' . $tl[0] . '">' . $tl[1] . '</span></td><td>' . ((int)$r['open_claims'] ?: '–') . '</td><td><a href="index.php?member=' . (int)$r['member_id'] . '">bearbeiten</a></td></tr>';
}
echo '</tbody></table>';
md_footer();
