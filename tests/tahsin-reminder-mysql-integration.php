<?php
/** Isolated localhost-only fixtures. No application credentials or production writes. */
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/tahsin-reminder-tests.php';
require_once __DIR__ . '/../includes/tahsin_migration_helper.php';
$database = $argv[1] ?? 'tahsin_test_weekly_reminder_' . getmypid();
if (!preg_match('/^tahsin_test_[a-z0-9_]+$/', $database)) throw new RuntimeException('Only a new tahsin_test_* database is allowed.');
$pdo = new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4', 'root', 'tahsin_local_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$database}`");
$pdo->exec("SET time_zone = '+07:00'");
$pdo->exec(file_get_contents(__DIR__ . '/fixtures/tahsin-schema.sql'));
$pdo->exec("CREATE TABLE holidays (id INT AUTO_INCREMENT PRIMARY KEY, tanggal DATE UNIQUE, keterangan VARCHAR(255)) ENGINE=InnoDB;
CREATE TABLE activity_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, username VARCHAR(50), action VARCHAR(100), description TEXT, ip_address VARCHAR(45), created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE login_attempts (id INT AUTO_INCREMENT PRIMARY KEY, ip_address VARCHAR(45), username VARCHAR(50), attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE pengumuman (id INT AUTO_INCREMENT PRIMARY KEY, judul VARCHAR(255), isi TEXT, is_aktif TINYINT DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100), setting_value TEXT) ENGINE=InnoDB");
$insert = $pdo->prepare('INSERT INTO users (id,username,password,nama_lengkap,role,no_hp) VALUES (?,?,?,?,?,?)');
foreach ([[1,'admin_weekly','Admin','admin'],[2,'guru_weekly','Guru Pekanan','ustadz'],[3,'guru_other','Guru Lain','ustadz']] as [$id,$username,$name,$role]) $insert->execute([$id,$username,password_hash('TahsinTest-2026', PASSWORD_DEFAULT),$name,$role,'081234567890']);
$pdo->exec("INSERT INTO halaqoh (id,nama_halaqoh,ustadz_id) VALUES (1,'Kelompok Uji',2)");
for ($id = 1; $id <= 4; $id++) {
    $pdo->prepare("INSERT INTO wali_santri (id,nama_bapak,kategori,status_aktif) VALUES (?,?,'reguler',1)")->execute([$id,'Wali Uji '.$id]);
    $pdo->prepare('INSERT INTO halaqoh_members (halaqoh_id,wali_santri_id) VALUES (1,?)')->execute([$id]);
}
$before = $checks;
$fixedNow = new DateTimeImmutable('2026-10-12 10:00:00', new DateTimeZone('Asia/Jakarta'));
$dataset = tahsinLoadDataset($pdo, '2026-10-12', $fixedNow);
expectTahsin(false, $dataset['schema_ready'], 'Legacy database reproduces absent reporting tables');
expectTahsin(4, tahsinReminderOverview($dataset, $fixedNow)['data'][0]['missing_presensi'], 'Legacy DB with no saved attendance still reminds all four participants');
$legacyMonitor = tahsinFetchPendingOverview($pdo, 'admin', 0, [], $fixedNow);
expectTahsin(1, $legacyMonitor['summary']['pending_teachers'], 'Legacy monitoring matches weekly reminder, not empty history');
expectTahsin(true, $legacyMonitor['sessions'][0]['current_roster_available'], 'Legacy monitoring labels the active roster explicitly');

// Fixture history enables deterministic persistence checks for the fixed session.
tahsinApplyMigration($pdo);
$pdo->exec("UPDATE tahsin_tracking SET tracked_from = '2026-10-01 00:00:00'; UPDATE tahsin_roster_history SET valid_from = '2026-10-01 00:00:00'");
$pdo->exec("INSERT INTO presensi (halaqoh_id,wali_santri_id,tanggal,status,jenis_materi,jilid,halaman,hasil_talaqqi) VALUES (1,1,'2026-10-11','H','Iqro',2,'1-2','Lulus'),(1,2,'2026-10-11','A',NULL,NULL,NULL,NULL)");
$valid = ['status'=>'H','jenis_materi'=>'Iqro','jilid'=>'2','halaman_dari'=>'1','halaman_sampai'=>'2','hasil_talaqqi'=>'Lulus'];
$initialCount = (int) $pdo->query('SELECT COUNT(*) FROM presensi')->fetchColumn();
try { attendanceSaveEntries($pdo,1,2,'2026-10-11',[3=>[]]); throw new RuntimeException('Blank draft accepted'); }
catch (AttendanceValidationException $e) { $checks++; }
expectTahsin($initialCount, (int) $pdo->query('SELECT COUNT(*) FROM presensi')->fetchColumn(), 'Blank draft does not create Alfa');
try { attendanceSaveEntries($pdo,1,2,'2026-10-11',[1=>$valid,2=>['status'=>'A'],3=>[]],true); throw new RuntimeException('Changed roster accepted'); }
catch (AttendanceValidationException $e) { $checks++; }
expectTahsin($initialCount, (int) $pdo->query('SELECT COUNT(*) FROM presensi')->fetchColumn(), 'Incomplete roster submission creates no Alfa');
try { attendanceSaveEntries($pdo,1,2,'2026-10-11',[1=>['status'=>'H'],2=>['status'=>'A'],3=>[],4=>[]],true); throw new RuntimeException('Incomplete achievement accepted'); }
catch (AttendanceValidationException $e) { $checks++; }
expectTahsin($initialCount, (int) $pdo->query('SELECT COUNT(*) FROM presensi')->fetchColumn(), 'Invalid Hadir blocks entire finalization');
try { attendanceSaveEntries($pdo,1,3,'2026-10-11',[1=>$valid,2=>['status'=>'A'],3=>[],4=>[]],true); throw new RuntimeException('Other teacher accepted'); }
catch (AttendanceValidationException $e) { $checks++; }
expectTahsin($initialCount, (int) $pdo->query('SELECT COUNT(*) FROM presensi')->fetchColumn(), 'Another teacher cannot finalize or generate Alfa');
$result = attendanceSaveEntries($pdo,1,2,'2026-10-11',[1=>[],2=>['status'=>'A'],3=>[],4=>[]],true);
expectTahsin(2, $result['auto_alpha'], 'Finalization adds Alfa for exactly two genuinely unselected participants');
expectTahsin('H', $pdo->query('SELECT status FROM presensi WHERE wali_santri_id=1')->fetchColumn(), 'Stale blank preserves previously autosaved Hadir');
expectTahsin(3, (int) $pdo->query("SELECT COUNT(*) FROM presensi WHERE status='A'")->fetchColumn(), 'Saved Alfa includes explicit and finalized absences');
$overview = tahsinReminderOverview(tahsinLoadDataset($pdo, '2026-10-12', $fixedNow), $fixedNow);
expectTahsin(0, $overview['count'], 'Guru leaves reminder only after every participant is complete');
expectTahsin(4, $overview['summary']['complete_participant_sessions'], 'Complete response proves every roster participant was inspected');
expectTahsin(3, $overview['summary']['attendance_counts']['A'], 'Complete response still reports all three Alfa');
expectTahsin(0, attendanceSaveEntries($pdo,1,2,'2026-10-11',[1=>[],2=>[],3=>[],4=>[]],true)['auto_alpha'], 'Repeated finalization changes no saved statuses');
expectTahsin(4, (int) $pdo->query('SELECT COUNT(*) FROM presensi')->fetchColumn(), 'Repeated finalization introduces no duplicate rows');
echo 'PASS ' . ($checks - $before) . " weekly reminder MySQL integration assertions\n";
echo "STAGING_DATABASE={$database}\n";
