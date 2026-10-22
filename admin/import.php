<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_admin();
$aid = (int)$me['member_id'];
$encodings = ['UTF-8' => 'UTF-8', 'Windows-1252' => 'Windows-1252 (Excel/Windows)', 'ISO-8859-1' => 'ISO-8859-1'];

// ---- Schritt 1: Datei hochladen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    md_csrf_check();
    $f = $_FILES['csv'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 20 * 1024 * 1024) { md_flash('Upload fehlgeschlagen (max. 20 MB).', 'err'); header('Location: ' . md_url('admin/import.php')); exit; }
    $token = md_import_store($f['tmp_name'], $_POST['encoding'] ?? 'UTF-8');
    $_SESSION['md_import_name'][$token] = basename($f['name']);
    $delim = ($_POST['delimiter'] ?? 'auto') === 'auto' ? md_import_detect_delimiter(md_import_path($token)) : ($_POST['delimiter'] === 'tab' ? "\t" : $_POST['delimiter']);
    header('Location: ' . md_url('admin/import.php?token=') . $token . '&delim=' . urlencode($delim === "\t" ? 'tab' : $delim));
    exit;
}

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$path = $token !== '' ? md_import_path($token) : null;
$dparam = (string)($_GET['delim'] ?? $_POST['delim'] ?? ';');
$delim = $dparam === 'tab' ? "\t" : (in_array($dparam, [';', ',', '|'], true) ? $dparam : ';');

// ---- Profil speichern / laden, Vorschau, Import
$plan = null;
$result = null;
if ($path && $_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    [$headers, $rows] = md_import_read($path, $delim);
    $map = [];
    foreach (array_keys(MD_IMPORT_TARGETS) as $k) if (isset($_POST['map'][$k]) && $_POST['map'][$k] !== '') $map[$k] = (int)$_POST['map'][$k];
    $opts = ['update_existing' => !empty($_POST['update_existing']), 'placeholder_email' => !empty($_POST['placeholder_email'])];
    $sig = hash('sha256', json_encode([$token, $map, $opts]));
    $act = $_POST['action'] ?? '';
    if ($act === 'save_profile' && trim($_POST['profile_name'] ?? '') !== '') {
        $name = trim($_POST['profile_name']);
        $json = json_encode(['map' => array_map(fn($i) => $headers[$i] ?? '', $map), 'opts' => $opts], JSON_UNESCAPED_UNICODE);
        if (md_one('SELECT 1 FROM md_import_profiles WHERE name = ?', [$name])) md_exec('UPDATE md_import_profiles SET mapping=?, delimiter_char=?, source_software=? WHERE name=?', [$json, $delim, trim($_POST['source_software'] ?? '') ?: null, $name]);
        else md_exec('INSERT INTO md_import_profiles (name, source_software, delimiter_char, mapping) VALUES (?,?,?,?)', [$name, trim($_POST['source_software'] ?? '') ?: null, $delim, $json]);
        md_flash('Zuordnung als Profil „' . $name . '“ gespeichert.');
        header('Location: ' . md_url('admin/import.php?token=') . $token . '&delim=' . urlencode($dparam) . '&profile=' . urlencode($name));
        exit;
    }
    if ($act === 'preview' || $act === 'commit') {
        $plan = md_import_plan($rows, $map, $opts);
        if ($act === 'preview') {
            $_SESSION['md_import_sig'] = $sig;
        } elseif (($_SESSION['md_import_sig'] ?? '') !== $sig) {
            md_flash('Bitte zuerst die Vorschau mit genau diesen Einstellungen ansehen.', 'err');
            $act = 'preview';
            $_SESSION['md_import_sig'] = $sig;
        } else {
            try {
                [$c, $u, $s] = md_import_apply($plan, $aid);
                md_exec('INSERT INTO md_import_runs (file_name, started_by, started_at, rows_total, rows_created, rows_updated, rows_skipped) VALUES (?,?,?,?,?,?,?)',
                    [$_SESSION['md_import_name'][$token] ?? 'import.csv', $aid, md_now(), count($plan), $c, $u, $s]);
                unset($_SESSION['md_import_sig']);
                @unlink($path);
                md_flash("Import abgeschlossen: $c angelegt, $u aktualisiert, $s übersprungen.");
                header('Location: ' . md_url('admin/import.php'));
                exit;
            } catch (Throwable $e) {
                error_log('md import: ' . $e->getMessage());
                md_flash('Import abgebrochen, nichts wurde geschrieben: ' . $e->getMessage(), 'err');
            }
        }
    }
}

