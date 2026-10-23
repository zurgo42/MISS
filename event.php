<?php
require_once __DIR__ . '/src/bootstrap.php';
$me = md_require_login();
$mid = (int)$me['member_id'];
if (!md_consent_ok($mid)) {
    md_flash('Bitte stimme zuerst den Nutzungsbedingungen zu. Veranstaltungen anlegen und buchen ist erst danach möglich.', 'warn');
    header('Location: ' . md_url('index.php#terms'));
    exit;
}
$id = (int)($_GET['id'] ?? $_POST['event_id'] ?? 0);
$ev = $id ? md_event_get($id) : null;
if ($id && !$ev) { http_response_code(404); exit('Veranstaltung nicht gefunden.'); }
$manage = $ev ? md_event_can_manage($ev, $me) : false;
$back = fn(int $i, string $anchor = '') => md_url('event.php?id=' . $i) . $anchor;

// CSV-Export der Teilnehmerliste (nur Veranstalter/Verwaltung)
if ($ev && isset($_GET['csv'])) {
    if (!$manage) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
    $cell = fn($v) => '"' . str_replace('"', '""', ltrim((string)$v, "=+-@\t\r")) . '"';   // keine Formeln aus Eingaben
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="teilnehmer-' . $id . '.csv"');
    echo "\xEF\xBB\xBF" . "Nr;Name;Mitgliedsnummer;Status;in Liste sichtbar;Anmerkung;Angemeldet am\r\n";
    foreach (md_event_participants($id, $mid, true)['rows'] as $i => $r)
        echo implode(';', [$i + 1, $cell($r['first_name'] . ' ' . $r['last_name']), $cell($r['membership_number']), $cell(MD_EVENT_REG_STATUS[$r['status']]), $r['show_in_list'] ? 'ja' : 'nein', $cell($r['note']), date('d.m.Y H:i', strtotime($r['created_at']))]) . "\r\n";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    $act = $_POST['action'] ?? '';
    $err = '';
    $ok = 'Gespeichert.';
    $to = $id ? $back($id) : md_url('events.php');
    if ($act === 'save') {
        if ($ev && !$manage) { http_response_code(403); exit('Nur der Veranstalter darf ändern.'); }
        [$nid, $err] = md_event_save($ev ? $id : null, $_POST, $mid);
        if ($nid) $to = $back($nid);
        elseif (!$ev) $to = md_url('event.php?new=1');
        if ($err && !$ev) $_SESSION['md_event_draft'] = $_POST;
        $ok = $ev ? 'Veranstaltung gespeichert.' : 'Veranstaltung angelegt. Du kannst jetzt Mitglieder dazu einladen; angemeldet bist du noch nicht.';
    } elseif ($act === 'register') {
        [$st, $err] = md_event_register($id, $mid, !empty($_POST['show_in_list']), $_POST['note'] ?? null, $mid);
        $ok = $st === 'waitlist' ? 'Die Veranstaltung ist ausgebucht: Du stehst auf der Warteliste und rückst automatisch nach, wenn ein Platz frei wird.' : 'Du bist angemeldet.';
    } elseif ($act === 'unregister') {
        $err = md_event_unregister($id, $mid, $mid);
        $ok = 'Du bist abgemeldet.';
    } elseif ($act === 'show') {
        $err = md_event_set_show($id, $mid, !empty($_POST['show_in_list']), $mid);
        $ok = 'Gespeichert.';
    } elseif ($act === 'cancel') {
        if (!$manage) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
        $err = md_event_cancel($id, (string)($_POST['reason'] ?? ''), $mid);
        $ok = 'Die Veranstaltung ist abgesagt; Angemeldete wurden per E-Mail benachrichtigt.';
    } elseif ($act === 'message') {
        if (!$manage) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
        [$n, $err] = md_event_message($id, (string)($_POST['subject'] ?? ''), (string)($_POST['body'] ?? ''), !empty($_POST['waitlist']), $me);
        $ok = "Nachricht an $n Angemeldete gesendet." . ($err ? ' ' . $err : '');
        if ($n > 0) $err = '';
    } elseif ($act === 'remove' && $manage) {
        $err = md_event_unregister($id, (int)($_POST['member_id'] ?? 0), $mid);
        $ok = 'Anmeldung entfernt.';
    }
    md_flash($err ?: $ok, $err ? 'err' : 'ok');
    header('Location: ' . $to);
    exit;
}

