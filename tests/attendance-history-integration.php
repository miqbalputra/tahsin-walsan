<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/attendance_recovery_helper.php';
require_once __DIR__ . '/../includes/autosave_migration_helper.php';
require_once __DIR__ . '/../includes/tahsin_migration_helper.php';
require_once __DIR__ . '/../includes/backup_helper.php';
require_once __DIR__ . '/../includes/halaqoh_helper.php';
$database = 'tahsin_test_history_' . getmypid() . '_' . bin2hex(random_bytes(4));
$pdo = new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4', 'root', 'tahsin_local_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `$database`; SET time_zone='+07:00'");
$pdo->exec(file_get_contents(__DIR__ . '/fixtures/tahsin-schema.sql'));
$pdo->exec("INSERT INTO users(id,username,password,nama_lengkap,role) VALUES(1,'admin','synthetic','Admin Uji','admin'),(2,'pj','synthetic','PJ Uji','pj_tahfidz'),(3,'teacher','synthetic','Guru Uji','ustadz'),(4,'other','synthetic','Guru Lain','ustadz');
INSERT INTO wali_santri(id,nama_bapak,no_hp) VALUES(1,'Wali Lama','08111111111'),(2,'Wali Lain','08222222222');
INSERT INTO santri_detail(id,wali_santri_id,nama_anak,kelas) VALUES(1,1,'Anak Lama','Mustawa 3');
INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(1,'Kelompok Lama',3),(2,'Kelompok Lain',4);
INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(1,1),(2,2);
ALTER TABLE presensi ADD extra_bytes VARBINARY(50), ADD extra_number DECIMAL(20,4), ADD extra_generated INT GENERATED ALWAYS AS (OCTET_LENGTH(alasan)) STORED;
INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status,alasan,extra_bytes,extra_number) VALUES(1,1,1,'2026-10-18','I','Lama  ',UNHEX('00FF5C0041'),12345678901234.5678),(2,2,2,'2026-10-18','A',NULL,NULL,NULL);");
tahsinApplyMigration($pdo); autosaveApplyMigration($pdo);
$pdo->exec("INSERT INTO tahsin_autosave_drafts(user_id,halaqoh_id,session_date,wali_id,payload_json,official_revision,context_revision) VALUES(3,1,'2026-10-18',1,'{\"status\":\"I\",\"alasan\":\"Draft lama\"}','official','context')");
$checks = 0;
function ahCheck(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function ahRows(PDO $pdo): array { return $pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll(); }
function ahDigest(PDO $pdo): string {
    $tables=[]; foreach (discoverBackupTables($pdo) as $table) $tables[$table]=$pdo->query("SELECT * FROM `$table`" . tableOrderClause($pdo,$table,tableColumns($pdo,$table)))->fetchAll();
    return hash('sha256',serialize($tables));
}
function ahReject(PDO $pdo, callable $operation, string $message): void {
    $before=ahDigest($pdo);
    try { $operation(); } catch (AttendanceHistoryException|InvalidArgumentException $error) { ahCheck(ahDigest($pdo)===$before,$message); return; }
    throw new RuntimeException($message);
}
$original=ahRows($pdo);
$drafts=$pdo->query('SELECT * FROM tahsin_autosave_drafts')->fetchAll();
$children=$pdo->query('SELECT * FROM santri_detail')->fetchAll();
ahCheck(!attendanceHistoryReady($pdo),'Missing history schema is detected');
attendanceHistoryApplyMigration($pdo); attendanceHistoryApplyMigration($pdo);
ahCheck(attendanceHistoryReady($pdo) && ahRows($pdo)===$original,'Additive migration and retry preserve every existing attendance column');
ahCheck((int)$pdo->query('SELECT COUNT(*) FROM presensi_history')->fetchColumn()===0,'Migration does not invent changes for old rows');
$pdo->beginTransaction();
try { attendanceHistoryApplyMigration($pdo); throw new LogicException('Migration implicitly committed active transaction'); }
catch (RuntimeException $error) { ahCheck($pdo->inTransaction(),'Migration refuses active transactions without committing them'); }
$pdo->rollBack();
$stale=attendanceHistoryHash(attendanceHistorySelect($pdo,'1')['row']);
$pdo->exec("UPDATE presensi SET alasan='lama  ' WHERE id=1");
$edit=$pdo->query('SELECT * FROM presensi_history ORDER BY id DESC LIMIT 1')->fetch();
ahCheck($edit['event']==='update' && attendanceHistoryDecode($edit['before_json'])['alasan']==='Lama  ' && attendanceHistoryDecode($edit['after_json'])['alasan']==='lama  ','Case-only and trailing-space edits retain exact former text');
ahReject($pdo,fn()=>attendanceHistoryDeleteOne($pdo,1,'1',$stale),'Stale delete form cannot remove a changed attendance row');
$preview=attendanceRecoveryPreview($pdo,(int)$edit['id']);
ahCheck($preview['can_restore'],'An overwritten row can be previewed for recovery');
$recovered=attendanceRecoveryApply($pdo,1,(int)$edit['id'],$preview['fingerprint']);
ahCheck(ahRows($pdo)===$original,'Update recovery preserves IDs, decimal precision, binary bytes, NULL and generated values');
$digest=ahDigest($pdo);
ahCheck(attendanceRecoveryApply($pdo,1,(int)$edit['id'],$preview['fingerprint'])['replayed'] && ahDigest($pdo)===$digest,'Repeated recovery returns its receipt without writes');
$fingerprint=attendanceHistoryHash(attendanceHistorySelect($pdo,'1')['row']);
ahReject($pdo,fn()=>attendanceHistoryDeleteOne($pdo,4,'1',$fingerprint),'Another teacher cannot delete attendance outside the assigned group');
attendanceHistoryDeleteOne($pdo,3,'1',$fingerprint);
$deleted=$pdo->query("SELECT * FROM presensi_history WHERE event='delete' ORDER BY id DESC LIMIT 1")->fetch();
ahCheck((int)$deleted['actor_id']===3 && $deleted['actor_name']==='Guru Uji' && attendanceHistoryDecode($deleted['before_json'])['extra_bytes']===hex2bin('00FF5C0041'),'Teacher deletion retains the complete row and account identity');
$preview=attendanceRecoveryPreview($pdo,(int)$deleted['id']);
ahReject($pdo,fn()=>attendanceRecoveryApply($pdo,3,(int)$deleted['id'],$preview['fingerprint']),'Teachers cannot run administrative recovery');
$pdo->prepare("INSERT INTO presensi_history(instance_key,event,presensi_id,participant_ref,before_json,after_json,context_json,offset_minutes) VALUES(?,'delete',?,?,?,?,?,?)")->execute([str_repeat('f',32),$deleted['presensi_id'],$deleted['participant_ref'],$deleted['before_json'],null,$deleted['context_json'],$deleted['offset_minutes']]);
$foreignId=(int)$pdo->lastInsertId();
ahCheck(!attendanceRecoveryPreview($pdo,$foreignId)['can_restore'],'Versions imported from another database instance cannot attach old rows to local identities');
$pdo->exec("CREATE TRIGGER reject_restore_audit BEFORE INSERT ON presensi_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic restore audit failure'");
$beforeRestoreFailure=ahDigest($pdo);
try { attendanceRecoveryApply($pdo,1,(int)$deleted['id'],$preview['fingerprint']); throw new LogicException('Restore audit failure was not raised'); }
catch (PDOException $error) { ahCheck(ahDigest($pdo)===$beforeRestoreFailure,'A recovery audit failure rolls back the inserted row and all revision changes'); }
$pdo->exec('DROP TRIGGER reject_restore_audit');
$pdo->exec("INSERT INTO presensi(halaqoh_id,wali_santri_id,tanggal,status) VALUES(1,1,'2026-10-20','A')");
ahReject($pdo,fn()=>attendanceRecoveryApply($pdo,1,(int)$deleted['id'],$preview['fingerprint']),'A new replacement entry blocks recovery without being overwritten');
$pdo->exec("DELETE FROM presensi WHERE wali_santri_id=1 AND tanggal='2026-10-20'");
$pdo->exec("UPDATE wali_santri SET nama_bapak='Identitas berubah' WHERE id=1");
ahCheck(!attendanceRecoveryPreview($pdo,(int)$deleted['id'])['can_restore'],'Changed parent identity blocks automatic attachment to another identity');
$pdo->exec("UPDATE wali_santri SET nama_bapak='Wali Lama' WHERE id=1");
$preview=attendanceRecoveryPreview($pdo,(int)$deleted['id']);
attendanceRecoveryApply($pdo,2,(int)$deleted['id'],$preview['fingerprint']);
ahCheck(ahRows($pdo)===$original && $pdo->query('SELECT * FROM tahsin_autosave_drafts')->fetchAll()===$drafts && $pdo->query('SELECT * FROM santri_detail')->fetchAll()===$children,'Delete recovery restores the original row while retaining drafts and child identities');
$pdo->exec("SET time_zone='-04:00'");
$fingerprint=attendanceHistoryHash(attendanceHistorySelect($pdo,'1')['row']);
attendanceHistoryDeleteOne($pdo,1,'1',$fingerprint);
$tzDelete=$pdo->query("SELECT * FROM presensi_history WHERE event='delete' ORDER BY id DESC LIMIT 1")->fetch();
$pdo->exec("SET time_zone='+07:00'");
$preview=attendanceRecoveryPreview($pdo,(int)$tzDelete['id']);
attendanceRecoveryApply($pdo,1,(int)$tzDelete['id'],$preview['fingerprint']);
ahCheck(ahRows($pdo)===$original && $pdo->query('SELECT @@SESSION.time_zone')->fetchColumn()==='+07:00','Recovery preserves TIMESTAMP instants across UTC offsets and restores the connection timezone');
$pdo->exec("CREATE TRIGGER reject_history_insert BEFORE INSERT ON presensi_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
$beforeFailure=ahDigest($pdo);
try { attendanceHistoryDeleteOne($pdo,1,'1',attendanceHistoryHash(attendanceHistorySelect($pdo,'1')['row'])); throw new LogicException('Audit failure was not raised'); }
catch (PDOException $error) { ahCheck(ahDigest($pdo)===$beforeFailure,'Audit failure rolls back deletion and autosave revision changes'); }
$pdo->exec('DROP TRIGGER reject_history_insert');
$pdo->exec('ALTER TABLE presensi ADD future_field VARCHAR(30) NULL');
ahCheck(!attendanceHistoryReady($pdo),'New attendance columns invalidate old trigger readiness');
ahReject($pdo,fn()=>attendanceHistoryDeleteOne($pdo,1,'1',attendanceHistoryHash(attendanceHistorySelect($pdo,'1')['row'])),'Destructive actions stop until new columns are covered by snapshots');
attendanceHistoryApplyMigration($pdo);
$historyCount=(int)$pdo->query('SELECT COUNT(*) FROM presensi_history')->fetchColumn();
$pdo->exec("UPDATE presensi SET future_field='Nilai baru' WHERE id=1");
ahCheck((int)$pdo->query('SELECT COUNT(*) FROM presensi_history')->fetchColumn()===$historyCount+1 && attendanceHistoryDecode($pdo->query('SELECT after_json FROM presensi_history ORDER BY id DESC LIMIT 1')->fetchColumn())['future_field']==='Nilai baru','Trigger version switch captures new fields once without duplicate audit entries');
$originalMode=$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
$pdo->prepare('SET SESSION sql_mode=?')->execute([$originalMode . ',NO_AUTO_VALUE_ON_ZERO']);
$pdo->exec("INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status) VALUES(0,1,1,'2026-09-13','A'); DELETE FROM presensi WHERE id=0");
$zeroDelete=(int)$pdo->query("SELECT id FROM presensi_history WHERE event='delete' AND presensi_id='0' ORDER BY id DESC LIMIT 1")->fetchColumn();
$pdo->prepare('SET SESSION sql_mode=?')->execute([$originalMode]);
$zeroPreview=attendanceRecoveryPreview($pdo,$zeroDelete);
attendanceRecoveryApply($pdo,1,$zeroDelete,$zeroPreview['fingerprint']);
ahCheck((int)$pdo->query('SELECT COUNT(*) FROM presensi WHERE id=0')->fetchColumn()===1,'Legacy zero primary keys are restored without generating a replacement identity');
ahCheck((int)$pdo->query('SELECT @tahsin_restore_origin IS NULL')->fetchColumn()===1 && $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn()===$originalMode,'Recovery clears its origin marker and restores strict-mode configuration');
$pdo->exec("INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(9,'Riwayat Saja',3); INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status) VALUES(9,9,1,'2026-09-20','A'); DELETE FROM presensi WHERE id=9");
ahCheck(halaqohHasReferences($pdo,9),'A group remains referenced when only its deleted-attendance snapshot is left');
$beforeGroupDelete=ahDigest($pdo);
try { deleteEmptyHalaqoh($pdo,9); throw new LogicException('Historical group was deleted'); }
catch (HalaqohManagementException $error) { ahCheck(ahDigest($pdo)===$beforeGroupDelete,'Deleted-attendance history prevents group ID reuse and program reassignment'); }
ahCheck(!$pdo->inTransaction() && (int)$pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn()===1,'Transactions close and foreign key protection stays enabled');
echo "PASS $checks attendance history/recovery assertions\nSTAGING_DATABASE=$database\n";