md_header('CSV-Import', $me, 'import');
md_show_flash();
echo '<h1>Mitglieder aus CSV importieren</h1>';

if (!$path) {
    echo '<section class="card"><h2>1. Datei wählen</h2><p>Exportiere die Mitglieder aus der bisherigen Software als CSV (Excel-Export „CSV“ genügt). Die Spalten ordnest du im nächsten Schritt zu.</p>';
    echo '<form method="post" enctype="multipart/form-data">' . md_csrf_field() . '<input type="hidden" name="action" value="upload"><div class="addr">';
    echo '<label>CSV-Datei<input type="file" name="csv" accept=".csv,.txt,text/csv" required></label>';
    echo '<label>Zeichensatz<select name="encoding">';
    foreach ($encodings as $k => $l) echo '<option value="' . $k . '">' . h($l) . '</option>';
    echo '</select></label><label>Trennzeichen<select name="delimiter"><option value="auto">automatisch</option><option value=";">Semikolon</option><option value=",">Komma</option><option value="tab">Tabulator</option><option value="|">|</option></select></label></div><button>Hochladen</button></form></section>';
    $runs = md_q('SELECT r.*, s.first_name, s.last_name FROM md_import_runs r LEFT JOIN svmembers s ON s.member_id = r.started_by ORDER BY run_id DESC LIMIT 10');
    if ($runs) {
        echo '<section class="card"><h2>Bisherige Importe</h2><table><thead><tr><th>Wann</th><th>Datei</th><th>Wer</th><th>Zeilen</th><th>Angelegt</th><th>Aktualisiert</th><th>Übersprungen</th></tr></thead><tbody>';
        foreach ($runs as $r) echo '<tr><td>' . h(date('d.m.Y H:i', strtotime($r['started_at']))) . '</td><td>' . h($r['file_name']) . '</td><td>' . h($r['first_name'] . ' ' . $r['last_name']) . '</td><td>' . (int)$r['rows_total'] . '</td><td>' . (int)$r['rows_created'] . '</td><td>' . (int)$r['rows_updated'] . '</td><td>' . (int)$r['rows_skipped'] . '</td></tr>';
        echo '</tbody></table></section>';
    }
    md_footer();
    exit;
}

[$headers, $rows] = md_import_read($path, $delim);
$profiles = md_q('SELECT * FROM md_import_profiles ORDER BY name');
$current = [];
$opts = ['update_existing' => false, 'placeholder_email' => false];
if (isset($_POST['map'])) {
    foreach ($_POST['map'] as $k => $v) if ($v !== '') $current[$k] = (int)$v;
    $opts = ['update_existing' => !empty($_POST['update_existing']), 'placeholder_email' => !empty($_POST['placeholder_email'])];
} elseif (!empty($_GET['profile']) && ($pr = md_one('SELECT * FROM md_import_profiles WHERE name = ?', [$_GET['profile']]))) {
    $saved = json_decode($pr['mapping'], true) ?: [];
    foreach ($saved['map'] ?? [] as $k => $col) { $i = array_search($col, $headers, true); if ($i !== false) $current[$k] = $i; }
    $opts = $saved['opts'] ?? $opts;
} else {
    $current = md_import_guess_mapping($headers);
}

