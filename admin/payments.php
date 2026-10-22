<?php
require_once __DIR__ . '/../src/bootstrap.php';
$me = md_require_perm('fees', 'edit');
$aid = (int)$me['member_id'];
$encodings = ['UTF-8' => 'UTF-8', 'Windows-1252' => 'Windows-1252 (Excel/Windows)', 'ISO-8859-1' => 'ISO-8859-1'];
$self = 'admin/payments.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    md_csrf_check();
    $f = $_FILES['csv'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 20 * 1024 * 1024) { md_flash('Upload fehlgeschlagen (max. 20 MB).', 'err'); header('Location: ' . md_url($self)); exit; }
    $token = md_import_store($f['tmp_name'], $_POST['encoding'] ?? 'UTF-8');
    $delim = ($_POST['delimiter'] ?? 'auto') === 'auto' ? md_import_detect_delimiter(md_import_path($token)) : ($_POST['delimiter'] === 'tab' ? "\t" : $_POST['delimiter']);
    header('Location: ' . md_url($self . '?token=' . $token . '&delim=' . urlencode($delim === "\t" ? 'tab' : $delim)));
    exit;
}
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$path = $token !== '' ? md_import_path($token) : null;
$dparam = (string)($_GET['delim'] ?? $_POST['delim'] ?? ';');
$delim = $dparam === 'tab' ? "\t" : (in_array($dparam, [';', ',', '|'], true) ? $dparam : ';');
$plan = null;
$map = [];
if ($path) {
    [$headers, $rows] = md_import_read($path, $delim);
    if (isset($_POST['map'])) {
        foreach (array_keys(MD_PAYMENT_TARGETS) as $k) {
            if (($_POST['map'][$k] ?? '') !== '') $map[$k] = (int)$_POST['map'][$k];
        }
    } else {
        $map = md_payment_guess_mapping($headers);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['preview', 'commit'], true)) {
        md_csrf_check();
        $plan = md_payment_plan($rows, $map);
        $sig = hash('sha256', json_encode([$token, $map]));
        if ($_POST['action'] === 'preview') $_SESSION['md_pay_sig'] = $sig;
        elseif (($_SESSION['md_pay_sig'] ?? '') !== $sig) { md_flash('Bitte zuerst die Vorschau ansehen.', 'err'); $_SESSION['md_pay_sig'] = $sig; }
        else {
            [$n, $errs] = md_payment_apply($plan, array_map('intval', (array)($_POST['accept'] ?? [])), $aid);
            if ($errs) md_flash('Abgebrochen, nichts wurde gebucht: ' . implode(' ', $errs), 'err');
            else { unset($_SESSION['md_pay_sig']); @unlink($path); md_flash("$n Forderung(en) als bezahlt vermerkt."); header('Location: ' . md_url($self)); exit; }
        }
    }
}

