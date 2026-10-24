<?php
require_once __DIR__ . '/src/bootstrap.php';
$me = md_require_login();
if (!md_consent_ok((int)$me['member_id']) || !md_can($me, 'directory', 'view')) {
    md_flash('Das Mitgliederverzeichnis ist für dich nicht verfügbar' . (md_consent_ok((int)$me['member_id']) ? '.' : ': Bitte stimme zuerst den Nutzungsbedingungen zu.'), 'warn');
    header('Location: ' . md_url('index.php'));
    exit;
}
$meId = (int)$me['member_id'];
// Spalten, die der Benutzer für sich gewählt hat (Name und Foto sind immer da)
const DIR_COLUMNS = ['groups' => 'Teilgruppen', 'zipcity' => 'PLZ / Ort', 'phone' => 'Telefon', 'email' => 'E-Mail', 'interests' => 'Interessen', 'occupation' => 'Beruf', 'funcs' => 'Funktionen', 'join' => 'Eintritt', 'status' => 'Status'];
const DIR_DEFAULT = ['groups', 'zipcity', 'phone', 'email'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    $chosen = array_values(array_intersect(array_keys(DIR_COLUMNS), (array)($_POST['cols'] ?? [])));
    md_pref_set($meId, 'dir.cols', !empty($_POST['reset']) ? implode(',', DIR_DEFAULT) : implode(',', $chosen));
    header('Location: ' . md_url('directory.php' . (!empty($_POST['back']) ? '?' . preg_replace('/[^A-Za-z0-9%=&_.\-+]/', '', (string)$_POST['back']) : '')));
    exit;
}
$cols = array_values(array_intersect(array_keys(DIR_COLUMNS), explode(',', (string)md_pref_get($meId, 'dir.cols', implode(',', DIR_DEFAULT)))));
$seeAll = md_is_admin($me) || md_can($me, 'members', 'edit');
$privileged = md_is_privileged($me);
$today = date('Y-m-d');

