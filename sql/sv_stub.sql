-- NUR für die lokale Demo ohne Sitzungsverwaltung: Minimalversion von svmembers.
-- Produktiv wird die echte Tabelle der Sitzungsverwaltung verwendet (nicht anlegen!).
CREATE TABLE IF NOT EXISTS svmembers (
    member_id INT PRIMARY KEY AUTO_INCREMENT,
    membership_number VARCHAR(50) UNIQUE NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    role VARCHAR(20) DEFAULT 'mitglied',
    aktiv INT DEFAULT 0,
    funktion VARCHAR(10) DEFAULT NULL,
    is_admin INT DEFAULT 0,
    is_active INT DEFAULT 1,
    is_confidential INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ressortliste der Sitzungsverwaltung (nur Demo)
CREATE TABLE IF NOT EXISTS svressorts (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    Code VARCHAR(4) DEFAULT NULL,
    Ressort VARCHAR(100) NOT NULL,
    Reihenfolge INT DEFAULT 100,
    aktiv INT DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
