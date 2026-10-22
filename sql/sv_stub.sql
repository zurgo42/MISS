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
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