echo '<section class="card"><h2>2. Spalten zuordnen</h2><p>' . count($rows) . ' Datenzeilen, ' . count($headers) . ' Spalten. ';
if ($profiles) {
    echo '<form class="inline" method="get"><input type="hidden" name="token" value="' . h($token) . '"><input type="hidden" name="delim" value="' . h($dparam) . '"><select name="profile"><option value="">Gespeichertes Profil laden …</option>';
    foreach ($profiles as $p) echo '<option>' . h($p['name']) . '</option>';
    echo '</select> <button>Laden</button></form>';
}
echo '</p><form method="post">' . md_csrf_field() . '<input type="hidden" name="token" value="' . h($token) . '"><input type="hidden" name="delim" value="' . h($dparam) . '">';
echo '<table class="map"><thead><tr><th>Feld im System</th><th>Spalte in der Datei</th><th>Beispiel</th></tr></thead><tbody>';
foreach (MD_IMPORT_TARGETS as $k => [$label]) {
    echo '<tr><td>' . h($label) . '</td><td><select name="map[' . $k . ']"><option value="">– nicht importieren –</option>';
    foreach ($headers as $i => $hd) echo '<option value="' . $i . '"' . (isset($current[$k]) && $current[$k] === $i ? ' selected' : '') . '>' . h($hd) . '</option>';
    echo '</select></td><td class="muted">' . h(isset($current[$k]) ? ($rows[0][$current[$k]] ?? '') : '') . '</td></tr>';
}
echo '</tbody></table>';
echo '<label class="check"><input type="checkbox" name="update_existing" value="1"' . ($opts['update_existing'] ? ' checked' : '') . '> Vorhandene Mitglieder aktualisieren (Zuordnung über Mitgliedsnr., E-Mail oder Name+Geburtsdatum; leere Zellen überschreiben nichts)</label><br>';
echo '<label class="check"><input type="checkbox" name="placeholder_email" value="1"' . ($opts['placeholder_email'] ? ' checked' : '') . '> Neue Mitglieder ohne E-Mail mit Platzhalter-Adresse anlegen</label>';
echo '<p><button name="action" value="preview">Vorschau</button></p>';
echo '<details><summary>Zuordnung als Profil speichern</summary><div class="addr"><label>Profilname<input name="profile_name" placeholder="z. B. Export Altsoftware"></label><label>Quell-Software<input name="source_software"></label></div><button name="action" value="save_profile">Profil speichern</button></details></form></section>';

if ($plan !== null) {
    $cnt = ['create' => 0, 'update' => 0, 'error' => 0];
    foreach ($plan as $e) $cnt[$e['action']]++;
    echo '<section class="card" id="preview"><h2>3. Vorschau</h2><p><span class="chip ok">' . $cnt['create'] . ' neu</span> <span class="chip">' . $cnt['update'] . ' aktualisieren</span> <span class="chip bad">' . $cnt['error'] . ' mit Fehler (werden übersprungen)</span></p>';
    echo '<table><thead><tr><th>Zeile</th><th>Aktion</th><th>Name</th><th>E-Mail</th><th>Hinweise</th></tr></thead><tbody>';
    foreach (array_slice($plan, 0, 200) as $e) {
        $d = $e['data'];
        $lbl = ['create' => 'neu', 'update' => 'aktualisieren', 'error' => 'Fehler'][$e['action']];
        echo '<tr class="' . ($e['action'] === 'error' ? 'rowbad' : '') . '"><td>' . $e['row'] . '</td><td>' . $lbl . '</td><td>' . h(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? '')) . '</td><td>' . h($d['email'] ?? '') . '</td><td>' . h(implode('; ', array_merge($e['errors'], $e['warnings']))) . '</td></tr>';
    }
    echo '</tbody></table>' . (count($plan) > 200 ? '<p class="muted">Es werden die ersten 200 Zeilen angezeigt.</p>' : '');
    if ($cnt['create'] + $cnt['update'] > 0) {
        echo '<form method="post" onsubmit="return confirm(\'Import jetzt durchführen?\')">' . md_csrf_field() . '<input type="hidden" name="token" value="' . h($token) . '"><input type="hidden" name="delim" value="' . h($dparam) . '">';
        foreach ($current as $k => $i) echo '<input type="hidden" name="map[' . h($k) . ']" value="' . (int)$i . '">';
        foreach ($opts as $k => $v) if ($v) echo '<input type="hidden" name="' . h($k) . '" value="1">';
        echo '<button name="action" value="commit">' . ($cnt['create'] + $cnt['update']) . ' Zeilen importieren</button> <span class="muted">Ein Fehler beim Schreiben bricht alles ab; es wird nichts halb importiert.</span></form>';
    }
    echo '</section>';
}
md_footer();
