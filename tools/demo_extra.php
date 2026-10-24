<?php
/**
 * demo_extra.php – 60 weitere "einfache" Demo-Mitglieder (DEMO0013–DEMO0072), damit das Mitgliederverzeichnis überzeugend wirkt.
 *   13–52 (40 "aktive"): Foto, unterschiedlich viele Inhalte, Beitragsgruppe, Nutzungsbedingungen akzeptiert
 *   53–72 (20 "passive"): nur Grunddaten (Name, E-Mail, Eintritt), keine Inhalte, keine Zustimmung
 * Sie sind keine wählbaren Demo-Zugänge. Deterministisch (gleicher Aufruf = gleiche Daten).
 */
function demo_extra(int $Y, array $groupIds, callable $dt): array
{
    $fFirst = ['Anja', 'Birgit', 'Carla', 'Dagmar', 'Elke', 'Franziska', 'Gudrun', 'Heike', 'Ines', 'Jana', 'Katrin', 'Lena', 'Marta', 'Nadine', 'Olga', 'Paula', 'Renate', 'Silke', 'Tanja', 'Ursula', 'Vera', 'Waltraud', 'Yvonne', 'Zoe', 'Ilse', 'Monika', 'Sonja', 'Eva', 'Clara', 'Hanna'];
    $mFirst = ['Achim', 'Bernd', 'Christian', 'Dieter', 'Emil', 'Felix', 'Georg', 'Holger', 'Ingo', 'Jan', 'Klaus', 'Lars', 'Markus', 'Norbert', 'Oliver', 'Peter', 'Rainer', 'Stefan', 'Thorsten', 'Uwe', 'Volker', 'Werner', 'Jonas', 'Ralf', 'Bruno', 'Heinz', 'Kurt', 'Leon', 'Martin', 'Tobias'];
    $last = ['Ahrens', 'Bauer', 'Conrad', 'Dietrich', 'Engel', 'Fischer', 'Graf', 'Hartmann', 'Imhof', 'Jäger', 'Keller', 'Lorenz', 'Möller', 'Neumann', 'Ostermann', 'Pohl', 'Quast', 'Richter', 'Schäfer', 'Thiel', 'Ulrich', 'Vogt', 'Weber', 'Zimmer', 'Albrecht', 'Brandt', 'Claasen', 'Dahl', 'Ehlers', 'Förster', 'Gerber', 'Haas', 'Iversen', 'Jahn', 'Kraus', 'Lange', 'Mertens', 'Nolte', 'Otto', 'Peters', 'Roth', 'Sander', 'Tietz', 'Unger', 'Voss', 'Wagner', 'Yilmaz', 'Ziegler', 'Arndt', 'Becker', 'Cordes', 'Decker', 'Eberle', 'Funke', 'Götz', 'Heine', 'Jordan', 'Kunze', 'Lindner', 'Maurer'];
    $cities = [['30159', 'Hannover'], ['28195', 'Bremen'], ['20457', 'Hamburg'], ['50667', 'Köln'], ['80331', 'München'], ['24103', 'Kiel'], ['79098', 'Freiburg'], ['37073', 'Göttingen'], ['10115', 'Berlin'],
               ['70173', 'Stuttgart'], ['01067', 'Dresden'], ['04109', 'Leipzig'], ['99084', 'Erfurt'], ['48143', 'Münster'], ['55116', 'Mainz'], ['66111', 'Saarbrücken'], ['39104', 'Magdeburg'], ['18055', 'Rostock'], ['26122', 'Oldenburg'], ['97070', 'Würzburg']];
    $streets = ['Lindenstraße', 'Bahnhofstraße', 'Gartenweg', 'Schulstraße', 'Birkenallee', 'Am Markt', 'Waldweg', 'Hauptstraße', 'Kirchplatz', 'Ringstraße', 'Mühlenweg', 'Bergstraße'];
    $jobs = ['Lehrerin', 'Ingenieur', 'Krankenpfleger', 'Architektin', 'Studentin', 'Rentner', 'Tischler', 'Ärztin', 'Bibliothekar', 'Programmierer', 'Buchhalterin', 'Gärtner', 'Fotografin', 'Elektriker', 'Apothekerin', 'Landwirt', 'Journalist', 'Physiotherapeutin'];
    $hobbies = ['Wandern', 'Lesen', 'Schach', 'Radfahren', 'Kochen', 'Gärtnern', 'Fotografie', 'Chor', 'Segeln', 'Yoga', 'Schwimmen', 'Malen', 'Basteln', 'Laufen', 'Brettspiele'];
    $interests = ['Natur und Umwelt', 'Geschichte', 'Vereinsorganisation', 'Reisen', 'Musik', 'Politik und Gesellschaft', 'Weiterbildung', 'Nachwuchsarbeit', 'Digitalisierung', 'Gesundheit'];
    $intros = ['Seit ein paar Jahren dabei und gern bei Veranstaltungen mit Rat und Tat.', 'Ich freue mich über neue Kontakte im Verein.', 'Familienmensch, Hobbygärtner und Kuchenbäcker.', 'Ich bin über Freunde zum Verein gekommen und geblieben.', 'Am liebsten draußen unterwegs – gern auch mit der Gruppe.', 'Neu im Verein und gespannt auf die Treffen.'];
    $wishes = ['Mehr Treffen am Wochenende.', 'Eine Übersicht aller Termine auf einen Blick.', 'Mehr Angebote für Familien.'];
    $tr = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue'];
    $seed = 4242;
    $rnd = function (int $n) use (&$seed): int { $seed = ($seed * 1103515245 + 12345) & 0x7fffffff; return (int)(($seed >> 8) % max(1, $n)); };
    $pick = fn(array $a) => $a[$rnd(count($a))];
    $out = ['svmembers' => [], 'md_profiles' => [], 'md_addresses' => [], 'md_contacts' => [], 'md_visibility' => [], 'md_member_groups' => [], 'md_member_fees' => [], 'md_terms_consents' => []];
    $groupNames = array_keys($groupIds);
    for ($i = 0; $i < 60; $i++) {
        $id = 13 + $i;
        $female = $i % 2 === 0;
        $fn = $female ? $fFirst[intdiv($i, 2)] : $mFirst[intdiv($i, 2)];
        $ln = $last[$i];
        $email = strtolower(strtr($fn . '.' . $ln, $tr)) . '@example.com';
        $active = $id <= 52;
        $join = sprintf('%04d-%02d-%02d', $Y - 1 - $rnd(18), 1 + $rnd(12), 1 + $rnd(27));
        if ($id > 52) $join = sprintf('%04d-%02d-%02d', $Y - 1 - $rnd(4), 1 + $rnd(12), 1 + $rnd(27));
        $status = $id % 17 === 0 ? 'ruhend' : ($id === 20 || $id === 41 ? 'ehrenmitglied' : 'aktiv');
        $out['svmembers'][] = ['is_confidential' => 0, 'member_id' => $id, 'membership_number' => sprintf('DEMO%04d', $id), 'first_name' => $fn, 'last_name' => $ln, 'email' => $email,
                               'role' => 'Mitglied', 'aktiv' => 0, 'funktion' => null, 'is_admin' => 0, 'is_active' => 1];
        $prof = ['member_id' => $id, 'salutation' => null, 'title' => null, 'nickname' => null, 'birthdate' => null, 'occupation' => null, 'employer' => null, 'relationship_status' => null,
                 'hobbies' => null, 'interests' => null, 'intro_text' => null, 'wishes_text' => null, 'join_date' => $join, 'status' => $status, 'leave_date' => null, 'admin_note' => null];
        if ($active) {
            $prof['salutation'] = $female ? 'Frau' : 'Herr';
            $prof['birthdate'] = sprintf('%04d-%02d-%02d', $Y - 25 - $rnd(50), 1 + $rnd(12), 1 + $rnd(27));
            $depth = $rnd(4);   // 0 = wenig, 3 = viel Inhalt
            if ($rnd(4) === 0) $prof['nickname'] = mb_substr($fn, 0, 3);
            if ($depth >= 1) $prof['occupation'] = $pick($jobs);
            if ($depth >= 1) $prof['hobbies'] = $pick($hobbies) . ($depth >= 2 ? "\n" . $pick($hobbies) : '');
            if ($depth >= 2) $prof['interests'] = $pick($interests) . ', ' . $pick($interests);
            if ($depth >= 2) $prof['intro_text'] = $pick($intros);
            if ($depth >= 3) { $prof['wishes_text'] = $pick($wishes); $prof['relationship_status'] = $pick(['ledig', 'verheiratet', 'in Beziehung']); $prof['employer'] = null; }
            [$zip, $city] = $pick($cities);
            $out['md_addresses'][] = ['member_id' => $id, 'slot' => 1, 'label' => 'Privat', 'street' => $pick($streets) . ' ' . (1 + $rnd(80)), 'zip' => $zip, 'city' => $city, 'country' => 'Deutschland'];
            if ($depth === 3 && $rnd(2) === 0) { [$z2, $c2] = $pick($cities); $out['md_addresses'][] = ['member_id' => $id, 'slot' => 2, 'label' => 'Ferienwohnung', 'street' => $pick($streets) . ' ' . (1 + $rnd(30)), 'zip' => $z2, 'city' => $c2, 'country' => 'Deutschland']; }
            if ($rnd(10) < 6) $out['md_contacts'][] = ['member_id' => $id, 'kind' => 'phone', 'value' => '0' . (30 + $rnd(900)) . ' ' . (100000 + $rnd(899999))];
            if ($rnd(10) < 7) $out['md_contacts'][] = ['member_id' => $id, 'kind' => 'mobile', 'value' => '01' . (50 + $rnd(30)) . ' ' . (1000000 + $rnd(8999999))];
            if ($depth >= 2 && $rnd(3) === 0) $out['md_contacts'][] = ['member_id' => $id, 'kind' => $pick(['instagram', 'website', 'facebook', 'mastodon']), 'value' => '@' . strtolower(strtr($fn, $tr)) . '.' . strtolower(strtr($ln, $tr))];
            // Sichtbarkeit im Verzeichnis (Standard: Rufname, Vorstellung und Foto 1 sichtbar, alles andere nicht)
            foreach (['email' => 6, 'address.1' => 7, 'occupation' => 7, 'hobbies' => 5, 'interests' => 5, 'contact.phone' => 4, 'contact.mobile' => 4, 'birthdate' => 3] as $k => $chance) if ($rnd(10) < $chance) $out['md_visibility'][] = ['member_id' => $id, 'field_key' => $k, 'level' => 'all'];
            if ($rnd(6) === 0) $out['md_visibility'][] = ['member_id' => $id, 'field_key' => 'photo.1', 'level' => 'me'];
            // Teilgruppen
            $g1 = $groupNames[$rnd(count($groupNames))];
            $out['md_member_groups'][] = ['member_id' => $id, 'group_id' => $groupIds[$g1], 'since' => $join, 'until' => null];
            if ($rnd(3) === 0) { $g2 = $groupNames[$rnd(count($groupNames))]; if ($g2 !== $g1) $out['md_member_groups'][] = ['member_id' => $id, 'group_id' => $groupIds[$g2], 'since' => $join, 'until' => null]; }
            // Beitragsgruppe und Zustimmung
            $fg = $status === 'ehrenmitglied' ? 3 : ($rnd(6) === 0 ? 2 : ($rnd(8) === 0 ? 4 : 1));
            $out['md_member_fees'][] = ['member_id' => $id, 'group_id' => $fg, 'valid_from' => $join, 'valid_to' => null, 'amount_override_cents' => null, 'discount_percent' => null, 'reason' => null, 'approved_by' => null, 'created_at' => $dt(-300), 'created_by' => 1];
            $out['md_terms_consents'][] = ['consent_id' => 100 + $i, 'member_id' => $id, 'terms_id' => 2, 'consented_at' => $dt(-(10 + $rnd(100)), '10:' . sprintf('%02d', $rnd(60)) . ':00'), 'channel' => 'self', 'recorded_by' => $id, 'ip_truncated' => '203.0.113.0', 'note' => null, 'revoked_at' => null];
        }
        $out['md_profiles'][] = $prof;
    }
    return $out;
}