md_header('Zahlungen zuordnen', $me, 'fees');
md_show_flash();
echo '<h1>Zahlungseingänge zuordnen</h1><p><a href="admin/fees.php">← Beiträge</a> · <a href="admin/dunning.php">Mahnwesen</a></p>';
if (!$path) {
    echo '<section class="card"><h2>1. Kontoauszug (CSV) wählen</h2><p>Lade den Kontoauszug als CSV aus dem Online-Banking oder der Buchhaltung. Die Zuordnung erfolgt über die <strong>Rechnungs- bzw. Mahnschreibennummer</strong> im Verwendungszweck (z. B. <code>R2027-0001</code>), '
        . 'sonst über Mitgliedsnummer und Betrag. Du bestätigst jede Zuordnung; Zahlungen mit abweichendem Betrag werden nur angezeigt und müssen von Hand gebucht werden.</p>'
        . '<form method="post" enctype="multipart/form-data">' . md_csrf_field() . '<input type="hidden" name="action" value="upload"><div class="addr"><label>CSV-Datei<input type="file" name="csv" accept=".csv,.txt,text/csv" required></label><label>Zeichensatz<select name="encoding">';
    foreach ($encodings as $k => $l) echo '<option value="' . $k . '">' . h($l) . '</option>';
    echo '</select></label><label>Trennzeichen<select name="delimiter"><option value="auto">automatisch</option><option value=";">Semikolon</option><option value=",">Komma</option><option value="tab">Tabulator</option></select></label></div><button>Hochladen</button></form></section>';
    md_footer();
    exit;
}
echo '<section class="card"><h2>2. Spalten zuordnen</h2><form method="post">' . md_csrf_field() . '<input type="hidden" name="token" value="' . h($token) . '"><input type="hidden" name="delim" value="' . h($dparam) . '"><div class="addr">';
foreach (MD_PAYMENT_TARGETS as $k => [$label]) {
    echo '<label>' . h($label) . '<select name="map[' . $k . ']"><option value="">–</option>';
    foreach ($headers as $i => $hd) echo '<option value="' . $i . '"' . (isset($map[$k]) && $map[$k] === $i ? ' selected' : '') . '>' . h($hd) . '</option>';
    echo '</select></label>';
}
echo '</div><button name="action" value="preview">Vorschau</button></form></section>';
if ($plan !== null) {
    $cnt = ['sure' => 0, 'suggest' => 0, 'diff' => 0, 'none' => 0, 'debit' => 0, 'error' => 0];
    foreach ($plan as $r) $cnt[$r['status']]++;
    echo '<section class="card"><h2>3. Vorschau</h2><p><span class="chip ok">' . $cnt['sure'] . ' sicher</span> <span class="chip">' . $cnt['suggest'] . ' Vorschlag</span> <span class="chip warn">' . $cnt['diff'] . ' Betrag weicht ab</span> <span class="chip">' . $cnt['none'] . ' nicht zugeordnet</span> <span class="chip">' . $cnt['debit'] . ' Ausgänge</span>' . ($cnt['error'] ? ' <span class="chip bad">' . $cnt['error'] . ' fehlerhaft</span>' : '') . '</p>';
    echo '<form method="post" onsubmit="return confirm(\'Die markierten Zahlungen jetzt als bezahlt vermerken?\')">' . md_csrf_field() . '<input type="hidden" name="token" value="' . h($token) . '"><input type="hidden" name="delim" value="' . h($dparam) . '">';
    foreach ($map as $k => $i) echo '<input type="hidden" name="map[' . h($k) . ']" value="' . (int)$i . '">';
    echo '<table><thead><tr><th></th><th>Zeile</th><th>Datum</th><th class="r">Betrag</th><th>Verwendungszweck / Absender</th><th>Zuordnung</th></tr></thead><tbody>';
    $labels = ['sure' => 'sicher', 'suggest' => 'Vorschlag', 'diff' => 'Betrag weicht ab', 'none' => 'nicht zugeordnet', 'debit' => 'Ausgang', 'error' => 'Fehler'];
    foreach (array_slice($plan, 0, 500) as $r) {
        $can = in_array($r['status'], ['sure', 'suggest'], true);
        echo '<tr class="' . (in_array($r['status'], ['error'], true) ? 'rowbad' : (in_array($r['status'], ['debit', 'none'], true) ? 'muted' : '')) . '"><td>' . ($can ? '<input type="checkbox" name="accept[]" value="' . $r['row'] . '"' . ($r['status'] === 'sure' ? ' checked' : '') . '>' : '') . '</td><td>' . $r['row'] . '</td><td>' . h(md_date($r['date'])) . '</td><td class="r">' . ($r['cents'] ? md_money($r['cents']) : '') . '</td><td>' . h($r['purpose']) . ($r['payer'] ? '<br><span class="muted">' . h($r['payer']) . '</span>' : '') . '</td><td>' . h($labels[$r['status']]) . ($r['matches'] ? '<br>' . h(implode('; ', $r['matches'])) : '') . ($r['note'] ? '<br><span class="muted">' . h($r['note']) . '</span>' : '') . '</td></tr>';
    }
    echo '</tbody></table>' . ($cnt['sure'] + $cnt['suggest'] ? '<p><button name="action" value="commit">Markierte als bezahlt vermerken</button> <span class="muted">„Sicher“ ist vorangekreuzt, „Vorschlag“ bitte einzeln prüfen.</span></p>' : '') . '</form></section>';
}
md_footer();
