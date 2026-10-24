<?php
/**
 * test_admin.php – prüft die Angaben der Administration: Vereinsangaben, Vereinskonten (Beitrags- und Spendenkonto), Funktionen und Teilgruppen an/abwählbar.
 * Aufruf: php tools/test_admin.php   (nach demo_setup.php bzw. demo_load.php). Läuft in einer Transaktion und rollt zurück.
 */
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__) . '/src/bootstrap.php';
if (!MD_DEMO_MODE) exit("nur mit Demo-Daten\n");
$fail = 0;
$check = function (string $name, $got, $want) use (&$fail) {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("%s %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $ok ? '' : ' – erwartet ' . json_encode($want, JSON_UNESCAPED_UNICODE) . ', war ' . json_encode($got, JSON_UNESCAPED_UNICODE));
};
md_db()->beginTransaction();
echo "-- Vereinsangaben\n";
$o = md_org();
$check('Demo-Angaben', [$o['name'], $o['city'], $o['signer']], ['Musterverein e. V.', 'Hannover', 'Max Mustermann, Finanzvorstand']);
md_exec("DELETE FROM md_settings WHERE setting_key = 'org.name'");
md_exec("INSERT INTO md_settings (setting_key, setting_value, description) VALUES ('org.name', 'Testclub', 't')");
$check('Vereinsname frei änderbar, Spendenempfänger unabhängig', [md_org_name(), md_club()['name'] !== 'Testclub'], ['Testclub', true]);
md_exec("DELETE FROM md_settings WHERE setting_key LIKE 'org.%'");
$check('Rückfall auf die frühere Angabe (club.*)', md_org_name(), md_club()['name']);
$check('Impressum aus Angaben', md_impressum_text() !== '', true);

echo "-- Vereinskonten\n";
$check('IBAN ungültig', md_bank_account_save(null, ['label' => 'x', 'iban' => 'DE00']), 'IBAN ungültig.');
$check('Bezeichnung fehlt', md_bank_account_save(null, ['label' => '', 'iban' => 'DE89370400440532013000']), 'Bezeichnung fehlt.');
$check('Demo: Beitragskonto und Spendenkonto', [md_bank_account('invoice')['label'] ?? null, md_bank_account('donation')['label'] ?? null], ['Girokonto', 'Spendenkonto']);
$check('Zahlungsempfänger des Spendenkontos weicht vom Verein ab', md_bank_account('donation')['holder'], 'Förderverein Musterverein e. V.');
$conf = md_fee_conf();
$check('Rechnungen nutzen das Beitragskonto', [$conf['iban'], $conf['holder']], ['DE02120300000000202051', 'Musterverein e. V.']);
$check('Lastschrift: Zahlungsempfänger und IBAN aus dem Beitragskonto', [md_sepa_conf()['name'], md_sepa_conf()['iban']], ['Musterverein e. V.', 'DE02120300000000202051']);
$check('neues Beitragskonto löst das alte ab', (function () { md_bank_account_save(null, ['label' => 'Neu', 'holder' => 'Neuer Name', 'iban' => 'DE02500105170137075030', 'use_invoice' => 1]); return [md_bank_account('invoice')['label'], md_fee_conf()['iban'], md_sepa_conf()['name'], md_bank_account('donation')['label']]; })(), ['Neu', 'DE02500105170137075030', 'Neuer Name', 'Spendenkonto']);
$id = (int)md_bank_account('invoice')['account_id'];
$check('Konto deaktivieren', [md_bank_account_save($id, ['label' => 'Neu', 'iban' => 'DE02500105170137075030', 'use_invoice' => 1, 'active' => 0]), md_bank_account('invoice')], ['', null]);
$check('ohne Beitragskonto Rückfall auf fees.* (leer = keine Angaben)', md_fee_conf()['holder'], '');

echo "-- Funktionen und Teilgruppen an/abwählbar\n";
$f = md_one("SELECT * FROM md_functions WHERE code = 'GF'");
md_exec('UPDATE md_functions SET active = 0, name = ? WHERE function_id = ?', ['Geschäftsleitung', $f['function_id']]);
$act = array_column(md_q('SELECT code FROM md_functions WHERE active = 1'), 'code');
$check('abgewählte Funktion nicht mehr in der Auswahl', [in_array('GF', $act, true), in_array('Vo', $act, true)], [false, true]);
$check('Bezeichnung änderbar', md_one('SELECT name FROM md_functions WHERE code = ?', ['GF'])['name'], 'Geschäftsleitung');
$julia = md_one("SELECT * FROM svmembers WHERE membership_number = 'DEMO0004'");
md_perm_cache(true);
$check('Inhaberin behält die Funktion und ihre Rechte', md_perm($julia, 'members'), 'edit');
$g = md_one('SELECT * FROM md_groups ORDER BY group_id LIMIT 1');
md_exec('UPDATE md_groups SET active = 0, name = ? WHERE group_id = ?', ['Umbenannt', $g['group_id']]);
$check('Teilgruppe abgewählt und umbenannt, Mitglieder bleiben', [in_array((int)$g['group_id'], array_map('intval', array_column(md_q('SELECT group_id FROM md_groups WHERE active = 1'), 'group_id')), true), (int)md_one('SELECT COUNT(*) c FROM md_member_groups WHERE group_id = ?', [$g['group_id']])['c'] > 0, md_load_groups(4)[0]['name'] ?? ''], [false, true, 'Umbenannt']);

md_db()->rollBack();
echo $fail ? "\n$fail Fehler\n" : "\nalles ok\n";
exit($fail ? 1 : 0);
