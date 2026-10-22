<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('finance', 'edit');
$aid = (int)$me['member_id'];
$encodings = ['UTF-8' => 'UTF-8', 'Windows-1252' => 'Windows-1252 (Excel/Windows)', 'ISO-8859-1' => 'ISO-8859-1'];
$self = 'admin/finance_import.php';

// ---- 1. Hochladen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    md_csrf_check();
    $f = $_FILES['csv'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 20 * 1024 * 1024) { md_flash('Upload fehlgeschlagen (max. 20 MB).', 'err'); header('Location: ' . md_url($self)); exit; }
    $token = md_import_store($f['tmp_name'], $_POST['encoding'] ?? 'UTF-8');
    $_SESSION['md_import_name'][$token] = basename($f['name']);
    $delim = ($_POST['delimiter'] ?? 'auto') === 'auto' ? md_import_detect_delimiter(md_import_path($token)) : ($_POST['delimiter'] === 'tab' ? "\t" : $_POST['delimiter']);
    header('Location: ' . md_url($self . '?token=' . $token . '&delim=' . urlencode($delim === "\t" ? 'tab' : $delim)));
    exit;
}

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$path = $token !== '' ? md_import_path($token) : null;
$dparam = (string)($_GET['delim'] ?? $_POST['delim'] ?? ';');
$delim = $dparam === 'tab' ? "\t" : (in_array($dparam, [';', ',', '|'], true) ? $dparam : ';');
$plan = null;

if ($path && $_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    [$headers, $rows] = md_import_read($path, $delim);
    $map = [];
    foreach (array_keys(MD_LEDGER_IMPORT_TARGETS) as $k) if (isset($_POST['map'][$k]) && $_POST['map'][$k] !== '') $map[$k] = (int)$_POST['map'][$k];
    $dk = isset(MD_LEDGER_KINDS[$_POST['default_kind'] ?? '']) ? $_POST['default_kind'] : null;
    $opts = ['default_kind' => $dk, 'default_status' => in_array($_POST['default_status'] ?? '', ['open', 'paid'], true) ? $_POST['default_status'] : ''];
    $sig = hash('sha256', json_encode([$token, $map, $opts]));
    $act = $_POST['action'] ?? '';
    if ($act === 'save_profile' && trim($_POST['profile_name'] ?? '') !== '') {
        $name = trim($_POST['profile_name']);
        $json = json_encode(['map' => array_map(fn($i) => $headers[$i] ?? '', $map), 'opts' => $opts], JSON_UNESCAPED_UNICODE);
        if (md_one('SELECT 1 FROM md_import_profiles WHERE name = ?', [$name])) md_exec('UPDATE md_import_profiles SET mapping=?, delimiter_char=?, source_software=?, target=? WHERE name=?', [$json, $delim, trim($_POST['source_software'] ?? '') ?: null, 'ledger', $name]);
        else md_exec('INSERT INTO md_import_profiles (name, target, source_software, delimiter_char, mapping) VALUES (?,?,?,?,?)', [$name, 'ledger', trim($_POST['source_software'] ?? '') ?: null, $delim, $json]);
        md_flash('Zuordnung als Profil „' . $name . '“ gespeichert.');
        header('Location: ' . md_url($self . '?token=' . $token . '&delim=' . urlencode($dparam) . '&profile=' . urlencode($name)));
        exit;
    }
    if ($act === 'preview' || $act === 'commit') {
        $plan = md_ledger_import_plan($rows, $map, $opts);
        if ($act === 'preview') {
            $_SESSION['md_import_sig'] = $sig;
        } elseif (($_SESSION['md_import_sig'] ?? '') !== $sig) {
            md_flash('Bitte zuerst die Vorschau mit genau diesen Einstellungen ansehen.', 'err');
            $_SESSION['md_import_sig'] = $sig;
        } else {
            try {
                [$n, $skip] = md_ledger_import_apply($plan, $aid);
                md_exec('INSERT INTO md_import_runs (file_name, started_by, started_at, rows_total, rows_created, rows_updated, rows_skipped, note) VALUES (?,?,?,?,?,?,?,?)',
                    [$_SESSION['md_import_name'][$token] ?? 'buchungen.csv', $aid, md_now(), count($plan), $n, 0, $skip, 'Buchungen']);
                unset($_SESSION['md_import_sig']);
                @unlink($path);
                md_flash("Import abgeschlossen: $n Buchung(en) angelegt, $skip Zeile(n) übersprungen.");
                header('Location: ' . md_url('admin/finance.php'));
                exit;
            } catch (Throwable $e) {
                error_log('md ledger import: ' . $e->getMessage());
                md_flash('Import abgebrochen, nichts wurde geschrieben: ' . $e->getMessage(), 'err');
            }
        }
    }
}

