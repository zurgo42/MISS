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
    description VARCHAR(255) DEFAULT NULL
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
    sv_aktiv_level INT DEFAULT NULL                -- zugehöriges Berechtigungslevel (svmembers.aktiv), siehe PERMISSIONS.md
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