// ---- alle Daten in wenigen Abfragen laden (Verein mit mehreren hundert Mitgliedern)
$rows = md_q("SELECT s.member_id, s.first_name, s.last_name, s.email, p.status, p.nickname, p.occupation, p.employer, p.hobbies, p.interests, p.intro_text, p.birthdate, p.join_date
              FROM svmembers s LEFT JOIN md_profiles p ON p.member_id = s.member_id
              WHERE COALESCE(s.is_active, 1) <> 0 AND s.membership_number NOT LIKE 'DEL-%' AND COALESCE(p.status, 'aktiv') NOT IN ('ausgetreten', 'interessent')
              ORDER BY s.last_name, s.first_name");
$vm = md_visibility_maps_all();
$groupsOf = $funcsOf = $photosOf = $addrOf = $contactOf = [];
foreach (md_q('SELECT mg.member_id, g.group_id, g.name FROM md_member_groups mg JOIN md_groups g ON g.group_id = mg.group_id WHERE mg.until IS NULL OR mg.until >= ? ORDER BY g.name', [$today]) as $r) $groupsOf[(int)$r['member_id']][(int)$r['group_id']] = $r['name'];
foreach (md_q('SELECT mf.member_id, f.code, f.name FROM md_member_functions mf JOIN md_functions f ON f.function_id = mf.function_id WHERE (mf.valid_to IS NULL OR mf.valid_to >= ?) AND (mf.valid_from IS NULL OR mf.valid_from <= ?) ORDER BY f.sv_aktiv_level DESC', [$today, $today]) as $r) $funcsOf[(int)$r['member_id']][$r['code']] = $r['name'];
foreach (md_q('SELECT member_id, slot, photo_id FROM md_photos ORDER BY slot') as $r) $photosOf[(int)$r['member_id']][(int)$r['slot']] = (int)$r['photo_id'];
foreach (md_q('SELECT * FROM md_addresses ORDER BY slot') as $r) $addrOf[(int)$r['member_id']][(int)$r['slot']] = $r;
foreach (md_q('SELECT member_id, kind, value FROM md_contacts') as $r) $contactOf[(int)$r['member_id']][$r['kind']] = $r['value'];
$myGroups = array_keys($groupsOf[$meId] ?? []);

$people = [];
foreach ($rows as $r) {
    $id = (int)$r['member_id'];
    $v = ($vm[$id] ?? []) + $vm[0];
    $see = function (string $k) use ($id, $v, $meId, $seeAll, $privileged, $myGroups, $groupsOf): bool {
        if ($id === $meId || $seeAll) return true;
        return match ($v[$k] ?? 'me') { 'all' => true, 'admin' => $privileged, 'group' => $privileged || (bool)array_intersect($myGroups, array_keys($groupsOf[$id] ?? [])), default => false };
    };
    $d = ['id' => $id, 'name' => $r['first_name'] . ' ' . $r['last_name'], 'last' => $r['last_name'], 'nick' => ($r['nickname'] && $see('nickname')) ? $r['nickname'] : '', 'status' => $r['status'] ?: 'aktiv', 'join' => $r['join_date'],
          'funcs' => $funcsOf[$id] ?? [], 'groups' => $groupsOf[$id] ?? [], 'email' => $see('email') ? $r['email'] : '', 'phone' => '', 'city' => '', 'zipcity' => '', 'cities' => [], 'photos' => [], 'details' => [],
          'interests' => '', 'occupation' => ($r['occupation'] && $see('occupation')) ? $r['occupation'] : ''];
    if ($r['interests'] && $see('interests')) $d['interests'] = $r['interests'];
    elseif ($r['hobbies'] && $see('hobbies')) $d['interests'] = $r['hobbies'];
    foreach ($photosOf[$id] ?? [] as $s => $pid) if ($see("photo.$s")) $d['photos'][$s] = $pid;
    foreach (($addrOf[$id] ?? []) as $s => $a) if ($see("address.$s")) {
        if ($a['city'] !== '' && $a['city'] !== null) { $d['cities'][] = $a['city']; if ($d['zipcity'] === '') $d['zipcity'] = trim($a['zip'] . ' ' . $a['city']); }
        $d['details'][$a['label'] ?: "Adresse $s"] = trim($a['street'] . ', ' . $a['zip'] . ' ' . $a['city'], ', ');
    }
    $d['city'] = $d['cities'][0] ?? '';
    foreach (($contactOf[$id] ?? []) as $k => $val) if ($see("contact.$k")) { $d['details'][MD_CONTACT_KINDS[$k] ?? $k] = $val; if (in_array($k, ['phone', 'mobile'], true) && $d['phone'] === '') $d['phone'] = $val; }
    foreach (['occupation' => 'Beruf', 'employer' => 'Arbeitgeber', 'hobbies' => 'Hobbys', 'interests' => 'Interessen', 'intro_text' => 'Das bin ich'] as $k => $label) if ($r[$k] && $see($k)) $d['details'][$label] = $r[$k];
    if ($r['birthdate'] && $see('birthdate')) $d['details']['Geburtstag'] = md_date($r['birthdate']);
    $d['hay'] = mb_strtolower($d['name'] . ' ' . $d['nick'] . ' ' . implode(' ', $d['groups']) . ' ' . implode(' ', $d['funcs']) . ' ' . implode(' ', $d['details']) . ' ' . $d['email']);
    $people[] = $d;
}

// ---- Filter
$q = trim((string)($_GET['q'] ?? ''));
$fGroup = (int)($_GET['group'] ?? 0);
$fFunc = (string)($_GET['func'] ?? '');
$fStatus = (string)($_GET['status'] ?? '');
$fCity = (string)($_GET['city'] ?? '');
$fYear = (string)($_GET['year'] ?? '');
$fLetter = mb_strtoupper(mb_substr((string)($_GET['letter'] ?? ''), 0, 1));
$fPhoto = !empty($_GET['photo']);
$sort = in_array($_GET['sort'] ?? '', ['name', 'join', 'city'], true) ? $_GET['sort'] : 'name';
$per = in_array((int)($_GET['per'] ?? 25), [25, 50, 100], true) ? (int)($_GET['per'] ?? 25) : 25;
$page = max(1, (int)($_GET['page'] ?? 1));

$allGroups = $allFuncs = $allCities = $allYears = [];
foreach ($people as $d) { $allGroups += $d['groups']; $allFuncs += $d['funcs']; foreach ($d['cities'] as $c) $allCities[$c] = $c; if ($d['join']) $allYears[substr($d['join'], 0, 4)] = substr($d['join'], 0, 4); }
asort($allGroups); asort($allFuncs); ksort($allCities); krsort($allYears);

$hit = array_values(array_filter($people, function ($d) use ($q, $fGroup, $fFunc, $fStatus, $fCity, $fYear, $fLetter, $fPhoto) {
    if ($q !== '' && mb_strpos($d['hay'], mb_strtolower($q)) === false) return false;
    if ($fGroup && !isset($d['groups'][$fGroup])) return false;
    if ($fFunc !== '' && !isset($d['funcs'][$fFunc])) return false;
    if ($fStatus !== '' && $d['status'] !== $fStatus) return false;
    if ($fCity !== '' && !in_array($fCity, $d['cities'], true)) return false;
    if ($fYear !== '' && substr((string)$d['join'], 0, 4) !== $fYear) return false;
    if ($fLetter !== '' && mb_strtoupper(mb_substr($d['last'], 0, 1)) !== $fLetter) return false;
    if ($fPhoto && !$d['photos']) return false;
    return true;
}));
usort($hit, function ($a, $b) use ($sort) {
    if ($sort === 'join') return strcmp((string)$b['join'], (string)$a['join']) ?: strcasecmp($a['last'], $b['last']);
    if ($sort === 'city') return strcasecmp($a['city'] ?: 'zzz', $b['city'] ?: 'zzz') ?: strcasecmp($a['last'], $b['last']);
    return strcasecmp($a['last'], $b['last']) ?: strcasecmp($a['name'], $b['name']);
});
$total = count($hit);
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$slice = array_slice($hit, ($page - 1) * $per, $per);
$letters = [];
foreach ($people as $d) $letters[mb_strtoupper(mb_substr($d['last'], 0, 1))] = true;
ksort($letters);

$keep = ['q' => $q, 'group' => $fGroup ?: '', 'func' => $fFunc, 'status' => $fStatus, 'city' => $fCity, 'year' => $fYear, 'letter' => $fLetter, 'photo' => $fPhoto ? 1 : '', 'sort' => $sort === 'name' ? '' : $sort, 'per' => $per === 25 ? '' : $per];
$url = fn(array $over = []) => 'directory.php' . (($qs = http_build_query(array_filter($over + $keep, fn($x) => $x !== '' && $x !== null))) !== '' ? '?' . $qs : '');

md_header('Mitgliederverzeichnis', $me, 'directory');
echo '<h1>Mitgliederverzeichnis</h1><p class="muted">' . ($seeAll ? 'Du siehst als Verwaltung alle Angaben. ' : 'Du siehst, was die Mitglieder für das Verzeichnis freigegeben haben. ') . count($people) . ' Mitglieder.</p>';
echo '<form class="filters" method="get"><label class="grow">Suche<input name="q" value="' . h($q) . '" placeholder="Name, Ort, Beruf, Gruppe, Funktion …"></label>';
$sel = function (string $name, string $label, array $opts, $cur, string $all = 'alle') {
    $o = '<label>' . h($label) . '<select name="' . $name . '"><option value="">' . h($all) . '</option>';
    foreach ($opts as $k => $t) $o .= '<option value="' . h((string)$k) . '"' . ((string)$cur === (string)$k && (string)$cur !== '' ? ' selected' : '') . '>' . h((string)$t) . '</option>';
    return $o . '</select></label>';
};
echo $sel('group', 'Teilgruppe', $allGroups, $fGroup) . $sel('func', 'Funktion', $allFuncs, $fFunc) . $sel('status', 'Status', ['aktiv' => 'Aktiv', 'ruhend' => 'Ruhend', 'ehrenmitglied' => 'Ehrenmitglied'], $fStatus)
    . $sel('city', 'Ort', $allCities, $fCity) . $sel('year', 'Eintritt', $allYears, $fYear)
    . '<label>Sortierung<select name="sort"><option value="name"' . ($sort === 'name' ? ' selected' : '') . '>Name</option><option value="join"' . ($sort === 'join' ? ' selected' : '') . '>Eintritt (neueste zuerst)</option><option value="city"' . ($sort === 'city' ? ' selected' : '') . '>Ort</option></select></label>'
    . '<label>pro Seite<select name="per">' . implode('', array_map(fn($n) => '<option' . ($per === $n ? ' selected' : '') . '>' . $n . '</option>', [25, 50, 100])) . '</select></label>'
    . '<label class="check"><input type="checkbox" name="photo" value="1"' . ($fPhoto ? ' checked' : '') . '> nur mit Foto</label><button>Filtern</button> <a class="btn small ghost" href="directory.php">Zurücksetzen</a></form>';
echo '<div class="alpha"><a href="' . h($url(['letter' => '', 'page' => ''])) . '"' . ($fLetter === '' ? ' class="on"' : '') . '>Alle</a>';
foreach (array_keys($letters) as $L) echo '<a href="' . h($url(['letter' => $L, 'page' => ''])) . '"' . ($fLetter === $L ? ' class="on"' : '') . '>' . h($L) . '</a>';
echo '</div>';
$back = http_build_query(array_filter($keep + ['page' => $page > 1 ? $page : ''], fn($x) => $x !== '' && $x !== null));
echo '<details class="acc inner"><summary>Spalten wählen</summary><div class="accbody"><form method="post" class="colform">' . md_csrf_field() . '<input type="hidden" name="back" value="' . h($back) . '"><div class="colpick">';
foreach (DIR_COLUMNS as $k => $label) echo '<label class="check"><input type="checkbox" name="cols[]" value="' . $k . '"' . (in_array($k, $cols, true) ? ' checked' : '') . '> ' . h($label) . '</label>';
echo '</div><p><button class="small">Speichern</button> <button class="small secondary" name="reset" value="1">Standard</button> <span class="muted">Gilt nur für dich. Name und Foto sind immer sichtbar.</span></p></form></div></details>';
echo '<p class="muted">' . $total . ' Treffer' . ($total !== count($people) ? ' von ' . count($people) : '') . '.</p>';
$colspan = 2 + count($cols);
$th = fn(string $k) => '<th>' . h(DIR_COLUMNS[$k]) . '</th>';
echo '<section class="card"><table class="dir"><thead><tr><th></th><th>Name</th>' . implode('', array_map($th, $cols)) . '</tr></thead><tbody>';
foreach ($slice as $d) {
    $first = $d['photos'] ? reset($d['photos']) : null;
    $hasMore = $d['details'] || count($d['photos']) > 1;
    echo '<tr><td class="ph">' . ($first ? '<img class="thumb" src="photo.php?id=' . $first . '" alt="">' : '<span class="avatar">' . h(mb_strtoupper(mb_substr($d['name'], 0, 1) . mb_substr($d['last'], 0, 1))) . '</span>') . '</td>';
    echo '<td><strong>' . h($d['name']) . '</strong>' . ($d['nick'] ? ' <span class="muted">„' . h($d['nick']) . '“</span>' : '') . ($hasMore ? '<br><button type="button" class="more">Mehr ▸</button>' : '') . '</td>';
    foreach ($cols as $c) {
        echo '<td' . (in_array($c, ['zipcity', 'phone', 'join'], true) ? ' style="white-space:nowrap"' : '') . '>';
        switch ($c) {
            case 'groups': foreach ($d['groups'] as $gn) echo '<span class="chip">' . h($gn) . '</span> '; break;
            case 'funcs': foreach ($d['funcs'] as $fn) echo '<span class="chip fn">' . h($fn) . '</span> '; break;
            case 'zipcity': echo h($d['zipcity']); break;
            case 'phone': echo h($d['phone']); break;
            case 'email': echo $d['email'] ? '<a href="mailto:' . h($d['email']) . '">' . h($d['email']) . '</a>' : ''; break;
            case 'interests': echo h(str_replace("\n", ', ', mb_strimwidth($d['interests'], 0, 80, '…'))); break;
            case 'occupation': echo h($d['occupation']); break;
            case 'join': echo h($d['join'] ? md_date($d['join']) : ''); break;
            case 'status': echo h(MD_STATUS[$d['status']] ?? $d['status']); break;
        }
        echo '</td>';
    }
    echo '</tr>';
    if ($hasMore) {
        echo '<tr class="more" hidden><td colspan="' . $colspan . '"><div class="moreph">';
        foreach ($d['photos'] as $pid) echo '<img class="thumb" src="photo.php?id=' . $pid . '" alt="">';
        echo '</div><dl class="moregrid">';
        foreach ($d['details'] as $k => $val) echo '<div><dt>' . h($k) . '</dt><dd>' . nl2br(h($val)) . '</dd></div>';
        if (in_array('groups', $cols, true) === false && $d['groups']) echo '<div><dt>Teilgruppen</dt><dd>' . h(implode(', ', $d['groups'])) . '</dd></div>';
        echo '</dl></td></tr>';
    }
}
if (!$slice) echo '<tr><td colspan="' . $colspan . '" class="muted">Keine Treffer. <a href="directory.php">Filter zurücksetzen</a></td></tr>';
echo '</tbody></table></section>';
if ($pages > 1) {
    echo '<div class="pager">Seite ';
    for ($i = 1; $i <= $pages; $i++) {
        if ($pages > 12 && $i > 2 && $i < $pages - 1 && abs($i - $page) > 2) { if (!isset($dots[$i > $page ? 'r' : 'l'])) { $dots[$i > $page ? 'r' : 'l'] = 1; echo '<span>…</span>'; } continue; }
        echo $i === $page ? '<span class="cur">' . $i . '</span>' : '<a href="' . h($url(['page' => $i])) . '">' . $i . '</a>';
    }
    echo '</div>';
}
md_footer();