md_header('Buchungen importieren', $me, 'finance');
md_show_flash();
echo '<h1>Buchungen aus CSV importieren</h1><p><a href="admin/finance.php">← zurück zu den Finanzen</a></p>';

if (!$path) {
    echo '<section class="card"><h2>1. Datei wählen</h2><p>Eine CSV-Datei aus der Buchhaltung oder dem Kontoauszug. Pro Zeile eine Buchung, Zuordnung zum Mitglied über Mitgliedsnummer oder E-Mail. '
        . 'Beträge wie <code>1.234,56</code> oder <code>1234.56</code>. Ein Export aus DATEV oder einer anderen Software wird hier über die Spaltenzuordnung angepasst; '
        . 'die Zuordnung lässt sich als Profil speichern.</p>';
    echo '<form method="post" enctype="multipart/form-data">' . md_csrf_field() . '<input type="hidden" name="action" value="upload"><div class="addr">';
    echo '<label>CSV-Datei<input type="file" name="csv" accept=".csv,.txt,text/csv" required></label><label>Zeichensatz<select name="encoding">';
    foreach ($encodings as $k => $l) echo '<option value="' . $k . '">' . h($l) . '</option>';
    echo '</select></label><label>Trennzeichen<select name="delimiter"><option value="auto">automatisch</option><option value=";">Semikolon</option><option value=",">Komma</option><option value="tab">Tabulator</option><option value="|">|</option></select></label></div><button>Hochladen</button></form></section>';
    md_footer();
    exit;
}

[$headers, $rows] = md_import_read($path, $delim);
$profiles = md_q("SELECT * FROM md_import_profiles WHERE target = 'ledger' ORDER BY name");
$current = [];
$opts = ['default_kind' => null, 'default_status' => ''];
if (isset($_POST['map'])) {
    foreach ($_POST['map'] as $k => $v) if ($v !== '') $current[$k] = (int)$v;
    $opts = ['default_kind' => $_POST['default_kind'] ?? null, 'default_status' => $_POST['default_status'] ?? ''];
} elseif (!empty($_GET['profile']) && ($pr = md_one("SELECT * FROM md_import_profiles WHERE name = ? AND target = 'ledger'", [$_GET['profile']]))) {
    $saved = json_decode($pr['mapping'], true) ?: [];
    foreach ($saved['map'] ?? [] as $k => $col) { $i = array_search($col, $headers, true); if ($i !== false) $current[$k] = $i; }
    $opts = $saved['opts'] ?? $opts;
} else {
    $current = md_ledger_guess_mapping($headers);
}

