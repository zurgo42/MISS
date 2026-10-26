<?php
require_once __DIR__ . '/src/bootstrap.php';
md_require_schema();

// Demo: Figur wählen (nur im Demo-Modus); die echte Anmeldung lässt sich mit ?echt=1 ausprobieren
if (MD_DEMO_MODE && isset($_GET['as'])) {
    $m = md_one('SELECT member_id FROM svmembers WHERE member_id = ?', [(int)$_GET['as']]);
    if ($m) md_login_start((int)$m['member_id']);
    header('Location: ' . md_url('index.php'));
    exit;
}
$err = '';
$ident = '';
$pwKeep = '';
$magicDone = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_csrf_check();
    $ident = trim((string)($_POST['ident'] ?? ''));
    if (($_POST['action'] ?? '') === 'magic') {
        // Anmeldelink per E-Mail; die Antwort ist immer dieselbe, damit niemand Adressen ausprobieren kann
        if ($ident !== '' && !md_login_throttled('magicreq|' . $ident)) {
            md_login_fail('magicreq|' . $ident);
            md_magic_request($ident);
        }
        $magicDone = true;
    } else {
        [$m, $err] = md_login_check($ident, (string)($_POST['password'] ?? ''));
        if ($m) {
            md_login_start((int)$m['member_id']);
            header('Location: ' . md_url('index.php'));
            exit;
        }
        $pwKeep = (string)($_POST['password'] ?? '');   // die Eingabe bleibt stehen, damit man sie über das Auge prüfen kann
    }
}
if (MD_DEMO_MODE && !isset($_GET['echt']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Demo-Startseite im Aufbau der Sitzungsverwaltung: oben die Leistungen, darunter die Demo-Figuren mit ihren Rollen
    // zuerst, was Mitglieder betrifft, dann die Funktionen der Verwaltung
    $features = [
        ['👤', 'Mein Dashboard', 'Alle eigenen Angaben übersichtlich in ausklappbaren Bereichen: Profil, Fotos, Adressen, Kontakte, Finanzen, Protokoll – jede Änderung wird dokumentiert.'],
        ['🔎', 'Mitgliederverzeichnis', 'Durchsuchbar und filterbar (Teilgruppe, Funktion, Ort, Eintritt …) mit wählbaren Spalten. Jedes Mitglied bestimmt selbst, was andere sehen.'],
        ['📅', 'Veranstaltungen', 'Jedes Mitglied kann Veranstaltungen anlegen und sich anmelden; Teilnehmerliste mit Sichtbarkeitswahl, Warteliste, Teilnahmegebühr.'],
        ['✉️', 'E-Mail & Verteiler', 'Rechnungen und Mahnungen per E-Mail, Verteiler für Teilgruppen und Funktionen, Nachrichten an Veranstaltungsteilnehmer.'],
        ['🛡️', 'Datenschutz (DSGVO)', 'Auskunft zum Download, Löschfristen in zwei Stufen, Zustimmung zu den Nutzungsbedingungen, Hell- und Dunkelmodus.'],
        ['📝', 'Mitglied werden', 'Interessierte melden sich selbst an und bestätigen ihre Adresse; der Vorstand nimmt auf oder lehnt ab. Unbestätigte Anträge werden nach 6 Wochen gelöscht.'],
        ['💶', 'Beiträge & Mahnwesen', 'Beitragsgruppen, Beitragslauf, Rechnungen, dreistufiges Mahnwesen, Zahlungszuordnung aus dem Kontoauszug.'],
        ['🏦', 'SEPA-Lastschrift', 'Mandate mit verschlüsselter IBAN, Einzugsläufe mit pain.008-Datei, Rücklastschriften.'],
        ['🧾', 'Kasse, Spenden & DATEV', 'Buchungen und CSV-Import, Spendenbescheinigungen (Einzel und Jahressammel), Import aus DATEV.'],
        ['🔐', 'Rechte & Funktionen', 'Funktionen steuern die Rechte – auch die Rechte in der Sitzungsverwaltung. Die Rechtetabelle ist einstellbar.'],
        ['⚙️', 'Administration', 'Anpassung der vereinsspezifischen Bezeichnungen und Regeln: Vereinsname und Logo, Impressum und Datenschutzerklärung, Vereinskonten, Mitgliedsarten und Beiträge, Funktionen, Teilgruppen.'],
    ];
    // Wesentliche Demo-Rollen (Reihenfolge wie in der Sitzungsverwaltung: Vorstand, Geschäftsführung, Assistenz, Führungsteam, Mitglieder)
    $roles = ['DEMO0001' => 'Finanzvorstand · Kassenführung', 'DEMO0002' => 'Vorstand · Finanzverwaltung', 'DEMO0003' => 'Vorstand', 'DEMO0004' => 'Geschäftsführung · Admin', 'DEMO0005' => 'Vorstandsassistenz · Admin',
              'DEMO0007' => 'Ressortleitung · Veranstaltungen', 'DEMO0009' => 'Ressortleitung', 'DEMO0010' => 'Finanzprüfung (nur lesen)', 'DEMO0011' => 'Technik-Admin', 'DEMO0006' => 'Mitglied · aktiv', 'DEMO0008' => 'Mitglied · passiv'];
    $ph1 = [];
    foreach (md_q('SELECT member_id, photo_id FROM md_photos WHERE slot = 1') as $r) $ph1[(int)$r['member_id']] = (int)$r['photo_id'];
    $byNr = [];
    foreach (md_q("SELECT * FROM svmembers WHERE membership_number LIKE 'DEMO%'") as $m) $byNr[$m['membership_number']] = $m;
    md_header('Demo', null);
    echo '<div class="welcome-content"><div class="features-box"><h2>' . MD_APP_NAME . ' – ' . MD_APP_TAG . '</h2><p class="app-lead">Die Anwendung für Vereinsmitglieder und für die Verwaltung eines Vereins: Mitglieder pflegen ihre Daten selbst, es gibt ein Mitgliederverzeichnis und eine Veranstaltungsfunktion; die Verwaltung organisiert die Mitgliedsbeiträge und weitere Funktionen.</p><h3>Was ' . MD_APP_NAME . ' leistet:</h3><div class="features-grid">';
    foreach ($features as [$icon, $name, $text]) echo '<div class="feature-item"><span class="icon">' . $icon . '</span><span class="text"><strong>' . h($name) . ':</strong> ' . h($text) . '</span></div>';
    echo '</div></div><div class="demo-box"><h2>🎭 Demo-Zugang – Testbenutzer auswählen:</h2><div class="demo-grid">';
    foreach ($roles as $nr => $label) {
        $m = $byNr[$nr] ?? null;
        if (!$m) continue;
        $pic = isset($ph1[(int)$m['member_id']]) ? '<img class="thumb" src="photo.php?id=' . $ph1[(int)$m['member_id']] . '&pick=1" alt="">' : '<span class="avatar">' . h(md_initials($m)) . '</span>';
        echo '<a class="demo-btn" href="login.php?as=' . (int)$m['member_id'] . '">' . $pic . '<span><strong>' . h($m['first_name'] . ' ' . $m['last_name']) . '</strong><small>' . h($label) . '</small></span></a>';
    }
    echo '</div><p class="demo-hint">Zusätzlich gibt es ' . max(0, (int)md_one("SELECT COUNT(*) c FROM svmembers WHERE membership_number LIKE 'DEMO%' AND is_active = 1")['c'] - count($roles)) . ' weitere Mitglieder (aktive mit Foto und Inhalten, andere nur mit Grunddaten), die im Mitgliederverzeichnis und in den Verwaltungsseiten auftauchen. '
        . '<a href="login.php?echt=1">Anmeldung mit Passwort ausprobieren</a></p><div class="doc-links">'
        . (defined('MD_SV_URL') && MD_SV_URL !== '' ? '<a href="' . h(MD_SV_URL) . '">🏛️ Sitzungsverwaltung</a>' : '') . '<a href="apply.php">📝 Mitglied werden</a><a href="https://github.com/zurgo42/Mitgliederverwaltung" target="_blank" rel="noopener">GitHub</a></div></div></div>';
    md_footer();
    exit;
}
header('Cache-Control: no-store');
md_header('Anmeldung');
echo '<section class="card" style="max-width:26rem;margin:2rem auto"><h1>Anmeldung</h1>';
if ($err) echo '<div class="flash err">' . h($err) . '</div>';
if ($magicDone) echo '<div class="flash ok">Wenn zu dieser Angabe ein Zugang mit E-Mail-Adresse existiert, ist ein Anmeldelink unterwegs (' . MD_MAGIC_MINUTES . ' Minuten gültig). Bitte auch den Spam-Ordner prüfen. Ist im Profil weder ein Spitzname noch ein Geburtsdatum hinterlegt, geht diese Anmeldeart nicht; dann kommt stattdessen eine Mail mit einem Hinweis.</div>';
md_show_flash();
echo '<form method="post" action="login.php' . (isset($_GET['echt']) ? '?echt=1' : '') . '" autocomplete="on">' . md_csrf_field()
    . '<label class="block">E-Mail-Adresse oder Mitgliedsnummer<input name="ident" value="' . h($ident) . '" autocomplete="username" required autofocus style="width:100%"></label>'
    . md_password_field('password', 'Passwort', 'current-password', false, $pwKeep)
    . '<p><button>Anmelden</button></p></form>'
    . '<p class="or">oder ohne Passwort</p>'
    . '<form method="post" action="login.php' . (isset($_GET['echt']) ? '?echt=1' : '') . '">' . md_csrf_field() . '<input type="hidden" name="action" value="magic">'
    . '<label class="block">E-Mail-Adresse oder Mitgliedsnummer<input name="ident" value="' . h($ident) . '" autocomplete="username" required style="width:100%"></label>'
    . '<p><button class="secondary">Anmeldelink per E-Mail senden</button></p>'
    . '<p class="muted">Du bekommst einen Link (' . MD_MAGIC_MINUTES . ' Minuten gültig). Zur Sicherheit gibst du danach deinen Spitznamen ein, oder, wenn keiner eingetragen ist, dein Geburtsdatum. Das geht nur, wenn eins von beiden in deinem Profil steht.</p></form>'
    . '<p class="muted"><a href="forgot.php">Passwort vergessen oder noch keins?</a></p></section>';
md_footer();
