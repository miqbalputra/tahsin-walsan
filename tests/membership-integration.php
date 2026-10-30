<?php
if (PHP_SAPI!=='cli') exit;
putenv('DATA_INDUK_ENABLED=0');
require_once __DIR__.'/../includes/membership_helper.php';
require_once __DIR__.'/../includes/halaqoh_helper.php';
require_once __DIR__.'/../includes/membership_history_view.php';
foreach (['tahsin','autosave','family'] as $name) require_once __DIR__.'/../includes/'.$name.'_migration_helper.php';
$database='tahsin_test_membership_'.getmypid().'_'.bin2hex(random_bytes(4));
$pdo=new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4','root','tahsin_local_test',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `$database`; SET time_zone='+07:00'");
$pdo->exec(file_get_contents(__DIR__.'/fixtures/tahsin-schema.sql'));
$pdo->exec("INSERT INTO users(id,username,password,nama_lengkap,role) VALUES(1,'admin','synthetic','Admin Uji','admin'),(2,'pj','synthetic','PJ Uji','pj_tahfidz'),(3,'teacher','synthetic','Guru Uji','ustadz');
INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(1,'Bapak Lama',3),(2,'Bapak Baru',3),(3,'Ibu Tetap',3),(4,'Program Unknown',3);
INSERT INTO wali_santri(id,nama_bapak,status_aktif,kategori) VALUES(1,'Wali Dua Program',1,'reguler'),(2,'Wali Baru',1,'askar'),(3,'Relasi Nol',1,'reguler'),(4,'Alumni',1,'reguler'),(5,'Tahsin Luar',1,'tahsin_luar'),(6,'Arsip',0,'reguler'),(7,'Tanpa Anak',1,'reguler'),(8,'Sumber Keluar',1,'reguler'),(9,'Ibu Sumber',1,'reguler'),(10,'Duplikat Lama',1,'reguler'),(11,'Kelas NULL',1,'reguler');
INSERT INTO santri_detail(id,wali_santri_id,nama_anak,kelas) VALUES(1,1,'Anak Lama','2A'),(2,2,'Anak Baru','3A'),(3,3,'Anak Nol','1A'),(4,4,'Lulusan','Lulus'),(5,5,'Anak Luar','2A'),(6,6,'Anak Arsip','2A'),(8,8,'Legacy stale active','2A'),(10,10,'Anak Duplikat','4A'),(11,11,'Tanpa Kelas',NULL);
INSERT INTO halaqoh_members(id,halaqoh_id,wali_santri_id) VALUES(1,1,1),(2,3,1),(50,1,10),(51,1,10),(52,3,10);
INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status,alasan) VALUES(1,1,1,'2026-10-18','I','Riwayat  ');
ALTER TABLE wali_santri ADD extra_bytes VARBINARY(30); ALTER TABLE santri_detail ADD extra_bytes VARBINARY(30); ALTER TABLE halaqoh_members ADD extra_bytes VARBINARY(30);
UPDATE wali_santri SET extra_bytes=UNHEX('FF0041') WHERE id=1; UPDATE santri_detail SET extra_bytes=UNHEX('00FF0042') WHERE id=1; UPDATE halaqoh_members SET extra_bytes=UNHEX('FF0043') WHERE id=1;");
tahsinApplyMigration($pdo); autosaveApplyMigration($pdo); familyApplyMigration($pdo);
$pdo->exec("UPDATE halaqoh SET program_code='ibu' WHERE id=3; UPDATE halaqoh SET program_code='unknown' WHERE id=4;
INSERT INTO tahsin_capaian_awal(wali_santri_id,jenis_materi,jilid,halaman,updated_by) VALUES(1,'Iqro',2,'1-2',1);
INSERT INTO tahsin_autosave_drafts(user_id,halaqoh_id,session_date,wali_id,payload_json,official_revision,context_revision) VALUES(3,1,'2026-10-18',1,'{\"status\":\"H\"}','official','context');
INSERT INTO family_guardians(id,source_namespace,source_parent_id,wali_santri_id,guardian_type,source_name,source_phone,source_address,source_active,account_active,snapshot_date) VALUES
(8,'fixture','source-8',8,'mother','Sumber Keluar','','',1,1,'2026-10-18'),(9,'fixture','source-9',9,'mother','Ibu Sumber','','',1,1,'2026-10-18');
INSERT INTO family_students(id,source_namespace,source_student_id,nis,nama_anak,kelas,school_status,snapshot_date) VALUES(8,'fixture','student-8','008','Keluar sekolah','2A','withdrawn','2026-10-18'),(9,'fixture','student-9','009','Anak Ibu Sumber','3A','active','2026-10-18');
INSERT INTO family_relations(guardian_id,student_id,relationship,is_primary,is_current) VALUES(8,8,'mother',1,1),(9,9,'mother',1,1);
SET @old_mode=@@SESSION.sql_mode; SET SESSION sql_mode=CONCAT(@@SESSION.sql_mode,',NO_AUTO_VALUE_ON_ZERO');
INSERT INTO halaqoh_members(id,halaqoh_id,wali_santri_id,archived_at,archive_reason,extra_bytes) VALUES(0,2,3,'2026-10-01 10:00:00','Arsip lama  ',UNHEX('00FF0044')); SET SESSION sql_mode=@old_mode;");
$checks=0;
function msCheck(bool $value,string $message): void { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
function msDigest(PDO $pdo): string {
    $tables=$GLOBALS['ms_tables']??= $pdo->query("SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $rows=[]; foreach ($tables as $table) $rows[$table]=$pdo->query("SELECT * FROM `$table` ORDER BY 1")->fetchAll();
    return hash('sha256',serialize($rows));
}
function msContext(PDO $pdo,int $group,int $wali): array { return membershipContext($pdo,$group,$wali); }
function msApply(PDO $pdo,int $group,int $wali,string $action,?array $context=null,?string $operation=null,int $actor=1): array {
    return membershipApply($pdo,$actor,$group,$wali,$action,($context??msContext($pdo,$group,$wali))['fingerprint'],$operation??bin2hex(random_bytes(16)));
}
function msReject(PDO $pdo,callable $action,string $message,?int $status=null): void {
    $before=msDigest($pdo);
    try { $action(); } catch (MembershipException|HalaqohManagementException|ParticipantManagementException|AttendanceHistoryException|InvalidArgumentException $error) {
        msCheck(msDigest($pdo)===$before && ($status===null || ($error instanceof MembershipException && $error->httpStatus===$status)),$message); return;
    }
    throw new LogicException('Expected rejection: '.$message);
}
msReject($pdo,fn()=>msApply($pdo,3,2,'add'),'Missing participant journal refuses membership changes');
participantApplyMigration($pdo); unset($GLOBALS['ms_tables']);
msCheck(membershipReady($pdo),'Existing reporting and participant journals prepare the membership service without a new schema');
$untouched=[]; foreach (['wali_santri','santri_detail','presensi','tahsin_capaian_awal','tahsin_autosave_drafts'] as $table) $untouched[$table]=$pdo->query("SELECT * FROM `$table` ORDER BY 1")->fetchAll();
msReject($pdo,fn()=>msApply($pdo,2,1,'add'),'Adding another group in the same program cannot silently reassign old membership');
foreach ([4,5,6,7,8,11] as $id) msReject($pdo,fn()=>msApply($pdo,3,$id,'add'),'Alumni, external, archived, childless, withdrawn-source and empty-class candidates are not newly assigned');
msReject($pdo,fn()=>msApply($pdo,3,2,'add',null,null,3),'Teacher role cannot call the management mutation service');
msReject($pdo,fn()=>msApply($pdo,4,2,'add'),'Unknown program codes are preserved instead of guessed');
$context=msContext($pdo,3,2); $op=bin2hex(random_bytes(16)); $result=msApply($pdo,3,2,'add',$context,$op,2);
msCheck($result['changed'] && (int)$pdo->query('SELECT COUNT(*) FROM halaqoh_members WHERE wali_santri_id=2 AND archived_at IS NULL')->fetchColumn()===1,'PJ can add an eligible participant to the exact requested program');
$digest=msDigest($pdo); msCheck(msApply($pdo,3,2,'add',$context,$op,2)===$result && msDigest($pdo)===$digest,'A repeated add returns its durable receipt with no data or interval churn');
msReject($pdo,fn()=>msApply($pdo,3,2,'remove',$context,$op,2),'An operation cannot be reused for another action',409);
$beforeNoop=$pdo->query('SELECT * FROM tahsin_roster_history ORDER BY id')->fetchAll();
msCheck(!msApply($pdo,3,2,'add')['changed'] && $pdo->query('SELECT * FROM tahsin_roster_history ORDER BY id')->fetchAll()===$beforeNoop,'A separate add of an already active relation creates no duplicate or new interval');
$oldMembership=$pdo->query('SELECT * FROM halaqoh_members WHERE id=1')->fetch();
$removed=msApply($pdo,1,1,'remove');
msCheck($pdo->query('SELECT halaqoh_id FROM halaqoh_members WHERE wali_santri_id=1 AND archived_at IS NULL')->fetchAll(PDO::FETCH_COLUMN)===[3],'Removing Bapak preserves active Ibu placement');
$archived=$pdo->query('SELECT * FROM halaqoh_members WHERE id=1')->fetch();
msCheck($archived['archived_at']!==null && $archived['archive_reason']==='MANUAL_REMOVE' && $archived['extra_bytes']===$oldMembership['extra_bytes'],'Archiving keeps the original membership ID and unknown binary columns');
$journal=$pdo->query("SELECT * FROM participant_changes WHERE operation_id='".$removed['operation_id']."'")->fetch();
$before=json_decode($journal['before_json'],true); $after=json_decode($journal['after_json'],true);
msCheck(attendanceHistoryDecode($before['membership_source']['parent'])['extra_bytes']===hex2bin('FF0041') && attendanceHistoryDecode($before['membership_source']['memberships'][0])['extra_bytes']===hex2bin('FF0043'),'Full old parent/membership bytes survive in journal sidecars');
msCheck($before['memberships'][0]['archived_at']===null && $after['memberships'][0]['archived_at']!==null,'Human-readable before/after membership state matches the actual operation');
msApply($pdo,1,1,'add'); msCheck((int)$pdo->query('SELECT COUNT(*) FROM halaqoh_members WHERE wali_santri_id=1')->fetchColumn()===2 && $pdo->query('SELECT archived_at FROM halaqoh_members WHERE id=1')->fetchColumn()===null,'Reactivation uses the same original row instead of replacing its ID');
$zero=msApply($pdo,2,3,'add');
msCheck($zero['membership_id']===0 && (int)$pdo->query('SELECT COUNT(*) FROM halaqoh_members WHERE wali_santri_id=3')->fetchColumn()===1 && $pdo->query('SELECT extra_bytes FROM halaqoh_members WHERE id=0')->fetchColumn()===hex2bin('00FF0044'),'An archived zero-ID relationship is reactivated without duplicating or losing its original bytes');
$stale=msContext($pdo,1,1); $pdo->exec('UPDATE halaqoh_members SET archived_at=NOW() WHERE id=1; UPDATE halaqoh_members SET archived_at=NULL WHERE id=1');
msReject($pdo,fn()=>msApply($pdo,1,1,'remove',$stale),'An intervening archive/reactivation invalidates a form even when current membership values match again',409);
$familyState=msContext($pdo,3,9); $pdo->exec("UPDATE family_students SET school_status='graduated' WHERE id=9");
msReject($pdo,fn()=>msApply($pdo,3,9,'add',$familyState),'A source-school change invalidates an old confirmation',409);
$pdo->exec("UPDATE family_students SET school_status='active' WHERE id=9; DROP VIEW family_children");
msReject($pdo,fn()=>msApply($pdo,3,8,'add'),'A missing family view cannot revive stale active legacy child data');
msApply($pdo,3,9,'add');
msCheck((int)$pdo->query('SELECT COUNT(*) FROM halaqoh_members WHERE wali_santri_id=9 AND archived_at IS NULL')->fetchColumn()===1,'A parent with only authoritative family children remains eligible when the optional view is unavailable');
$familyJournal=$pdo->query("SELECT after_json FROM participant_changes WHERE wali_id=9 AND action='membership_add' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
$schoolChildren=membershipHistorySchool(json_decode($familyJournal,true));
msCheck(count($schoolChildren)===1&&$schoolChildren[0]['name']==='Anak Ibu Sumber'&&$schoolChildren[0]['source']==='fixture / student-9','Membership history displays the actual family child from its immutable source snapshot');
msCheck(!participantCanJoinHalaqoh($pdo,8) && participantCanJoinHalaqoh($pdo,9),'The participant editor and membership page use the same authoritative eligibility');
familyApplyMigration($pdo);
$duplicated=$pdo->query('SELECT id FROM halaqoh_members WHERE wali_santri_id=10 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$catalog=membershipCatalog($pdo,1,membershipFilters([]));
msCheck(count(array_filter($catalog['members']['rows'],static fn($row)=>(int)$row['id']===10))===1,'Legacy duplicate relationships are listed as one participant');
msApply($pdo,1,10,'remove');
msCheck($pdo->query('SELECT id FROM halaqoh_members WHERE wali_santri_id=10 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)===$duplicated && $pdo->query('SELECT halaqoh_id FROM halaqoh_members WHERE wali_santri_id=10 AND archived_at IS NULL')->fetchAll(PDO::FETCH_COLUMN)===[3],'Removal archives all target duplicates while retaining their IDs and the other program');
foreach ($untouched as $table=>$rows) msCheck($pdo->query("SELECT * FROM `$table` ORDER BY 1")->fetchAll()===$rows,'Membership operations leave '.$table.' byte-for-byte unchanged');
$failure=msContext($pdo,1,1); $failOp=bin2hex(random_bytes(16)); $digest=msDigest($pdo); $mode=$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
$pdo->exec("CREATE TRIGGER reject_membership_journal BEFORE INSERT ON participant_changes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic membership audit failure'");
try { msApply($pdo,1,1,'remove',$failure,$failOp); throw new LogicException('Mandatory audit failure ignored'); }
catch (PDOException $error) { msCheck(msDigest($pdo)===$digest,'A late journal failure rolls back the relation, roster intervals and all journal changes'); }
$pdo->exec('DROP TRIGGER reject_membership_journal'); msApply($pdo,1,1,'remove',$failure,$failOp);
msCheck((int)$pdo->query("SELECT COUNT(*) FROM participant_changes WHERE operation_id='$failOp'")->fetchColumn()===1,'The original token can safely retry after a fully rolled-back failure');
$schoolChild=$pdo->prepare('INSERT INTO santri_detail(wali_santri_id,nama_anak,kelas) VALUES(2,?,NULL)');
for ($i=0;$i<20;$i++) $schoolChild->execute([str_repeat('Nama anak ',8).sprintf('%02d',$i)]);
$long=membershipCatalog($pdo,3,membershipFilters(['search'=>'Wali Baru']));
msCheck(strlen($long['members']['rows'][0]['children'])>1024 && str_contains($long['members']['rows'][0]['children'],'19 [kelas belum tersedia]'),'Long child lists and NULL classes are displayed without GROUP_CONCAT truncation');
for ($id=100;$id<155;$id++) $pdo->exec("INSERT INTO wali_santri(id,nama_bapak) VALUES($id,'Pagination $id'); INSERT INTO santri_detail(wali_santri_id,nama_anak,kelas) VALUES($id,'Anak Pagination $id','2A')");
$page=membershipCatalog($pdo,1,membershipFilters(['search'=>'Pagination','available_page'=>'2']));
msCheck($page['available']['count']===55 && count($page['available']['rows'])===5 && $page['available']['pages']===2,'Candidate search is paginated without silently dropping later participants');
$parallel=msContext($pdo,2,100); $parallelOp=bin2hex(random_bytes(16));
$workerCode= <<<'PHP'
require getcwd().'/includes/membership_helper.php';
putenv('DATA_INDUK_ENABLED=0');
$db=$argv[1]; if (!preg_match('/^tahsin_test_membership_[0-9]+_[a-f0-9]{8}$/D',$db)) exit(2);
$p=new PDO('mysql:host=127.0.0.1;port=13317;dbname='.$db.';charset=utf8mb4','root','tahsin_local_test',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$p->exec("SET time_zone='+07:00'"); echo 'READY:'.$p->query('SELECT CONNECTION_ID()')->fetchColumn()."\n"; fflush(STDOUT);
try { membershipApply($p,1,2,100,'add',$argv[2],$argv[3]); echo json_encode(['status'=>200]); }
catch (MembershipException $error) { echo json_encode(['status'=>$error->httpStatus,'message'=>$error->getMessage()]); }
PHP;
$statement=$pdo->prepare('SELECT GET_LOCK(?,5)'); $statement->execute([participantWriteLockName($pdo)]); if ((int)$statement->fetchColumn()!==1) throw new RuntimeException('Cannot hold the fixture write mutex');
$worker=null;
try {
    $cmd=[PHP_BINARY];
    $moduleProbe=proc_open([PHP_BINARY,'-m'],[1=>['pipe','w'],2=>['pipe','w']],$modulePipes,__DIR__.'/..',null,['bypass_shell'=>true]);
    if (!is_resource($moduleProbe)) throw new RuntimeException('Cannot inspect the fixture PHP modules');
    $modules=stream_get_contents($modulePipes[1]); $moduleErrors=stream_get_contents($modulePipes[2]); fclose($modulePipes[1]); fclose($modulePipes[2]);
    if (proc_close($moduleProbe)!==0 || $moduleErrors!=='') throw new RuntimeException('Fixture PHP module probe failed');
    if (stripos($modules,'pdo_mysql')===false) $cmd=[...$cmd,'-d','extension=pdo_mysql'];
    $worker=proc_open([...$cmd,'-r',$workerCode,$database,$parallel['fingerprint'],$parallelOp],[1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__.'/..',null,['bypass_shell'=>true]);
    if (!is_resource($worker)) throw new RuntimeException('Cannot start the isolated membership worker');
    $readyLine=trim((string)fgets($pipes[1])); if (!preg_match('/^READY:([0-9]+)$/D',$readyLine,$match)) throw new RuntimeException('Worker readiness was not confirmed');
    $probe=$pdo->prepare('SELECT STATE FROM information_schema.PROCESSLIST WHERE ID=?'); $waiting=false;
    for ($attempt=0;$attempt<100;$attempt++) { $probe->execute([(int)$match[1]]); if (stripos((string)$probe->fetchColumn(),'User lock')!==false) { $waiting=true; break; } usleep(20000); }
    msCheck($waiting,'A separate membership writer is observed waiting on the same participant mutex');
    $snapshot=participantSnapshot($pdo,100); $input=array_intersect_key($snapshot['parent'],array_flip(['nama_bapak','no_hp','alamat','kategori','tempat_tahsin','ustadz_luar']));
    foreach ($input as &$value) $value=(string)$value; unset($value);
    $input['anak']=array_map(static fn($row)=>['id'=>(string)$row['id'],'nama'=>$row['nama_anak'],'kelas'=>(string)$row['kelas']],$snapshot['children']); $input['memberships']=['bapak'=>'1','ibu'=>'keep'];
    participantApply($pdo,1,'save',100,$input,participantFingerprint($snapshot),bin2hex(random_bytes(16)));
} finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([participantWriteLockName($pdo)]); }
$parallelOutput=stream_get_contents($pipes[1]); $parallelErrors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($worker);
msCheck($exit===0&&$parallelErrors===''&&json_decode($parallelOutput,true)['status']===409,'A parallel group-page add cannot create a competing assignment');
msCheck($pdo->query('SELECT halaqoh_id FROM halaqoh_members WHERE wali_santri_id=100 AND archived_at IS NULL')->fetchAll(PDO::FETCH_COLUMN)===[1] && (int)$pdo->query("SELECT COUNT(*) FROM participant_changes WHERE operation_id='$parallelOp'")->fetchColumn()===0,'Concurrent paths leave one active placement and no spurious second receipt');
$readyContext=msContext($pdo,1,100); $pdo->exec('DROP TRIGGER tahsin_hm_au');
msReject($pdo,fn()=>msApply($pdo,1,100,'add',$readyContext),'Missing roster trigger blocks membership mutations');
tahsinApplyMigration($pdo); msCheck(membershipReady($pdo),'Reporting migration repairs readiness without rewriting participant data');
$pdo->exec('DROP TRIGGER tahsin_autosave_au');
msCheck(halaqohProgramColumnReady($pdo) && !autosaveSchemaReady($pdo),'Program identity remains available when optional autosave setup is incomplete');
$newGroup=saveManagedHalaqoh($pdo,['nama_halaqoh'=>'Ibu tanpa autosave','ustadz_id'=>'3','program_code'=>'ibu']);
msCheck($pdo->query('SELECT program_code FROM halaqoh WHERE id='.$newGroup)->fetchColumn()==='ibu','An explicitly selected Ibu program is stored without depending on autosave triggers');
saveManagedHalaqoh($pdo,['id'=>(string)$newGroup,'nama_halaqoh'=>'Ibu diubah','ustadz_id'=>'3']);
msCheck($pdo->query('SELECT program_code FROM halaqoh WHERE id='.$newGroup)->fetchColumn()==='ibu','An omitted program preserves actual Ibu identity during a teacher/name edit');
msReject($pdo,fn()=>saveManagedHalaqoh($pdo,['id'=>'3','nama_halaqoh'=>'Should not switch','ustadz_id'=>'3','program_code'=>'bapak']),'An in-use Ibu group cannot be silently treated as Bapak when autosave is unavailable');
autosaveApplyMigration($pdo);
$pdo->exec("UPDATE users SET role='kepsek' WHERE id=2");
msReject($pdo,fn()=>msApply($pdo,1,100,'add',null,null,2),'A revoked PJ role is rechecked inside the write transaction');
$statement=$pdo->prepare('SELECT IS_FREE_LOCK(?)'); $statement->execute([participantWriteLockName($pdo)]);
msCheck(!$pdo->inTransaction() && (int)$statement->fetchColumn()===1 && $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn()===$mode && (int)$pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn()===1,'Success and failure paths release locks, restore SQL mode and keep FK checks enabled');
echo "PASS $checks membership/data preservation assertions\nSTAGING_DATABASE=$database\n";
