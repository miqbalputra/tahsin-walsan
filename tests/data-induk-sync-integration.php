<?php
/** Isolated localhost integration for Data Induk roster sync; never uses app DB credentials. */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/data_induk_helper.php';

$baseUrl = rtrim((string) getenv('TEST_DATA_INDUK_BASE_URL'), '/');
if (!preg_match('~^http://127\.0\.0\.1:\d+$~D', $baseUrl)) throw new RuntimeException('A loopback mock Data Induk API is required.');
$database = 'tahsin_test_data_induk_sync_' . getmypid() . '_' . bin2hex(random_bytes(4));
$pdo = new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4', 'root', 'tahsin_local_test', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `$database`; SET time_zone='+07:00'");
$pdo->exec(file_get_contents(__DIR__ . '/fixtures/tahsin-schema.sql'));
$pdo->exec("ALTER TABLE halaqoh_members ADD archived_at DATETIME NULL, ADD archive_reason VARCHAR(50) NULL;
ALTER TABLE wali_santri ADD data_induk_guardian_id VARCHAR(36) NULL;
ALTER TABLE santri_detail ADD data_induk_student_id VARCHAR(36) NULL, ADD data_induk_enrollment_id VARCHAR(36) NULL, ADD data_induk_class_id VARCHAR(36) NULL, ADD status_roster ENUM('active','archived') NOT NULL DEFAULT 'active';
ALTER TABLE users ADD data_induk_teacher_id VARCHAR(36) NULL, ADD data_induk_active TINYINT(1) NOT NULL DEFAULT 1;
CREATE TABLE data_induk_references (master_id VARCHAR(36) PRIMARY KEY, reference_type VARCHAR(30) NOT NULL, reference_name VARCHAR(255) NULL) ENGINE=InnoDB;
CREATE TABLE data_induk_sync_state (id TINYINT PRIMARY KEY, change_cursor VARCHAR(64) NOT NULL DEFAULT '0', last_success_at DATETIME NULL, last_error_at DATETIME NULL, last_error TEXT NULL) ENGINE=InnoDB;
INSERT INTO data_induk_sync_state (id) VALUES (1);
CREATE TABLE data_induk_sync_runs (id BIGINT AUTO_INCREMENT PRIMARY KEY, scope VARCHAR(30) NOT NULL, status VARCHAR(20) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME NULL, stats_json LONGTEXT NULL, error_message TEXT NULL) ENGINE=InnoDB;
CREATE TABLE data_induk_sync_conflicts (id BIGINT AUTO_INCREMENT PRIMARY KEY, entity_type VARCHAR(30) NOT NULL, master_id VARCHAR(36) NOT NULL, local_id INT NULL, reason VARCHAR(255) NOT NULL, payload_json LONGTEXT NULL, status ENUM('pending','approved','ignored') NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL, resolved_at DATETIME NULL, resolved_by INT NULL) ENGINE=InnoDB;
INSERT INTO users(id,username,password,nama_lengkap,role) VALUES(1,'sync_teacher','synthetic','Guru Uji','ustadz');
INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(1,'Halaqoh Uji',1);
INSERT INTO wali_santri(id,nama_bapak,no_hp,alamat,kategori,status_aktif,data_induk_guardian_id) VALUES
 (1,'Bapak Satu','+62 (812) 3456-789','Alamat Satu','askar',1,NULL),
 (2,'Bapak Kembar','08000000000','Alamat Kembar','reguler',1,NULL),
 (3,'Bapak Kembar','08000000000','Alamat Kembar','reguler',1,NULL),
 (4,'Bapak Terpetakan','081200000000','Alamat Terpetakan','reguler',1,'central-mapped');
INSERT INTO santri_detail(id,wali_santri_id,nama_anak,kelas,data_induk_student_id,data_induk_enrollment_id,data_induk_class_id,status_roster) VALUES
 (11,4,'Anak Satu','1A',NULL,NULL,NULL,'active'),
 (12,4,'Anak Kembar','2A',NULL,NULL,NULL,'active'),
 (13,4,'Anak Kembar','3A',NULL,NULL,NULL,'active'),
 (14,4,'Anak Terpetakan','1B','student-mapped','enrollment-old','class-old','active');
INSERT INTO halaqoh_members(id,halaqoh_id,wali_santri_id) VALUES(101,1,4);
INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status,alasan) VALUES(201,1,4,'2026-10-18','H','Catatan lama');
INSERT INTO data_induk_references(master_id,reference_type,reference_name) VALUES('class-new','class','Mustawa 2');
CREATE TABLE participant_merge_aliases(source_id INT PRIMARY KEY,target_id INT NOT NULL) ENGINE=InnoDB;
INSERT INTO participant_merge_aliases VALUES(5,4);
ALTER TABLE wali_santri AUTO_INCREMENT=5;");