echo '<section class="card"><h2>2. Spalten zuordnen</h2><p>' . count($rows) . ' Datenzeilen, ' . count($headers) . ' Spalten. ';
if ($profiles) {
    echo '<form class="inline" method="get"><input type="hidden" name="token" value="' . h($token) . '"><input type="hidden" name="delim" value="' . h($dparam) . '"><select name="profile"><option value="">Gespeichertes Profil laden …</option>';
    foreach ($profiles as $p) echo '<option>' . h($p['name']) . '</option>';
    echo '</select> <button>Laden</button></form>';
}
echo '</p><form method="post">' . md_csrf_field() . '<input type="hidden" name="token" value="' . h($token) . '"><input type="hidden" name="delim" value="' . h($dparam) . '">';
echo '<table class="map"><thead><tr><th>Feld im System</th><th>Spalte in der Datei</th><th>Beispiel</th></tr></thead><tbody>';
foreach (MD_LEDGER_IMPORT_TARGETS as $k => [$label]) {
    echo '<tr><td>' . h($label) . '</td><td><select name="map[' . $k . ']"><option value="">– nicht importieren –</option>';
    foreach ($headers as $i => $hd) echo '<option value="' . $i . '"' . (isset($current[$k]) && $current[$k] === $i ? ' selected' : '') . '>' . h($hd) . '</option>';
    echo '</select></td><td class="muted">' . h(isset($current[$k]) ? ($rows[0][$current[$k]] ?? '') : '') . '</td></tr>';
}
echo '</tbody></table><div class="addr"><label>Standardart (wenn keine Art-Spalte oder leer)<select name="default_kind"><option value="">– keine –</option>';
foreach (MD_LEDGER_KINDS as $k => [$l]) echo '<option value="' . $k . '"' . (($opts['default_kind'] ?? '') === $k ? ' selected' : '') . '>' . h($l) . '</option>';
echo '</select></label><label>Standardstatus (wenn keine Status-Spalte)<select name="default_status"><option value=""' . (($opts['default_status'] ?? '') === '' ? ' selected' : '') . '>nach Zahldatum</option><option value="open"' . (($opts['default_status'] ?? '') === 'open' ? ' selected' : '') . '>alle offen</option><option value="paid"' . (($opts['default_status'] ?? '') === 'paid' ? ' selected' : '') . '>alle bezahlt</option></select></label></div>';
echo '<p><button name="action" value="preview">Vorschau</button></p><details><summary>Zuordnung als Profil speichern</summary><div class="addr"><label>Profilname<input name="profile_name" placeholder="z. B. Export Buchhaltung"></label><label>Quell-Software<input name="source_software"></label></div><button name="action" value="save_profile">Profil speichern</button></details></form></section>';

if ($plan !== null) {
    $cnt = ['create' => 0, 'error' => 0];
    $sumc = 0;
    foreach ($plan as $e) { $cnt[$e['action']]++; if ($e['action'] === 'create') $sumc += $e['data']['amount_cents']; }
    echo '<section class="card"><h2>3. Vorschau</h2><p><span class="chip ok">' . $cnt['create'] . ' Buchung(en), Summe ' . md_money($sumc) . '</span> <span class="chip bad">' . $cnt['error'] . ' mit Fehler (werden übersprungen)</span></p>';
    echo '<table><thead><tr><th>Zeile</th><th>Mitglied</th><th>Art</th><th class="r">Betrag</th><th>Datum</th><th>Status</th><th>Hinweise</th></tr></thead><tbody>';
    foreach (array_slice($plan, 0, 200) as $e) {
        $d = $e['data'];
        echo '<tr class="' . ($e['action'] === 'error' ? 'rowbad' : '') . '"><td>' . $e['row'] . '</td><td>' . h($e['member_name']) . '</td><td>' . h(MD_LEDGER_KINDS[$d['kind']][0] ?? '') . '</td><td class="r">' . ($d['amount_cents'] ? md_money($d['amount_cents']) : '') . '</td><td>' . h(md_date($d['entry_date'])) . '</td><td>' . h(['open' => 'offen', 'paid' => 'bezahlt'][$d['status']] ?? '') . '</td><td>' . h(implode('; ', array_merge($e['errors'], $e['warnings']))) . '</td></tr>';
    }
    echo '</tbody></table>' . (count($plan) > 200 ? '<p class="muted">Es werden die ersten 200 Zeilen angezeigt.</p>' : '');
    if ($cnt['create'] > 0) {
        echo '<form method="post" onsubmit="return confirm(\'Import jetzt durchführen?\')">' . md_csrf_field() . '<input type="hidden" name="token" value="' . h($token) . '"><input type="hidden" name="delim" value="' . h($dparam) . '">';
        foreach ($current as $k => $i) echo '<input type="hidden" name="map[' . h($k) . ']" value="' . (int)$i . '">';
        echo '<input type="hidden" name="default_kind" value="' . h((string)($opts['default_kind'] ?? '')) . '"><input type="hidden" name="default_status" value="' . h((string)($opts['default_status'] ?? '')) . '">';
        echo '<button name="action" value="commit">' . $cnt['create'] . ' Buchung(en) importieren</button> <span class="muted">Ein Fehler beim Schreiben bricht alles ab; es wird nichts halb importiert.</span></form>';
    }
    echo '</section>';
}
md_footer();
