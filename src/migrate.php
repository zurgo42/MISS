<?php
declare(strict_types=1);

/** Spalten einer Tabelle (kleingeschrieben), MySQL und SQLite. */
function md_table_columns(string $table): array
{
    $rows = MD_DB_DRIVER === 'sqlite' ? md_q("PRAGMA table_info($table)") : md_q("SHOW COLUMNS FROM $table");
    return array_map('strtolower', array_column($rows, MD_DB_DRIVER === 'sqlite' ? 'name' : 'Field'));
}

function md_table_exists(string $table): bool
{
    return MD_DB_DRIVER === 'sqlite'
        ? (bool)md_one("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?", [$table])
        : (bool)md_one('SHOW TABLES LIKE ' . md_db()->quote($table));
}

/** Nachträglich ergänzte Spalten für bestehende Installationen: [Tabelle, Spalte, Definition]. */
const MD_MIGRATIONS = [
    ['md_ledger', 'created_by', 'INT DEFAULT NULL'],
    ['md_ledger', 'source', "VARCHAR(10) NOT NULL DEFAULT 'manual'"],
    ['md_ledger', 'cancelled_at', 'DATETIME DEFAULT NULL'],
    ['md_ledger', 'cancelled_by', 'INT DEFAULT NULL'],
    ['md_ledger', 'cancel_reason', 'VARCHAR(255) DEFAULT NULL'],
    ['md_import_profiles', 'target', "VARCHAR(10) NOT NULL DEFAULT 'members'"],
    ['md_ledger', 'receipt_id', 'INT DEFAULT NULL'],
    ['md_ledger', 'sepa_item_id', 'INT DEFAULT NULL'],
    ['md_mail_outbox', 'reply_to', 'VARCHAR(255) DEFAULT NULL'],
    ['md_mail_outbox', 'unsub_url', 'VARCHAR(255) DEFAULT NULL'],
    ['md_functions', 'active', 'INT NOT NULL DEFAULT 1'],
    ['md_groups', 'active', 'INT NOT NULL DEFAULT 1'],
    ['md_fee_groups', 'public', 'INT NOT NULL DEFAULT 0'],
    ['md_profiles', 'letter_channel', "VARCHAR(10) NOT NULL DEFAULT 'post'"],
    ['md_ledger', 'run_id', 'INT DEFAULT NULL'],
    ['md_ledger', 'fee_period', 'VARCHAR(10) DEFAULT NULL'],
    ['md_ledger', 'dunning_level', 'INT NOT NULL DEFAULT 0'],
    ['md_ledger', 'last_dunned_at', 'DATE DEFAULT NULL'],
    ['md_ledger', 'deferred_until', 'DATE DEFAULT NULL'],
    ['md_ledger', 'deferred_reason', 'VARCHAR(255) DEFAULT NULL'],
    ['md_profiles', 'retention_hold', 'INT NOT NULL DEFAULT 0'],
    ['md_profiles', 'hold_reason', 'VARCHAR(255) DEFAULT NULL'],
    ['md_profiles', 'purged_at', 'DATETIME DEFAULT NULL'],
    ['md_profiles', 'anonymized_at', 'DATETIME DEFAULT NULL'],
    ['md_member_functions', 'aktiv_level', 'INT DEFAULT NULL'],
    ['md_member_functions', 'ressort_id', 'INT DEFAULT NULL'],
];

/** Ergänzt fehlende Spalten. Gibt die Liste der ergänzten "tabelle.spalte" zurück. */
function md_migrate_schema(): array
{
    $done = [];
    foreach (MD_MIGRATIONS as [$table, $col, $ddl]) {
        if (!md_table_exists($table)) continue;
        if (!in_array($col, md_table_columns($table), true)) {
            md_db()->exec("ALTER TABLE $table ADD COLUMN $col $ddl");
            $done[] = "$table.$col";
        }
    }
    return $done;
}

/** Ist das Schema aktuell? Sonst Hinweis statt Datenbankfehler. */
function md_require_schema(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $ok = md_table_exists('md_permissions') && md_table_exists('md_receipts') && md_table_exists('md_privacy_log') && md_table_exists('md_fee_groups') && md_table_exists('md_dunning_letters') && md_table_exists('md_sepa_batches') && md_table_exists('md_mail_outbox') && md_table_exists('md_login_tokens') && md_table_exists('md_events') && md_table_exists('md_mail_lists') && md_table_exists('md_applications') && md_table_exists('md_datev_rows') && md_table_exists('md_user_prefs') && md_table_exists('md_bank_accounts');
    if ($ok) foreach (MD_MIGRATIONS as [$t, $c]) if (md_table_exists($t) && !in_array($c, md_table_columns($t), true)) { $ok = false; break; }
    if (!$ok) {
        http_response_code(503);
        exit('Die Datenbank der Mitgliederverwaltung ist nicht auf dem neuesten Stand. Bitte "php tools/install.php" erneut ausführen (ergänzt nur Neues, löscht nichts).');
    }
}
