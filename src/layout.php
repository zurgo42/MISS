<?php
declare(strict_types=1);

function md_is_test_instance(): bool
{
    return defined('MD_TEST_INSTANCE') && MD_TEST_INSTANCE === true;
}

/** Sonderfunktionen nach Rechten, gruppiert: Gruppe => [Schlüssel => [Adresse, Beschriftung]]. Leere Gruppen entfallen. */
function md_special_groups(?array $me): array
{
    if (!$me || !md_consent_ok((int)$me['member_id'])) return [];
    $can = fn(string $a, string $l = 'view') => md_can($me, $a, $l);
    $open = $can('members') ? md_apply_open_count() : 0;
    $g = [
        'Mitglieder' => array_filter([
            'admin' => $can('members') ? ['admin/index.php', 'Mitgliederliste'] : null,
            'apply' => $can('members') ? ['admin/applications.php', 'Anmeldungen' . ($open ? " ($open)" : '')] : null,
            'import' => $can('import', 'edit') ? ['admin/import.php', 'CSV-Import'] : null,
            'terms' => $can('terms') ? ['admin/terms.php', 'Nutzungsbedingungen'] : null,
        ]),
        'Finanzen' => array_filter([
            'finance' => $can('finance') ? ['admin/finance.php', 'Kasse'] : null,
            'fees' => $can('fees') ? ['admin/fees.php', 'Beiträge'] : null,
            'dunning' => $can('fees') ? ['admin/dunning.php', 'Mahnwesen'] : null,
            'sepa' => $can('sepa') ? ['admin/sepa.php', 'Lastschrift'] : null,
            'datev' => $can('finance') ? ['admin/datev.php', 'DATEV-Import'] : null,
            'receipts' => $can('receipts') ? ['admin/receipts.php', 'Spendenbescheinigungen'] : null,
        ]),
        'Kommunikation' => array_filter([
            'mail' => $can('mail') ? ['admin/mail.php', 'E-Mail-Ausgang'] : null,
            'lists' => $can('mail') ? ['admin/lists.php', 'Verteiler'] : null,
        ]),
        'System' => array_filter([
            'privacy' => $can('privacy') ? ['admin/privacy.php', 'Datenschutz'] : null,
            'settings' => $can('settings', 'edit') ? ['admin/settings.php', 'Einstellungen'] : null,
        ]),
    ];
    return array_filter($g);
}

/** Logo des Vereins: hochgeladene Datei (Einstellungen) oder ein schlichtes Standardzeichen mit Initialen. */
function md_logo_html(): string
{
    return '<img class="logo" src="logo.php?v=' . h(md_setting('club.logo_file', '') ?: 'default') . '" alt="" width="36" height="36">';
}

function md_header(string $title, ?array $me = null, string $active = ''): void
{
    $nav = $me ? ['dashboard' => ['index.php', 'Mein Dashboard']] : [];
    $consent = $me && md_consent_ok((int)$me['member_id']);
    if ($me) $nav['events'] = ['events.php', 'Veranstaltungen'];
    if ($consent && md_can($me, 'directory', 'view')) $nav['directory'] = ['directory.php', 'Mitgliederverzeichnis'];
    $sub = [];
    foreach (md_special_groups($me) as $gname => $items) if (isset($items[$active])) { $sub = $items; $sub_name = $gname; break; }
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><base href="' . h(md_url()) . '">';
    echo '<title>' . h($title) . ' – ' . h(MD_CLUB_NAME) . '</title><link rel="stylesheet" href="assets/md.css"></head><body>';
    echo '<header class="top"><div class="wrap"><a class="brand" href="index.php">' . md_logo_html() . '<span class="bname">' . h(MD_CLUB_NAME) . '</span></a><nav>';
    foreach ($nav as $k => [$url, $label]) echo '<a href="' . $url . '"' . ($k === $active ? ' class="on"' : '') . '>' . h($label) . '</a>';
    echo '</nav>';
    if ($me) echo '<span class="who">' . h($me['first_name'] . ' ' . $me['last_name']) . ' · <a href="account.php">Mein Zugang</a> · <a href="logout.php">Abmelden</a></span>';
    echo '</div></header>';
    if ($sub) {
        echo '<div class="subnav"><div class="wrap"><a class="btn small ghost" href="index.php#sonder">← Dashboard</a> <strong class="subname">' . h($sub_name) . ':</strong>';
        foreach ($sub as $k => [$url, $label]) echo '<a class="btn small' . ($k === $active ? ' on' : '') . '" href="' . h($url) . '">' . h($label) . '</a>';
        echo '</div></div>';
    }
    echo '<main class="wrap">';
    if (MD_DEMO_MODE) echo '<div class="demo">Demo-Modus mit erfundenen Daten.</div>';
    elseif (md_is_test_instance()) echo '<div class="demo"><strong>Testsystem</strong> – alle Daten sind erfunden. Bitte keine echten personenbezogenen Daten eingeben.</div>';
}

function md_footer(): void
{
    echo '<script>' . md_page_js() . '</script></main></body></html>';
}

