<?php
declare(strict_types=1);

/** Sichtbarkeitsstufen, aufsteigend nach Reichweite. */
const MD_LEVELS = [
    'me'    => 'Nur ich',
    'admin' => 'Vorstand/Verwaltung',
    'group' => 'Meine Teilgruppen',
    'all'   => 'Alle Mitglieder',
];

const MD_STATUS = [
    'interessent' => 'Interessent',
    'aktiv'       => 'Aktiv',
    'ruhend'      => 'Ruhend',
    'ausgetreten' => 'Ausgetreten',
    'ehrenmitglied' => 'Ehrenmitglied',
];

/** Persönliche Felder, die das Mitglied selbst pflegt. type: text|date|textarea|select */
const MD_PROFILE_FIELDS = [
    'salutation' => ['Anrede', 'select', 'Persönliche Daten', 'admin', ['', 'Frau', 'Herr', 'Divers']],
    'title'      => ['Titel', 'text', 'Persönliche Daten', 'admin'],
    'nickname'   => ['Spitzname / Rufname', 'text', 'Persönliche Daten', 'all'],
    'birthdate'  => ['Geburtsdatum', 'date', 'Persönliche Daten', 'me'],
    'relationship_status' => ['Beziehungsstand', 'select', 'Persönliche Daten', 'me',
        ['', 'ledig', 'in Beziehung', 'verheiratet', 'verwitwet', 'geschieden', 'keine Angabe']],
    'occupation' => ['Beruf', 'text', 'Beruf & Interessen', 'group'],
    'employer'   => ['Arbeitgeber', 'text', 'Beruf & Interessen', 'admin'],
    'hobbies'    => ['Hobbys', 'textarea', 'Beruf & Interessen', 'group'],
    'interests'  => ['Interessen', 'textarea', 'Beruf & Interessen', 'group'],
    'intro_text' => ['Das bin ich', 'textarea', 'Vorstellung', 'all'],
    'wishes_text' => ['Was ich mir im Verein wünsche', 'textarea', 'Vorstellung', 'admin'],
];

const MD_ADDRESS_FIELDS = ['label' => 'Bezeichnung', 'street' => 'Straße, Nr.', 'zip' => 'PLZ', 'city' => 'Ort', 'country' => 'Land'];

const MD_CONTACT_KINDS = [
    'email'     => 'E-Mail (privat)',
    'phone'     => 'Telefon',
    'mobile'    => 'Mobil',
    'fax'       => 'Fax',
    'website'   => 'Webseite',
    'facebook'  => 'Facebook',
    'instagram' => 'Instagram',
    'linkedin'  => 'LinkedIn',
    'xing'      => 'XING',
    'x'         => 'X (Twitter)',
    'mastodon'  => 'Mastodon',
    'youtube'   => 'YouTube',
    'tiktok'    => 'TikTok',
    'whatsapp'  => 'WhatsApp',
    'telegram'  => 'Telegram',
];

const MD_SLOTS = [1, 2, 3];

const MD_LEDGER_KINDS = [
    'beitrag'       => ['Mitgliedsbeitrag', 'to_club'],
    'eintritt'      => ['Eintritt Veranstaltung', 'to_club'],
    'spende'        => ['Spende', 'to_club'],
    'erstattung'    => ['Kostenerstattung', 'to_member'],
    'uebungsleiter' => ['Übungsleiterpauschale', 'to_member'],
];

/** Sichtbarkeits-Schlüssel mit Standardwert und Anzeigename. */
function md_visibility_keys(): array
{
    $keys = [];
    foreach (MD_PROFILE_FIELDS as $k => $f) $keys[$k] = [$f[0], $f[3]];
    foreach (MD_SLOTS as $s) $keys["address.$s"] = ["Adresse $s", $s === 1 ? 'admin' : 'me'];
    foreach (MD_SLOTS as $s) $keys["photo.$s"] = ["Foto $s", $s === 1 ? 'all' : 'group'];
    foreach (MD_CONTACT_KINDS as $k => $label) $keys["contact.$k"] = [$label, in_array($k, ['email', 'phone', 'mobile'], true) ? 'admin' : 'me'];
    return $keys;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function md_money(int $cents): string
{
    return number_format($cents / 100, 2, ',', '.') . ' €';
}

function md_date(?string $iso): string
{
    if (!$iso) return '';
    $t = strtotime($iso);
    return $t ? date('d.m.Y', $t) : $iso;
}

/** Datum aus d.m.Y oder Y-m-d in ISO; null bei leer/ungültig. */
function md_parse_date(?string $s): ?string
{
    $s = trim((string)$s);
    if ($s === '') return null;
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return "$m[1]-$m[2]-$m[3]";
    }
    return null;
}

/** Standard-Funktionen mit Level (nach PERMISSIONS.md der Sitzungsverwaltung). Reihenfolge = function_id bei der Installation. */
const MD_DEFAULT_FUNCTIONS = [
    ['Vo', 'Vorstandsmitglied', 19], ['FVo', 'Vorstand Finanzen', 19], ['FVv', 'Vorstand + Vertretung Finanzvorstand', 19],
    ['GF', 'Geschäftsführung', 18], ['VA', 'Vorstandsassistenz', 18], ['SV', 'Sekretariat Vorstand', 11],
    ['RL', 'Ressortleitung', 15], ['PL', 'Projektleitung', 12], ['MB', 'Mitgliederbetreuung', 9], ['Ka', 'Kassenführung', 9],
    ['FP', 'Finanzprüfung', 3], ['AD', 'Technischer Admin', 18], ['Vx', 'Ehemaliges Vorstandsmitglied', 0], ['JT', 'Organisator Jahrestreffen', 12],
    ['TM', 'Teamleitung', 11], ['Orga', 'Hilfsfunktion Antragstellung', 9], ['Rx', 'Ehemalige Ressortleitung', 0], ['Xx', 'Früher berechtigtes Mitglied', 0],
];
