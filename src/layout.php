<?php
declare(strict_types=1);

function md_header(string $title, ?array $me = null, string $active = ''): void
{
    $nav = ['dashboard' => ['index.php', 'Mein Dashboard']];
    if ($me && md_consent_ok((int)$me['member_id'])) {
        if (md_can($me, 'directory', 'view')) $nav['directory'] = ['directory.php', 'Mitgliederverzeichnis'];
        if (md_can($me, 'members', 'view')) $nav['admin'] = ['admin/index.php', 'Mitglieder'];
        if (md_can($me, 'finance', 'view')) $nav['finance'] = ['admin/finance.php', 'Finanzen'];
        if (md_can($me, 'fees', 'view')) { $nav['fees'] = ['admin/fees.php', 'Beiträge']; $nav['dunning'] = ['admin/dunning.php', 'Mahnwesen']; }
        if (md_can($me, 'sepa', 'view')) $nav['sepa'] = ['admin/sepa.php', 'SEPA'];
        if (md_can($me, 'receipts', 'view')) $nav['receipts'] = ['admin/receipts.php', 'Spendenbescheinigungen'];
        if (md_can($me, 'privacy', 'view')) $nav['privacy'] = ['admin/privacy.php', 'Datenschutz'];
        if (md_can($me, 'import', 'edit')) $nav['import'] = ['admin/import.php', 'CSV-Import'];
        if (md_can($me, 'terms', 'view')) $nav['terms'] = ['admin/terms.php', 'Nutzungsbedingungen'];
        if (md_can($me, 'settings', 'edit')) $nav['settings'] = ['admin/settings.php', 'Einstellungen'];
    }
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><base href="' . h(md_url()) . '">';
    echo '<title>' . h($title) . ' – ' . h(MD_CLUB_NAME) . '</title><link rel="stylesheet" href="assets/md.css"></head><body>';
    echo '<header class="top"><div class="wrap"><a class="brand" href="index.php">' . h(MD_CLUB_NAME) . ' <span>Mitgliederdaten</span></a><nav>';
    foreach ($nav as $k => [$url, $label]) echo '<a href="' . $url . '"' . ($k === $active ? ' class="on"' : '') . '>' . h($label) . '</a>';
    echo '</nav>';
    if ($me) {
        echo '<span class="who">' . h($me['first_name'] . ' ' . $me['last_name']) . ' · <a href="logout.php">Abmelden</a></span>';
    }
    echo '</div></header><main class="wrap">';
    if (MD_DEMO_MODE) echo '<div class="demo">Demo-Modus mit erfundenen Daten.</div>';
}

function md_footer(): void
{
    echo '</main></body></html>';
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

/** Auswahl der Sichtbarkeit zu einem Feld. */
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
