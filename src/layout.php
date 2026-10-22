<?php
declare(strict_types=1);

function md_header(string $title, ?array $me = null, string $active = ''): void
{
    $nav = [
        'dashboard' => ['index.php', 'Mein Dashboard'],
        'directory' => ['directory.php', 'Mitgliederverzeichnis'],
    ];
    if ($me && md_is_admin($me)) {
        $nav['admin'] = ['admin/index.php', 'Verwaltung'];
        $nav['import'] = ['admin/import.php', 'CSV-Import'];
        $nav['terms'] = ['admin/terms.php', 'Nutzungsbedingungen'];
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
