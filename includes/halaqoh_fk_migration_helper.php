<?php

/** Inspect the legacy attendance-to-halaqoh constraint without changing data. */
function halaqohFkMigrationPlan(PDO $pdo): array
{
    $tables = $pdo->query("SELECT TABLE_NAME,ENGINE FROM information_schema.tables
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('presensi','halaqoh') AND TABLE_TYPE='BASE TABLE'")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    if (($tables['presensi'] ?? null) !== 'InnoDB' || ($tables['halaqoh'] ?? null) !== 'InnoDB') {
        throw new RuntimeException('Tabel presensi dan halaqoh harus tersedia sebagai InnoDB. Migrasi tidak dijalankan.');
    }

    $columnStmt = $pdo->prepare("SELECT COLUMN_TYPE,DATA_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLUMN_COMMENT
        FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='presensi' AND COLUMN_NAME='halaqoh_id'");
    $columnStmt->execute();
    $column = $columnStmt->fetch(PDO::FETCH_ASSOC);
    $parentStmt = $pdo->prepare("SELECT COLUMN_TYPE,DATA_TYPE FROM information_schema.columns
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='halaqoh' AND COLUMN_NAME='id'");
    $parentStmt->execute();
    $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$column || !$parent) throw new RuntimeException('Kolom presensi.halaqoh_id atau halaqoh.id tidak ditemukan.');
    if (!in_array(strtolower($column['DATA_TYPE']), ['tinyint','smallint','mediumint','int','integer','bigint'], true)
        || strtolower($column['DATA_TYPE']) !== strtolower($parent['DATA_TYPE'])
        || str_contains(strtolower($column['COLUMN_TYPE']), 'unsigned') !== str_contains(strtolower($parent['COLUMN_TYPE']), 'unsigned')
        || !preg_match('/^(?:tinyint|smallint|mediumint|int|integer|bigint)(?:\([0-9]+\))?(?: unsigned)?$/iD', $column['COLUMN_TYPE'])
        || $column['EXTRA'] !== '') {
        throw new RuntimeException('Tipe presensi.halaqoh_id tidak cocok dengan halaqoh.id atau memiliki atribut khusus. Tinjau skema sebelum migrasi.');
    }

    $fkStmt = $pdo->query("SELECT k.CONSTRAINT_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,
            (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE all_k
                WHERE all_k.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND all_k.TABLE_NAME=k.TABLE_NAME
                  AND all_k.CONSTRAINT_NAME=k.CONSTRAINT_NAME) AS column_count
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
        WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME='presensi' AND k.COLUMN_NAME='halaqoh_id'");
    $foreignKeys = $fkStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($foreignKeys) > 1) throw new RuntimeException('Lebih dari satu foreign key memakai presensi.halaqoh_id. Tinjau skema sebelum migrasi.');
    $foreignKey = $foreignKeys[0] ?? null;
    if ($foreignKey && ($foreignKey['REFERENCED_TABLE_NAME'] !== 'halaqoh' || $foreignKey['REFERENCED_COLUMN_NAME'] !== 'id'
        || (int) $foreignKey['column_count'] !== 1)) {
        throw new RuntimeException('Foreign key presensi.halaqoh_id memiliki relasi yang tidak dikenal. Tinjau skema sebelum migrasi.');
    }

    $orphans = (int) $pdo->query('SELECT COUNT(*) FROM presensi p LEFT JOIN halaqoh h ON h.id=p.halaqoh_id WHERE p.halaqoh_id IS NOT NULL AND h.id IS NULL')->fetchColumn();
    $existingConstraintName = $foreignKey['CONSTRAINT_NAME'] ?? null;
    $needsChange = $column['IS_NULLABLE'] !== 'YES' || !$foreignKey || $foreignKey['DELETE_RULE'] !== 'SET NULL';
    $constraintName = $existingConstraintName;
    if ($needsChange) {
        $baseName = 'fk_presensi_halaqoh_set_null';
        $constraintName = $baseName;
        for ($suffix = 2; ; $suffix++) {
            $nameStmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME=?');
            $nameStmt->execute([$constraintName]);
            if ((int) $nameStmt->fetchColumn() === 0) break;
            $constraintName = $baseName . '_' . $suffix;
            if ($suffix > 20) throw new RuntimeException('Nama constraint untuk relasi presensi tidak tersedia.');
        }
    }
    return [
        'needs_change' => $needsChange,
        'nullable' => $column['IS_NULLABLE'] === 'YES',
        'constraint_name' => $constraintName,
        'existing_constraint_name' => $existingConstraintName,
        'delete_rule' => $foreignKey['DELETE_RULE'] ?? null,
        'orphan_count' => $orphans,
        'column' => $column,
    ];
}

/** Apply one idempotent ALTER only after a complete orphan-reference preflight. */
function halaqohFkMigrationApply(PDO $pdo): array
{
    if ($pdo->inTransaction()) throw new RuntimeException('Jalankan migrasi foreign key di luar transaksi aplikasi.');
    $plan = halaqohFkMigrationPlan($pdo);
    if ($plan['orphan_count'] > 0) {
        throw new InvalidArgumentException('Ditemukan ' . $plan['orphan_count'] . ' presensi dengan halaqoh yang tidak ada. Migrasi dibatalkan tanpa mengubah skema atau isi presensi.');
    }
    if (!$plan['needs_change']) return ['changed' => false, 'constraint_name' => $plan['constraint_name']];

    $column = $plan['column'];
    $definition = '`halaqoh_id` ' . $column['COLUMN_TYPE'] . ' NULL';
    if ($column['COLUMN_DEFAULT'] !== null) $definition .= ' DEFAULT ' . $pdo->quote((string) $column['COLUMN_DEFAULT']);
    if ($column['COLUMN_COMMENT'] !== '') $definition .= ' COMMENT ' . $pdo->quote((string) $column['COLUMN_COMMENT']);
    $constraint = '`' . str_replace('`', '``', $plan['constraint_name']) . '`';
    $clauses = [];
    if ($plan['delete_rule'] !== null) {
        $oldConstraint = '`' . str_replace('`', '``', $plan['existing_constraint_name']) . '`';
        $clauses[] = 'DROP FOREIGN KEY ' . $oldConstraint;
    }
    $clauses[] = 'MODIFY COLUMN ' . $definition;
    $clauses[] = 'ADD CONSTRAINT ' . $constraint . ' FOREIGN KEY (`halaqoh_id`) REFERENCES `halaqoh` (`id`) ON DELETE SET NULL';
    $pdo->exec('ALTER TABLE `presensi` ' . implode(', ', $clauses));

    $verified = halaqohFkMigrationPlan($pdo);
    if ($verified['needs_change'] || $verified['orphan_count'] > 0) {
        throw new RuntimeException('Foreign key setelah migrasi belum sesuai. Isi baris presensi tidak dihapus; periksa skema secara manual.');
    }
    return ['changed' => true, 'constraint_name' => $verified['constraint_name']];
}
