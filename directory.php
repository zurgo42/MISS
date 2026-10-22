<?php
require_once __DIR__ . '/src/bootstrap.php';
$me = md_require_login();
if (!md_consent_ok((int)$me['member_id']) || !md_can($me, 'directory', 'view')) {
    md_flash('Das Mitgliederverzeichnis ist für dich nicht verfügbar' . (md_consent_ok((int)$me['member_id']) ? '.' : ': Bitte stimme zuerst den Nutzungsbedingungen zu.'), 'warn');
    header('Location: ' . md_url('index.php'));
    exit;
}
$q = trim((string)($_GET['q'] ?? ''));
$rows = md_q("SELECT s.*, p.status, p.nickname, p.occupation, p.employer, p.hobbies, p.interests, p.intro_text, p.birthdate
              FROM svmembers s LEFT JOIN md_profiles p ON p.member_id = s.member_id
              WHERE s.is_active = 1 AND COALESCE(p.status, 'aktiv') NOT IN ('ausgetreten', 'interessent')
              ORDER BY s.last_name, s.first_name");
md_header('Mitgliederverzeichnis', $me, 'directory');
echo '<h1>Mitgliederverzeichnis</h1><p class="muted">Du siehst nur, was die Mitglieder für dich freigegeben haben.</p>';
echo '<form class="search" method="get"><input name="q" value="' . h($q) . '" placeholder="Name, Ort, Beruf, Gruppe …"><button>Suchen</button></form><div class="grid3">';
$shown = 0;
foreach ($rows as $r) {
    $id = (int)$r['member_id'];
    $v = md_visibility_map($id);
    $see = fn(string $k) => md_can_see($me, $id, $k, $v);
    $groups = md_load_groups($id);
    $photos = md_load_photos($id);
    $addr = md_load_addresses($id);
    $contacts = md_load_contacts($id);
    $funcs = array_filter(md_load_functions($id), fn($f) => !$f['valid_to'] || $f['valid_to'] >= date('Y-m-d'));
    $details = [];
    if ($r['nickname'] && $see('nickname')) $details['Rufname'] = $r['nickname'];
    if ($r['occupation'] && $see('occupation')) $details['Beruf'] = $r['occupation'];
    if ($r['employer'] && $see('employer')) $details['Arbeitgeber'] = $r['employer'];
    if ($r['birthdate'] && $see('birthdate')) $details['Geburtstag'] = md_date($r['birthdate']);
    foreach ([1, 2, 3] as $s) if (isset($addr[$s]) && $see("address.$s")) $details[$addr[$s]['label'] ?: "Adresse $s"] = trim($addr[$s]['street'] . ', ' . $addr[$s]['zip'] . ' ' . $addr[$s]['city'], ', ');
    foreach ($contacts as $k => $val) if ($see("contact.$k")) $details[MD_CONTACT_KINDS[$k]] = $val;
    if ($r['hobbies'] && $see('hobbies')) $details['Hobbys'] = $r['hobbies'];
    if ($r['interests'] && $see('interests')) $details['Interessen'] = $r['interests'];
    if ($r['intro_text'] && $see('intro_text')) $details['Das bin ich'] = $r['intro_text'];
    $hay = mb_strtolower($r['first_name'] . ' ' . $r['last_name'] . ' ' . implode(' ', $details) . ' ' . implode(' ', array_column($groups, 'name')));
    if ($q !== '' && mb_strpos($hay, mb_strtolower($q)) === false) continue;
    $shown++;
    $ph = null;
    foreach ($photos as $s => $p) if ($see("photo.$s")) { $ph = $p; break; }
    echo '<article class="card person">' . ($ph ? '<img class="thumb" src="photo.php?id=' . (int)$ph['photo_id'] . '" alt="">' : '<span class="avatar">' . h(md_initials($r)) . '</span>');
    echo '<h3>' . h($r['first_name'] . ' ' . $r['last_name']) . '</h3><p>';
    foreach ($funcs as $f) echo '<span class="chip fn">' . h($f['name']) . '</span> ';
    foreach ($groups as $g) echo '<span class="chip">' . h($g['name']) . '</span> ';
    echo '</p>';
    if ($details) {
        echo '<details><summary>Details</summary><dl>';
        foreach ($details as $k => $val) echo '<dt>' . h($k) . '</dt><dd>' . nl2br(h($val)) . '</dd>';
        echo '</dl></details>';
    }
    echo '</article>';
}
if (!$shown) echo '<p class="muted">Keine Treffer.</p>';
echo '</div>';
md_footer();
