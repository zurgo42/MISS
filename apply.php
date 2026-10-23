<?php
require_once __DIR__ . '/src/bootstrap.php';
md_require_schema();
$club = md_club();
$name = $club['name'] ?: MD_CLUB_NAME;
$err = '';
$done = false;
$v = $_POST;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    [$ok, $err] = md_apply_submit($_POST, (string)($_SERVER['REMOTE_ADDR'] ?? 'cli'));
    $done = $ok;
}
md_header('Mitglied werden');
echo '<section class="card" style="max-width:40rem;margin:1.5rem auto"><h1>Mitglied werden bei ' . h($name) . '</h1>';
if (!md_apply_enabled()) {
    echo '<div class="flash warn">Die Online-Anmeldung ist zurzeit nicht freigeschaltet. Bitte melde dich beim Vorstand.</div></section>';
    md_footer();
    exit;
}
if ($done) {
    echo '<div class="flash ok">Danke! Wir haben dir eine E-Mail geschickt. Bitte klicke auf den Link darin, um deine Adresse zu bestätigen – erst dann geht dein Antrag an den Vorstand. Schau bei Bedarf auch im Spam-Ordner nach.</div></section>';
    md_footer();
    exit;
}
if ($intro = trim(md_setting('apply.intro_text', ''))) echo '<p>' . nl2br(h($intro)) . '</p>';
if ($err) echo '<div class="flash err">' . h($err) . '</div>';
$f = fn($k) => h($v[$k] ?? '');
echo '<form method="post" autocomplete="on">' . md_csrf_field() . '<input type="hidden" name="ft" value="' . h(md_apply_form_token()) . '">'
    . '<div style="position:absolute;left:-9999px" aria-hidden="true"><label>Bitte leer lassen<input name="website" tabindex="-1" autocomplete="off"></label></div>'
    . '<div class="addr"><label>Anrede<select name="salutation"><option value="">–</option><option' . (($v['salutation'] ?? '') === 'Frau' ? ' selected' : '') . '>Frau</option><option' . (($v['salutation'] ?? '') === 'Herr' ? ' selected' : '') . '>Herr</option></select></label>'
    . '<label>Vorname*<input name="first_name" value="' . $f('first_name') . '" required autocomplete="given-name"></label><label>Nachname*<input name="last_name" value="' . $f('last_name') . '" required autocomplete="family-name"></label></div>'
    . '<label class="block">E-Mail-Adresse*<input type="email" name="email" value="' . $f('email') . '" required autocomplete="email" style="width:100%"></label>'
    . '<div class="addr"><label>Straße und Nr.*<input name="street" value="' . $f('street') . '" required autocomplete="street-address"></label><label>PLZ*<input name="zip" size="6" value="' . $f('zip') . '" required autocomplete="postal-code"></label><label>Ort*<input name="city" value="' . $f('city') . '" required autocomplete="address-level2"></label></div>'
    . '<div class="addr"><label>Geburtsdatum (optional)<input name="birthdate" placeholder="TT.MM.JJJJ" size="12" value="' . $f('birthdate') . '"></label><label>Telefon (optional)<input name="phone" value="' . $f('phone') . '" autocomplete="tel"></label></div>';
$groups = md_apply_public_groups();
if ($groups) {
    echo '<label class="block">Mitgliedsart<select name="fee_group_id"><option value="">Bitte wählen</option>';
    foreach ($groups as $g) echo '<option value="' . (int)$g['group_id'] . '"' . ((int)($v['fee_group_id'] ?? 0) === (int)$g['group_id'] ? ' selected' : '') . '>' . h($g['name'] . ' – ' . md_money((int)$g['amount_cents']) . ' ' . (MD_RHYTHMS[$g['rhythm']] ?? '') . ($g['description'] ? ' (' . $g['description'] . ')' : '')) . '</option>';
    echo '</select></label>';
}
$pu = trim(md_setting('apply.privacy_url', ''));
$su = trim(md_setting('apply.statute_url', ''));
echo '<label class="block">Nachricht an den Vorstand (optional)<textarea name="message" rows="3" maxlength="1000" style="width:100%">' . $f('message') . '</textarea></label>'
    . '<label class="block"><input type="checkbox" name="ack_privacy" value="1"' . (!empty($v['ack_privacy']) ? ' checked' : '') . '> Ich habe die ' . ($pu ? '<a href="' . h($pu) . '" target="_blank" rel="noopener">Datenschutzhinweise</a>' : 'Datenschutzhinweise') . ' gelesen. Meine Angaben werden zur Prüfung des Antrags und, bei Aufnahme, zur Mitgliederverwaltung genutzt.*</label>'
    . '<label class="block"><input type="checkbox" name="ack_statute" value="1"' . (!empty($v['ack_statute']) ? ' checked' : '') . '> Ich habe die ' . ($su ? '<a href="' . h($su) . '" target="_blank" rel="noopener">Satzung</a>' : 'Satzung') . ' zur Kenntnis genommen und beantrage die Mitgliedschaft.*</label>'
    . '<p><button>Antrag absenden</button></p><p class="muted">* Pflichtangaben. Über die Aufnahme entscheidet der Vorstand. Unbestätigte Anträge werden nach 3 Tagen gelöscht.</p></form></section>';
md_footer();