putenv('DATA_INDUK_ENABLED=1');
putenv('DATA_INDUK_API_KEY=fixture-api-key');
putenv('DATA_INDUK_UNIT_ID=fixture-unit');
$checks = 0;
function diSyncCheck(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function diSyncReject(callable $fn, string $message): Throwable
{
    try { $fn(); } catch (Throwable $error) { diSyncCheck(true, $message); return $error; }
    throw new RuntimeException($message . ' (operation unexpectedly succeeded)');
}
function diSyncBaseDigest(PDO $pdo): string
{
    $data = [];
    foreach (['wali_santri','santri_detail','halaqoh_members','presensi','data_induk_sync_state','data_induk_sync_runs','data_induk_sync_conflicts'] as $table) {
        $data[$table] = $pdo->query("SELECT * FROM `$table` ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256', serialize($data));
}
function diSyncRun(PDO $pdo, string $scenario): array
{
    putenv('DATA_INDUK_BASE_URL=' . rtrim((string) getenv('TEST_DATA_INDUK_BASE_URL'), '/') . '/' . $scenario);
    return dataIndukSyncRoster($pdo);
}

$baseline = diSyncBaseDigest($pdo);
$invalidScope = diSyncReject(fn() => dataIndukSync($pdo, 'typo'), 'An unknown sync scope is rejected instead of expanding to a full sync');
diSyncCheck($invalidScope instanceof InvalidArgumentException, 'An unknown scope is rejected as invalid input before configuration or sync work');
diSyncCheck(hash_equals($baseline, diSyncBaseDigest($pdo)), 'An unknown sync scope leaves participant data, sync state, run history, and conflicts unchanged');
diSyncReject(fn() => diSyncRun($pdo, 'invalid-status'), 'Unknown central status cannot archive existing children');
diSyncCheck(hash_equals($baseline, diSyncBaseDigest($pdo)), 'Invalid central status leaves every local participant and history row unchanged');
diSyncReject(fn() => diSyncRun($pdo, 'unknown-class'), 'Unknown class reference cannot clear the saved class');
diSyncCheck(hash_equals($baseline, diSyncBaseDigest($pdo)), 'Unknown class leaves every local participant and history row unchanged');
diSyncReject(fn() => diSyncRun($pdo, 'multiple-fathers'), 'Multiple active father links cannot move a child to an arbitrary guardian');
diSyncCheck(hash_equals($baseline, diSyncBaseDigest($pdo)), 'Conflicting father links leave all local data unchanged');
diSyncReject(fn() => diSyncRun($pdo, 'missing-status'), 'Missing central student status cannot be interpreted as archived');
diSyncCheck(hash_equals($baseline, diSyncBaseDigest($pdo)), 'Missing status leaves every local participant and history row unchanged');

// Reusing a merged source ID must roll back the full remote sync and keep the old alias.
diSyncReject(fn() => diSyncRun($pdo, 'valid'), 'A reused merge alias ID aborts Data Induk sync');
diSyncCheck(hash_equals($baseline, diSyncBaseDigest($pdo)) && (int)$pdo->query('SELECT target_id FROM participant_merge_aliases WHERE source_id=5')->fetchColumn() === 4,
    'Alias collision rolls back all roster writes and preserves the historical ID mapping');
$pdo->exec('ALTER TABLE wali_santri AUTO_INCREMENT=6');

// A failure after the guardian insert also rolls back candidates, mappings, and conflicts.
$pdo->exec("CREATE TRIGGER reject_data_induk_child BEFORE INSERT ON santri_detail FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='FIXTURE_CHILD_INSERT_REJECTED'");
diSyncReject(fn() => diSyncRun($pdo, 'valid'), 'A child write failure aborts the entire roster snapshot');
$pdo->exec('DROP TRIGGER reject_data_induk_child');
diSyncCheck(hash_equals($baseline, diSyncBaseDigest($pdo)), 'Database failure rolls back guardian, child, class, and conflict changes together');

$stats = diSyncRun($pdo, 'valid');
diSyncCheck($stats['conflicts'] === 4 && $stats['guardians'] === 2 && $stats['children'] === 2,
    'Sync creates only new identities and records four candidates for review');
diSyncCheck((int)$pdo->query('SELECT COUNT(*) FROM wali_santri')->fetchColumn() === 5
    && (int)$pdo->query('SELECT COUNT(*) FROM santri_detail')->fetchColumn() === 5,
    'Phone punctuation and ambiguous names do not create duplicate local guardians or children');
$conflicts = $pdo->query("SELECT entity_type,master_id,local_id,payload_json FROM data_induk_sync_conflicts WHERE status='pending' ORDER BY entity_type,master_id")->fetchAll(PDO::FETCH_ASSOC);
$conflictMap = [];
foreach ($conflicts as $conflict) $conflictMap[$conflict['entity_type'] . ':' . $conflict['master_id']] = $conflict;
diSyncCheck((int)$conflictMap['guardian:central-unique']['local_id'] === 1
    && $conflictMap['guardian:central-ambiguous']['local_id'] === null
    && json_decode($conflictMap['guardian:central-ambiguous']['payload_json'], true)['local_candidate_ids'] === [2,3],
    'Guardian identity matching normalizes phone punctuation and retains ambiguous candidate IDs without choosing one');
diSyncCheck((int)$conflictMap['student:student-unique']['local_id'] === 11
    && $conflictMap['student:student-ambiguous']['local_id'] === null
    && json_decode($conflictMap['student:student-ambiguous']['payload_json'], true)['local_candidate_ids'] === [12,13],
    'Student identity matching normalizes names and records every ambiguous candidate');
$ambiguousConflictId = (int)$pdo->query("SELECT id FROM data_induk_sync_conflicts WHERE entity_type='student' AND master_id='student-ambiguous' AND status='pending'")->fetchColumn();
diSyncReject(fn() => dataIndukResolveConflict($pdo, $ambiguousConflictId, 'approve'), 'An ambiguous identity cannot be approved without a unique local candidate');
diSyncCheck($pdo->query("SELECT status FROM data_induk_sync_conflicts WHERE id=$ambiguousConflictId")->fetchColumn() === 'pending',
    'Rejecting an unsafe approval leaves the conflict pending for review');
diSyncCheck($pdo->query("SELECT kelas FROM santri_detail WHERE id=14")->fetchColumn() === 'Mustawa 2'
    && $pdo->query("SELECT data_induk_class_id FROM santri_detail WHERE id=14")->fetchColumn() === 'class-new'
    && $pdo->query("SELECT data_induk_enrollment_id FROM santri_detail WHERE id=14")->fetchColumn() === 'enrollment-new',
    'A mapped child updates class and enrollment in place without changing the local row ID');
 $newWaliId = (int)$pdo->query("SELECT id FROM wali_santri WHERE data_induk_guardian_id='central-new'")->fetchColumn();
diSyncCheck($newWaliId > 5 && !((int)$pdo->query('SELECT COUNT(*) FROM participant_merge_aliases WHERE source_id=' . $newWaliId)->fetchColumn())
    && (int)$pdo->query("SELECT wali_santri_id FROM santri_detail WHERE data_induk_student_id='student-new'")->fetchColumn() === $newWaliId,
    'New guardian and child mappings are inserted with the alias-safe local IDs');
$presensiBefore = $pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$membershipBefore = $pdo->query('SELECT * FROM halaqoh_members ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
diSyncRun($pdo, 'missing-class-field');
diSyncCheck($pdo->query('SELECT kelas FROM santri_detail WHERE id=14')->fetchColumn() === 'Mustawa 2'
    && $pdo->query('SELECT data_induk_class_id FROM santri_detail WHERE id=14')->fetchColumn() === 'class-new',
    'An omitted class field preserves the mapped child’s saved class and source mapping');
diSyncCheck($presensiBefore === $pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
    && $membershipBefore === $pdo->query('SELECT * FROM halaqoh_members ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    'Every sync scenario preserves existing attendance and membership row identities/content');
diSyncCheck((int)$pdo->query("SELECT status_aktif FROM wali_santri WHERE id=1")->fetchColumn() === 1
    && $pdo->query("SELECT status_roster FROM santri_detail WHERE id=14")->fetchColumn() === 'active',
    'A successful sync preserves explicit active statuses');

echo json_encode(['ok'=>true,'checks'=>$checks,'database'=>$database], JSON_UNESCAPED_UNICODE) . PHP_EOL;
