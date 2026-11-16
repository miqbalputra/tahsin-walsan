<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/participant_helper.php';
require_once __DIR__ . '/../includes/membership_helper.php';
require_once __DIR__ . '/../includes/tahsin_migration_helper.php';
require_once __DIR__ . '/../includes/family_migration_helper.php';
$database = 'tahsin_test_data_induk_archive_' . getmypid() . '_' . bin2hex(random_bytes(4));
$pdo = new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4', 'root', 'tahsin_local_test', [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false,
]);
$checks = 0;
function diCheck(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `$database`; SET time_zone='+07:00'");
$pdo->exec(file_get_contents(__DIR__ . '/fixtures/tahsin-schema.sql'));
$pdo->exec("INSERT INTO users(id,username,password,nama_lengkap,role) VALUES(1,'archive_admin','synthetic','Admin Uji','admin');
INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(1,'Halaqoh Uji',1);
ALTER TABLE wali_santri ADD COLUMN data_induk_guardian_id VARCHAR(36) NULL;
ALTER TABLE santri_detail ADD COLUMN data_induk_student_id VARCHAR(36) NULL, ADD COLUMN status_roster ENUM('active','archived') NOT NULL DEFAULT 'active';
INSERT INTO wali_santri(id,nama_bapak,kategori,status_aktif,data_induk_guardian_id) VALUES
    (1,'Ayah Dua Anak','reguler',1,'guardian-1'),(2,'Ayah Tunggal','reguler',1,'guardian-2'),(3,'Ayah Kelas Kosong','reguler',1,'guardian-3');
INSERT INTO santri_detail(id,wali_santri_id,nama_anak,kelas,data_induk_student_id,status_roster) VALUES
    (11,1,'Anak Aktif Satu','1A','student-11','active'),(12,1,'Anak Aktif Dua','2A','student-12','active'),
    (21,2,'Anak Ayah Tunggal','3A','student-21','active'),(31,3,'Anak Data Induk','4A','student-31','active'),
    (32,3,'Anak Lokal Kelas Kosong',NULL,NULL,'active');
INSERT INTO halaqoh_members(id,halaqoh_id,wali_santri_id) VALUES(101,1,1),(102,1,2),(103,1,3);
INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status,alasan) VALUES
    (201,1,1,'2026-10-11','H',NULL),(202,1,2,'2026-10-11','I','Riwayat ayah tunggal'),(203,1,3,'2026-10-11','S','Riwayat kelas kosong');
CREATE TABLE data_induk_sync_conflicts(id BIGINT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB;");
try {
    dataIndukArchiveGuardian($pdo, 'guardian-2');
    throw new RuntimeException('Archive unexpectedly proceeded before roster archive columns were installed.');
} catch (DataIndukException $error) {
    diCheck((int)$pdo->query('SELECT status_aktif FROM wali_santri WHERE id=2')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM halaqoh_members WHERE id=102')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM presensi')->fetchColumn() === 3,
        'Archive fails closed before migration without changing guardian, membership, or attendance data');
}
tahsinApplyMigration($pdo);
familyApplyMigration($pdo);
$attendanceBefore = $pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$membershipBefore = $pdo->query('SELECT id,wali_santri_id FROM halaqoh_members ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$first = dataIndukArchiveStudent($pdo, 'student-11');
diCheck($first['archived_memberships'] === 0 && $pdo->query('SELECT archived_at FROM halaqoh_members WHERE id=101')->fetchColumn() === null, 'Archiving one child keeps the guardian in the roster while an active sibling remains');
diCheck((int)$pdo->query('SELECT status_aktif FROM wali_santri WHERE id=1')->fetchColumn() === 1, 'Archiving a child does not deactivate the guardian account');
$second = dataIndukArchiveStudent($pdo, 'student-12');
$membership = $pdo->query('SELECT * FROM halaqoh_members WHERE id=101')->fetch(PDO::FETCH_ASSOC);
diCheck($second['archived_memberships'] === 1 && $membership['archived_at'] !== null && $membership['archive_reason'] === 'DATA_INDUK_STUDENT_ARCHIVE', 'The last archived Data Induk child closes the active membership with a reason');
diCheck((int)$pdo->query('SELECT COUNT(*) FROM halaqoh_members WHERE id=101')->fetchColumn() === 1 && $pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $attendanceBefore, 'Archiving keeps the original membership and every attendance row unchanged');
diCheck($pdo->query('SELECT valid_until FROM tahsin_roster_history WHERE membership_id=101')->fetchColumn() !== null, 'The roster history interval closes instead of being deleted');
$snapshot = participantSchoolSnapshot($pdo, 1);
diCheck(!$snapshot['eligible'] && count(array_filter($snapshot['children'], static fn($child)=>$child['school_status']==='archived')) === 2, 'Archived Data Induk children no longer qualify the guardian for new roster placement');
$familyChildren = $pdo->query("SELECT school_status,status_roster FROM family_children WHERE wali_santri_id=1 ORDER BY nama_anak")->fetchAll(PDO::FETCH_ASSOC);
diCheck(count($familyChildren) === 2 && array_unique(array_column($familyChildren, 'school_status')) === ['archived'] && array_unique(array_column($familyChildren, 'status_roster')) === ['archived'], 'The optional family view also exposes archived Data Induk students as archived');
$membershipChildren = $pdo->query('SELECT school_status FROM (' . membershipChildSource($pdo) . ') c WHERE c.wali_santri_id=1')->fetchAll(PDO::FETCH_COLUMN);
diCheck($membershipChildren === ['archived','archived'], 'The membership candidate source excludes archived Data Induk students');
$third = dataIndukArchiveStudent($pdo, 'student-31');
diCheck($third['archived_memberships'] === 0 && $pdo->query('SELECT archived_at FROM halaqoh_members WHERE id=103')->fetchColumn() === null, 'An unclassified local sibling conservatively keeps the existing membership');
$guardian = dataIndukArchiveGuardian($pdo, 'guardian-2');
$guardianMembership = $pdo->query('SELECT * FROM halaqoh_members WHERE id=102')->fetch(PDO::FETCH_ASSOC);
$archivedAt = $guardianMembership['archived_at'];
dataIndukArchiveGuardian($pdo, 'guardian-2');
$replayed = $pdo->query('SELECT archived_at FROM halaqoh_members WHERE id=102')->fetchColumn();
diCheck($guardian['archived_memberships'] === 1 && (int)$pdo->query('SELECT status_aktif FROM wali_santri WHERE id=2')->fetchColumn() === 0, 'Guardian archive deactivates the local guardian and archives active memberships');
diCheck($replayed === $archivedAt && (int)$pdo->query('SELECT COUNT(*) FROM halaqoh_members WHERE wali_santri_id=2')->fetchColumn() === 1, 'Replayed archive events are idempotent and retain the membership row');
diCheck($pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $attendanceBefore && $pdo->query('SELECT id,wali_santri_id FROM halaqoh_members ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $membershipBefore, 'All historical attendance and membership identities survive both archive paths');
echo json_encode(['ok'=>true,'checks'=>$checks,'database'=>$database], JSON_UNESCAPED_UNICODE) . PHP_EOL;