$isNew = !$ev && !empty($_GET['new']);
if (!$ev && !$isNew) { header('Location: ' . md_url('events.php')); exit; }
$draft = $isNew ? ($_SESSION['md_event_draft'] ?? []) : [];
unset($_SESSION['md_event_draft']);
$dtval = fn($dt) => $dt ? date('Y-m-d\TH:i', strtotime($dt)) : '';
$form = function (array $v) use ($ev): string {
    $f = fn($k, $d = '') => h($v[$k] ?? $d);
    return '<form method="post">' . md_csrf_field() . '<input type="hidden" name="action" value="save">' . ($ev ? '<input type="hidden" name="event_id" value="' . (int)$ev['event_id'] . '">' : '')
        . '<label class="block">Titel<input name="title" value="' . $f('title') . '" maxlength="150" required style="width:100%"></label>'
        . '<label class="block">Beschreibung<textarea name="description" rows="5" style="width:100%">' . $f('description') . '</textarea></label>'
        . '<label class="block">Ort<input name="location" value="' . $f('location') . '" maxlength="200" style="width:100%"></label>'
        . '<div class="addr"><label>Beginn<input type="datetime-local" name="starts_at" value="' . $f('starts_at') . '" required></label><label>Ende (optional)<input type="datetime-local" name="ends_at" value="' . $f('ends_at') . '"></label>'
        . '<label>Anmeldeschluss (optional)<input type="datetime-local" name="reg_deadline" value="' . $f('reg_deadline') . '"></label></div>'
        . '<div class="addr"><label>Teilnehmerzahl (leer = unbegrenzt)<input name="capacity" size="6" value="' . $f('capacity') . '"></label><label>Teilnahmegebühr in € (leer = kostenlos)<input name="price" size="8" value="' . $f('price') . '"></label></div>'
        . '<button>Speichern</button></form>';
};
md_header($ev ? $ev['title'] : 'Veranstaltung anlegen', $me, 'events');
md_show_flash();
echo '<p><a href="events.php">← alle Veranstaltungen</a></p>';

if ($isNew) {
    echo '<h1>Veranstaltung anlegen</h1><section class="card">' . $form($draft) . '<p class="muted">Du bist Veranstalter und siehst alle Anmeldungen. Eine Teilnahmegebühr erscheint bei den Angemeldeten als offene Forderung; bezahlt wird per Überweisung.</p></section>';
    md_footer();
    exit;
}

$c = md_event_counts($id);
$free = md_event_free($ev);
$reg = md_event_my_reg($id, $mid);
$closed = md_event_closed_reason($ev);
echo '<h1>' . h($ev['title']) . ($ev['status'] === 'cancelled' ? ' <span class="chip warn">abgesagt</span>' : '') . '</h1>';
if ($ev['status'] === 'cancelled') echo '<div class="flash warn">Abgesagt: ' . h($ev['cancel_reason']) . '</div>';
echo '<section class="card"><table class="plain"><tr><td>Beginn</td><td><strong>' . h(md_event_dt($ev['starts_at'])) . '</strong>' . ($ev['ends_at'] ? ' bis ' . h(md_event_dt($ev['ends_at'])) : '') . '</td></tr>'
    . ($ev['location'] ? '<tr><td>Ort</td><td>' . h($ev['location']) . '</td></tr>' : '')
    . '<tr><td>Veranstalter</td><td>' . h($ev['ofn'] . ' ' . $ev['oln']) . '</td></tr>'
    . '<tr><td>Teilnahmegebühr</td><td>' . ((int)$ev['price_cents'] > 0 ? h(md_money((int)$ev['price_cents'])) : 'kostenlos') . '</td></tr>'
    . '<tr><td>Plätze</td><td>' . $c['registered'] . ' angemeldet' . ($ev['capacity'] ? ' von ' . (int)$ev['capacity'] . ($free === 0 ? ' – <strong>ausgebucht</strong>' : ', ' . $free . ' frei') : '') . ($c['waitlist'] ? ', ' . $c['waitlist'] . ' auf der Warteliste' : '') . '</td></tr>'
    . ($ev['reg_deadline'] ? '<tr><td>Anmeldeschluss</td><td>' . h(md_event_dt($ev['reg_deadline'])) . '</td></tr>' : '') . '</table>'
    . ($ev['description'] ? '<p>' . nl2br(h($ev['description'])) . '</p>' : '') . '</section>';

