<?php
/** Additive, resumable Data Induk schema migration. Never rewrites legacy rows. */

function dataIndukMigrationIdentifier(string $value): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        throw new InvalidArgumentException('Invalid migration identifier.');
    }
    return '`' . $value . '`';
}

function dataIndukMigrationColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() === 1;
}

function dataIndukMigrationEnsureColumn(PDO $pdo, string $table, string $column, string $definition): string
{
    $quotedTable = dataIndukMigrationIdentifier($table);
    $quotedColumn = dataIndukMigrationIdentifier($column);
    if (dataIndukMigrationColumnExists($pdo, $table, $column)) {
        return "SKIP {$table}.{$column} already exists";
    }
    $pdo->exec("ALTER TABLE {$quotedTable} ADD COLUMN {$quotedColumn} {$definition}");
    return "OK {$table}.{$column} added";
}

function dataIndukMigrationIndex(PDO $pdo, string $table, string $index): array
{
    $stmt = $pdo->prepare('SELECT column_name, non_unique, seq_in_index FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? ORDER BY seq_in_index');
    $stmt->execute([$table, $index]);
    return array_map(static fn(array $row): array => array_change_key_case($row, CASE_LOWER), $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function dataIndukMigrationEnsureIndex(PDO $pdo, string $table, string $index, array $columns, bool $unique = false): string
{
    $existing = dataIndukMigrationIndex($pdo, $table, $index);
    if ($existing !== []) {
        $existingColumns = array_column($existing, 'column_name');
        $existingUnique = (int) $existing[0]['non_unique'] === 0;
        if ($existingColumns !== $columns || $existingUnique !== $unique) {
            throw new RuntimeException("Index {$index} exists with an unexpected definition; migration stopped without changing it.");
        }
        return "SKIP index {$index} already exists";
    }

    if ($unique) {
        $column = dataIndukMigrationIdentifier($columns[0]);
        $tableName = dataIndukMigrationIdentifier($table);
        $duplicates = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT 1 FROM {$tableName} WHERE {$column} IS NOT NULL GROUP BY {$column} HAVING COUNT(*) > 1) duplicate_values")->fetchColumn();
        if ($duplicates > 0) {
            throw new RuntimeException("Cannot add {$index}: {$duplicates} duplicate non-NULL mapping value(s) require review.");
        }
    }

    $quotedColumns = implode(',', array_map('dataIndukMigrationIdentifier', $columns));
    $kind = $unique ? 'UNIQUE ' : '';
    $pdo->exec('ALTER TABLE ' . dataIndukMigrationIdentifier($table) . ' ADD ' . $kind . 'INDEX ' . dataIndukMigrationIdentifier($index) . " ({$quotedColumns})");
    return "OK index {$index} added";
}

function dataIndukMigrationRequireTables(PDO $pdo, array $tables): void
{
    foreach ($tables as $table) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $stmt->execute([$table]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException("Required legacy table {$table} is missing; no migration was started.");
        }
    }
}

function dataIndukMigrationRequireColumns(PDO $pdo, array $columns): void
{
    foreach ($columns as [$table, $column]) {
        if (!dataIndukMigrationColumnExists($pdo, $table, $column)) {
            throw new RuntimeException("Required legacy column {$table}.{$column} is missing; no migration was started.");
        }
    }
}

