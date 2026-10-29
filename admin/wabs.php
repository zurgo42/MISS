<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('wahl', 'view');
$edit = md_can($me, 'wahl', 'edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    if (!$edit) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
    $aktion = (string)($_POST['aktion'] ?? 'create');
    $eid = (int)($_POST['election_id'] ?? 0);
    $ok = '';
    $err = '';
    if ($aktion === 'create') {
        $err = md_wabs_election_create((string)($_POST['title'] ?? ''), (string)($_POST['start_at'] ?? ''), (string)($_POST['end_at'] ?? ''), (int)$me['member_id']);
        $ok = 'Wahl angelegt.';
    } elseif ($aktion === 'set_url') {
        $err = md_wabs_election_set_vote_base_url($eid, (string)($_POST['vote_base_url'] ?? ''));
        $ok = 'Link-Basis-URL gespeichert.';
    } elseif ($aktion === 'generate') {
        [$queued, $noMail, $err] = md_wabs_requests_generate($eid);
        $ok = $queued . ' Wahlaufforderung(en) in die Warteschlange eingereiht' . ($noMail ? ", $noMail Mitglied(er) ohne E-Mail-Adresse (folgt mit dem PDF-Sammeldruck)" : '') . '.';
    } elseif ($aktion === 'send') {
        [$sent, $failed, $firstErr] = md_wabs_queue_send($eid, 100);
        $ok = $sent . ' Wahlaufforderung(en) versendet' . ($failed ? ", $failed fehlgeschlagen (siehe Liste unten, Grund: $firstErr)" : '') . '.';
    } elseif ($aktion === 'retry') {
        $err = md_wabs_request_retry($eid, (int)($_POST['member_id'] ?? 0));
        $ok = 'Neue Wahlaufforderung eingereiht.';
    }
    md_flash($err ?: $ok, $err ? 'err' : 'ok');
    header('Location: ' . md_url('admin/wabs.php' . ($eid ? '?election=' . $eid : '')));
    exit;
}

$elections = md_wabs_elections();
$selId = (int)($_GET['election'] ?? 0);
$sel = $selId ? md_wabs_election_get($selId) : null;

md_header('Wahl und Abstimmung', $me, 'wabs');
md_show_flash();
echo '<h1>Wahl und Abstimmung (WABS)</h1><p class="muted">Hier legt der Wahlausschuss eine Wahl an und stößt die Wahlaufforderung (Token per E-Mail) an. Mitglieder ohne E-Mail-Adresse und die Übertragung zum Wahlserver folgen in weiteren Schritten.</p>';

echo '<section class="card"><h2>Angelegte Wahlen</h2><table><thead><tr><th>Bezeichnung</th><th>Start</th><th>Ende</th><th>Status</th><th></th></tr></thead><tbody>';
foreach ($elections as $e) {
    echo '<tr' . ($sel && (int)$sel['election_id'] === (int)$e['election_id'] ? ' class="on"' : '') . '><td>' . h($e['title']) . '</td><td>' . h(date('d.m.Y H:i', strtotime($e['start_at']))) . '</td><td>' . h(date('d.m.Y H:i', strtotime($e['end_at']))) . '</td><td>' . h($e['status']) . '</td><td><a href="admin/wabs.php?election=' . (int)$e['election_id'] . '">verwalten</a></td></tr>';
}
if (!$elections) echo '<tr><td colspan="5" class="muted">Noch keine Wahl angelegt.</td></tr>';
echo '</tbody></table></section>';

if ($edit) {
    echo '<section class="card"><h2>Neue Wahl anlegen</h2><form method="post">' . md_csrf_field() . '<input type="hidden" name="aktion" value="create">'
        . '<label class="block">Bezeichnung<input name="title" placeholder="z. B. Wahl des Vorstands und der Kassenprüfer" required style="width:100%" maxlength="200"></label>'
        . '<div class="addr"><label>Start<input type="datetime-local" name="start_at" required></label><label>Ende<input type="datetime-local" name="end_at" required></label></div>'
        . '<button>Anlegen</button></form></section>';
}

