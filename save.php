<?php
require_once __DIR__ . '/src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$actor = md_require_login();
md_csrf_check();
$section = (string)($_POST['section'] ?? '');
// Ohne Zustimmung zu den Nutzungsbedingungen kann man nichts ändern (außer eben zustimmen bzw. widerrufen)
if (!in_array($section, ['consent', 'consent_revoke', 'privacy_request', 'directory_consent'], true) && !md_consent_ok((int)$actor['member_id'])) {
    md_flash('Ändern ist erst nach der Zustimmung zu den Nutzungsbedingungen möglich.', 'warn');
    header('Location: ' . md_url('index.php#terms'));
    exit;
}
[$target, $via] = md_target_member($actor, $_POST['member'] ?? 0, $section === 'fee_assign' ? 'view' : 'edit');
$mid = (int)$target['member_id'];
$aid = (int)$actor['member_id'];
$msg = 'Gespeichert.';
$kind = 'ok';
$pdo = md_db();
$pdo->beginTransaction();
try {
    switch ($section) {
        case 'profile':
            $fields = array_keys(array_filter(MD_PROFILE_FIELDS, fn($f) => $f[2] === ($_POST['group'] ?? '')));
            $n = md_save_profile($mid, $_POST, $aid, $via, $fields);
            $n += md_save_visibility($mid, $_POST, $aid, $via);
            if (($_POST['group'] ?? '') === 'Persönliche Daten' && isset($_POST['email'])) {
                [$changed, $eerr] = md_change_email($mid, (string)$_POST['email'], $aid, $via);
                if ($eerr) { $msg = $eerr; $kind = 'err'; } else $n += $changed;
            }
            if ($kind !== 'err') $msg = $n ? "$n Änderung(en) gespeichert." : 'Keine Änderungen.';
            break;
        case 'all':
            // Alle Kacheln mit gemeinsamem Speichern-Knopf: Profil (3 Gruppen), E-Mail, Adressen, Kontakt, Sichtbarkeiten
            $fields = array_keys(array_filter(MD_PROFILE_FIELDS, fn($f) => in_array($f[2], ['Persönliche Daten', 'Beruf & Interessen', 'Vorstellung'], true)));
            $n = md_save_profile($mid, $_POST, $aid, $via, $fields);
            $n += md_save_addresses($mid, $_POST, $aid, $via) + md_save_contacts($mid, $_POST, $aid, $via) + md_save_visibility($mid, $_POST, $aid, $via);
            if (isset($_POST['email'])) {
                [$changed, $eerr] = md_change_email($mid, (string)$_POST['email'], $aid, $via);
                if ($eerr) { $msg = $eerr; $kind = 'err'; } else $n += $changed;
            }
            // Einwilligung festhalten (Zeitpunkt im Änderungsprotokoll), solange der Wortlaut derselbe ist, nur einmal
            if ($via === 'self' && $kind !== 'err') {
                if (!md_directory_consent_given($mid)) md_log_change($mid, 'consent.directory', null, md_directory_consent_version(), $aid, 'self');
            }
            if ($kind !== 'err') $msg = $n ? "$n Änderung(en) gespeichert." : 'Keine Änderungen.';
            break;
        case 'directory_consent':
            // Einwilligung über den Hinweis auf dem Dashboard, ohne Daten zu ändern
            if ($via !== 'self') { http_response_code(403); exit('Nur für die eigenen Daten.'); }
            if (!md_directory_consent_given($mid)) md_log_change($mid, 'consent.directory', null, md_directory_consent_version(), $aid, 'self');
            $msg = 'Danke, deine Einwilligung ist dokumentiert.';
            break;
        case 'addresses':
            $n = md_save_addresses($mid, $_POST, $aid, $via) + md_save_visibility($mid, $_POST, $aid, $via);
            $msg = $n ? "$n Änderung(en) gespeichert." : 'Keine Änderungen.';
            break;
        case 'contacts':
            $n = md_save_contacts($mid, $_POST, $aid, $via) + md_save_visibility($mid, $_POST, $aid, $via);
            $msg = $n ? "$n Änderung(en) gespeichert." : 'Keine Änderungen.';
            break;
        case 'photo_upload':
            $err = md_save_photo($mid, (int)($_POST['slot'] ?? 0), $_FILES['photo'] ?? [], trim((string)($_POST['caption'] ?? '')) ?: null, $aid, $via);
            md_save_visibility($mid, $_POST, $aid, $via);
            if ($err) { $msg = $err; $kind = 'err'; } else { $msg = 'Foto gespeichert.'; }
            break;
        case 'photo_vis':
            md_save_visibility($mid, $_POST, $aid, $via);
            break;
        case 'photo_delete':
            md_delete_photo($mid, (int)($_POST['slot'] ?? 0), $aid, $via);
            $msg = 'Foto gelöscht.';
            break;
        case 'consent':
            $cur = md_current_terms();
            if ($cur && (int)$cur['terms_id'] === (int)($_POST['terms_id'] ?? 0) && !empty($_POST['agree'])) {
                if ($via === 'admin') { md_flash('Zustimmung bitte unter Verwaltung → Nutzungsbedingungen erfassen.', 'err'); $pdo->rollBack(); header('Location: ' . md_url('admin/terms.php')); exit; }
                md_record_consent($mid, (int)$cur['terms_id'], 'self', $aid, $_SERVER['REMOTE_ADDR'] ?? null);
                $msg = 'Zustimmung dokumentiert.';
            } else { $msg = 'Bitte das Häkchen setzen.'; $kind = 'err'; }
            break;
        case 'fee_assign':
            if ($via !== 'admin' || !md_can($actor, 'fees', 'edit')) { http_response_code(403); exit('Dafür fehlt die Berechtigung.'); }
            $err = md_fee_assign($mid, (int)($_POST['group_id'] ?? 0), $_POST, $aid);
            $msg = $err ?: 'Beitragszuordnung gespeichert.';
            $kind = $err ? 'err' : 'ok';
            break;
        case 'privacy_request':
            // Auskunft und Löschantrag sind Rechte des Mitglieds und gelten auch ohne Zustimmung zu den Nutzungsbedingungen
            if ($via !== 'self') { http_response_code(403); exit('Nur für die eigenen Daten.'); }
            [$rid, $rerr] = md_privacy_request_add($mid, 'erasure', 'self', $aid, trim((string)($_POST['note'] ?? '')) ?: null);
            $msg = $rerr ?: 'Dein Löschantrag ist eingegangen. Die Verwaltung antwortet spätestens innerhalb eines Monats.';
            $kind = $rerr ? 'err' : 'ok';
            break;
        case 'consent_revoke':
            md_revoke_consent($mid, (int)($_POST['consent_id'] ?? 0), $aid);
            $msg = 'Zustimmung widerrufen.';
            break;
        case 'invite':
            if ($via !== 'admin' || !md_can($actor, 'mail', 'edit')) { http_response_code(403); exit('Nur für die Verwaltung.'); }
            $ierr = md_login_send_link($mid, empty($target['password_hash']) ? 'invite' : 'reset', $aid);
            $msg = $ierr ?: 'Der Link wurde per E-Mail gesendet.';
            $kind = $ierr ? 'err' : 'ok';
            break;
        case 'club':
        case 'group_add':
        case 'group_remove':
        case 'func_add':
        case 'func_remove':
            if ($via !== 'admin') { http_response_code(403); exit('Nur für die Verwaltung.'); }
            require __DIR__ . '/src/save_club.php';
            break;
        default:
            $msg = 'Unbekannte Aktion.';
            $kind = 'err';
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('md save: ' . $e->getMessage());
    $msg = 'Speichern fehlgeschlagen.';
    $kind = 'err';
}
md_flash($msg, $kind);
$back = md_url('index.php') . ($via === 'admin' ? '?member=' . $mid : '');
header('Location: ' . $back . '#' . preg_replace('/[^a-z_]/', '', (string)($_POST['anchor'] ?? '')));