function dataIndukApplyMigration(PDO $pdo): array
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('Data Induk migration requires MySQL/MariaDB.');
    }
    dataIndukMigrationRequireTables($pdo, ['wali_santri', 'santri_detail', 'users', 'halaqoh_members', 'presensi']);
    dataIndukMigrationRequireColumns($pdo, [
        ['wali_santri', 'id'], ['santri_detail', 'id'], ['users', 'id'],
        ['halaqoh_members', 'halaqoh_id'], ['halaqoh_members', 'wali_santri_id'], ['halaqoh_members', 'archived_at'],
        ['presensi', 'halaqoh_id'], ['presensi', 'wali_santri_id'], ['presensi', 'tanggal'],
    ]);

    $messages = [];
    $columns = [
        ['wali_santri', 'data_induk_guardian_id', 'VARCHAR(36) NULL'],
        ['santri_detail', 'data_induk_student_id', 'VARCHAR(36) NULL'],
        ['santri_detail', 'data_induk_enrollment_id', 'VARCHAR(36) NULL'],
        ['santri_detail', 'data_induk_class_id', 'VARCHAR(36) NULL'],
        ['santri_detail', 'status_roster', "ENUM('active','archived') NOT NULL DEFAULT 'active'"],
        ['users', 'data_induk_teacher_id', 'VARCHAR(36) NULL'],
        ['users', 'data_induk_active', 'TINYINT(1) NOT NULL DEFAULT 1'],
    ];
    foreach ($columns as [$table, $column, $definition]) {
        $messages[] = dataIndukMigrationEnsureColumn($pdo, $table, $column, $definition);
    }

    $indexes = [
        ['wali_santri', 'uq_wali_data_induk_guardian', ['data_induk_guardian_id'], true],
        ['santri_detail', 'uq_santri_data_induk_student', ['data_induk_student_id'], true],
        ['users', 'uq_users_data_induk_teacher', ['data_induk_teacher_id'], true],
        ['halaqoh_members', 'idx_halaqoh_members_active', ['halaqoh_id', 'archived_at', 'wali_santri_id'], false],
    ];
    foreach ($indexes as [$table, $index, $columnsForIndex, $unique]) {
        foreach ($columnsForIndex as $column) {
            if (!dataIndukMigrationColumnExists($pdo, $table, $column)) {
                throw new RuntimeException("Cannot add {$index}: required column {$table}.{$column} is missing.");
            }
        }
        $messages[] = dataIndukMigrationEnsureIndex($pdo, $table, $index, $columnsForIndex, $unique);
    }

    $tables = [
        'data_induk_references' => "CREATE TABLE IF NOT EXISTS data_induk_references (
            master_id VARCHAR(36) PRIMARY KEY, reference_type VARCHAR(30) NOT NULL,
            reference_code VARCHAR(100) NULL, reference_name VARCHAR(255) NULL, payload_json LONGTEXT NULL,
            archived_at DATETIME NULL, updated_at DATETIME NOT NULL,
            KEY idx_reference_type_name (reference_type, reference_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'data_induk_sync_state' => "CREATE TABLE IF NOT EXISTS data_induk_sync_state (
            id TINYINT PRIMARY KEY, change_cursor VARCHAR(64) NOT NULL DEFAULT '0',
            last_success_at DATETIME NULL, last_error_at DATETIME NULL, last_error TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'data_induk_sync_runs' => "CREATE TABLE IF NOT EXISTS data_induk_sync_runs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, scope VARCHAR(30) NOT NULL, status VARCHAR(20) NOT NULL,
            started_at DATETIME NOT NULL, finished_at DATETIME NULL, stats_json LONGTEXT NULL,
            error_message TEXT NULL, KEY idx_sync_runs_status_started (status, started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'data_induk_sync_conflicts' => "CREATE TABLE IF NOT EXISTS data_induk_sync_conflicts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, entity_type VARCHAR(30) NOT NULL, master_id VARCHAR(36) NOT NULL,
            local_id INT NULL, reason VARCHAR(255) NOT NULL, payload_json LONGTEXT NULL,
            status ENUM('pending','approved','ignored') NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL,
            resolved_at DATETIME NULL, resolved_by INT NULL,
            KEY idx_conflict_status_created (status, created_at), KEY idx_conflict_master (entity_type, master_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $name => $sql) {
        $pdo->exec($sql);
        $messages[] = "OK table {$name}";
    }
    $pdo->exec('INSERT IGNORE INTO data_induk_sync_state (id) VALUES (1)');
    $messages[] = 'OK initial sync state';
    return $messages;
}
