<?php
declare(strict_types=1);

/**
 * Angaben des Vereins, die in jedem Verein anders lauten (Administration): Name, Anschrift, Kontakt, Impressum, Datenschutzerklärung, Vereinskonten.
 * Gespeichert in md_settings (org.*) und md_bank_accounts. Die Angaben für Spendenbescheinigungen (md_club, club.*) sind davon unabhängig:
 * Der Spendenempfänger kann ein anderer Rechtsträger sein (z. B. gemeinnütziger Unterverein).
 */

const MD_ORG_FIELDS = [   // Schlüssel => [Bezeichnung, Pflicht für Schreiben, Hinweis]
    'name'        => ['Name des Vereins (Titelzeile, Rechnungen, Schreiben)', true, 'z. B. Musterverein e. V.'],
    'street'      => ['Straße, Nr.', true, ''],
    'zip'         => ['PLZ', true, ''],
    'city'        => ['Ort', true, ''],
    'place'       => ['Ort für Unterschriften', false, 'sonst der Ort der Anschrift'],
    'signer'      => ['Unterzeichner von Rechnungen und Mahnungen', false, 'z. B. Max Mustermann, Finanzvorstand'],
    'email'       => ['E-Mail des Vereins', false, ''],
    'phone'       => ['Telefon', false, ''],
    'register'    => ['Vereinsregister (Impressum)', false, 'z. B. Amtsgericht Musterstadt, VR 12345'],
    'represented' => ['Vertretungsberechtigter Vorstand (Impressum)', false, 'Namen der Vorstandsmitglieder'],
    'responsible' => ['Inhaltlich verantwortlich (Impressum)', false, 'Name und Anschrift, falls abweichend'],
];

function md_org(): array
{
    $club = md_club();   // Rückfall für ältere Installationen: dort standen Name und Anschrift unter club.*
    $o = [];
    foreach (MD_ORG_FIELDS as $k => $_) {
        $o[$k] = md_setting('org.' . $k, '');
        if ($o[$k] === '' && in_array($k, ['name', 'street', 'zip', 'city', 'place', 'signer'], true)) $o[$k] = (string)($club[$k] ?? '');
    }
    if ($o['name'] === '' && defined('MD_CLUB_NAME')) $o['name'] = MD_CLUB_NAME;
    if ($o['place'] === '') $o['place'] = $o['city'];
    return $o;
}

function md_org_name(): string
{
    return md_org()['name'];
}

/** Vereinskonten. $use: 'invoice' oder 'donation' = das dafür bestimmte aktive Konto (oder null). Ohne Angabe alle aktiven. */
function md_bank_accounts(?string $use = null): array
{
    try {
        if ($use === null) return md_q('SELECT * FROM md_bank_accounts WHERE active = 1 ORDER BY account_id');
        $col = $use === 'donation' ? 'use_donation' : 'use_invoice';
        $r = md_one("SELECT * FROM md_bank_accounts WHERE active = 1 AND $col = 1 ORDER BY account_id LIMIT 1");
        return $r ? [$r] : [];
    } catch (Throwable $e) {
        return [];
    }
}

function md_bank_account(string $use): ?array
{
    return md_bank_accounts($use)[0] ?? null;
}

function md_bank_account_save(?int $id, array $d): string
{
    $label = trim((string)($d['label'] ?? ''));
    $iban = strtoupper(preg_replace('/\s+/', '', (string)($d['iban'] ?? '')));
    $bic = strtoupper(trim((string)($d['bic'] ?? '')));
    if ($label === '') return 'Bezeichnung fehlt.';
    if (!md_iban_valid($iban)) return 'IBAN ungültig.';
    if ($bic !== '' && !md_bic_valid($bic)) return 'BIC ungültig.';
    $holder = trim((string)($d['holder'] ?? '')) ?: null;
    $bank = trim((string)($d['bank'] ?? '')) ?: null;
    $inv = !empty($d['use_invoice']) ? 1 : 0;
    $don = !empty($d['use_donation']) ? 1 : 0;
    $act = !empty($d['active']) ? 1 : 0;
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if ($id) {
            md_exec('UPDATE md_bank_accounts SET label = ?, holder = ?, iban = ?, bic = ?, bank = ?, use_invoice = ?, use_donation = ?, active = ? WHERE account_id = ?', [$label, $holder, $iban, $bic ?: null, $bank, $inv, $don, $act, $id]);
        } else {
            md_exec('INSERT INTO md_bank_accounts (label, holder, iban, bic, bank, use_invoice, use_donation, active) VALUES (?,?,?,?,?,?,?,?)', [$label, $holder, $iban, $bic ?: null, $bank, $inv, $don, 1]);
            $id = (int)$pdo->lastInsertId();
        }
        // je Verwendung nur ein Konto
        if ($inv) md_exec('UPDATE md_bank_accounts SET use_invoice = 0 WHERE account_id <> ?', [$id]);
        if ($don) md_exec('UPDATE md_bank_accounts SET use_donation = 0 WHERE account_id <> ?', [$id]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return $e->getMessage();
    }
    return '';
}

/** Impressum aus den Angaben der Administration (leer, wenn nichts hinterlegt ist). */
function md_impressum_text(): string
{
    $o = md_org();
    if (trim(md_setting('org.impressum_extra', '')) === '' && $o['register'] === '' && $o['represented'] === '' && $o['street'] === '') return '';
    $t = $o['name'] . "\n" . trim($o['street']) . "\n" . trim($o['zip'] . ' ' . $o['city']);
    if ($o['represented'] !== '') $t .= "\n\nVertretungsberechtigter Vorstand:\n" . $o['represented'];
    if ($o['register'] !== '') $t .= "\n\nVereinsregister:\n" . $o['register'];
    $contact = trim(($o['phone'] !== '' ? 'Telefon: ' . $o['phone'] . "\n" : '') . ($o['email'] !== '' ? 'E-Mail: ' . $o['email'] : ''));
    if ($contact !== '') $t .= "\n\nKontakt:\n" . $contact;
    if ($o['responsible'] !== '') $t .= "\n\nInhaltlich verantwortlich:\n" . $o['responsible'];
    if (trim(md_setting('org.impressum_extra', '')) !== '') $t .= "\n\n" . trim(md_setting('org.impressum_extra', ''));
    return trim($t);
}