// ---- meine Anmeldung
echo '<section class="card" id="mine"><h2>Meine Anmeldung</h2>';
if ($reg) {
    echo '<p>' . ($reg['status'] === 'registered' ? '<span class="chip ok">Du bist angemeldet.</span>' : '<span class="chip warn">Du stehst auf der Warteliste.</span>') . '</p>';
    echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="event_id" value="' . $id . '"><input type="hidden" name="action" value="show"><label><input type="checkbox" name="show_in_list" value="1"' . ($reg['show_in_list'] ? ' checked' : '') . '> Andere Angemeldete dürfen mich in der Teilnehmerliste sehen</label> <button>Speichern</button></form>';
    if ($ev['status'] === 'open' && $ev['starts_at'] > date('Y-m-d H:i:s'))
        echo '<form method="post" onsubmit="return confirm(\'Wirklich abmelden?\')">' . md_csrf_field() . '<input type="hidden" name="event_id" value="' . $id . '"><input type="hidden" name="action" value="unregister"><button class="link">Abmelden</button></form>';
} elseif ($closed) {
    echo '<p class="muted">' . h($closed) . '</p>';
} else {
    echo '<form method="post">' . md_csrf_field() . '<input type="hidden" name="event_id" value="' . $id . '"><input type="hidden" name="action" value="register">'
        . '<label class="block"><input type="checkbox" name="show_in_list" value="1"> Andere Angemeldete dürfen mich in der Teilnehmerliste sehen <span class="muted">(Standard: nein; der Veranstalter sieht immer alle)</span></label>'
        . '<label class="block">Anmerkung an den Veranstalter (optional)<input name="note" maxlength="255" style="width:100%"></label>'
        . '<button>' . ($free === 0 ? 'Auf die Warteliste setzen' : 'Verbindlich anmelden') . '</button>' . ((int)$ev['price_cents'] > 0 ? ' <span class="muted">Die Teilnahmegebühr von ' . h(md_money((int)$ev['price_cents'])) . ' erscheint als offene Forderung in deinem Dashboard.</span>' : '') . '</form>';
}
echo '</section>';

