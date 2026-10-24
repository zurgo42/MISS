-- Mitgliederverwaltung: Tabellen mit Präfix md_ (Mitgliederdaten)
-- Anker ist svmembers (Sitzungsverwaltung): md_*.member_id verweist auf svmembers.member_id.
-- Bewusst portables SQL (MySQL/MariaDB produktiv, SQLite für lokale Demo):
-- keine ENUMs, keine Inline-INDEX-Klauseln. Wertelisten stehen im Kommentar.

-- 1. Stammdaten je Mitglied (1:1 zu svmembers)
CREATE TABLE IF NOT EXISTS md_profiles (
    member_id INT PRIMARY KEY,
    salutation VARCHAR(20) DEFAULT NULL,
    title VARCHAR(50) DEFAULT NULL,
    nickname VARCHAR(100) DEFAULT NULL,
    birthdate DATE DEFAULT NULL,
    occupation VARCHAR(150) DEFAULT NULL,
    employer VARCHAR(150) DEFAULT NULL,
    relationship_status VARCHAR(50) DEFAULT NULL,
    hobbies TEXT,
    interests TEXT,
    intro_text TEXT,
    wishes_text TEXT,
    join_date DATE DEFAULT NULL,
    leave_date DATE DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'aktiv',   -- interessent, aktiv, ruhend, ausgetreten, ehrenmitglied
    letter_channel VARCHAR(10) NOT NULL DEFAULT 'post',   -- Zustellweg für Rechnungen und Mahnungen: post oder mail
    admin_note TEXT,                               -- nur Verwaltung, nie für Mitglieder sichtbar
    retention_hold INT NOT NULL DEFAULT 0,         -- 1 = Löschsperre (z. B. offene Forderung, laufendes Verfahren)
    hold_reason VARCHAR(255) DEFAULT NULL,
    purged_at DATETIME DEFAULT NULL,               -- Stufe 1: Profildaten nach Austritt gelöscht
    anonymized_at DATETIME DEFAULT NULL,           -- Stufe 2: nach Ablauf der Aufbewahrungsfristen vollständig anonymisiert
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES svmembers(member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Adressen (bis zu 3 Plätze)
CREATE TABLE IF NOT EXISTS md_addresses (
    address_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    slot INT NOT NULL,                             -- 1..3
    label VARCHAR(50) DEFAULT NULL,                -- z. B. Privat, Zweitwohnsitz, Dienstlich
    street VARCHAR(150) DEFAULT NULL,
    zip VARCHAR(20) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    country VARCHAR(80) DEFAULT NULL,
    UNIQUE (member_id, slot),
    FOREIGN KEY (member_id) REFERENCES svmembers(member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Fotos (bis zu 3 Plätze)
CREATE TABLE IF NOT EXISTS md_photos (
    photo_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    slot INT NOT NULL,                             -- 1..3
    file_name VARCHAR(200) NOT NULL,               -- unter storage/photos/
    mime VARCHAR(50) NOT NULL,
    caption VARCHAR(150) DEFAULT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (member_id, slot),
    FOREIGN KEY (member_id) REFERENCES svmembers(member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Kontaktdaten und Social Media (je Art ein Eintrag)
CREATE TABLE IF NOT EXISTS md_contacts (
    contact_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    kind VARCHAR(30) NOT NULL,                     -- siehe src/fields.php (email, phone, mobile, fax, website, instagram, ...)
    value VARCHAR(255) NOT NULL,
    UNIQUE (member_id, kind),
    FOREIGN KEY (member_id) REFERENCES svmembers(member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Sichtbarkeit je Feld und Mitglied (fehlt ein Eintrag, gilt der Standard)
CREATE TABLE IF NOT EXISTS md_visibility (
    member_id INT NOT NULL,
    field_key VARCHAR(60) NOT NULL,                -- z. B. birthdate, address.1, photo.2, contact.email
    level VARCHAR(10) NOT NULL,                    -- me, admin, group, all
    PRIMARY KEY (member_id, field_key),
    FOREIGN KEY (member_id) REFERENCES svmembers(member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS md_visibility_defaults (
    field_key VARCHAR(60) PRIMARY KEY,
    level VARCHAR(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Teilgruppen (Regionalgruppen, Interessengruppen, ...)
CREATE TABLE IF NOT EXISTS md_groups (
    group_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL UNIQUE,
    kind VARCHAR(30) NOT NULL DEFAULT 'interesse', -- region, interesse, projekt
    description VARCHAR(255) DEFAULT NULL,
    active INT NOT NULL DEFAULT 1                  -- 0 = wird nicht mehr zur Auswahl angeboten
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS md_member_groups (
    member_id INT NOT NULL,
    group_id INT NOT NULL,
    since DATE DEFAULT NULL,
    until DATE DEFAULT NULL,
    PRIMARY KEY (member_id, group_id),
    FOREIGN KEY (member_id) REFERENCES svmembers(member_id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES md_groups(group_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Funktionen im Verein mit Zeitraum (Basis für Rechte im Sitzungstool)
CREATE TABLE IF NOT EXISTS md_functions (
    function_id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(10) NOT NULL UNIQUE,              -- Funktionscodes wie in der Sitzungsverwaltung (Vo, FVo, GF, RL, ...)
    name VARCHAR(120) NOT NULL,
    sv_aktiv_level INT DEFAULT NULL,               -- zugehöriges Berechtigungslevel (svmembers.aktiv), siehe PERMISSIONS.md
    active INT NOT NULL DEFAULT 1                  -- 0 = wird nicht mehr zur Auswahl angeboten (der Verein hat diese Funktion nicht)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS md_member_functions (
    member_function_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    function_id INT NOT NULL,
    valid_from DATE DEFAULT NULL,
    valid_to DATE DEFAULT NULL,
    aktiv_level INT DEFAULT NULL,                  -- überschreibt md_functions.sv_aktiv_level (z. B. RL 14/15/17)
    ressort_id INT DEFAULT NULL,                   -- lose Verknüpfung zu svressorts.ID (Ressortleitung), kein Fremdschlüssel
    note VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (member_id) REFERENCES svmembers(member_id) ON DELETE CASCADE,
    FOREIGN KEY (function_id) REFERENCES md_functions(function_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Finanzen aus Sicht des Mitglieds: eine Buchungsliste für alles
CREATE TABLE IF NOT EXISTS md_ledger (
    entry_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    kind VARCHAR(20) NOT NULL,                     -- beitrag, eintritt, spende (Mitglied -> Verein); erstattung, uebungsleiter (Verein -> Mitglied)
    direction VARCHAR(10) NOT NULL,                -- to_club, to_member
    amount_cents INT NOT NULL,                     -- immer positiv
    entry_date DATE NOT NULL,                      -- Datum der Forderung/Leistung
    due_date DATE DEFAULT NULL,
    paid_date DATE DEFAULT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'open',    -- open, paid, cancelled
    description VARCHAR(255) NOT NULL,
    reference VARCHAR(80) DEFAULT NULL,            -- Rechnungs-/Belegnummer
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by INT DEFAULT NULL,                   -- wer hat gebucht
    source VARCHAR(10) NOT NULL DEFAULT 'manual',  -- manual, csv, demo
    cancelled_at DATETIME DEFAULT NULL,            -- Storno statt Löschen
    cancelled_by INT DEFAULT NULL,
    cancel_reason VARCHAR(255) DEFAULT NULL,
    receipt_id INT DEFAULT NULL,                   -- Spende: durch diese Zuwendungsbestätigung abgedeckt (md_receipts)
    sepa_item_id INT DEFAULT NULL,                 -- Lastschriftlauf (md_sepa_items), in dem diese Forderung eingezogen wird
    run_id INT DEFAULT NULL,                       -- Beitragslauf (md_fee_runs), der diese Forderung erzeugt hat
    fee_period VARCHAR(10) DEFAULT NULL,           -- Beitragslauf: Zeitraum, z. B. 2027, 2027-H1, 2027-Q2, 2027-03
    dunning_level INT NOT NULL DEFAULT 0,          -- erreichte Mahnstufe (0 = keine)
    last_dunned_at DATE DEFAULT NULL,
    deferred_until DATE DEFAULT NULL,              -- Stundung: bis dahin keine Mahnung
    deferred_reason VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (member_id) REFERENCES svmembers(member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Nutzungsbedingungen: Versionen und dokumentierte Zustimmung
CREATE TABLE IF NOT EXISTS md_terms_versions (
    terms_id INT PRIMARY KEY AUTO_INCREMENT,
    doc_type VARCHAR(30) NOT NULL DEFAULT 'nutzungsbedingungen',
    version_label VARCHAR(20) NOT NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    valid_from DATE NOT NULL,
    requires_consent INT NOT NULL DEFAULT 1,       -- 1: Mitglieder müssen dieser Version zustimmen
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (doc_type, version_label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS md_terms_consents (
    consent_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    terms_id INT NOT NULL,
    consented_at DATETIME NOT NULL,
    channel VARCHAR(10) NOT NULL,                  -- self, admin, paper, import
    recorded_by INT DEFAULT NULL,                  -- bei admin/paper: wer hat es erfasst
    ip_truncated VARCHAR(45) DEFAULT NULL,         -- gekürzt (letztes Oktett 0), nur bei channel self
    note VARCHAR(255) DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    FOREIGN KEY (member_id) REFERENCES svmembers(member_id) ON DELETE CASCADE,
    FOREIGN KEY (terms_id) REFERENCES md_terms_versions(terms_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Änderungsprotokoll (Selbstpflege, Admin, Import)
CREATE TABLE IF NOT EXISTS md_changelog (
    change_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,                        -- betroffenes Mitglied
    field_key VARCHAR(80) NOT NULL,
    old_value TEXT,
    new_value TEXT,
    changed_by INT DEFAULT NULL,                   -- wer hat geändert (member_id)
    changed_via VARCHAR(10) NOT NULL,              -- self, admin, import
    changed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. CSV-Import: gespeicherte Spaltenzuordnungen und Läufe
CREATE TABLE IF NOT EXISTS md_import_profiles (
    profile_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL UNIQUE,
    target VARCHAR(10) NOT NULL DEFAULT 'members', -- members, ledger
    source_software VARCHAR(120) DEFAULT NULL,
    delimiter_char VARCHAR(5) NOT NULL DEFAULT ';',
    encoding VARCHAR(20) NOT NULL DEFAULT 'UTF-8',
    mapping TEXT NOT NULL,                         -- JSON: Zielfeld -> Spaltenname
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS md_import_runs (
    run_id INT PRIMARY KEY AUTO_INCREMENT,
    profile_id INT DEFAULT NULL,
    file_name VARCHAR(200) NOT NULL,
    started_by INT NOT NULL,
    started_at DATETIME NOT NULL,
    rows_total INT NOT NULL DEFAULT 0,
    rows_created INT NOT NULL DEFAULT 0,
    rows_updated INT NOT NULL DEFAULT 0,
    rows_skipped INT NOT NULL DEFAULT 0,
    note TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Einstellungen
CREATE TABLE IF NOT EXISTS md_settings (
    setting_key VARCHAR(60) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    description VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Rechte in der Mitgliederverwaltung: je Funktion (oder '*' = alle Mitglieder) und Bereich eine Stufe.
-- Wirksam ist die höchste Stufe über alle aktuell gültigen Funktionen des Mitglieds; svmembers.is_admin darf immer alles.
CREATE TABLE IF NOT EXISTS md_permissions (
    subject VARCHAR(10) NOT NULL,                  -- Funktionscode (Vo, FVo, GF, MB, Ka, FP, ...) oder '*'
    area VARCHAR(20) NOT NULL,                     -- directory, members, finance, terms, import, settings
    level VARCHAR(10) NOT NULL,                    -- none, view, edit
    PRIMARY KEY (subject, area)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Zuwendungsbestätigungen (Spendenquittungen): nummeriert, mit Momentaufnahme der Angaben, nie gelöscht
CREATE TABLE IF NOT EXISTS md_receipts (
    receipt_id INT PRIMARY KEY AUTO_INCREMENT,
    receipt_no VARCHAR(20) NOT NULL UNIQUE,        -- z. B. 2026-0001 (Jahr der Ausstellung, laufende Nummer)
    member_id INT NOT NULL,
    kind VARCHAR(10) NOT NULL,                     -- single (Einzelbestätigung), annual (Sammelbestätigung für einen Zeitraum)
    period_from DATE NOT NULL,
    period_to DATE NOT NULL,
    total_cents INT NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'issued',  -- issued, void
    issued_at DATETIME NOT NULL,
    issued_by INT DEFAULT NULL,
    replaces_id INT DEFAULT NULL,                  -- ersetzt diese (für ungültig erklärte) Bestätigung
    void_reason VARCHAR(255) DEFAULT NULL,
    voided_at DATETIME DEFAULT NULL,
    voided_by INT DEFAULT NULL,
    snapshot TEXT NOT NULL,                        -- JSON: Verein, Spender, Einzelzuwendungen zum Zeitpunkt der Ausstellung
    FOREIGN KEY (member_id) REFERENCES svmembers(member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS md_receipt_events (
    event_id INT PRIMARY KEY AUTO_INCREMENT,
    receipt_id INT NOT NULL,
    event VARCHAR(10) NOT NULL,                    -- issued, printed, voided
    event_at DATETIME NOT NULL,
    event_by INT DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (receipt_id) REFERENCES md_receipts(receipt_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Datenschutz: Anfragen (Auskunft, Löschung) mit Fristüberwachung und Protokoll der Vorgänge
CREATE TABLE IF NOT EXISTS md_privacy_requests (
    request_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    kind VARCHAR(10) NOT NULL,                     -- access (Auskunft), erasure (Löschung)
    received_at DATETIME NOT NULL,
    received_via VARCHAR(10) NOT NULL DEFAULT 'self', -- self (Dashboard), mail, letter, phone, other
    recorded_by INT DEFAULT NULL,
    note TEXT,
    status VARCHAR(10) NOT NULL DEFAULT 'open',    -- open, done, refused
    handled_at DATETIME DEFAULT NULL,
    handled_by INT DEFAULT NULL,
    response_note VARCHAR(500) DEFAULT NULL,
    FOREIGN KEY (member_id) REFERENCES svmembers(member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Protokoll ohne Fremdschlüssel: bleibt als Nachweis, auch wenn Daten gelöscht oder anonymisiert sind (enthält keine Klarnamen)
CREATE TABLE IF NOT EXISTS md_privacy_log (
    event_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    event VARCHAR(12) NOT NULL,                    -- export, request, handled, hold_on, hold_off, purged, anonymized
    event_at DATETIME NOT NULL,
    event_by INT DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Beiträge: Gruppen, Zuordnung mit Abweichungen, Läufe
CREATE TABLE IF NOT EXISTS md_fee_groups (
    group_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    amount_cents INT NOT NULL,                     -- Betrag je Abrechnungszeitraum (siehe rhythm)
    rhythm VARCHAR(12) NOT NULL DEFAULT 'yearly',  -- yearly, halfyearly, quarterly, monthly
    entry_rule VARCHAR(10) NOT NULL DEFAULT 'full',-- full: voller Betrag, prorated: anteilig ab Eintrittsmonat bis Austrittsmonat
    description VARCHAR(255) DEFAULT NULL,
    active INT NOT NULL DEFAULT 1,
    public INT NOT NULL DEFAULT 0                  -- 1 = bei der Online-Anmeldung wählbar
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS md_member_fees (
    fee_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    group_id INT NOT NULL,
    valid_from DATE NOT NULL,
    valid_to DATE DEFAULT NULL,
    amount_override_cents INT DEFAULT NULL,        -- abweichender Betrag je Abrechnungszeitraum
    discount_percent INT DEFAULT NULL,             -- oder Ermäßigung in Prozent
    reason VARCHAR(255) DEFAULT NULL,              -- Pflicht bei Abweichung
    approved_by VARCHAR(120) DEFAULT NULL,         -- wer hat es bewilligt (Nachvollziehbarkeit)
    created_at DATETIME NOT NULL,
    created_by INT DEFAULT NULL,
    FOREIGN KEY (member_id) REFERENCES svmembers(member_id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES md_fee_groups(group_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS md_fee_runs (
    run_id INT PRIMARY KEY AUTO_INCREMENT,
    period_key VARCHAR(10) NOT NULL,
    run_at DATETIME NOT NULL,
    run_by INT DEFAULT NULL,
    due_date DATE NOT NULL,
    entries INT NOT NULL DEFAULT 0,
    total_cents INT NOT NULL DEFAULT 0,
    note VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Mahnwesen: Stufen und ausgestellte Mahnschreiben (mit Momentaufnahme)
CREATE TABLE IF NOT EXISTS md_dunning_levels (
    level INT PRIMARY KEY,                         -- 1..3
    name VARCHAR(60) NOT NULL,                     -- z. B. Zahlungserinnerung, 1. Mahnung, 2. Mahnung
    days INT NOT NULL,                             -- Stufe 1: Tage nach Fälligkeit; Stufe 2/3: Tage nach der vorherigen Mahnung
    deadline_days INT NOT NULL DEFAULT 14,         -- Zahlungsfrist ab Mahnung
    fee_cents INT NOT NULL DEFAULT 0,              -- Mahngebühr je Schreiben
    body TEXT NOT NULL                             -- Text mit Platzhaltern {anrede} {name} {total} {deadline}
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS md_dunning_letters (
    letter_id INT PRIMARY KEY AUTO_INCREMENT,
    letter_no VARCHAR(20) NOT NULL UNIQUE,         -- z. B. M2027-0001
    member_id INT NOT NULL,
    level INT NOT NULL,
    issued_at DATETIME NOT NULL,
    issued_by INT DEFAULT NULL,
    total_cents INT NOT NULL,
    fee_cents INT NOT NULL DEFAULT 0,
    deadline DATE NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'issued',  -- issued, void
    void_reason VARCHAR(255) DEFAULT NULL,
    snapshot TEXT NOT NULL,
    FOREIGN KEY (member_id) REFERENCES svmembers(member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. SEPA-Lastschrift: Mandate (IBAN verschlüsselt), Einzugsläufe, Einzelposten, Ereignisse
CREATE TABLE IF NOT EXISTS md_sepa_mandates (
    mandate_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    mandate_ref VARCHAR(35) NOT NULL UNIQUE,       -- Mandatsreferenz
    signed_on DATE NOT NULL,                       -- Datum der Unterschrift
    debtor_name VARCHAR(100) NOT NULL,             -- Kontoinhaber
    iban_enc TEXT NOT NULL,                        -- verschlüsselt (AES-256-GCM, Schlüssel MD_ENCRYPTION_KEY in config.php)
    iban_last4 VARCHAR(4) DEFAULT NULL,
    bic VARCHAR(11) DEFAULT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'active',  -- active, suspended, revoked
    status_reason VARCHAR(255) DEFAULT NULL,
    document_note VARCHAR(255) DEFAULT NULL,       -- Ablageort der Mandatsunterlage
    created_at DATETIME NOT NULL,
    created_by INT DEFAULT NULL,
    status_at DATETIME DEFAULT NULL,
    FOREIGN KEY (member_id) REFERENCES svmembers(member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS md_sepa_batches (
    batch_id INT PRIMARY KEY AUTO_INCREMENT,
    batch_no VARCHAR(20) NOT NULL UNIQUE,          -- z. B. L2027-0001
    created_at DATETIME NOT NULL,
    created_by INT DEFAULT NULL,
    collection_date DATE NOT NULL,                 -- Fälligkeitstag der Lastschrift (ReqdColltnDt)
    format VARCHAR(20) NOT NULL,                   -- pain.008.001.08 oder pain.008.001.02
    status VARCHAR(10) NOT NULL DEFAULT 'created', -- created (Datei erzeugt), submitted (bei der Bank eingereicht), closed (Einzug bestätigt), void
    items INT NOT NULL DEFAULT 0,
    total_cents INT NOT NULL DEFAULT 0,
    creditor TEXT NOT NULL,                        -- JSON: Name, IBAN, BIC, Gläubiger-ID zum Zeitpunkt der Erstellung
    submitted_at DATETIME DEFAULT NULL,
    submitted_by INT DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    void_reason VARCHAR(255) DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS md_sepa_items (
    item_id INT PRIMARY KEY AUTO_INCREMENT,
    batch_id INT NOT NULL,
    member_id INT NOT NULL,
    mandate_id INT DEFAULT NULL,
    mandate_ref VARCHAR(35) NOT NULL,
    signed_on DATE NOT NULL,
    debtor_name VARCHAR(100) NOT NULL,
    iban_enc TEXT NOT NULL,
    bic VARCHAR(11) DEFAULT NULL,
    amount_cents INT NOT NULL,
    seq_type VARCHAR(4) NOT NULL,                  -- FRST, RCUR
    end_to_end_id VARCHAR(35) NOT NULL,
    purpose VARCHAR(140) NOT NULL,
    entry_ids TEXT NOT NULL,                       -- Komma-getrennte md_ledger.entry_id
    status VARCHAR(10) NOT NULL DEFAULT 'pending', -- pending, collected, returned, cancelled
    returned_at DATE DEFAULT NULL,
    return_code VARCHAR(6) DEFAULT NULL,
    return_text VARCHAR(255) DEFAULT NULL,
    return_fee_cents INT DEFAULT NULL,
    FOREIGN KEY (batch_id) REFERENCES md_sepa_batches(batch_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS md_sepa_events (
    event_id INT PRIMARY KEY AUTO_INCREMENT,
    batch_id INT NOT NULL,
    event VARCHAR(12) NOT NULL,                    -- created, downloaded, submitted, closed, returned, void
    event_at DATETIME NOT NULL,
    event_by INT DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- E-Mail-Ausgang (Warteschlange und Nachweis)
CREATE TABLE IF NOT EXISTS md_mail_outbox (
    mail_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT DEFAULT NULL,
    to_email VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    kind VARCHAR(12) NOT NULL DEFAULT 'other',     -- dunning, invoice, invite, reset, receipt, list, apply, other
    ref VARCHAR(40) DEFAULT NULL,                  -- z. B. Mahnschreiben- oder Rechnungsnummer
    status VARCHAR(10) NOT NULL DEFAULT 'queued',  -- queued, sent, failed, cancelled
    attempts INT NOT NULL DEFAULT 0,
    last_error VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    created_by INT DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    reply_to VARCHAR(255) DEFAULT NULL,            -- abweichende Antwortadresse (z. B. Veranstalter)
    unsub_url VARCHAR(255) DEFAULT NULL            -- Abmeldelink bei Verteiler-Nachrichten (Kopfzeile List-Unsubscribe)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Einmal-Links zum Passwort setzen (nur der Hash des Tokens wird gespeichert)
CREATE TABLE IF NOT EXISTS md_login_tokens (
    token_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    kind VARCHAR(10) NOT NULL,                     -- invite, reset
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fehlversuche bei der Anmeldung (Hash der Kennung, keine Klartext-Eingaben)
CREATE TABLE IF NOT EXISTS md_login_attempts (
    attempt_id INT PRIMARY KEY AUTO_INCREMENT,
    ident_hash VARCHAR(64) NOT NULL,
    attempt_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Veranstaltungen: jedes Mitglied kann anlegen und sich anmelden
CREATE TABLE IF NOT EXISTS md_events (
    event_id INT PRIMARY KEY AUTO_INCREMENT,
    organizer_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT,
    location VARCHAR(200) DEFAULT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME DEFAULT NULL,
    reg_deadline DATETIME DEFAULT NULL,            -- Anmeldeschluss (leer = bis zum Beginn)
    capacity INT DEFAULT NULL,                     -- leer = unbegrenzt
    price_cents INT NOT NULL DEFAULT 0,            -- 0 = kostenlos; sonst entsteht je Anmeldung eine Forderung (Art Eintritt)
    status VARCHAR(10) NOT NULL DEFAULT 'open',    -- open, cancelled
    cancel_reason VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS md_event_regs (
    reg_id INT PRIMARY KEY AUTO_INCREMENT,
    event_id INT NOT NULL,
    member_id INT NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'registered',   -- registered, waitlist, cancelled
    show_in_list INT NOT NULL DEFAULT 0,           -- 1 = andere Angemeldete sehen mich in der Teilnehmerliste
    note VARCHAR(255) DEFAULT NULL,                -- Anmerkung an den Veranstalter
    entry_id INT DEFAULT NULL,                     -- Forderung im Kassenbuch (bei Teilnahmegebühr)
    created_at DATETIME NOT NULL,
    status_at DATETIME DEFAULT NULL,
    UNIQUE (event_id, member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- E-Mail-Verteiler (Empfänger ergeben sich aus Teilgruppe, Funktion oder allen Mitgliedern)
CREATE TABLE IF NOT EXISTS md_mail_lists (
    list_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL,
    source_kind VARCHAR(10) NOT NULL DEFAULT 'group',  -- all, group, function
    source_ref VARCHAR(50) DEFAULT NULL,               -- group_id bzw. Funktionskürzel
    optional INT NOT NULL DEFAULT 1,                   -- 1 = Mitglieder können den Verteiler abbestellen
    active INT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS md_mail_optout (
    member_id INT NOT NULL,
    list_id INT NOT NULL,
    opted_at DATETIME NOT NULL,
    PRIMARY KEY (member_id, list_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS md_mail_campaigns (
    campaign_id INT PRIMARY KEY AUTO_INCREMENT,
    list_id INT DEFAULT NULL,
    event_id INT DEFAULT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    recipients INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    created_by INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Online-Anmeldung neuer Mitglieder (Antrag, bis der Vorstand entscheidet)
CREATE TABLE IF NOT EXISTS md_applications (
    app_id INT PRIMARY KEY AUTO_INCREMENT,
    status VARCHAR(12) NOT NULL DEFAULT 'unconfirmed',  -- unconfirmed (E-Mail noch nicht bestätigt), new, approved, rejected
    created_at DATETIME NOT NULL,
    confirmed_at DATETIME DEFAULT NULL,
    token_hash VARCHAR(64) DEFAULT NULL,
    token_expires DATETIME DEFAULT NULL,
    salutation VARCHAR(20) DEFAULT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    birthdate DATE DEFAULT NULL,
    street VARCHAR(150) DEFAULT NULL,
    zip VARCHAR(20) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    fee_group_id INT DEFAULT NULL,
    message VARCHAR(1000) DEFAULT NULL,
    ack_at DATETIME NOT NULL,                      -- Datenschutzhinweise und Satzung zur Kenntnis genommen
    decided_at DATETIME DEFAULT NULL,
    decided_by INT DEFAULT NULL,
    decision_note VARCHAR(255) DEFAULT NULL,
    member_id INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DATEV-Import: Personenkonten der Mitglieder, Zuordnung von Sach-/Erlöskonten zu Buchungsarten, Protokoll
CREATE TABLE IF NOT EXISTS md_datev_accounts (
    account VARCHAR(9) PRIMARY KEY,                -- Personenkonto (Debitor oder Kreditor) in der Buchhaltung
    member_id INT NOT NULL,
    type VARCHAR(10) NOT NULL DEFAULT 'debitor'    -- debitor (Forderungen an das Mitglied), kreditor (Zahlungen an das Mitglied)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS md_datev_kinds (
    rule_id INT PRIMARY KEY AUTO_INCREMENT,
    account_from VARCHAR(9) NOT NULL,              -- Gegenkonto (Erlös-/Aufwandskonto) von ... bis
    account_to VARCHAR(9) NOT NULL,
    kind VARCHAR(20) NOT NULL                      -- Buchungsart im Kassenbuch (beitrag, spende, eintritt, ...)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS md_datev_imports (
    import_id INT PRIMARY KEY AUTO_INCREMENT,
    file_name VARCHAR(200) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    created_by INT DEFAULT NULL,
    period_from DATE DEFAULT NULL,
    period_to DATE DEFAULT NULL,
    rows_total INT NOT NULL DEFAULT 0,
    paid_count INT NOT NULL DEFAULT 0,                -- Forderungen als bezahlt vermerkt
    created_count INT NOT NULL DEFAULT 0,             -- neue Buchungen angelegt
    skipped_count INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS md_datev_rows (
    fingerprint VARCHAR(40) PRIMARY KEY,           -- Prüfsumme der Buchungszeile: dieselbe Zeile wird nie zweimal übernommen
    import_id INT NOT NULL,
    row_no INT NOT NULL,
    entry_id INT DEFAULT NULL,
    imported_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- persönliche Einstellungen (z. B. gewählte Spalten im Mitgliederverzeichnis)
CREATE TABLE IF NOT EXISTS md_user_prefs (
    member_id INT NOT NULL,
    pref_key VARCHAR(40) NOT NULL,
    pref_value TEXT,
    PRIMARY KEY (member_id, pref_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vereinskonten (mehrere möglich): Standardkonto für Rechnungen/Lastschrift und Spendenkonto
CREATE TABLE IF NOT EXISTS md_bank_accounts (
    account_id INT PRIMARY KEY AUTO_INCREMENT,
    label VARCHAR(100) NOT NULL,                   -- interne Bezeichnung, z. B. "Girokonto" oder "Spendenkonto"
    holder VARCHAR(150) DEFAULT NULL,              -- Kontoinhaber/Zahlungsempfänger (kann vom Vereinsnamen abweichen)
    iban VARCHAR(34) NOT NULL,
    bic VARCHAR(11) DEFAULT NULL,
    bank VARCHAR(100) DEFAULT NULL,
    use_invoice INT NOT NULL DEFAULT 0,            -- 1 = Konto für Rechnungen, Mahnungen und Lastschrift (genau eines)
    use_donation INT NOT NULL DEFAULT 0,           -- 1 = Spendenkonto (genau eines)
    active INT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