if ($sel) {
    $sum = md_wabs_requests_summary((int)$sel['election_id']);
    $failed = md_wabs_failed_recipients((int)$sel['election_id']);
    echo '<section class="card"><h2>„' . h($sel['title']) . '“ verwalten</h2>';
    if ($edit) {
        echo '<form method="post"><input type="hidden" name="aktion" value="set_url"><input type="hidden" name="election_id" value="' . (int)$sel['election_id'] . '">' . md_csrf_field()
            . '<label class="block">Link-Basis-URL zum Wahlserver <span class="muted">(z. B. https://verein.de/WABS – noch ohne Dateinamen; vor dem Versand eintragen)</span><input name="vote_base_url" value="' . h($sel['vote_base_url'] ?? '') . '" style="width:100%" placeholder="https://…"></label>'
            . '<button>Speichern</button></form>';
    } else {
        echo '<p>Link-Basis-URL: ' . h($sel['vote_base_url'] ?: '(noch nicht eingetragen)') . '</p>';
    }
    echo '<table><tbody>'
        . '<tr><td>Bereits versendet</td><td class="r">' . (int)$sum['sent'] . '</td></tr>'
        . '<tr><td>Fehlgeschlagen</td><td class="r">' . (int)$sum['failed'] . '</td></tr>'
        . '<tr><td>In der Warteschlange</td><td class="r">' . (int)$sum['queue_rows'] . '</td></tr>'
        . '<tr><td>Noch nicht berücksichtigt, mit E-Mail-Adresse</td><td class="r">' . (int)$sum['offen_mit_mail'] . '</td></tr>'
        . '<tr><td>Ohne E-Mail-Adresse (Phase 3: PDF-Sammeldruck)</td><td class="r">' . (int)$sum['offen_ohne_mail'] . '</td></tr>'
        . '</tbody></table>';
    if ($edit) {
        if ($sum['offen_mit_mail'] > 0) {
            echo '<form method="post" onsubmit="return confirm(\'Für ' . (int)$sum['offen_mit_mail'] . ' Mitglied(er) je ein neues Token erzeugen und die Wahlaufforderung in die Warteschlange einreihen?\')"><input type="hidden" name="aktion" value="generate"><input type="hidden" name="election_id" value="' . (int)$sel['election_id'] . '">' . md_csrf_field()
                . '<button>Wahlaufforderungen für ' . (int)$sum['offen_mit_mail'] . ' Mitglied(er) erzeugen</button></form>';
        }
        if ($sum['queue_rows'] > 0) {
            echo '<form method="post"><input type="hidden" name="aktion" value="send"><input type="hidden" name="election_id" value="' . (int)$sel['election_id'] . '">' . md_csrf_field()
                . '<button>' . (int)$sum['queue_rows'] . ' wartende Wahlaufforderung(en) jetzt senden</button></form>';
        }
    }
    if ($failed) {
        echo '<h3>Fehlgeschlagen – bitte einzeln erneut anstoßen</h3><table><thead><tr><th>Mitglied</th><th>Zuletzt versucht</th><th></th></tr></thead><tbody>';
        foreach ($failed as $f) {
            echo '<tr><td>' . h($f['first_name'] . ' ' . $f['last_name']) . '</td><td>' . h(date('d.m.Y H:i', strtotime($f['attempted_at']))) . '</td><td>'
                . ($edit ? '<form method="post" style="display:inline"><input type="hidden" name="aktion" value="retry"><input type="hidden" name="election_id" value="' . (int)$sel['election_id'] . '"><input type="hidden" name="member_id" value="' . (int)$f['member_id'] . '">' . md_csrf_field() . '<button class="link">erneut versuchen</button></form>' : '') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</section>';
}
md_footer();