/** Kleines Skript: Akkordeon per Ankerlink öffnen, Passwort ein-/ausblenden, Dateiauswahl sofort absenden. */
function md_page_js(): string
{
    return <<<'JS'
(function(){
  function openHash(){var h=location.hash.slice(1);if(!h)return;var e=document.getElementById(h);while(e){if(e.tagName==='DETAILS')e.open=true;e=e.parentElement;}var t=document.getElementById(h);if(t)t.scrollIntoView();}
  openHash();window.addEventListener('hashchange',openHash);
  document.querySelectorAll('.pw-eye').forEach(function(b){b.addEventListener('click',function(){var i=b.parentElement.querySelector('input');var show=i.type==='password';i.type=show?'text':'password';b.setAttribute('aria-pressed',show);b.title=show?'Passwort verbergen':'Passwort anzeigen';b.classList.toggle('on',show);});});
  document.querySelectorAll('input[data-autosubmit]').forEach(function(i){i.addEventListener('change',function(){if(i.form)i.form.submit();});});
})();
JS;
}

function md_flash(?string $msg = null, string $kind = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['md_flash'] = [$msg, $kind];
        return null;
    }
    $f = $_SESSION['md_flash'] ?? null;
    unset($_SESSION['md_flash']);
    return $f;
}

function md_show_flash(): void
{
    if ($f = md_flash()) echo '<div class="flash ' . h($f[1]) . '">' . h($f[0]) . '</div>';
}

/** Sichtbarkeit im Mitgliederverzeichnis: ja/nein (Verwaltung sieht ohnehin alles). */
function md_vis_toggle(string $key, array $vmap, string $label = 'im Verzeichnis sichtbar', bool $auto = false): string
{
    $name = 'vis_' . str_replace('.', '__', $key);
    return '<label class="vis" title="Andere Mitglieder sehen diese Angabe im Mitgliederverzeichnis"><input type="hidden" name="' . h($name) . '" value="me"><input type="checkbox" name="' . h($name) . '" value="all"'
        . (($vmap[$key] ?? 'me') === 'all' ? ' checked' : '') . ($auto ? ' onchange="this.form.submit()"' : '') . '> ' . h($label) . '</label>';
}

/** Passwortfeld mit Auge-Symbol zum Anzeigen. */
function md_password_field(string $name, string $label, string $autocomplete, bool $autofocus = false): string
{
    return '<label class="block">' . h($label) . '<span class="pwwrap"><input type="password" name="' . h($name) . '" autocomplete="' . h($autocomplete) . '" required' . ($autofocus ? ' autofocus' : '') . '>'
        . '<button type="button" class="pw-eye" title="Passwort anzeigen" aria-label="Passwort anzeigen" aria-pressed="false"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/><path class="slash" d="M3 3l18 18"/></svg></button></span></label>';
}

/** Alte Auswahl (nur noch für die Einstellungen der Standard-Sichtbarkeit). */
function md_vis_select(string $key, array $vmap): string
{
    $name = 'vis_' . str_replace('.', '__', $key);
    $out = '<select class="vis" name="' . h($name) . '" title="Wer darf das sehen?">';
    foreach (MD_LEVELS as $lvl => $label) {
        $out .= '<option value="' . $lvl . '"' . (($vmap[$key] ?? 'me') === $lvl ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $out . '</select>';
}

function md_initials(array $m): string
{
    return mb_strtoupper(mb_substr($m['first_name'], 0, 1) . mb_substr($m['last_name'], 0, 1));
}

/** Gemeinsames Druck-Stylesheet für Rechnungen und Mahnschreiben (A4, ein Schreiben je Seite). */
function md_doc_css(): string
{
    return 'body{font:11pt/1.45 "Helvetica Neue",Arial,sans-serif;color:#000;background:#eee;margin:0}.bar{background:#005596;color:#fff;padding:10px 20px;font:14px system-ui,sans-serif;display:flex;gap:16px;align-items:center}'
        . '.bar button{font:inherit;padding:6px 14px;cursor:pointer}.bar a{color:#8ECAF6}.doc{position:relative;background:#fff;width:190mm;min-height:270mm;margin:12px auto;padding:15mm 18mm;box-sizing:border-box;page-break-after:always}'
        . '.doc:last-child{page-break-after:auto}.sender{font-size:8pt;border-bottom:1px solid #999;padding-bottom:1mm;margin:0 0 6mm;width:85mm}.rcp{margin:0 0 10mm;min-height:25mm}'
        . '.doc h2{font-size:14pt;margin:8mm 0 3mm}table.meta{margin:0 0 6mm auto;border-collapse:collapse}table.meta td{padding:0.5mm 3mm;font-size:10pt}table.meta td:first-child{color:#555}'
        . 'table.list{width:100%;border-collapse:collapse;margin:3mm 0 5mm;font-size:10.5pt}table.list th,table.list td{border-bottom:1px solid #999;padding:1.5mm 2mm;text-align:left}table.list th.r,table.list td.r{text-align:right}'
        . '.small{font-size:9pt;color:#444}.sign{margin-top:10mm}.mark{position:absolute;top:12mm;right:15mm;border:2px solid #b00;color:#b00;font:bold 13pt system-ui,sans-serif;padding:1mm 4mm;transform:rotate(-4deg)}'
        . '.err{background:#fff;border:2px solid #b00;padding:10px 14px;margin:12px auto;width:190mm;box-sizing:border-box;font:14px system-ui,sans-serif;color:#900}'
        . '@media print{body{background:#fff}.bar,.err{display:none}.doc{margin:0;width:auto;min-height:0;padding:0}@page{size:A4;margin:18mm}}';
}
