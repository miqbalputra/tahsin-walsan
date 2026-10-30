<?php
/** Isolated MariaDB integration for additive, prevalidated Data Induk DDL. */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/data_induk_migration_helper.php';

$pdo = new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4', 'root', 'tahsin_local_test', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$checks = 0;
function dimCheck(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function dimDatabase(PDO $pdo): string
{
    $database = 'tahsin_test_data_induk_migration_' . getmypid() . '_' . bin2hex(random_bytes(4));
    $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `{$database}`");
    $pdo->exec(file_get_contents(__DIR__ . '/fixtures/tahsin-schema.sql'));
    $pdo->exec('ALTER TABLE halaqoh_members ADD archived_at DATETIME NULL, ADD archive_reason VARCHAR(50) NULL');
    $pdo->exec("INSERT INTO users(id,username,password,nama_lengkap,role) VALUES (1,'guru-lama','hash-lama','Guru Lama','ustadz');
        INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES (1,'Kelompok Lama',1);
        INSERT INTO wali_santri(id,nama_bapak,no_hp,alamat,kategori,status_aktif) VALUES (1,'Wali Lama','08123456789','Alamat lama','reguler',1),(2,'Wali Kedua','08234567890','Alamat kedua','askar',1);
        INSERT INTO santri_detail(id,wali_santri_id,nama_anak,kelas) VALUES (11,1,'Anak Lama','Mustawa 2');
        INSERT INTO halaqoh_members(id,halaqoh_id,wali_santri_id,archived_at,archive_reason) VALUES (21,1,1,NULL,NULL);
        INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status,alasan) VALUES (31,1,1,'2026-10-18','H','Catatan lama');");
    return $database;
}
function dimLegacyDigest(PDO $pdo): string
{
    $columns = [
        'users' => 'id,username,password,nama_lengkap,role,no_hp,created_at',
        'wali_santri' => 'id,nama_bapak,no_hp,alamat,kategori,tempat_tahsin,ustadz_luar,status_aktif,lanjut_tahsin,created_at',
        'santri_detail' => 'id,wali_santri_id,nama_anak,kelas',
        'halaqoh_members' => 'id,halaqoh_id,wali_santri_id,archived_at,archive_reason',
        'presensi' => 'id,halaqoh_id,wali_santri_id,tanggal,status,alasan,jenis_materi,jilid,nama_surat,halaman,hasil_talaqqi,created_at',
    ];
    $rows = [];
    foreach ($columns as $table => $list) $rows[$table] = $pdo->query("SELECT {$list} FROM `{$table}` ORDER BY id")->fetchAll();
    return hash('sha256', serialize($rows));
}
function dimTableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $statement->execute([$table]);
    return (int) $statement->fetchColumn() > 0;
}
function dimColumnExists(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $statement->execute([$table, $column]);
    return (int) $statement->fetchColumn() > 0;
}
function dimReject(callable $operation, string $message): Throwable
{
    try { $operation(); } catch (Throwable $error) { dimCheck(true, $message); return $error; }
    throw new RuntimeException($message . ' (operation unexpectedly succeeded)');
}

