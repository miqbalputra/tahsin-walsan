<?php
/** Isolated migration drill; every database is a newly created loopback fixture. */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/halaqoh_fk_migration_helper.php';

$database = 'tahsin_test_halaqoh_fk_' . getmypid() . '_' . bin2hex(random_bytes(4));
$pdo = new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4', 'root', 'tahsin_local_test', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `$database`; SET time_zone='+07:00'");
$pdo->exec(file_get_contents(__DIR__ . '/fixtures/tahsin-schema.sql'));
$pdo->exec("INSERT INTO users(id,username,password,nama_lengkap,role) VALUES(1,'fixture_teacher','synthetic','Guru Uji','ustadz');
INSERT INTO wali_santri(id,nama_bapak) VALUES(1,'Wali Uji');
INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(1,'Kelompok Lama',1),(2,'Kelompok Dihapus',1);");
$legacyConstraint = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='presensi' AND COLUMN_NAME='halaqoh_id' AND REFERENCED_TABLE_NAME='halaqoh'")->fetchColumn();
if (!is_string($legacyConstraint) || $legacyConstraint === '') throw new RuntimeException('Fixture foreign key tidak ditemukan.');
$quotedLegacy = '`' . str_replace('`', '``', $legacyConstraint) . '`';
$pdo->exec("ALTER TABLE presensi DROP FOREIGN KEY $quotedLegacy,
    MODIFY COLUMN halaqoh_id INT NOT NULL,
    ADD CONSTRAINT fk_presensi_halaqoh_legacy FOREIGN KEY (halaqoh_id) REFERENCES halaqoh(id) ON DELETE CASCADE");
$pdo->exec("INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status,alasan) VALUES
    (1,1,1,'2026-10-18','I','Catatan lama'),(2,2,1,'2026-10-25','H','Presensi kelompok yang akan dihapus')");

$checks = 0;
function hfCheck(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function hfRows(PDO $pdo): array { return $pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll(PDO::FETCH_ASSOC); }
function hfConstraint(PDO $pdo): array
{
    return $pdo->query("SELECT k.CONSTRAINT_NAME,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
        WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME='presensi' AND k.COLUMN_NAME='halaqoh_id'")->fetch(PDO::FETCH_ASSOC) ?: [];
}

$before = hfRows($pdo);
$plan = halaqohFkMigrationPlan($pdo);
hfCheck($plan['needs_change'] && !$plan['nullable'] && $plan['delete_rule'] === 'CASCADE', 'Legacy schema is identified without mutating it');
hfCheck(hfRows($pdo) === $before && hfConstraint($pdo)['DELETE_RULE'] === 'CASCADE', 'Planning preserves every old attendance row and existing foreign key');
$applied = halaqohFkMigrationApply($pdo);
hfCheck($applied['changed'] && $applied['constraint_name'] === 'fk_presensi_halaqoh_legacy', 'Migration updates the existing relation in one idempotent ALTER');
hfCheck(hfRows($pdo) === $before, 'Migration preserves all attendance identities and field values');
$ready = halaqohFkMigrationPlan($pdo);
hfCheck(!$ready['needs_change'] && $ready['nullable'] && $ready['delete_rule'] === 'SET NULL', 'Post-migration schema keeps deleted group references recoverable as NULL');
$repeated = halaqohFkMigrationApply($pdo);
hfCheck(!$repeated['changed'] && hfRows($pdo) === $before, 'Repeating the migration is a no-op and preserves old attendance');
$pdo->exec('DELETE FROM halaqoh WHERE id=2');
$afterGroupDelete = $pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
hfCheck(count($afterGroupDelete) === 2 && $afterGroupDelete[0] === $before[0]
    && $afterGroupDelete[1]['id'] === $before[1]['id'] && $afterGroupDelete[1]['halaqoh_id'] === null
    && $afterGroupDelete[1]['status'] === $before[1]['status'] && $afterGroupDelete[1]['alasan'] === $before[1]['alasan'],
    'Deleting a referenced group preserves the old attendance row and only clears its removed group reference');

$currentConstraint = hfConstraint($pdo);
$quotedCurrent = '`' . str_replace('`', '``', $currentConstraint['CONSTRAINT_NAME']) . '`';
$pdo->exec("ALTER TABLE presensi DROP FOREIGN KEY $quotedCurrent; SET FOREIGN_KEY_CHECKS=0;
    INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status,alasan) VALUES(10,999,1,'2026-11-01','A','Orphan sintetis');
    SET FOREIGN_KEY_CHECKS=1");
$orphanRows = hfRows($pdo);
$orphanPlan = halaqohFkMigrationPlan($pdo);
hfCheck($orphanPlan['orphan_count'] === 1, 'Migration detects legacy orphan references before DDL');
try {
    halaqohFkMigrationApply($pdo);
    throw new RuntimeException('Migration unexpectedly accepted an orphan reference.');
} catch (InvalidArgumentException $error) {
    hfCheck(hfRows($pdo) === $orphanRows && hfConstraint($pdo) === [], 'Orphan preflight blocks schema changes and preserves every attendance row');
}

echo "PASS $checks halaqoh FK migration assertions\nSTAGING_DATABASE=$database\n";