// ---- Teilnehmerliste
$pl = md_event_participants($id, $mid, $manage);
echo '<section class="card" id="people"><h2>Teilnehmerliste</h2>';
if ($manage) {
    echo '<p class="muted">Du siehst alle Anmeldungen (als Veranstalter bzw. Verwaltung). Andere Angemeldete sehen nur, wer es erlaubt hat. <a href="event.php?id=' . $id . '&csv=1">als CSV herunterladen</a></p>';
    echo '<table><thead><tr><th>Nr.</th><th>Name</th><th>Status</th><th>Für andere sichtbar</th><th>Anmerkung</th><th>Angemeldet</th><th>Gebühr</th><th></th></tr></thead><tbody>';
    foreach ($pl['rows'] as $i => $r) {
        $fee = '';
        if ($r['entry_id']) { $le = md_one('SELECT status FROM md_ledger WHERE entry_id = ?', [$r['entry_id']]); $fee = $le ? ['open' => 'offen', 'paid' => 'bezahlt', 'cancelled' => 'storniert'][$le['status']] : ''; }
        echo '<tr><td>' . ($i + 1) . '</td><td>' . h($r['first_name'] . ' ' . $r['last_name']) . '</td><td>' . h(MD_EVENT_REG_STATUS[$r['status']]) . '</td><td>' . ($r['show_in_list'] ? 'ja' : 'nein') . '</td><td>' . h($r['note']) . '</td><td>' . h(date('d.m.Y', strtotime($r['created_at']))) . '</td><td>' . h($fee) . '</td><td>';
        if ($ev['status'] === 'open') echo '<form method="post" onsubmit="return confirm(\'Anmeldung entfernen?\')">' . md_csrf_field() . '<input type="hidden" name="event_id" value="' . $id . '"><input type="hidden" name="action" value="remove"><input type="hidden" name="member_id" value="' . (int)$r['member_id'] . '"><button class="link">entfernen</button></form>';
        echo '</td></tr>';
    }
    if (!$pl['rows']) echo '<tr><td colspan="8" class="muted">Noch keine Anmeldungen.</td></tr>';
    echo '</tbody></table>';
} elseif (!empty($pl['restricted'])) {
    echo '<p class="muted">Die Namen sehen nur Angemeldete – und auch nur von denen, die es erlaubt haben. Bisher angemeldet: ' . $pl['hidden'] . '.</p>';
} else {
    echo '<ul class="plain">';
    foreach ($pl['rows'] as $r) echo '<li>' . h($r['first_name'] . ' ' . $r['last_name']) . ($r['mine'] ? ' <span class="muted">(du' . ($r['status'] === 'waitlist' ? ', Warteliste' : '') . ')</span>' : '') . '</li>';
    echo '</ul>' . ($pl['hidden'] ? '<p class="muted">… und ' . $pl['hidden'] . ' weitere, die nicht in der Liste erscheinen möchten.</p>' : '');
}
echo '</section>';

// ---- Nachricht an alle Angemeldeten
if ($manage && $ev['status'] === 'open') {
    echo '<section class="card" id="message"><h2>Nachricht an die Angemeldeten</h2><p class="muted">Geht einzeln per E-Mail an alle Angemeldeten; Antworten kommen direkt an deine E-Mail-Adresse (' . h($me['email']) . '). Höchstens 3 Nachrichten je Tag.</p>'
        . '<form method="post">' . md_csrf_field() . '<input type="hidden" name="event_id" value="' . $id . '"><input type="hidden" name="action" value="message"><label class="block">Betreff<input name="subject" maxlength="200" required style="width:100%"></label>'
        . '<label class="block">Text <span class="muted">({anrede} {vorname} {name} werden ersetzt)</span><textarea name="body" rows="6" required style="width:100%"></textarea></label><label class="check"><input type="checkbox" name="waitlist" value="1"> auch an die Warteliste</label> <button>Senden</button></form></section>';
}

// ---- Verwalten
if ($manage && $ev['status'] === 'open') {
    echo '<section class="card" id="edit"><h2>Veranstaltung bearbeiten</h2>' . $form([
        'title' => $ev['title'], 'description' => $ev['description'], 'location' => $ev['location'], 'starts_at' => $dtval($ev['starts_at']), 'ends_at' => $dtval($ev['ends_at']), 'reg_deadline' => $dtval($ev['reg_deadline']),
        'capacity' => $ev['capacity'], 'price' => (int)$ev['price_cents'] > 0 ? number_format($ev['price_cents'] / 100, 2, ',', '') : '']) . '</section>';
    echo '<section class="card"><h2>Absagen</h2><p class="muted">Alle Angemeldeten bekommen eine E-Mail; offene Teilnahmegebühren werden storniert.</p><form method="post" onsubmit="return this.reason.value.trim() !== \'\' && confirm(\'Veranstaltung wirklich absagen?\') || (alert(\'Bitte einen Grund angeben.\'), false)">'
        . md_csrf_field() . '<input type="hidden" name="event_id" value="' . $id . '"><input type="hidden" name="action" value="cancel"><input name="reason" placeholder="Grund" size="40"> <button>Absagen</button></form></section>';
}
md_footer();
