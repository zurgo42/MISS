<?php
/**
 * demo_build.php – erzeugt tools/demo_data.json (deterministisch, frei erfundene Daten).
 * Dieselben 12 Figuren wie in der Sitzungsverwaltung (tools/demo_build.php dort), hier mit
 * Profil, Adressen, Kontakten, Gruppen, Funktionen, Finanzen und Zustimmungen.
 *
 * Aufruf: php tools/demo_build.php [--anchor=YYYY-MM-DD]
 * Relative Datumsangaben (offene Posten, Fälligkeiten) hängen am Anker, Standard heute.
 */

function demo_build(string $anchor): array
{
    $A = strtotime($anchor);
    $Y = (int)date('Y', $A);
    $d = fn(int $days) => date('Y-m-d', strtotime("$days days", $A));
    $dt = fn(int $days, string $t = '10:00:00') => date('Y-m-d', strtotime("$days days", $A)) . ' ' . $t;

    // id, Vorname, Nachname, Mail, Rolle, aktiv, Funktion, Admin, aktiv?
    $members = [
        [1,  'Max',    'Mustermann', 'max',    'vorstand',      19, 'FVo', 1, 1],
        [2,  'Erika',  'Musterfrau', 'erika',  'vorstand',      19, 'FVv', 0, 1],
        [3,  'Lukas',  'Beispiel',   'lukas',  'vorstand',      19, 'Vo',  0, 1],
        [4,  'Julia',  'Sommer',     'julia',  'gf',            18, 'GF',  1, 1],
        [5,  'Thomas', 'Winter',     'thomas', 'assistenz',     17, 'VA',  1, 1],
        [6,  'Sabine', 'Herbst',     'sabine', 'assistenz',     11, 'SV',  1, 1],
        [7,  'Hans',   'Lehmann',    'hans',   'fuehrungsteam', 17, 'RL',  0, 1],
        [8,  'Anna',   'Frühling',   'anna',   'fuehrungsteam', 15, 'RL',  0, 1],
        [9,  'Petra',  'Nord',       'petra',  'fuehrungsteam', 14, 'RL',  0, 1],
        [10, 'Karl',   'Prüfer',     'karl',   'Mitglied',      3,  'FP',  0, 1],
        [11, 'Mia',    'Technik',    'mia',    'Mitglied',      10, 'AD',  1, 1],
        [12, 'Rolf',   'Ehemals',    'rolf',   'Mitglied',      0,  'Vx',  0, 0],
    ];
    $conf = [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 0, 9 => 0, 10 => 0, 11 => 0, 12 => 0];
    $svmembers = [];
    foreach ($members as [$id, $fn, $ln, $mail, $role, $aktiv, $fkt, $adm, $act]) {
        $svmembers[] = ['is_confidential' => $conf[$id], 'member_id' => $id, 'membership_number' => sprintf('DEMO%04d', $id), 'first_name' => $fn, 'last_name' => $ln,
            'email' => "$mail@example.com", 'role' => $role, 'aktiv' => $aktiv, 'funktion' => $fkt, 'is_admin' => $adm, 'is_active' => $act];
    }

    require_once dirname(__DIR__) . '/src/fields.php';
    $functions = MD_DEFAULT_FUNCTIONS;
    $fid = array_flip(array_column($functions, 0));
    $groups = [
        ['Regionalgruppe Nord', 'region'], ['Regionalgruppe Süd', 'region'], ['Schachgruppe', 'interesse'],
        ['Wandergruppe', 'interesse'], ['Projektgruppe Satzung', 'projekt'],
    ];
    $gid = array_flip(array_column($groups, 0));

    // Profile: id => [Anrede, Titel, Rufname, Geburtsdatum, Beruf, Arbeitgeber, Beziehung, Hobbys, Interessen, Vorstellung, Wünsche, Eintritt, Status]
    $P = [
        1 => ['Herr', 'Dr.', null, '1968-03-14', 'Steuerberater', 'Kanzlei Mustermann & Partner', 'verheiratet', "Segeln\nSchach", 'Vereinsfinanzen, Datenschutz, Genossenschaftsrecht',
            'Seit der Gründung dabei und für die Finanzen zuständig. Ich mag klare Zahlen und kurze Sitzungen.', 'Mehr digitale Abläufe und weniger Papier bei der Beitragsverwaltung.', '2009-04-01', 'aktiv'],
        2 => ['Frau', null, 'Eri', '1971-09-02', 'Schulleiterin', 'Gesamtschule Lindenau', 'verheiratet', "Gärtnern\nLesen", 'Bildung, Nachwuchsarbeit',
            'Ich vertrete den Verein gern nach außen und kümmere mich um die Jugendarbeit.', 'Eine feste Anlaufstelle für neue Mitglieder in den ersten Monaten.', '2010-01-15', 'aktiv'],
        3 => ['Herr', null, null, '1980-11-23', 'Softwareentwickler', 'Beispiel Systems GmbH', 'in Beziehung', "Radfahren\nFotografie", 'Open Source, Vereinsorganisation',
            'Technikfreund mit Hang zu gut dokumentierten Prozessen.', 'Ein Mitgliederbereich, der auch auf dem Handy gut funktioniert.', '2014-06-01', 'aktiv'],
        4 => ['Frau', null, null, '1976-05-30', 'Betriebswirtin', 'Verein (hauptamtlich)', 'ledig', "Wandern\nKochen", 'Organisation, Veranstaltungen',
            'Ich halte die Fäden in der Geschäftsstelle zusammen und plane die Jahrestagung.', 'Verlässliche Fristen und eine gemeinsame Terminübersicht.', '2016-03-01', 'aktiv'],
        5 => ['Herr', null, 'Tom', '1985-02-08', 'Verwaltungsfachangestellter', 'Stadtverwaltung Nordstadt', 'verheiratet', "Modellbau\nBrettspiele", 'Satzungsfragen, Protokolle',
            'Ich unterstütze den Vorstand bei Protokollen und Anträgen.', 'Klarere Zuständigkeiten, wer welche Anträge bearbeitet.', '2017-09-01', 'aktiv'],
        6 => ['Frau', null, null, '1990-07-19', 'Sekretärin', 'Autohaus Herbst', 'in Beziehung', "Yoga\nBacken", 'Kommunikation, Mitgliederbetreuung',
            'Erste Ansprechpartnerin im Sekretariat.', 'Einen Newsletter, der wirklich gelesen wird.', '2019-02-01', 'aktiv'],
        7 => ['Herr', 'Prof.', null, '1964-12-01', 'Hochschullehrer', 'Universität Lehmstadt', 'verheiratet', "Wandern\nKlavier", 'Wissenschaft, Weiterbildung',
            'Ich leite die Kurse im Ressort Bildung und halte gern Vorträge.', 'Mehr Kooperation zwischen den Regionalgruppen.', '2011-10-01', 'aktiv'],
        8 => ['Frau', null, null, '1983-04-17', 'Physiotherapeutin', 'Praxis am Markt', 'verheiratet', "Laufen\nSchwimmen", 'Gesundheit, Sport',
            'Ich leite die Bewegungsangebote des Vereins.', 'Hallenzeiten, die sich besser planen lassen.', '2015-05-01', 'aktiv'],
        9 => ['Frau', null, 'Pe', '1992-08-25', 'Journalistin', 'freiberuflich', 'ledig', "Reisen\nSchach", 'Öffentlichkeitsarbeit, Social Media',
            'Presse und Social Media – wer etwas posten will, meldet sich bei mir.', 'Mehr Beteiligung der jüngeren Mitglieder.', '2020-01-01', 'aktiv'],
        10 => ['Herr', null, null, '1958-06-11', 'Wirtschaftsprüfer i. R.', null, 'verwitwet', "Garten\nBriefmarken", 'Prüfung, Rechnungswesen',
            'Ich prüfe die Kasse und habe dafür einen Blick für Belege.', 'Nachvollziehbare Belegablage.', '2012-03-01', 'aktiv'],
        11 => ['Divers', null, null, '1995-01-29', 'IT-Administrator*in', 'Rechenzentrum Süd', 'keine Angabe', "Gaming\nLöten", 'Server, Sicherheit',
            'Ich halte die Technik des Vereins am Laufen.', 'Zwei-Faktor-Anmeldung für alle.', '2022-04-01', 'aktiv'],
        12 => ['Herr', null, null, '1960-10-05', 'Rentner', null, 'geschieden', 'Angeln', 'Vereinsgeschichte',
            'Früher im Vorstand, inzwischen ausgetreten.', null, '2008-05-01', 'ausgetreten'],
    ];
    $profiles = [];
    foreach ($P as $id => $r) {
        $profiles[] = ['member_id' => $id, 'salutation' => $r[0], 'title' => $r[1], 'nickname' => $r[2], 'birthdate' => $r[3], 'occupation' => $r[4], 'employer' => $r[5],
            'relationship_status' => $r[6], 'hobbies' => $r[7], 'interests' => $r[8], 'intro_text' => $r[9], 'wishes_text' => $r[10], 'join_date' => $r[11], 'status' => $r[12],
            'leave_date' => $id === 12 ? ($Y - 1) . '-12-31' : null,
            'admin_note' => $id === 3 ? 'Hat Neufassung der Bedingungen noch nicht bestätigt.' : ($id === 11 ? 'Bitte E-Mail-Adresse vor dem nächsten Rundbrief prüfen.' : null)];
    }

    // Adressen: id => Liste [Bezeichnung, Straße, PLZ, Ort, Land]
    $A_ = [
        1 => [['Privat', 'Gartenweg 4', '30159', 'Hannover', 'Deutschland'], ['Dienstlich', 'Marktstraße 12', '30159', 'Hannover', 'Deutschland']],
        2 => [['Privat', 'Lindenallee 17', '28195', 'Bremen', 'Deutschland']],
        3 => [['Privat', 'Am Mühlbach 3', '50667', 'Köln', 'Deutschland'], ['Zweitwohnsitz', 'Dünenstraße 8', '26579', 'Baltrum', 'Deutschland']],
        4 => [['Privat', 'Hafenstraße 21', '20457', 'Hamburg', 'Deutschland']],
        5 => [['Privat', 'Birkenweg 9', '24103', 'Kiel', 'Deutschland']],
        6 => [['Privat', 'Rosenstraße 5', '80331', 'München', 'Deutschland']],
        7 => [['Privat', 'Professorenpfad 2', '37073', 'Göttingen', 'Deutschland'], ['Dienstlich', 'Universitätsplatz 1', '37073', 'Göttingen', 'Deutschland'], ['Ferienhaus', 'Seeblick 14', '17255', 'Wesenberg', 'Deutschland']],
        8 => [['Privat', 'Sonnenhang 6', '79098', 'Freiburg', 'Deutschland']],
        9 => [['Privat', 'Nordstraße 31', '10115', 'Berlin', 'Deutschland']],
        10 => [['Privat', 'Eichenhof 1', '70173', 'Stuttgart', 'Deutschland']],
        11 => [['Privat', 'Serverstraße 404', '60311', 'Frankfurt am Main', 'Deutschland']],
        12 => [['Privat', 'Anglerweg 7', '23552', 'Lübeck', 'Deutschland']],
    ];
    $addresses = [];
    foreach ($A_ as $id => $list) foreach ($list as $i => $a) {
        $addresses[] = ['member_id' => $id, 'slot' => $i + 1, 'label' => $a[0], 'street' => $a[1], 'zip' => $a[2], 'city' => $a[3], 'country' => $a[4]];
    }

    // Kontakte: id => [Art => Wert]
    $C = [
        1 => ['email' => 'max.privat@example.com', 'phone' => '0511 5550101', 'mobile' => '0170 5550101', 'website' => 'https://mustermann-steuern.example.com', 'linkedin' => 'linkedin.com/in/max-mustermann-demo'],
        2 => ['email' => 'erika.privat@example.com', 'mobile' => '0171 5550102', 'instagram' => '@erika.musterfrau'],
        3 => ['email' => 'lukas.privat@example.com', 'mobile' => '0172 5550103', 'mastodon' => '@lukas@social.example.com', 'website' => 'https://lukas-beispiel.example.com'],
        4 => ['email' => 'julia.sommer@example.com', 'phone' => '040 5550104', 'mobile' => '0173 5550104', 'xing' => 'xing.com/profile/Julia_Sommer_demo'],
        5 => ['email' => 'thomas.winter@example.com', 'mobile' => '0174 5550105'],
        6 => ['email' => 'sabine.herbst@example.com', 'phone' => '089 5550106', 'instagram' => '@sabine.backt', 'whatsapp' => '0175 5550106'],
        7 => ['email' => 'hans.lehmann@example.com', 'phone' => '0551 5550107', 'fax' => '0551 5550199', 'website' => 'https://lehmann-uni.example.com', 'youtube' => 'youtube.com/@lehmann-vortraege-demo'],
        8 => ['email' => 'anna.fruehling@example.com', 'mobile' => '0176 5550108', 'facebook' => 'facebook.com/anna.fruehling.demo'],
        9 => ['email' => 'petra.nord@example.com', 'mobile' => '0177 5550109', 'instagram' => '@petra.nord', 'x' => '@petranord', 'tiktok' => '@petra.reist', 'telegram' => '@petranord'],
        10 => ['email' => 'karl.pruefer@example.com', 'phone' => '0711 5550110'],
        11 => ['phone' => '069 5550111', 'mastodon' => '@mia@chaos.example.com'],
        12 => ['email' => 'rolf.ehemals@example.com', 'phone' => '0451 5550112'],
    ];
    $contacts = [];
    foreach ($C as $id => $list) foreach ($list as $k => $v) $contacts[] = ['member_id' => $id, 'kind' => $k, 'value' => $v];

    // Sichtbarkeit: abweichend vom Standard (Beispiele für jede Stufe)
    $vis = [
        [1, 'birthdate', 'admin'], [1, 'address.2', 'all'], [1, 'contact.linkedin', 'all'], [1, 'contact.website', 'all'],
        [2, 'birthdate', 'group'], [2, 'contact.instagram', 'all'], [2, 'photo.2', 'me'],
        [3, 'contact.mastodon', 'all'], [3, 'contact.website', 'all'], [3, 'address.2', 'group'], [3, 'relationship_status', 'me'],
        [4, 'contact.phone', 'all'], [4, 'contact.mobile', 'all'], [4, 'address.1', 'me'], [4, 'birthdate', 'all'],
        [5, 'address.1', 'me'], [5, 'contact.mobile', 'group'],
        [6, 'contact.instagram', 'all'], [6, 'contact.email', 'all'],
        [7, 'address.2', 'all'], [7, 'contact.website', 'all'], [7, 'contact.youtube', 'all'], [7, 'wishes_text', 'all'],
        [8, 'contact.facebook', 'group'], [8, 'birthdate', 'group'],
        [9, 'contact.instagram', 'all'], [9, 'contact.x', 'all'], [9, 'contact.tiktok', 'all'], [9, 'contact.email', 'all'], [9, 'wishes_text', 'all'],
        [10, 'address.1', 'me'], [10, 'contact.phone', 'me'],
        [11, 'contact.mastodon', 'all'], [11, 'address.1', 'me'],
    ];
    $visibility = array_map(fn($v) => ['member_id' => $v[0], 'field_key' => $v[1], 'level' => $v[2]], $vis);

    // Gruppen und Funktionen
    $mg = [[1, 'Regionalgruppe Nord'], [1, 'Schachgruppe'], [2, 'Regionalgruppe Nord'], [3, 'Regionalgruppe Süd'], [3, 'Projektgruppe Satzung'],
           [4, 'Regionalgruppe Nord'], [5, 'Regionalgruppe Nord'], [5, 'Projektgruppe Satzung'], [6, 'Regionalgruppe Süd'],
           [7, 'Wandergruppe'], [7, 'Regionalgruppe Süd'], [8, 'Regionalgruppe Süd'], [8, 'Wandergruppe'], [9, 'Regionalgruppe Nord'], [9, 'Schachgruppe'],
           [10, 'Regionalgruppe Süd'], [11, 'Regionalgruppe Süd'], [11, 'Projektgruppe Satzung']];
    $memberGroups = array_map(fn($x) => ['member_id' => $x[0], 'group_id' => $gid[$x[1]] + 1, 'since' => ($Y - 3) . '-01-01', 'until' => null], $mg);
    // [Mitglied, Code, von, bis, Level (null = Standard der Funktion), Ressort-ID]
    $svressorts = [[1, 'R01', 'Finanzen'], [2, 'R02', 'Bildung'], [3, 'R03', 'Öffentlichkeitsarbeit'], [4, 'R04', 'Sport & Gesundheit'], [5, 'R05', 'Veranstaltungen']];
    $mf = [[1, 'FVo', ($Y - 7) . '-05-01', null, null, null], [2, 'FVv', ($Y - 6) . '-05-01', null, null, null], [3, 'Vo', ($Y - 4) . '-05-01', null, null, null],
           [4, 'GF', ($Y - 10) . '-03-01', null, null, null], [5, 'VA', ($Y - 9) . '-09-01', null, 17, null], [6, 'SV', ($Y - 7) . '-02-01', null, null, null],
           [7, 'RL', ($Y - 12) . '-10-01', null, 17, 2], [8, 'RL', ($Y - 8) . '-05-01', null, 15, 4], [9, 'RL', ($Y - 5) . '-01-01', null, 14, 3],
           [10, 'FP', ($Y - 3) . '-05-01', null, 3, null], [11, 'AD', ($Y - 4) . '-04-01', null, 10, null], [5, 'MB', ($Y - 2) . '-01-01', null, null, null],
           [12, 'Vo', ($Y - 12) . '-05-01', ($Y - 3) . '-04-30', null, null], [12, 'Vx', ($Y - 3) . '-05-01', null, 0, null],
           [3, 'PL', ($Y - 6) . '-01-01', ($Y - 3) . '-12-31', null, null], [7, 'JT', ($Y - 3) . '-01-01', null, 12, 5]];
    $memberFunctions = array_map(fn($x) => ['member_id' => $x[0], 'function_id' => $fid[$x[1]] + 1, 'valid_from' => $x[2], 'valid_to' => $x[3], 'aktiv_level' => $x[4], 'ressort_id' => $x[5], 'note' => null], $mf);

    // Finanzen: [Mitglied, Art, Betrag €, Datum, Fällig, Bezahlt, Status, Beschreibung, Beleg]
    $L = [];
    $fee = function (int $id, float $amount, string $label) use (&$L, $Y, $d, $dt) {
        $L[] = [$id, 'beitrag', $amount, ($Y - 1) . '-01-15', ($Y - 1) . '-02-15', ($Y - 1) . '-02-' . sprintf('%02d', 10 + $id), 'paid', "Mitgliedsbeitrag " . ($Y - 1) . " ($label)", 'R' . ($Y - 1) . sprintf('%04d', $id)];
    };
    foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] as $id) $fee($id, in_array($id, [10, 11], true) ? 60.0 : 120.0, in_array($id, [10, 11], true) ? 'ermäßigt' : 'Vollmitglied');
    foreach ([1, 2, 4, 5, 6, 7, 8, 9, 10] as $id) {
        $L[] = [$id, 'beitrag', $id === 10 ? 60.0 : 120.0, "$Y-01-15", "$Y-02-15", "$Y-02-" . sprintf('%02d', 8 + $id), 'paid', "Mitgliedsbeitrag $Y (" . ($id === 10 ? 'ermäßigt' : 'Vollmitglied') . ')', "R$Y" . sprintf('%04d', $id)];
    }
    $L[] = [3, 'beitrag', 120.0, "$Y-01-15", $d(-45), null, 'open', "Mitgliedsbeitrag $Y (Vollmitglied) – 1. Zahlungserinnerung", "R$Y" . '0003'];
    $L[] = [11, 'beitrag', 60.0, $d(-10), $d(20), null, 'open', "Mitgliedsbeitrag $Y (ermäßigt)", "R$Y" . '0011'];
    // Eintrittsgelder
    foreach ([1, 2, 4, 5, 7, 8, 9] as $id) $L[] = [$id, 'eintritt', 45.0, "$Y-05-02", null, "$Y-05-02", 'paid', "Jahrestagung $Y – Teilnahmegebühr", "T$Y" . sprintf('%03d', $id)];
    foreach ([1, 3, 6, 9, 10] as $id) $L[] = [$id, 'eintritt', 15.0, "$Y-07-18", null, "$Y-07-18", 'paid', "Sommerfest $Y – Eintritt", "S$Y" . sprintf('%03d', $id)];
    $L[] = [3, 'eintritt', 15.0, $d(5), $d(12), null, 'open', "Herbstwanderung – Anmeldegebühr", "H$Y" . '003'];
    // Spenden
    $L[] = [1, 'spende', 100.0, "$Y-03-20", null, "$Y-03-20", 'paid', 'Spende für die Jugendarbeit', 'SP' . $Y . '01'];
    $L[] = [2, 'spende', 50.0, "$Y-06-04", null, "$Y-06-04", 'paid', 'Spende Sommerfest', 'SP' . $Y . '02'];
    $L[] = [4, 'spende', 25.0, "$Y-09-12", null, "$Y-09-12", 'paid', 'Spende', 'SP' . $Y . '03'];
    $L[] = [7, 'spende', 200.0, ($Y - 1) . '-12-20', null, ($Y - 1) . '-12-20', 'paid', 'Jahresendspende', 'SP' . ($Y - 1) . '04'];
    $L[] = [7, 'spende', 150.0, "$Y-12-01", null, null, 'cancelled', 'Zugesagte Spende (storniert)', null];
    // Erstattungen an das Mitglied
    $L[] = [1, 'erstattung', 54.0, "$Y-04-11", null, "$Y-04-25", 'paid', 'Fahrtkosten Prüfungsgespräch', 'E' . $Y . '01'];
    $L[] = [4, 'erstattung', 14.8, "$Y-06-02", null, "$Y-06-10", 'paid', 'Porto Mitgliederpost', 'E' . $Y . '02'];
    $L[] = [5, 'erstattung', 86.4, "$Y-05-04", null, "$Y-05-16", 'paid', 'Reisekosten Vorstandssitzung', 'E' . $Y . '03'];
    $L[] = [9, 'erstattung', 23.1, "$Y-08-20", null, "$Y-08-31", 'paid', 'Material Infostand', 'E' . $Y . '04'];
    $L[] = [11, 'erstattung', 129.9, $d(-6), null, null, 'open', 'Ersatz-Router Geschäftsstelle (Beleg liegt vor)', 'E' . $Y . '05'];
    // Übungsleiterpauschale (Quartalsweise)
    foreach ([['03-31', 'I. Quartal'], ['06-30', 'II. Quartal'], ['09-30', 'III. Quartal']] as [$md, $q]) {
        $L[] = [7, 'uebungsleiter', 600.0, "$Y-$md", null, date('Y-m-d', strtotime("$Y-$md +5 days")), 'paid', "Übungsleiterpauschale $q $Y (Kurse Bildung)", 'U' . $Y . '7' . substr($md, 1, 1)];
        $L[] = [8, 'uebungsleiter', 300.0, "$Y-$md", null, date('Y-m-d', strtotime("$Y-$md +5 days")), 'paid', "Übungsleiterpauschale $q $Y (Bewegungsangebote)", 'U' . $Y . '8' . substr($md, 1, 1)];
    }
    $ledger = [];
    foreach ($L as $r) {
        $ledger[] = ['member_id' => $r[0], 'kind' => $r[1], 'direction' => in_array($r[1], ['erstattung', 'uebungsleiter'], true) ? 'to_member' : 'to_club',
            'amount_cents' => (int)round($r[2] * 100), 'entry_date' => $r[3], 'due_date' => $r[4], 'paid_date' => $r[5], 'status' => $r[6], 'description' => $r[7], 'reference' => $r[8]];
    }

    // Nutzungsbedingungen
    $body1 = "1. Zweck\nDiese Plattform dient der Pflege der Mitgliedsdaten und der Kommunikation im Verein.\n\n2. Deine Angaben\nDu pflegst deine Daten selbst. Jede Änderung wird mit Zeitpunkt und Bearbeiter protokolliert.\n\n3. Sichtbarkeit\nDu bestimmst für jedes Feld, wer es sehen darf. Ohne deine Freigabe sehen andere Mitglieder nur Name und Funktion.\n\n4. Datenschutz\nDie Daten werden nur für Vereinszwecke verwendet und nicht an Dritte weitergegeben.";
    $body2 = $body1 . "\n\n5. Fotos (neu)\nHochgeladene Fotos werden verkleinert gespeichert. Mit der Freigabe für andere Mitglieder erklärst du dich mit der Anzeige im Mitgliederverzeichnis einverstanden. Die Freigabe kannst du jederzeit ändern.\n\n6. Widerruf\nDu kannst deine Zustimmung jederzeit widerrufen. Dann bleibt der Zugang eingeschränkt, bis du erneut zustimmst.";
    $terms = [
        ['terms_id' => 1, 'doc_type' => 'nutzungsbedingungen', 'version_label' => '1', 'title' => 'Nutzungsbedingungen und Datenschutzhinweise', 'body' => $body1, 'valid_from' => ($Y - 2) . '-01-01', 'requires_consent' => 1],
        ['terms_id' => 2, 'doc_type' => 'nutzungsbedingungen', 'version_label' => '2', 'title' => 'Nutzungsbedingungen und Datenschutzhinweise', 'body' => $body2, 'valid_from' => $d(-120), 'requires_consent' => 1],
    ];
    // Zustimmungen: [Mitglied, Version, wann, Weg, erfasst von, Vermerk, widerrufen]
    $cs = [
        [1, 2, $dt(-110, '09:12:00'), 'self', 1, null, null], [2, 2, $dt(-109, '18:40:00'), 'self', 2, null, null],
        [3, 1, $dt(-600, '20:05:00'), 'self', 3, null, null], [4, 2, $dt(-118, '08:30:00'), 'self', 4, null, null],
        [5, 2, $dt(-117, '11:02:00'), 'self', 5, null, null], [6, 2, $dt(-100, '14:15:00'), 'self', 6, null, null],
        [7, 2, $dt(-95, '09:50:00'), 'admin', 6, 'telefonisch bestätigt, durch Sekretariat erfasst', null],
        [8, 2, $dt(-90, '19:20:00'), 'self', 8, null, null], [9, 2, $dt(-88, '12:00:00'), 'self', 9, null, null],
        [10, 2, $dt(-70, '12:00:00'), 'paper', 5, 'Brief vom ' . date('d.m.Y', strtotime('-72 days', $A)) . ', Eingang Geschäftsstelle', null],
        [12, 1, $dt(-900, '10:00:00'), 'self', 12, null, $dt(-300, '10:00:00')],
    ];
    $consents = [];
    foreach ($cs as $i => $c) {
        $consents[] = ['consent_id' => $i + 1, 'member_id' => $c[0], 'terms_id' => $c[1], 'consented_at' => $c[2], 'channel' => $c[3], 'recorded_by' => $c[4],
            'ip_truncated' => $c[3] === 'self' ? '203.0.113.0' : null, 'note' => $c[5], 'revoked_at' => $c[6]];
    }

    // Änderungsprotokoll (Beispiele)
    $log = [
        [3, 'contact.mastodon', null, '@lukas@social.example.com', 3, 'self', $dt(-60, '21:10:00')],
        [3, 'address.2.city', null, 'Baltrum', 3, 'self', $dt(-60, '21:12:00')],
        [7, 'wishes_text', null, 'Mehr Kooperation zwischen den Regionalgruppen.', 6, 'admin', $dt(-50, '10:30:00')],
        [9, 'visibility.contact.tiktok', 'Nur ich', 'Alle Mitglieder', 9, 'self', $dt(-30, '17:45:00')],
        [11, 'status', null, 'aktiv', 5, 'import', $dt(-400, '09:00:00')],
        [4, 'address.1.street', 'Hafenstr. 21', 'Hafenstraße 21', 4, 'self', $dt(-14, '08:20:00')],
    ];
    $changelog = array_map(fn($r) => ['member_id' => $r[0], 'field_key' => $r[1], 'old_value' => $r[2], 'new_value' => $r[3], 'changed_by' => $r[4], 'changed_via' => $r[5], 'changed_at' => $r[6]], $log);

    // Import-Profil als Beispiel
    $importProfiles = [['name' => 'Beispiel: Excel-Export Altverwaltung', 'source_software' => 'Excel', 'delimiter_char' => ';',
        'mapping' => json_encode(['map' => ['membership_number' => 'Mitgl-Nr', 'first_name' => 'Vorname', 'last_name' => 'Nachname', 'email' => 'E-Mail', 'birthdate' => 'Geb.-Datum',
            'join_date' => 'Eintritt', 'street' => 'Strasse', 'zip' => 'PLZ', 'city' => 'Ort', 'groups' => 'Gruppe'], 'opts' => ['update_existing' => true, 'placeholder_email' => false]], JSON_UNESCAPED_UNICODE)]];

    return [
        'generator' => 'tools/demo_build.php', 'anchor_date' => $anchor,
        'svmembers' => $svmembers,
        'svressorts' => array_map(fn($r, $i) => ['ID' => $i + 1, 'Code' => $r[1], 'Ressort' => $r[2], 'Reihenfolge' => ($i + 1) * 10, 'aktiv' => 1], $svressorts, array_keys($svressorts)),
        'md_functions' => array_map(fn($f) => ['code' => $f[0], 'name' => $f[1], 'sv_aktiv_level' => $f[2]], $functions),
        'md_groups' => array_map(fn($g) => ['name' => $g[0], 'kind' => $g[1]], $groups),
        'md_profiles' => $profiles, 'md_addresses' => $addresses, 'md_contacts' => $contacts, 'md_visibility' => $visibility,
        'md_member_groups' => $memberGroups, 'md_member_functions' => $memberFunctions, 'md_ledger' => $ledger,
        'md_terms_versions' => $terms, 'md_terms_consents' => $consents, 'md_changelog' => $changelog, 'md_import_profiles' => $importProfiles,
        'md_settings' => [['setting_key' => 'ueb_freibetrag_cents', 'setting_value' => '330000', 'description' => 'Jahresfreibetrag Übungsleiterpauschale in Cent (Stand 2026: 3.300 €, bitte jährlich prüfen)']],
    ];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $anchor = date('Y-m-d');
    foreach ($argv as $a) if (preg_match('/^--anchor=(\d{4}-\d{2}-\d{2})$/', $a, $m)) $anchor = $m[1];
    file_put_contents(__DIR__ . '/demo_data.json', json_encode(demo_build($anchor), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    echo "tools/demo_data.json geschrieben (Anker $anchor)\n";
}
