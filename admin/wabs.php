<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('wahl', 'view');
$edit = md_can($me, 'wahl', 'edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    $aktion = (string)($_POST['aktion'] ?? 'create');
    $eid = (int)($_POST['election_id'] ?? 0);
    $ok = '';
    $err = '';
    // committee_set/committee_unset sind Selbstbedienung für Wahlausschuss-Mitglieder
    // (siehe md_wabs_committee_set()) und brauchen deshalb nicht das Recht "wahl:bearbeiten" -
    // die Funktion wirkt ohnehin nur auf die eigene Zeile und prüft die WA-Funktion selbst.
    if (!in_array($aktion, ['committee_set', 'committee_unset'], true) && !$edit) {
        http_response_code(403); exit('Dafür fehlt die Berechtigung.');
    }
    if ($aktion === 'committee_set') {
        $err = md_wabs_committee_set($me, true, (string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
        $ok = 'Zugang zum Wahlserver gespeichert.';
    } elseif ($aktion === 'committee_unset') {
        $err = md_wabs_committee_set($me, false, '', '');
        $ok = 'Zugang zum Wahlserver entfernt.';
    } elseif ($aktion === 'create') {
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
    } elseif ($aktion === 'generate_pdf') {
        [$pages, $err] = md_wabs_pdf_generate($eid, (int)$me['member_id']);
        $ok = $pages . ' Seite(n) im PDF-Sammeldruck erzeugt.';
    } elseif ($aktion === 'pdf_delete') {
        $err = md_wabs_pdf_delete($eid, (int)$me['member_id']);
        $ok = 'PDF-Datei gelöscht.';
    } elseif ($aktion === 'save_transfer_conf') {
        $err = md_wabs_transfer_conf_save((string)($_POST['mode'] ?? ''), (string)($_POST['host'] ?? ''), (string)($_POST['port'] ?? ''),
            (string)($_POST['dbname'] ?? ''), (string)($_POST['dbuser'] ?? ''), (string)($_POST['dbpass'] ?? ''));
        $ok = 'Wahlserver-Verbindung gespeichert.';
    } elseif ($aktion === 'transfer_direct') {
        $err = md_wabs_transfer_direct($eid, (int)$me['member_id']);
        $ok = 'Token-Liste direkt zum Wahlserver übertragen und aus MISS gelöscht.';
    } elseif ($aktion === 'csv_generate') {
        [, $err] = md_wabs_transfer_csv_generate($eid, (int)$me['member_id']);
        $ok = 'Token-CSV erzeugt.';
    } elseif ($aktion === 'csv_confirm') {
        $err = md_wabs_transfer_csv_confirm($eid, (int)$me['member_id']);
        $ok = 'Import bestätigt, CSV-Datei und Token-Liste in MISS gelöscht.';
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

$committee = md_wabs_committee_members();
$myWa = null;
foreach ($committee as $c) if ((int)$c['member_id'] === (int)$me['member_id']) { $myWa = $c; break; }
if ($committee) {
    echo '<section class="card"><h2>Zugang zum eigenständigen Wahlserver</h2>'
        . '<p class="muted">Der Wahlausschuss legt hier selbst fest, wer von euch beiden Zugang zum eigenständigen Wahlserver bekommt (eigenes Login dort, unabhängig von MISS). Die ersten zwei, die sich ankreuzen, sind die Gewählten - ihr könnt euch jederzeit selbst abwählen, dann muss jemand anderes übernehmen.</p>';
    echo '<table><thead><tr><th>Wahlausschuss-Mitglied</th><th>Externer Zugang</th><th>Benutzername</th></tr></thead><tbody>';
    foreach ($committee as $c) {
        echo '<tr><td>' . h($c['first_name'] . ' ' . $c['last_name']) . ((int)$c['member_id'] === (int)$me['member_id'] ? ' <span class="muted">(ich)</span>' : '') . '</td>'
            . '<td>' . ($c['is_external'] ? 'ja' : 'nein') . '</td><td>' . h($c['external_username'] ?? '') . '</td></tr>';
    }
    echo '</tbody></table>';
    if ($myWa) {
        if ($myWa['is_external']) {
            echo '<form method="post" onsubmit="return confirm(\'Wirklich abwählen? Der Zugang zum Wahlserver wird dann sofort entfernt.\')"><input type="hidden" name="aktion" value="committee_unset">' . md_csrf_field()
                . '<button>Mich abwählen (Zugang entfernen)</button></form>'
                . '<p class="muted">Benutzername/Passwort ändern: unten einfach erneut eintragen und speichern.</p>';
        }
        echo '<form method="post"><input type="hidden" name="aktion" value="committee_set">' . md_csrf_field()
            . '<label class="block">Benutzername für den Wahlserver<input name="username" value="' . h($myWa['external_username'] ?? '') . '" maxlength="100" required></label>'
            . '<label class="block">Passwort' . md_password_rules_html() . '<input type="password" name="password" autocomplete="new-password" required></label>'
            . '<button>' . ($myWa['is_external'] ? 'Aktualisieren' : 'Mich als einen der beiden externen Zugänge festlegen') . '</button></form>';
    }
    echo '</section>';
}

if ($edit) {
    echo '<section class="card"><h2>Neue Wahl anlegen</h2><form method="post">' . md_csrf_field() . '<input type="hidden" name="aktion" value="create">'
        . '<label class="block">Bezeichnung<input name="title" placeholder="z. B. Wahl des Vorstands und der Kassenprüfer" required style="width:100%" maxlength="200"></label>'
        . '<div class="addr"><label>Start<input type="datetime-local" name="start_at" required></label><label>Ende<input type="datetime-local" name="end_at" required></label></div>'
        . '<button>Anlegen</button></form></section>';

    $tconf = md_wabs_transfer_conf();
    echo '<section class="card"><h2>Wahlserver-Verbindung</h2><p class="muted">Gilt für alle Wahlen. Bei gleichem Server/gleicher Datenbank-Instanz: direkte Übertragung. Bei einem externen Server: CSV-Export zum manuellen Import dort.</p>'
        . '<form method="post">' . md_csrf_field() . '<input type="hidden" name="aktion" value="save_transfer_conf">'
        . '<label class="check"><input type="radio" name="mode" value="direct"' . ($tconf['mode'] === 'direct' ? ' checked' : '') . '> Direkte Übertragung (gleicher Server/gleiche Datenbank)</label> '
        . '<label class="check"><input type="radio" name="mode" value="csv"' . ($tconf['mode'] !== 'direct' ? ' checked' : '') . '> CSV-Export (externer Server)</label>'
        . '<div class="addr"><label>Datenbankserver<input name="host" value="' . h($tconf['host']) . '" placeholder="z. B. localhost"></label><label>Port<input name="port" value="' . h($tconf['port']) . '" placeholder="3306"></label></div>'
        . '<div class="addr"><label>Datenbankname<input name="dbname" value="' . h($tconf['name']) . '"></label><label>Benutzer<input name="dbuser" value="' . h($tconf['user']) . '"></label></div>'
        . '<label class="block">Passwort <span class="muted">(leer lassen, um ein bereits gespeichertes Passwort unverändert zu lassen)</span><input type="password" name="dbpass" autocomplete="new-password"></label>'
        . '<button>Speichern</button></form></section>';
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
        . '<tr><td>Bereits versendet/zugestellt</td><td class="r">' . (int)$sum['sent'] . '</td></tr>'
        . '<tr><td>Fehlgeschlagen (nur E-Mail)</td><td class="r">' . (int)$sum['failed'] . '</td></tr>'
        . '<tr><td>In der Warteschlange</td><td class="r">' . (int)$sum['queue_rows'] . '</td></tr>'
        . '<tr><td>Noch nicht berücksichtigt, mit E-Mail-Adresse</td><td class="r">' . (int)$sum['offen_mit_mail'] . '</td></tr>'
        . '<tr><td>Noch nicht berücksichtigt, ohne E-Mail (mit Postanschrift, für PDF-Sammeldruck)</td><td class="r">' . (int)$sum['offen_ohne_mail'] . '</td></tr>'
        . ($sum['unreachable'] > 0 ? '<tr><td>Weder E-Mail noch Postanschrift hinterlegt – nicht erreichbar</td><td class="r">' . (int)$sum['unreachable'] . '</td></tr>' : '')
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
        if (!empty($sel['pdf_file']) && empty($sel['pdf_deleted_at'])) {
            echo '<p>PDF-Sammeldruck vom ' . h(date('d.m.Y H:i', strtotime($sel['pdf_generated_at']))) . ': <a href="admin/wabs_pdf.php?election=' . (int)$sel['election_id'] . '">herunterladen/drucken</a></p>'
                . '<form method="post" onsubmit="return confirm(\'Erst bestätigen, wenn der Sammeldruck tatsächlich gedruckt und verschickt wurde - die Datei wird dabei endgültig von der Festplatte entfernt.\')"><input type="hidden" name="aktion" value="pdf_delete"><input type="hidden" name="election_id" value="' . (int)$sel['election_id'] . '">' . md_csrf_field()
                . '<button>Gedruckt und verschickt – PDF-Datei jetzt löschen</button></form>';
        } elseif ($sum['offen_ohne_mail'] > 0) {
            echo '<form method="post" onsubmit="return confirm(\'Für ' . (int)$sum['offen_ohne_mail'] . ' Mitglied(er) je ein neues Token erzeugen und eine Seite im PDF-Sammeldruck anlegen?\')"><input type="hidden" name="aktion" value="generate_pdf"><input type="hidden" name="election_id" value="' . (int)$sel['election_id'] . '">' . md_csrf_field()
                . '<button>PDF-Sammeldruck für ' . (int)$sum['offen_ohne_mail'] . ' Mitglied(er) erzeugen</button></form>';
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

    $tokenCount = md_wabs_tokens_count((int)$sel['election_id']);
    echo '<h3>Übertragung zum Wahlserver</h3>';
    if (!empty($sel['transferred_at'])) {
        echo '<p>Übertragen am ' . h(date('d.m.Y H:i', strtotime($sel['transferred_at']))) . ' (' . h($sel['transfer_mode'] === 'direct' ? 'direkt in die Wahlserver-Datenbank' : 'per CSV-Export') . '). Die Token-Liste ist in MISS gelöscht.</p>';
    } elseif ($tokenCount === 0) {
        echo '<p class="muted">Noch keine Token vorhanden - erst Wahlaufforderungen per E-Mail/PDF erzeugen.</p>';
    } else {
        echo '<p>' . $tokenCount . ' gültige(s) Token bereit zur Übertragung.</p>';
        if ($edit) {
            if ($tconf['mode'] === 'direct') {
                if ($tconf['host'] === '' || $tconf['name'] === '' || $tconf['user'] === '') {
                    echo '<p class="muted">Bitte zuerst oben die Wahlserver-Verbindung vollständig eintragen.</p>';
                } else {
                    echo '<form method="post" onsubmit="return confirm(\'' . $tokenCount . ' Token direkt in die Wahlserver-Datenbank übertragen? Die Liste wird danach sofort aus MISS gelöscht.\')"><input type="hidden" name="aktion" value="transfer_direct"><input type="hidden" name="election_id" value="' . (int)$sel['election_id'] . '">' . md_csrf_field()
                        . '<button>' . $tokenCount . ' Token direkt übertragen</button></form>';
                }
            } elseif (!empty($sel['csv_file']) && empty($sel['csv_deleted_at'])) {
                echo '<p>Token-CSV vom ' . h(date('d.m.Y H:i', strtotime($sel['csv_generated_at']))) . ': <a href="admin/wabs_csv.php?election=' . (int)$sel['election_id'] . '">herunterladen</a></p>'
                    . '<form method="post" onsubmit="return confirm(\'Erst bestätigen, wenn die CSV-Datei tatsächlich auf dem externen Wahlserver importiert wurde - die Datei wird dabei endgültig von der Festplatte entfernt und die Token-Liste aus MISS gelöscht.\')"><input type="hidden" name="aktion" value="csv_confirm"><input type="hidden" name="election_id" value="' . (int)$sel['election_id'] . '">' . md_csrf_field()
                    . '<button>Importiert – CSV-Datei jetzt löschen</button></form>';
            } else {
                echo '<form method="post"><input type="hidden" name="aktion" value="csv_generate"><input type="hidden" name="election_id" value="' . (int)$sel['election_id'] . '">' . md_csrf_field()
                    . '<button>Token-CSV erzeugen</button></form>';
            }
        }
    }
    echo '</section>';
}
md_footer();
