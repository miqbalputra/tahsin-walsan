<?php

/**
 * Plans and applies additive Data Induk schema changes. All compatibility and
 * duplicate checks run before the first DDL statement because MySQL DDL commits
 * implicitly. Existing rows are never updated or deleted by this migration.
 */
function dataIndukMigrationCurrentDatabase(PDO $pdo): string
{
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($database === '') throw new RuntimeException('Select the application database before planning the Data Induk migration.');
    return $database;
}

function dataIndukMigrationTable(PDO $pdo, string $table): ?array
{
    $statement = $pdo->prepare('SELECT engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $statement->execute([$table]);
    $engine = $statement->fetchColumn();
    if ($engine === false) return null;
    $columns = $pdo->prepare('SELECT column_name, column_type, is_nullable, column_default, extra FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?');
    $columns->execute([$table]);
    $columnMap = [];
    foreach ($columns->fetchAll(PDO::FETCH_ASSOC) as $column) $columnMap[$column['column_name']] = $column;
    return ['engine' => (string) $engine, 'columns' => $columnMap];
}

function dataIndukMigrationIndexes(PDO $pdo, string $table): array
{
    $statement = $pdo->prepare('SELECT index_name, non_unique, seq_in_index, column_name FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? ORDER BY index_name, seq_in_index');
    $statement->execute([$table]);
    $indexes = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = (string) $row['index_name'];
        if (!isset($indexes[$name])) $indexes[$name] = ['unique' => (int) $row['non_unique'] === 0, 'columns' => []];
        $indexes[$name]['columns'][] = $row['column_name'] === null ? null : (string) $row['column_name'];
    }
    return $indexes;
}

function dataIndukMigrationColumnMatches(array $actual, array $expected): bool
{
    $normalizeType = static function (string $type): string {
        $type = strtolower($type);
        return (string) preg_replace('/^(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/', '$1', $type);
    };
    $type = $normalizeType((string) $actual['column_type']);
    $nullable = strtoupper((string) $actual['is_nullable']) === 'YES';
    $default = $actual['column_default'];
    $expectedDefault = $expected['default'];
    if ($expectedDefault === null) {
        // MariaDB exposes SQL DEFAULT NULL as the unquoted string "NULL";
        // a literal string default is quoted in COLUMN_DEFAULT.
        $defaultMatches = $default === null || (string) $default === 'NULL';
    } else {
        $expectedDefault = (string) $expectedDefault;
        $quotedDefault = "'" . str_replace("'", "''", $expectedDefault) . "'";
        $defaultMatches = (string) $default === $expectedDefault || (string) $default === $quotedDefault;
    }
    return $type === $normalizeType($expected['type'])
        && $nullable === $expected['nullable']
        && $defaultMatches
        && strtolower((string) $actual['extra']) === strtolower((string) ($expected['extra'] ?? ''));
}

function dataIndukMigrationColumnDescription(array $column): string
{
    return 'type=' . (string) $column['column_type']
        . ', nullable=' . (string) $column['is_nullable']
        . ', default=' . var_export($column['column_default'], true)
        . ', extra=' . (string) $column['extra'];
}

function dataIndukMigrationAssertColumns(string $table, array $actualColumns, array $expectedColumns): void
{
    foreach ($expectedColumns as $column => $expected) {
        if (!isset($actualColumns[$column])) throw new RuntimeException("Existing Data Induk table {$table} is missing required column {$column}; inspect it before changing the schema.");
        if (!dataIndukMigrationColumnMatches($actualColumns[$column], $expected)) {
            throw new RuntimeException("Existing Data Induk column {$table}.{$column} has a different definition (" . dataIndukMigrationColumnDescription($actualColumns[$column]) . "); migration stopped without changing the database.");
        }
    }
}

function dataIndukMigrationRequireIndex(array $indexes, string $table, string $name, array $columns, bool $unique): bool
{
    if (isset($indexes[$name])) {
        $named = $indexes[$name];
        if ($named['columns'] !== $columns || ($unique && !$named['unique'])) {
            throw new RuntimeException("Index {$table}.{$name} already exists with a different definition; migration stopped without changing the database.");
        }
        return true;
    }
    foreach ($indexes as $index) {
        if ($index['columns'] === $columns && (!$unique || $index['unique'])) return true;
    }
    return false;
}

