<?php
require_once __DIR__ . '/config/database.php';
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/includes/auth_helper.php';
    checkRole(['admin']);
}
$apply = in_array('--apply', $_SERVER['argv'] ?? [], true) || (($_GET['apply'] ?? '') === '1') || getenv('APPLY_MIGRATIONS') === '1';
$sql = [
    "ALTER TABLE wali_santri ADD COLUMN data_induk_guardian_id VARCHAR(36) NULL, ADD UNIQUE KEY uq_wali_data_induk_guardian (data_induk_guardian_id), ADD KEY idx_wali_data_induk_guardian (data_induk_guardian_id)",
    "ALTER TABLE santri_detail ADD COLUMN data_induk_student_id VARCHAR(36) NULL, ADD COLUMN data_induk_enrollment_id VARCHAR(36) NULL, ADD COLUMN data_induk_class_id VARCHAR(36) NULL, ADD COLUMN status_roster ENUM('active','archived') NOT NULL DEFAULT 'active', ADD UNIQUE KEY uq_santri_data_induk_student (data_induk_student_id), ADD KEY idx_santri_data_induk_student (data_induk_student_id)",
    "ALTER TABLE users ADD COLUMN data_induk_teacher_id VARCHAR(36) NULL, ADD COLUMN data_induk_active TINYINT(1) NOT NULL DEFAULT 1, ADD UNIQUE KEY uq_users_data_induk_teacher (data_induk_teacher_id), ADD KEY idx_users_data_induk_teacher (data_induk_teacher_id)",
    "CREATE TABLE IF NOT EXISTS data_induk_references (master_id VARCHAR(36) PRIMARY KEY, reference_type VARCHAR(30) NOT NULL, reference_code VARCHAR(100) NULL, reference_name VARCHAR(255) NULL, payload_json LONGTEXT NULL, archived_at DATETIME NULL, updated_at DATETIME NOT NULL, KEY idx_reference_type_name (reference_type, reference_name)) ENGINE=InnoDB",
    "CREATE TABLE IF NOT EXISTS data_induk_sync_state (id TINYINT PRIMARY KEY, change_cursor VARCHAR(64) NOT NULL DEFAULT '0', last_success_at DATETIME NULL, last_error_at DATETIME NULL, last_error TEXT NULL)",
    "INSERT IGNORE INTO data_induk_sync_state (id) VALUES (1)",
    "CREATE TABLE IF NOT EXISTS data_induk_sync_runs (id BIGINT AUTO_INCREMENT PRIMARY KEY, scope VARCHAR(30) NOT NULL, status VARCHAR(20) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME NULL, stats_json LONGTEXT NULL, error_message TEXT NULL, KEY idx_sync_runs_status_started (status, started_at)) ENGINE=InnoDB",
    "CREATE TABLE IF NOT EXISTS data_induk_sync_conflicts (id BIGINT AUTO_INCREMENT PRIMARY KEY, entity_type VARCHAR(30) NOT NULL, master_id VARCHAR(36) NOT NULL, local_id INT NULL, reason VARCHAR(255) NOT NULL, payload_json LONGTEXT NULL, status ENUM('pending','approved','ignored') NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL, resolved_at DATETIME NULL, resolved_by INT NULL, KEY idx_conflict_status_created (status, created_at), KEY idx_conflict_master (entity_type, master_id)) ENGINE=InnoDB",
    "CREATE INDEX idx_halaqoh_members_active ON halaqoh_members (halaqoh_id, archived_at, wali_santri_id)",
];
header('Content-Type: text/plain; charset=utf-8');
echo $apply ? "Mode APPLY Data Induk migration\n" : "Mode DRY-RUN. Jalankan php migrate_data_induk.php --apply setelah backup.\n";
foreach ($sql as $statement) {
    $label = preg_replace('/\s+/', ' ', substr($statement, 0, 80));
    if (!$apply) { echo "PLAN {$label}\n"; continue; }
    try { $pdo->exec($statement); echo "OK {$label}\n"; }
    catch (PDOException $error) {
        if (stripos($error->getMessage(), 'Duplicate column') !== false || stripos($error->getMessage(), 'Duplicate key name') !== false || stripos($error->getMessage(), 'already exists') !== false) { echo "SKIP {$label}\n"; continue; }
        throw $error;
    }
}

$duplicates = $pdo->query('SELECT COUNT(*) FROM (SELECT 1 FROM presensi GROUP BY halaqoh_id,wali_santri_id,tanggal HAVING COUNT(*) > 1) duplicate_keys')->fetchColumn();
$uniqueIndex = $pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='presensi' AND index_name='uq_presensi_halaqoh_wali_tanggal'")->fetchColumn();
if ((int) $uniqueIndex > 0) {
    echo "SKIP attendance unique key already exists\n";
} elseif ((int) $duplicates > 0) {
    echo "BLOCK attendance unique key: {$duplicates} duplicate key(s). Run php scripts/audit-presensi-duplicates.php and resolve data first.\n";
    if ($apply) {
        exit(2);
    }
} elseif (!$apply) {
    echo "PLAN uq_presensi_halaqoh_wali_tanggal after duplicate audit\n";
} else {
    $pdo->exec('ALTER TABLE presensi ADD UNIQUE KEY uq_presensi_halaqoh_wali_tanggal (halaqoh_id,wali_santri_id,tanggal)');
    echo "OK attendance unique key created\n";
}