$databases = [];
try {
    // Duplicate attendance keys must be found before any migration DDL.
    $duplicateDb = dimDatabase($pdo); $databases[] = $duplicateDb;
    $pdo->exec("INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status,alasan) VALUES (32,1,1,'2026-10-18','S','Duplikat uji')");
    $duplicateBaseline = dimLegacyDigest($pdo);
    $duplicateError = dimReject(fn() => dataIndukMigrationPlan($pdo), 'Duplicate attendance keys block planning');
    dimCheck(str_contains($duplicateError->getMessage(), 'duplicate attendance'), 'Duplicate attendance error explains the safe next step');
    dimCheck(dimLegacyDigest($pdo) === $duplicateBaseline, 'Duplicate-key preflight preserves every existing row');
    foreach (['data_induk_guardian_id' => 'wali_santri', 'data_induk_student_id' => 'santri_detail', 'data_induk_teacher_id' => 'users'] as $column => $table) {
        dimCheck(!dimColumnExists($pdo, $table, $column), 'Duplicate preflight runs before mapping columns are added');
    }
    foreach (['data_induk_references','data_induk_sync_state','data_induk_sync_runs','data_induk_sync_conflicts'] as $table) {
        dimCheck(!dimTableExists($pdo, $table), 'Duplicate preflight runs before new tables are created');
    }

    // Existing duplicate remote IDs must also block the complete plan up front.
    $uuidDb = dimDatabase($pdo); $databases[] = $uuidDb;
    $pdo->exec("ALTER TABLE wali_santri ADD data_induk_guardian_id VARCHAR(36) NULL;
        UPDATE wali_santri SET data_induk_guardian_id='duplicate-master-id' WHERE id IN (1,2)");
    $uuidBaseline = dimLegacyDigest($pdo);
    $uuidRows = $pdo->query('SELECT id,data_induk_guardian_id FROM wali_santri ORDER BY id')->fetchAll();
    $uuidError = dimReject(fn() => dataIndukMigrationPlan($pdo), 'Duplicate existing UUID values block migration planning');
    dimCheck(str_contains($uuidError->getMessage(), 'Duplicate values exist'), 'UUID collision error explains the mapping that needs review: ' . $uuidError->getMessage());
    dimCheck(dimLegacyDigest($pdo) === $uuidBaseline && $pdo->query('SELECT id,data_induk_guardian_id FROM wali_santri ORDER BY id')->fetchAll() === $uuidRows,
        'UUID preflight preserves all source and mapping values');
    dimCheck(!dimColumnExists($pdo, 'santri_detail', 'data_induk_student_id') && !dimTableExists($pdo, 'data_induk_sync_state'),
        'UUID preflight runs before the rest of the schema is changed');

    // A partially applied historical migration is completed one object at a time.
    $partialDb = dimDatabase($pdo); $databases[] = $partialDb;
    $pdo->exec("ALTER TABLE wali_santri ADD data_induk_guardian_id VARCHAR(36) NULL, ADD UNIQUE KEY uq_wali_data_induk_guardian (data_induk_guardian_id);
        ALTER TABLE santri_detail ADD data_induk_student_id VARCHAR(36) NULL");
    $partialBaseline = dimLegacyDigest($pdo);
    $firstApply = dataIndukMigrationApply($pdo, static function (string $label): void {});
    dimCheck(count($firstApply) > 0, 'A partial migration produces only its missing operations');
    dimCheck(dimLegacyDigest($pdo) === $partialBaseline, 'Completing the partial migration preserves every legacy value');
    foreach ([
        ['wali_santri', 'data_induk_guardian_id'],
        ['santri_detail', 'data_induk_student_id'], ['santri_detail', 'data_induk_enrollment_id'], ['santri_detail', 'data_induk_class_id'], ['santri_detail', 'status_roster'],
        ['users', 'data_induk_teacher_id'], ['users', 'data_induk_active'],
    ] as [$table, $column]) dimCheck(dimColumnExists($pdo, $table, $column), "Partial migration completes {$table}.{$column}");
    foreach (['data_induk_references','data_induk_sync_state','data_induk_sync_runs','data_induk_sync_conflicts'] as $table) {
        dimCheck(dimTableExists($pdo, $table), "Partial migration creates {$table}");
        $engine = $pdo->query("SELECT engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='{$table}'")->fetchColumn();
        dimCheck(strtolower((string) $engine) === 'innodb', "{$table} uses InnoDB to support safe transactions");
    }
    dimCheck((int) $pdo->query('SELECT COUNT(*) FROM data_induk_sync_state WHERE id=1')->fetchColumn() === 1, 'Sync state seed is present exactly once');
    $afterFirstApply = dimLegacyDigest($pdo);
    $secondApply = dataIndukMigrationApply($pdo, static function (string $label): void {});
    dimCheck(count($secondApply) === 0, 'Rerun is idempotent and makes no database writes');
    dimCheck(dimLegacyDigest($pdo) === $afterFirstApply, 'Idempotent rerun preserves all legacy values');

    // A pre-existing index with a migration name but wrong columns is blocked up front.
    $collisionDb = dimDatabase($pdo); $databases[] = $collisionDb;
    $pdo->exec('CREATE INDEX uq_wali_data_induk_guardian ON wali_santri (id)');
    $collisionBaseline = dimLegacyDigest($pdo);
    $collisionError = dimReject(fn() => dataIndukMigrationPlan($pdo), 'A conflicting index name blocks migration planning');
    dimCheck(str_contains($collisionError->getMessage(), 'different definition'), 'Index collision error identifies the incompatible schema');
    dimCheck(dimLegacyDigest($pdo) === $collisionBaseline, 'Index collision leaves all existing rows untouched');
    dimCheck(!dimColumnExists($pdo, 'wali_santri', 'data_induk_guardian_id') && !dimTableExists($pdo, 'data_induk_sync_state'), 'Index collision is detected before any migration DDL');

    echo "OK Data Induk migration integration: {$checks} checks\n";
} finally {
    foreach ($databases as $database) $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
}