function dataIndukMigrationAddColumn(PDO $pdo, array &$operations, string $table, string $column, string $definition, array $expected): void
{
    $info = dataIndukMigrationTable($pdo, $table);
    $actual = $info['columns'][$column] ?? null;
    if ($actual !== null) {
        if (!dataIndukMigrationColumnMatches($actual, $expected)) {
            throw new RuntimeException("Existing column {$table}.{$column} has a different definition (" . dataIndukMigrationColumnDescription($actual) . "); migration stopped without changing the database.");
        }
        return;
    }
    $operations[] = ['label' => "{$table}.{$column}", 'sql' => "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}"];
}

function dataIndukMigrationAddIndex(PDO $pdo, array &$operations, string $table, string $name, array $columns, bool $unique): void
{
    $indexes = dataIndukMigrationIndexes($pdo, $table);
    if (dataIndukMigrationRequireIndex($indexes, $table, $name, $columns, $unique)) return;
    $columnList = implode(',', array_map(static fn(string $column): string => "`{$column}`", $columns));
    $kind = $unique ? 'UNIQUE KEY' : 'KEY';
    $operations[] = ['label' => "{$table}.{$name}", 'sql' => "ALTER TABLE `{$table}` ADD {$kind} `{$name}` ({$columnList})"];
}

function dataIndukMigrationCheckNoDuplicates(PDO $pdo, string $table, string $column): void
{
    $sql = "SELECT 1 FROM `{$table}` WHERE `{$column}` IS NOT NULL GROUP BY `{$column}` HAVING COUNT(*) > 1 LIMIT 1";
    if ($pdo->query($sql)->fetchColumn() !== false) {
        throw new RuntimeException("Duplicate values exist in {$table}.{$column}; resolve them before adding the unique mapping index. Existing rows were not changed.");
    }
}

function dataIndukMigrationValidateExistingTable(PDO $pdo, string $table, array $columnDefinitions, array $indexDefinitions): bool
{
    $info = dataIndukMigrationTable($pdo, $table);
    if ($info === null) return false;
    if (strtolower($info['engine']) !== 'innodb') {
        throw new RuntimeException("Existing table {$table} uses {$info['engine']}; Data Induk requires InnoDB. Convert it through a reviewed backup and staging migration.");
    }
    dataIndukMigrationAssertColumns($table, $info['columns'], $columnDefinitions);
    $indexes = dataIndukMigrationIndexes($pdo, $table);
    foreach ($indexDefinitions as [$name, $columns, $unique]) {
        if (!dataIndukMigrationRequireIndex($indexes, $table, $name, $columns, $unique)) {
            throw new RuntimeException("Existing Data Induk table {$table} is missing required index {$name}; inspect it before changing the schema.");
        }
    }
    return true;
}

