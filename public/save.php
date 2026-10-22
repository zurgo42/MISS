<?php
require_once __DIR__ . '/../src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$actor = md_require_login();
md_csrf_check();
[$target, $via] = md_target_member($actor, $_POST['member'] ?? 0);
$mid = (int)$target['member_id'];
$aid = (int)$actor['member_id'];
$section = (string)($_POST['section'] ?? '');
$msg = 'Gespeichert.';
$kind = 'ok';
$pdo = md_db();
$pdo->beginTransaction();
try {
    switch ($section) {
        case 'profile':
            $n = md_save_profile($mid, $_POST, $aid, $via, array_keys(array_filter(MD_PROFILE_FIELDS, fn($f) => $f[2] === ($_POST['group'] ?? ''))));
            $n += md_save_visibility($mid, $_POST, $aid, $via);
            $msg = $n ? "$n Änderung(en) gespeichert." : 'Keine Änderungen.';
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
                if ($via === 'admin') { md_flash('Zustimmung bitte unter Verwaltung → Nutzungsbedingungen erfassen.', 'err'); $pdo->rollBack(); header('Location: /admin/terms.php'); exit; }
                md_record_consent($mid, (int)$cur['terms_id'], 'self', $aid, $_SERVER['REMOTE_ADDR'] ?? null);
                $msg = 'Zustimmung dokumentiert.';
            } else { $msg = 'Bitte das Häkchen setzen.'; $kind = 'err'; }
            break;
        case 'consent_revoke':
            md_revoke_consent($mid, (int)($_POST['consent_id'] ?? 0), $aid);
            $msg = 'Zustimmung widerrufen.';
            break;
        case 'club':
        case 'group_add':
        case 'group_remove':
        case 'func_add':
        case 'func_remove':
            if (!md_is_admin($actor)) { http_response_code(403); exit('Nur Verwaltung.'); }
            require __DIR__ . '/../src/save_club.php';
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
$back = '/index.php' . ($via === 'admin' ? '?member=' . $mid : '');
header('Location: ' . $back . '#' . preg_replace('/[^a-z_]/', '', (string)($_POST['anchor'] ?? '')));
