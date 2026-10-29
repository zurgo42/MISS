<?php
declare(strict_types=1);

function md_is_test_instance(): bool
{
    return defined('MD_TEST_INSTANCE') && filter_var(MD_TEST_INSTANCE, FILTER_VALIDATE_BOOLEAN);   // true, 1 oder "true" in config.php
}

/** Sonderfunktionen nach Rechten, gruppiert: Gruppe => [Schlüssel => [Adresse, Beschriftung]]. Leere Gruppen entfallen. */
function md_special_groups(?array $me): array
{
    if (!$me || !md_consent_ok((int)$me['member_id'])) return [];
    $can = fn(string $a, string $l = 'view') => md_can($me, $a, $l);
    $open = $can('members') ? md_apply_open_count() : 0;
    $g = [
        'Mitglieder' => array_filter([
            'admin' => $can('members') ? ['admin/index.php', 'Mitglieder verwalten'] : null,
            'apply' => $can('members') ? ['admin/applications.php', 'Mitglied werden' . ($open ? " ($open)" : '')] : null,
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
        'Wahl' => array_filter([
            'wabs' => $can('wahl') ? ['admin/wabs.php', 'Wahl und Abstimmung'] : null,
        ]),
        'System' => array_filter([
            'privacy' => $can('privacy') ? ['admin/privacy.php', 'Datenschutz'] : null,
            'settings' => $can('settings', 'edit') ? ['admin/settings.php', 'Administration'] : null,
        ]),
    ];
    return array_filter($g);
}

/** Logo des Vereins: hochgeladene Datei (Einstellungen) oder ein schlichtes Standardzeichen mit Initialen. */
function md_logo_html(): string
{
    return '<img class="logo" src="logo.php?v=' . h(md_setting('club.logo_file', '') ?: 'default') . '" alt="" width="36" height="36">';
}

function md_dark_mode(): bool
{
    return ($_COOKIE['darkMode'] ?? '') === 'enabled';
}

/** Kopfbereich bis einschließlich Reiterleisten und Beginn des Inhalts. Aufbau wie in der Sitzungsverwaltung. */
function md_header(string $title, ?array $me = null, string $active = ''): void
{
    $nav = [];
    $consent = $me && md_consent_ok((int)$me['member_id']);
    if ($me) { $nav['dashboard'] = ['index.php', 'Mein Dashboard']; $nav['events'] = ['events.php', 'Veranstaltungen']; }
    if ($consent && md_can($me, 'directory', 'view')) $nav['directory'] = ['directory.php', 'Mitgliederverzeichnis'];
    $sub = [];
    foreach (md_special_groups($me) as $gname => $items) if (isset($items[$active])) { $sub = $items; $sub_name = $gname; break; }
    $dark = md_dark_mode();
    $orgName = md_org_name();
    echo '<!DOCTYPE html><html lang="de"' . ($dark ? ' class="dark-mode"' : '') . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><base href="' . h(md_url()) . '">';
    echo '<script>(function(){if(document.cookie.indexOf("darkMode=")<0){try{if(localStorage.getItem("darkMode")==="enabled"){document.documentElement.className="dark-mode";document.cookie="darkMode=enabled;path=/;max-age=31536000";}}catch(e){}}})();</script>';
    echo '<title>' . h($title) . ' – ' . MD_APP_NAME . ' ' . h($orgName) . '</title><link rel="stylesheet" href="assets/md.css"></head><body' . ($dark ? ' class="dark-mode"' : '') . '>';
    $area = $nav[$active][1] ?? $title;
    echo '<div class="header"><div class="header-inner header-dreiteilig"><a class="header-brand" href="index.php" title="Zur Startseite">' . md_logo_html() . '<span class="brand"><span class="orgname">' . h($orgName) . '</span><span class="appname"><b>' . MD_APP_NAME . '</b> · ' . MD_APP_TAG . '</span></span></a>';
    echo '<div class="header-mitte"><h1>' . h($area) . '</h1></div><div class="user-info"><div class="user-name">';
    if ($me) {
        $role = (int)($me['is_admin'] ?? 0) ? 'Admin' : ucfirst((string)($me['role'] ?? 'Mitglied'));
        echo '<span class="user-id"><span>' . h($me['first_name'] . ' ' . $me['last_name']) . '</span><span class="role-badge">' . h($role) . '</span></span>';
    } elseif (MD_DEMO_MODE) echo '<span>Demo-Modus</span>';
    if ($me) echo '<span class="user-links"><a class="logout-btn" href="account.php">Mein Zugang</a><a class="logout-btn" href="logout.php">Abmelden</a></span>';
    echo '</div><button type="button" class="dark-mode-toggle" id="darkModeToggle" title="Dunkelmodus umschalten"><span class="icon">' . ($dark ? '☀️' : '🌙') . '</span></button>';
    echo '</div></div></div>';
    if ($nav) {
        echo '<nav class="tabs">';
        foreach ($nav as $k => [$url, $label]) echo '<a class="tab' . ($k === $active ? ' active' : '') . '" href="' . $url . '">' . h($label) . '</a>';
        echo '</nav>';
    }
    if ($sub) {
        echo '<nav class="tabs sub"><a class="btn small ghost" href="index.php#sonder">← Dashboard</a> <strong class="subname">' . h($sub_name) . ':</strong>';
        foreach ($sub as $k => [$url, $label]) echo '<a class="btn small' . ($k === $active ? ' on' : '') . '" href="' . h($url) . '">' . h($label) . '</a>';
        echo '</nav>';
    }
    echo '<main class="content">';
    if (MD_DEMO_MODE && $me) echo '<div class="demo">Demo-Modus mit erfundenen Daten.</div>';
    elseif (md_is_test_instance()) echo '<div class="demo"><strong>Testsystem</strong> – alle Daten sind erfunden. Bitte keine echten personenbezogenen Daten eingeben.</div>';
}

function md_footer(): void
{
    $o = md_org();
    $copy = md_footer_setting('footer.copyright') ?: '&copy; ' . h($o['name']);
    $links = '';
    $own = ['footer.impressum_url' => ['Impressum', 'impressum.php', md_impressum_text() !== ''], 'footer.privacy_url' => ['Datenschutz', 'datenschutz.php', trim(md_setting('org.privacy_text', '')) !== '']];
    foreach ($own as $k => [$label, $page, $has]) {
        $u = md_footer_setting($k);
        if ($u !== '' && preg_match('~^(https?://|/)~i', $u)) $links .= ' | <a href="' . h($u) . '" target="_blank" rel="noopener">' . $label . '</a>';
        elseif ($has) $links .= ' | <a href="' . $page . '" target="_blank" rel="noopener">' . $label . '</a>';
    }
    echo '</main><footer class="page-footer">' . $copy . $links . '</footer><script>' . md_page_js() . '</script></body></html>';
}

/** Kleines Skript: Bereiche per Ankerlink öffnen, Passwort ein-/ausblenden, Dateiauswahl sofort absenden. */
function md_page_js(): string
{
    return <<<'JS'
(function(){
  function openHash(){var h=location.hash.slice(1);if(!h)return;var e=document.getElementById(h);while(e){if(e.tagName==='DETAILS')e.open=true;e=e.parentElement;}var t=document.getElementById(h);if(t)t.scrollIntoView();}
  openHash();window.addEventListener('hashchange',openHash);
  document.querySelectorAll('ul.pwrules').forEach(function(ul){var f=ul.closest('form');var i=f&&f.querySelector('input[name=password]');if(!i)return;var min=parseInt(ul.dataset.min,10)||8;var weak=['passwort123','password123','1234567890','qwertzuiop','abcdefghij'];
    function chk(){var v=i.value,l=ul.querySelector('[data-rule=len]'),m=ul.querySelector('[data-rule=mix]');if(l)l.className=v===''?'':(v.length>=min?'ok':'bad');if(m)m.className=v===''?'':((/^(.)\1+$/.test(v)||weak.indexOf(v.toLowerCase())>=0)?'bad':'ok');}
    i.addEventListener('input',chk);chk();});
  document.querySelectorAll('main.content table').forEach(function(t){var r=t.rows[0];if(r&&r.cells.length>=4)t.classList.add('wide');var w=document.createElement('div');w.className='tscroll';t.parentNode.insertBefore(w,t);w.appendChild(t);});
  document.querySelectorAll('.pw-eye').forEach(function(b){b.addEventListener('click',function(){var i=b.parentElement.querySelector('input');var show=i.type==='password';i.type=show?'text':'password';b.setAttribute('aria-pressed',show);b.title=show?'Passwort verbergen':'Passwort anzeigen';b.classList.toggle('on',show);});});
  var dm=document.getElementById('darkModeToggle');if(dm){var ic=dm.querySelector('.icon');dm.addEventListener('click',function(){var on=document.documentElement.classList.toggle('dark-mode');document.body.classList.toggle('dark-mode',on);ic.textContent=on?'☀️':'🌙';var v=on?'enabled':'disabled';document.cookie='darkMode='+v+';path=/;max-age=31536000';try{localStorage.setItem('darkMode',v);}catch(e){}});}
  var ta=document.getElementById('toggleAll');if(ta){ta.addEventListener('click',function(){var all=document.querySelectorAll('details.acc:not(.inner)');var anyClosed=Array.prototype.some.call(all,function(d){return !d.open;});all.forEach(function(d){d.open=anyClosed;});});}
  document.querySelectorAll('button.more').forEach(function(b){b.addEventListener('click',function(){var r=b.closest('tr').nextElementSibling;if(r){r.hidden=!r.hidden;b.textContent=r.hidden?'Mehr ▸':'Weniger ▾';}});});
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
/** Passwortfeld mit Auge. $value füllt das Feld nach einer fehlgeschlagenen Eingabe wieder aus (nur in der Antwort an dieselbe Person). */
function md_password_field(string $name, string $label, string $autocomplete, bool $autofocus = false, string $value = ''): string
{
    return '<label class="block">' . h($label) . '<span class="pwwrap"><input type="password" name="' . h($name) . '" autocomplete="' . h($autocomplete) . '" required' . ($autofocus ? ' autofocus' : '') . ($value !== '' ? ' value="' . h($value) . '"' : '') . '>'
        . '<button type="button" class="pw-eye" title="Passwort anzeigen" aria-label="Passwort anzeigen" aria-pressed="false"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/><path class="slash" d="M3 3l18 18"/></svg></button></span></label>';
}

/** Liste der Anforderungen an ein neues Passwort; die Punkte werden beim Tippen abgehakt (Länge und Einfachheit), der Rest wird beim Speichern geprüft. */
function md_password_rules_html(): string
{
    $o = '<ul class="pwrules" data-min="' . MD_PASSWORD_MIN . '">';
    foreach (md_password_rules() as $k => $text) $o .= '<li data-rule="' . $k . '">' . h($text) . '</li>';
    return $o . '</ul>';
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
