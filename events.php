<?php
require_once __DIR__ . '/src/bootstrap.php';
$me = md_require_login();
$mid = (int)$me['member_id'];
if (!md_consent_ok($mid)) {
    md_flash('Bitte stimme zuerst den Nutzungsbedingungen zu. Veranstaltungen anlegen und buchen ist erst danach möglich.', 'warn');
    header('Location: ' . md_url('index.php#terms'));
    exit;
}
$past = !empty($_GET['past']);
$now = date('Y-m-d H:i:s');
$rows = md_q('SELECT e.*, s.first_name ofn, s.last_name oln FROM md_events e JOIN svmembers s ON s.member_id = e.organizer_id WHERE ' . ($past ? 'e.starts_at < ?' : 'e.starts_at >= ?') . ' ORDER BY e.starts_at ' . ($past ? 'DESC' : 'ASC') . ' LIMIT 200', [$now]);
md_header('Veranstaltungen', $me, 'events');
md_show_flash();
echo '<h1>Veranstaltungen</h1><p class="muted">Jedes Mitglied kann Veranstaltungen anlegen und sich anmelden. <a href="event.php?new=1">Veranstaltung anlegen</a> · <a href="events.php' . ($past ? '' : '?past=1') . '">' . ($past ? 'kommende anzeigen' : 'vergangene anzeigen') . '</a></p>';
echo '<section class="card"><table><thead><tr><th>Wann</th><th>Veranstaltung</th><th>Ort</th><th>Veranstalter</th><th class="r">Gebühr</th><th>Plätze</th><th>Ich</th></tr></thead><tbody>';
foreach ($rows as $e) {
    $c = md_event_counts((int)$e['event_id']);
    $free = md_event_free($e);
    $reg = md_event_my_reg((int)$e['event_id'], $mid);
    $cancelled = $e['status'] === 'cancelled';
    echo '<tr' . ($cancelled ? ' class="muted"' : '') . '><td>' . h(md_event_dt($e['starts_at'])) . '</td><td><a href="event.php?id=' . (int)$e['event_id'] . '">' . h($e['title']) . '</a>' . ($cancelled ? ' <strong>(abgesagt)</strong>' : '') . '</td><td>' . h($e['location']) . '</td>'
        . '<td>' . h($e['ofn'] . ' ' . $e['oln']) . '</td><td class="r">' . ((int)$e['price_cents'] > 0 ? h(md_money((int)$e['price_cents'])) : 'kostenlos') . '</td>'
        . '<td>' . $c['registered'] . ($e['capacity'] ? ' / ' . (int)$e['capacity'] : '') . ($free === 0 && !$cancelled ? ' <span class="chip warn">ausgebucht</span>' : '') . ($c['waitlist'] ? ' <span class="muted">+' . $c['waitlist'] . ' Warteliste</span>' : '') . '</td>'
        . '<td>' . ($reg ? ($reg['status'] === 'registered' ? '<span class="chip ok">angemeldet</span>' : '<span class="chip warn">Warteliste</span>') : ((int)$e['organizer_id'] === $mid ? 'Veranstalter' : '')) . '</td></tr>';
}
if (!$rows) echo '<tr><td colspan="7" class="muted">Keine Veranstaltungen.</td></tr>';
echo '</tbody></table></section>';
md_footer();
