<?php
if (PHP_SAPI !== 'cli') exit;
putenv('DATA_INDUK_ENABLED=0');
require_once __DIR__ . '/../includes/student_progress_helper.php';
require_once __DIR__ . '/../includes/backup_helper.php';
foreach (['tahsin', 'autosave', 'family'] as $migration) require_once __DIR__ . '/../includes/' . $migration . '_migration_helper.php';
$database = 'tahsin_test_progress_' . getmypid() . '_' . bin2hex(random_bytes(4));
$pdo = new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4', 'root', 'tahsin_local_test', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `$database`");
$pdo->exec("SET time_zone = '+07:00'");
$pdo->exec(file_get_contents(__DIR__ . '/fixtures/tahsin-schema.sql'));
$pdo->exec("INSERT INTO users(id,username,password,nama_lengkap,role) VALUES(1,'admin','synthetic','Admin Uji','admin'),(2,'pj','synthetic','PJ Uji','pj_tahfidz'),(3,'teacher','synthetic','Guru Uji','ustadz');
INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(1,'Bapak',3),(2,'Ibu',3);
INSERT INTO wali_santri(id,nama_bapak,status_aktif) VALUES(1,'Wali Dua Anak',1),(2,'Wali Lulusan',1),(3,'Wali Arsip',0),(4,'Kelas Kosong',1),(5,'Kelas Tak Dikenal',1),(6,'Wali Lain',1);
INSERT INTO santri_detail(id,wali_santri_id,nama_anak,kelas) VALUES(1,1,'Anak Besar','Mustawa 6 Ikhwan'),(2,1,'Anak Kecil','Mustawa 3 Akhwat'),(3,2,'Anak Lulus','Mustawa 6'),(4,3,'Alumni','Lulus'),(5,4,'Kelas NULL',NULL),(6,5,'Kelas Invalid','Mustawa 99'),(7,6,'Anak Lain','Mustawa 3');
INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(1,1),(2,1),(1,2),(1,6);
INSERT INTO presensi(halaqoh_id,wali_santri_id,tanggal,status,alasan) VALUES(1,1,'2026-10-18','I','Catatan lama'),(1,2,'2026-10-18','A',NULL);");
tahsinApplyMigration($pdo); autosaveApplyMigration($pdo); familyApplyMigration($pdo);
$pdo->exec("UPDATE halaqoh SET program_code='ibu' WHERE id=2;
INSERT INTO tahsin_autosave_drafts(user_id,halaqoh_id,session_date,wali_id,payload_json,official_revision,context_revision) VALUES(3,1,'2026-10-18',1,'{\"status\":\"H\"}','official','context');
INSERT INTO tahsin_capaian_awal(wali_santri_id,jenis_materi,jilid,halaman,updated_by) VALUES(1,'Iqro',2,'1-2',1);");
$checks = 0;
function spCheck(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function spDigest(PDO $pdo): string {
    $rows=[];
    foreach (discoverBackupTables($pdo) as $table) $rows[$table]=$pdo->query("SELECT * FROM `$table`" . tableOrderClause($pdo,$table,tableColumns($pdo,$table)))->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256',serialize($rows));
}
function spExpected(PDO $pdo, array $ids): array {
    $statement=$pdo->prepare('SELECT s.*,w.status_aktif AS parent_active FROM santri_detail s JOIN wali_santri w ON w.id=s.wali_santri_id WHERE s.id IN (' . implode(',',array_fill(0,count($ids),'?')) . ')');
    $statement->execute($ids); $expected=[];
    foreach ($statement->fetchAll() as $child) $expected[(int)$child['id']]=studentProgressFingerprint($child,(int)$child['parent_active']);
    return $expected;
}
function spApply(PDO $pdo, string $action, array $ids, string $class='', ?array $expected=null, ?string $operation=null, int $actor=1): array {
    return studentProgressApply($pdo,$actor,$action,$ids,$class,$expected??spExpected($pdo,$ids),$operation??bin2hex(random_bytes(16)));
}
function spReject(PDO $pdo, callable $action, string $message): void {
    $before=spDigest($pdo);
    try { $action(); } catch (StudentProgressException|InvalidArgumentException $error) { spCheck(spDigest($pdo)===$before,$message); return; }
    throw new RuntimeException($message);
}
spReject($pdo,fn()=>spApply($pdo,'bulk_naik',[1]),'Missing journal blocks class changes without writes');
participantApplyMigration($pdo);
spCheck((int)$pdo->query('SELECT COUNT(*) FROM santri_detail')->fetchColumn()===7,'Journal setup preserves every child');
$beforeIds=$pdo->query('SELECT id,wali_santri_id,nama_anak FROM santri_detail ORDER BY id')->fetchAll();
$attendance=$pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll();
$drafts=$pdo->query('SELECT * FROM tahsin_autosave_drafts')->fetchAll();
$baseline=$pdo->query('SELECT * FROM tahsin_capaian_awal')->fetchAll();
$expected=spExpected($pdo,[1,3]); $operation=bin2hex(random_bytes(16));
$result=spApply($pdo,'bulk_naik',[1,3],'',$expected,$operation);
spCheck($result['done']===2 && $result['archived_wali']===1,'Graduation archives the correct parent after reading its actual ID');
spCheck((int)$pdo->query('SELECT status_aktif FROM wali_santri WHERE id=1')->fetchColumn()===1,'Younger sibling keeps its parent active');
spCheck((int)$pdo->query('SELECT status_aktif FROM wali_santri WHERE id=2')->fetchColumn()===0,'All-graduated parent is archived');
spCheck((int)$pdo->query('SELECT COUNT(*) FROM halaqoh_members WHERE wali_santri_id=2 AND archived_at IS NOT NULL')->fetchColumn()===1,'Alumni membership is retained as archived');
spCheck($pdo->query('SELECT id,wali_santri_id,nama_anak FROM santri_detail ORDER BY id')->fetchAll()===$beforeIds,'Class changes retain all child IDs, names and parent links');
spCheck($pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll()===$attendance && $pdo->query('SELECT * FROM tahsin_autosave_drafts')->fetchAll()===$drafts && $pdo->query('SELECT * FROM tahsin_capaian_awal')->fetchAll()===$baseline,'Attendance, server autosave drafts and initial achievements remain byte-for-byte equal');
$journal=$pdo->query("SELECT * FROM participant_changes WHERE wali_id=2 AND action='class_promote'")->fetch();
$before=json_decode($journal['before_json'],true); $after=json_decode($journal['after_json'],true);
spCheck($before['children'][0]['kelas']==='Mustawa 6' && $after['children'][0]['kelas']==='Lulus' && $before['memberships'][0]['archived_at']===null && $after['memberships'][0]['archived_at']!==null,'Journal preserves former class and membership state before automatic archiving');
$digest=spDigest($pdo);
spCheck(spApply($pdo,'bulk_naik',[1,3],'',$expected,$operation)===$result && spDigest($pdo)===$digest,'Repeated submission returns its receipt without promoting a second time');
spReject($pdo,fn()=>spApply($pdo,'bulk_lulus',[1,3],'',$expected,$operation),'A consumed operation cannot be reused for another action');
spReject($pdo,fn()=>spApply($pdo,'bulk_naik',[1,3],'',$expected),'An old form cannot overwrite changed child classes or parent status');
spReject($pdo,fn()=>spApply($pdo,'set_kelas',[2],'Mustawa 2'),'Restoring a class is restricted to actual graduates');
spReject($pdo,fn()=>spApply($pdo,'set_kelas',[3],'<script>'),'Replacement class must come from the available choices');
spApply($pdo,'set_kelas',[3],'Mustawa 6 Akhwat');
spCheck($pdo->query('SELECT kelas FROM santri_detail WHERE id=3')->fetchColumn()==='Mustawa 6 Akhwat','Canonical replacement classes remain available after all children graduated');
spCheck((int)$pdo->query('SELECT status_aktif FROM wali_santri WHERE id=2')->fetchColumn()===0 && (int)$pdo->query('SELECT COUNT(*) FROM halaqoh_members WHERE wali_santri_id=2 AND archived_at IS NULL')->fetchColumn()===0,'Class correction preserves explicit archive and membership status');
spReject($pdo,fn()=>spApply($pdo,'bulk_naik',[5]),'NULL classes are not guessed or overwritten');
spReject($pdo,fn()=>spApply($pdo,'bulk_naik',[6]),'Out-of-range legacy Mustawa levels are rejected');
spReject($pdo,fn()=>spApply($pdo,'bulk_naik',[2],'',null,null,3),'Teacher accounts cannot mutate classes');
$pdo->exec("ALTER TABLE santri_detail ADD data_induk_student_id VARCHAR(64); UPDATE santri_detail SET data_induk_student_id='central-student' WHERE id=7");
spReject($pdo,fn()=>spApply($pdo,'bulk_naik',[2,7]),'Mapped children stay protected even with integration disabled');
$pdo->exec('UPDATE santri_detail SET data_induk_student_id=NULL WHERE id=7');
$beforeFailure=spDigest($pdo);
$pdo->exec("CREATE TRIGGER reject_progress_journal BEFORE INSERT ON participant_changes FOR EACH ROW BEGIN IF NEW.wali_id=6 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic second journal failure'; END IF; END");
try { spApply($pdo,'bulk_lulus',[2,7]); throw new LogicException('Synthetic journal failure was not raised'); }
catch (PDOException $error) { spCheck(spDigest($pdo)===$beforeFailure,'Failure after the first journal rolls back every class, archive, membership, history and journal change'); }
$pdo->exec('DROP TRIGGER reject_progress_journal');
$_SESSION=[];
$rows=$pdo->query('SELECT s.*,w.status_aktif AS parent_active FROM santri_detail s JOIN wali_santri w ON w.id=s.wali_santri_id WHERE s.id=2')->fetchAll();
$token=studentProgressFormToken($rows,1);
spReject($pdo,fn()=>studentProgressSubmit($pdo,1,['action'=>'bulk_naik','santri_ids'=>['7'],'progress_token'=>$token]),'A forged child ID outside the displayed form is rejected');
spReject($pdo,fn()=>studentProgressSubmit($pdo,2,['action'=>'bulk_naik','santri_ids'=>['2'],'progress_token'=>$token]),'Form tokens belong to the account that opened them');
spReject($pdo,fn()=>studentProgressSubmit($pdo,1,['action'=>'bulk_naik','santri_ids'=>[['2']],'progress_token'=>$token]),'Nested array IDs are rejected without PHP warnings');
$submitted=studentProgressSubmit($pdo,1,['action'=>'bulk_naik','santri_ids'=>['2'],'progress_token'=>$token]);
spCheck($submitted['done']===1 && $pdo->query('SELECT kelas FROM santri_detail WHERE id=2')->fetchColumn()==='Mustawa 4 Akhwat','A session-bound form promotes exactly the selected child');
$_SESSION['student_progress_forms'][$token]['expires']=time()-1;
spReject($pdo,fn()=>studentProgressSubmit($pdo,1,['action'=>'bulk_naik','santri_ids'=>['2'],'progress_token'=>$token]),'Expired form tokens cannot mutate data');
spCheck(!$pdo->inTransaction() && (int)$pdo->query("SELECT IS_FREE_LOCK('" . participantWriteLockName($pdo) . "')")->fetchColumn()===1,'Every operation releases its transaction and shared write lock');
spCheck((int)$pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn()===1,'Foreign key protection remains enabled');
echo "PASS $checks student progress/data preservation assertions\nSTAGING_DATABASE=$database\n";
