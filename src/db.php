<?php
declare(strict_types=1);

function md_db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    if (MD_DB_DRIVER === 'sqlite') {
        $pdo = new PDO('sqlite:' . MD_SQLITE_FILE);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $pdo = new PDO('mysql:host=' . MD_DB_HOST . ';dbname=' . MD_DB_NAME . ';charset=utf8mb4', MD_DB_USER, MD_DB_PASS);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

/** Führt eine SQL-Datei aus; für SQLite wird das MySQL-Dialekt-Beiwerk entfernt. */
function md_run_sql_file(PDO $pdo, string $file): void
{
    $sql = (string)file_get_contents($file);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $sql = preg_replace('/\s--[^\n]*/', '', $sql);               // Zeilenendkommentare
    if (MD_DB_DRIVER === 'sqlite') {
        $sql = preg_replace('/\)\s*ENGINE=[^\n]*;/', ');', $sql);
        $sql = str_replace('INT PRIMARY KEY AUTO_INCREMENT', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    }
    foreach (array_filter(array_map('trim', preg_split('/;[ \t]*\R/', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
}

function md_q(string $sql, array $params = []): array
{
    $st = md_db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function md_one(string $sql, array $params = []): ?array
{
    $st = md_db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function md_exec(string $sql, array $params = []): int
{
    $st = md_db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

function md_now(): string
{
    return date('Y-m-d H:i:s');
}