/** Return a complete, prevalidated plan. No DDL or DML is executed here. */
function dataIndukMigrationPlan(PDO $pdo): array
{
    dataIndukMigrationCurrentDatabase($pdo);
    $baseRequirements = [
        'wali_santri' => ['id'],
        'santri_detail' => ['id'],
        'users' => ['id'],
        'halaqoh_members' => ['id', 'halaqoh_id', 'wali_santri_id', 'archived_at', 'archive_reason'],
        'presensi' => ['id', 'halaqoh_id', 'wali_santri_id', 'tanggal'],
    ];
    foreach ($baseRequirements as $table => $required) {
        $info = dataIndukMigrationTable($pdo, $table);
        if ($info === null) throw new RuntimeException("Required existing table {$table} is missing; migration stopped before making changes.");
        if (strtolower($info['engine']) !== 'innodb') throw new RuntimeException("Required existing table {$table} must use InnoDB so sync transactions preserve data.");
        foreach ($required as $column) {
            if (!isset($info['columns'][$column])) throw new RuntimeException("Required existing column {$table}.{$column} is missing; migration stopped before making changes.");
        }
    }

    $tables = [
        'data_induk_references' => [
            'columns' => [
                'master_id' => ['type' => 'varchar(36)', 'nullable' => false, 'default' => null],
                'reference_type' => ['type' => 'varchar(30)', 'nullable' => false, 'default' => null],
                'reference_code' => ['type' => 'varchar(100)', 'nullable' => true, 'default' => null],
                'reference_name' => ['type' => 'varchar(255)', 'nullable' => true, 'default' => null],
                'payload_json' => ['type' => 'longtext', 'nullable' => true, 'default' => null],
                'archived_at' => ['type' => 'datetime', 'nullable' => true, 'default' => null],
                'updated_at' => ['type' => 'datetime', 'nullable' => false, 'default' => null],
            ],
            'indexes' => [['PRIMARY', ['master_id'], true], ['idx_reference_type_name', ['reference_type', 'reference_name'], false]],
            'create' => "CREATE TABLE `data_induk_references` (`master_id` VARCHAR(36) NOT NULL, `reference_type` VARCHAR(30) NOT NULL, `reference_code` VARCHAR(100) NULL, `reference_name` VARCHAR(255) NULL, `payload_json` LONGTEXT NULL, `archived_at` DATETIME NULL, `updated_at` DATETIME NOT NULL, PRIMARY KEY (`master_id`), KEY `idx_reference_type_name` (`reference_type`,`reference_name`)) ENGINE=InnoDB",
        ],
        'data_induk_sync_state' => [
            'columns' => [
                'id' => ['type' => 'tinyint(4)', 'nullable' => false, 'default' => null],
                'change_cursor' => ['type' => 'varchar(64)', 'nullable' => false, 'default' => '0'],
                'last_success_at' => ['type' => 'datetime', 'nullable' => true, 'default' => null],
                'last_error_at' => ['type' => 'datetime', 'nullable' => true, 'default' => null],
                'last_error' => ['type' => 'text', 'nullable' => true, 'default' => null],
            ],
            'indexes' => [['PRIMARY', ['id'], true]],
            'create' => "CREATE TABLE `data_induk_sync_state` (`id` TINYINT NOT NULL, `change_cursor` VARCHAR(64) NOT NULL DEFAULT '0', `last_success_at` DATETIME NULL, `last_error_at` DATETIME NULL, `last_error` TEXT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB",
        ],
        'data_induk_sync_runs' => [
            'columns' => [
                'id' => ['type' => 'bigint(20)', 'nullable' => false, 'default' => null, 'extra' => 'auto_increment'],
                'scope' => ['type' => 'varchar(30)', 'nullable' => false, 'default' => null],
                'status' => ['type' => 'varchar(20)', 'nullable' => false, 'default' => null],
                'started_at' => ['type' => 'datetime', 'nullable' => false, 'default' => null],
                'finished_at' => ['type' => 'datetime', 'nullable' => true, 'default' => null],
                'stats_json' => ['type' => 'longtext', 'nullable' => true, 'default' => null],
                'error_message' => ['type' => 'text', 'nullable' => true, 'default' => null],
            ],
            'indexes' => [['PRIMARY', ['id'], true], ['idx_sync_runs_status_started', ['status', 'started_at'], false]],
            'create' => "CREATE TABLE `data_induk_sync_runs` (`id` BIGINT NOT NULL AUTO_INCREMENT, `scope` VARCHAR(30) NOT NULL, `status` VARCHAR(20) NOT NULL, `started_at` DATETIME NOT NULL, `finished_at` DATETIME NULL, `stats_json` LONGTEXT NULL, `error_message` TEXT NULL, PRIMARY KEY (`id`), KEY `idx_sync_runs_status_started` (`status`,`started_at`)) ENGINE=InnoDB",
        ],
        'data_induk_sync_conflicts' => [
            'columns' => [
                'id' => ['type' => 'bigint(20)', 'nullable' => false, 'default' => null, 'extra' => 'auto_increment'],
                'entity_type' => ['type' => 'varchar(30)', 'nullable' => false, 'default' => null],
                'master_id' => ['type' => 'varchar(36)', 'nullable' => false, 'default' => null],
                'local_id' => ['type' => 'int(11)', 'nullable' => true, 'default' => null],
                'reason' => ['type' => 'varchar(255)', 'nullable' => false, 'default' => null],
                'payload_json' => ['type' => 'longtext', 'nullable' => true, 'default' => null],
                'status' => ['type' => "enum('pending','approved','ignored')", 'nullable' => false, 'default' => 'pending'],
                'created_at' => ['type' => 'datetime', 'nullable' => false, 'default' => null],
                'resolved_at' => ['type' => 'datetime', 'nullable' => true, 'default' => null],
                'resolved_by' => ['type' => 'int(11)', 'nullable' => true, 'default' => null],
            ],
            'indexes' => [['PRIMARY', ['id'], true], ['idx_conflict_status_created', ['status', 'created_at'], false], ['idx_conflict_master', ['entity_type', 'master_id'], false]],
            'create' => "CREATE TABLE `data_induk_sync_conflicts` (`id` BIGINT NOT NULL AUTO_INCREMENT, `entity_type` VARCHAR(30) NOT NULL, `master_id` VARCHAR(36) NOT NULL, `local_id` INT NULL, `reason` VARCHAR(255) NOT NULL, `payload_json` LONGTEXT NULL, `status` ENUM('pending','approved','ignored') NOT NULL DEFAULT 'pending', `created_at` DATETIME NOT NULL, `resolved_at` DATETIME NULL, `resolved_by` INT NULL, PRIMARY KEY (`id`), KEY `idx_conflict_status_created` (`status`,`created_at`), KEY `idx_conflict_master` (`entity_type`,`master_id`)) ENGINE=InnoDB",
        ],
    ];

    // Validate every pre-existing destination table before planning any write.
    $existingTables = [];
    foreach ($tables as $table => $definition) {
        $existingTables[$table] = dataIndukMigrationValidateExistingTable($pdo, $table, $definition['columns'], $definition['indexes']);
    }

    $columnDefinitions = [
        'wali_santri' => [
            'data_induk_guardian_id' => ['sql' => 'VARCHAR(36) NULL', 'expected' => ['type' => 'varchar(36)', 'nullable' => true, 'default' => null]],
        ],
        'santri_detail' => [
            'data_induk_student_id' => ['sql' => 'VARCHAR(36) NULL', 'expected' => ['type' => 'varchar(36)', 'nullable' => true, 'default' => null]],
            'data_induk_enrollment_id' => ['sql' => 'VARCHAR(36) NULL', 'expected' => ['type' => 'varchar(36)', 'nullable' => true, 'default' => null]],
            'data_induk_class_id' => ['sql' => 'VARCHAR(36) NULL', 'expected' => ['type' => 'varchar(36)', 'nullable' => true, 'default' => null]],
            'status_roster' => ['sql' => "ENUM('active','archived') NOT NULL DEFAULT 'active'", 'expected' => ['type' => "enum('active','archived')", 'nullable' => false, 'default' => 'active']],
        ],
        'users' => [
            'data_induk_teacher_id' => ['sql' => 'VARCHAR(36) NULL', 'expected' => ['type' => 'varchar(36)', 'nullable' => true, 'default' => null]],
            'data_induk_active' => ['sql' => 'TINYINT(1) NOT NULL DEFAULT 1', 'expected' => ['type' => 'tinyint(1)', 'nullable' => false, 'default' => '1']],
        ],
    ];
    $allColumns = [];
    foreach ($columnDefinitions as $table => $columns) {
        $info = dataIndukMigrationTable($pdo, $table);
        foreach ($columns as $column => $definition) {
            if (isset($info['columns'][$column]) && !dataIndukMigrationColumnMatches($info['columns'][$column], $definition['expected'])) {
                throw new RuntimeException("Existing column {$table}.{$column} has a different definition (" . dataIndukMigrationColumnDescription($info['columns'][$column]) . "); migration stopped before changing the database.");
            }
            $allColumns[$table][$column] = isset($info['columns'][$column]);
        }
    }

    $operations = [];
    // Attendance uniqueness is the most likely data-dependent failure, so add
    // it before other DDL after checking that all non-NULL keys are unique.
    $presensiIndexes = dataIndukMigrationIndexes($pdo, 'presensi');
    $hasAttendanceUnique = dataIndukMigrationRequireIndex($presensiIndexes, 'presensi', 'uq_presensi_halaqoh_wali_tanggal', ['halaqoh_id', 'wali_santri_id', 'tanggal'], true);
    if (!$hasAttendanceUnique) {
        $duplicates = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT 1 FROM presensi WHERE halaqoh_id IS NOT NULL AND wali_santri_id IS NOT NULL AND tanggal IS NOT NULL GROUP BY halaqoh_id,wali_santri_id,tanggal HAVING COUNT(*) > 1) duplicate_keys')->fetchColumn();
        if ($duplicates > 0) throw new RuntimeException("{$duplicates} duplicate attendance key(s) exist; resolve them before migration. No schema changes were applied and existing attendance rows remain untouched.");
        $operations[] = ['label' => 'presensi.uq_presensi_halaqoh_wali_tanggal', 'sql' => 'ALTER TABLE `presensi` ADD UNIQUE KEY `uq_presensi_halaqoh_wali_tanggal` (`halaqoh_id`,`wali_santri_id`,`tanggal`)'];
    }

    foreach ($columnDefinitions as $table => $columns) {
        foreach ($columns as $column => $definition) {
            dataIndukMigrationAddColumn($pdo, $operations, $table, $column, $definition['sql'], $definition['expected']);
        }
    }

    $mappingIndexes = [
        ['wali_santri', 'uq_wali_data_induk_guardian', ['data_induk_guardian_id']],
        ['santri_detail', 'uq_santri_data_induk_student', ['data_induk_student_id']],
        ['users', 'uq_users_data_induk_teacher', ['data_induk_teacher_id']],
    ];
    foreach ($mappingIndexes as [$table, $name, $columns]) {
        $indexes = dataIndukMigrationIndexes($pdo, $table);
        if (!dataIndukMigrationRequireIndex($indexes, $table, $name, $columns, true)) {
            if (empty($allColumns[$table][$columns[0]])) {
                // The column will be added by this plan; its default NULL values
                // are unique by definition, so no row scan is needed.
            } else {
                dataIndukMigrationCheckNoDuplicates($pdo, $table, $columns[0]);
            }
            $columnList = '`' . implode('`,`', $columns) . '`';
            $operations[] = ['label' => "{$table}.{$name}", 'sql' => "ALTER TABLE `{$table}` ADD UNIQUE KEY `{$name}` ({$columnList})"];
        }
    }

    dataIndukMigrationAddIndex($pdo, $operations, 'halaqoh_members', 'idx_halaqoh_members_active', ['halaqoh_id', 'archived_at', 'wali_santri_id'], false);

    foreach ($tables as $table => $definition) {
        if (!$existingTables[$table]) $operations[] = ['label' => $table, 'sql' => $definition['create']];
    }
    $syncStateSeeded = false;
    if ($existingTables['data_induk_sync_state']) {
        $seed = $pdo->query('SELECT 1 FROM data_induk_sync_state WHERE id=1 LIMIT 1');
        $syncStateSeeded = $seed->fetchColumn() !== false;
    }
    if (!$syncStateSeeded) $operations[] = ['label' => 'data_induk_sync_state.seed', 'sql' => 'INSERT IGNORE INTO `data_induk_sync_state` (`id`) VALUES (1)'];
    return $operations;
}

/** Apply the prevalidated plan and report each independently resumable change. */
function dataIndukMigrationApply(PDO $pdo, callable $report): array
{
    $operations = dataIndukMigrationPlan($pdo);
    foreach ($operations as $operation) {
        $pdo->exec($operation['sql']);
        $report($operation['label']);
    }
    return $operations;
}
