<?php
/** Synthetic localhost-only recovery drill; never loads application DB credentials. */
if (PHP_SAPI!=='cli') exit;
putenv('DATA_INDUK_ENABLED=0');
require_once __DIR__.'/../includes/session_decision_helper.php';
require_once __DIR__.'/../includes/autosave_helper.php';
require_once __DIR__.'/../includes/backup_helper.php';
foreach (['tahsin','autosave','family'] as $name) require_once __DIR__.'/../includes/'.$name.'_migration_helper.php';
$database='tahsin_test_quality_'.getmypid().'_'.bin2hex(random_bytes(4));
$pdo=new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4','root','tahsin_local_test',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `$database`; SET time_zone='+07:00'");
$pdo->exec(file_get_contents(__DIR__.'/fixtures/tahsin-schema.sql'));
$pdo->exec("CREATE TABLE holidays(id INT PRIMARY KEY,tanggal DATE,keterangan VARCHAR(255)) ENGINE=InnoDB;
CREATE TABLE activity_logs(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,username VARCHAR(50),action VARCHAR(100),description TEXT,created_at DATETIME) ENGINE=InnoDB;
INSERT INTO users(id,username,password,nama_lengkap,role) VALUES(1,'quality_admin','synthetic','Admin','admin'),(2,'quality_pj','synthetic','PJ','pj_tahfidz'),(3,'quality_teacher','synthetic','Guru H9','ustadz'),(4,'quality_other','synthetic','Guru H15','ustadz');
INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(1,'H-9',3),(2,'H-15',4),(3,'Ibu',3);
INSERT INTO wali_santri(id,nama_bapak,no_hp) VALUES(72,'Purwanto','081234567890'),(73,'  PURWANTO  ','+62 812-3456-7890'),(74,'Nama Berbeda','081234567890');
INSERT INTO santri_detail(wali_santri_id,nama_anak,kelas) VALUES(72,'Zaidan Putra Arroyan','Mustawa 5 Ikhwan'),(73,'Zaidan Putra Arroyan','Mustawa 5 Ikhwan');
INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(1,72),(2,72),(3,72);
INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status,alasan) VALUES(1,1,72,'2026-11-15','I','Bukan Halaqah 09'),(2,2,72,'2026-11-15','A',NULL),(3,3,72,'2026-11-15','H',NULL);");
tahsinApplyMigration($pdo); autosaveApplyMigration($pdo); familyApplyMigration($pdo); participantMergeApplyMigration($pdo);
$pdo->exec("UPDATE halaqoh SET program_code='ibu' WHERE id=3");
attendanceHistoryApplyMigration($pdo);
$checks=0;
function qc(bool $ok,string $message):void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function qhash(PDO $pdo):string { $out=[]; foreach (discoverBackupTables($pdo) as $t) $out[$t]=$pdo->query('SELECT * FROM `'.$t.'`'.tableOrderClause($pdo,$t,tableColumns($pdo,$t)))->fetchAll(); return hash('sha256',serialize($out)); }
function qreject(PDO $pdo,callable $fn,string $message):void { $before=qhash($pdo); try { $fn(); } catch (Throwable $e) { if ($e instanceof LogicException) throw $e; qc($before===qhash($pdo),$message.' (atomic rollback)'); return; } throw new RuntimeException($message.' (unexpected success)'); }
$domainBefore=$pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll();
dataQualityApplyMigration($pdo); dataQualityApplyMigration($pdo);
qc($domainBefore===$pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll(),'Migration and retry preserve every existing attendance value');
qc(dataQualitySchemaReady($pdo),'Quality schema and scoped revision triggers ready');
$hash=qhash($pdo); $audit=dataQualityAudit($pdo); qc($hash===qhash($pdo),'Audit never writes domain or review rows');
$roster=array_values(array_filter($audit,fn($f)=>$f['kind']==='membership'));
qc(count($roster)===1 && $roster[0]['wali_ids']===[72] && $roster[0]['program']==='bapak','Purwanto one identity with H9/H15 roster conflict; Ibu stays separate');
$attendance=array_values(array_filter($audit,fn($f)=>$f['kind']==='attendance'));
qc(count($attendance)===1 && count($attendance[0]['records'])===2,'Bapak conflict excludes same-person Ibu attendance');
$identities=array_values(array_filter($audit,fn($f)=>$f['kind']==='identity'));
qc(count($identities)===3,'Normalized names and family phones produce reviewable pairs without automatic merge');
$entry=autosaveSessionState($pdo,3,1,'2026-11-15')['entries'][0];
qc($entry['blocked'] && $entry['conflict_type']==='roster_conflict','Teacher receives explicit blocked placement reason');
$f=$identities[0]; $op=bin2hex(random_bytes(16));
dataQualityReview($pdo,1,$f['key'],$f['fingerprint'],'distinct','Two different people',$op);
$hash=qhash($pdo); dataQualityReview($pdo,1,$f['key'],$f['fingerprint'],'distinct','Two different people',$op); qc($hash===qhash($pdo),'Review retries are idempotent');
qreject($pdo,fn()=>dataQualityReview($pdo,3,$f['key'],$f['fingerprint'],'distinct','Denied',bin2hex(random_bytes(16))),'Teacher cannot change review');
$fingerprint=tahsinFingerprint($attendance[0]['records']); $op=bin2hex(random_bytes(16));
$decision=dataQualityChooseRecord($pdo,1,72,'2026-11-15','bapak',$fingerprint,1,'Confirmed H9',$op);
qc(dataQualityChooseRecord($pdo,1,72,'2026-11-15','bapak',$fingerprint,1,'Confirmed H9',$op)===$decision,'Decision retry returns existing receipt');
$pdo->exec("INSERT INTO tahsin_autosave_drafts(user_id,halaqoh_id,session_date,wali_id,payload_json,official_revision,context_revision,state) VALUES(3,1,'2026-11-15',72,'{\"status\":\"I\"}','outdated','outdated','official')");
qc(count(array_filter(dataQualityAudit($pdo),fn($f)=>$f['kind']==='draft'))===1,'Read-only audit detects stale server drafts even if their stored state is official');
$pdo->exec('DELETE FROM tahsin_autosave_drafts WHERE wali_id=72');
$scope=tahsinScopedSession($pdo,72,'2026-11-15','bapak'); qc(tahsinResolveSession($scope['records'],$scope['verification'])['record']['id']===1,'Selected official attendance uses original row ID');
dataQualityChooseRecord($pdo,1,72,'2026-11-15','bapak',$fingerprint,null,'Undo decision',bin2hex(random_bytes(16)),$decision);
$scope=tahsinScopedSession($pdo,72,'2026-11-15','bapak'); qc(tahsinResolveSession($scope['records'],$scope['verification'])['state']==='conflict','Undo decision restores unresolved state without deleting sources');
qc(count($pdo->query('SELECT * FROM presensi')->fetchAll())===3,'Selecting and undoing never delete original attendance');
$pdo->exec("UPDATE halaqoh_members SET archived_at=NOW(6),archive_reason='TEST_REASSIGN' WHERE wali_santri_id=72 AND halaqoh_id=2");
// A separate program write must not stale a Bapak draft.
$bapak=autosaveSessionState($pdo,3,1,'2026-11-15')['entries'][0];
$pdo->exec("UPDATE presensi SET status='A' WHERE id=3");
$bapakAfter=autosaveSessionState($pdo,3,1,'2026-11-15')['entries'][0];
qc($bapak['official_revision']===$bapakAfter['official_revision'],'Ibu edit does not change Bapak official revision');
$report=tahsinAssembleReport(tahsinLoadDataset($pdo,'2026-11-15'),tahsinFilters(['start_date'=>'2026-11-15','end_date'=>'2026-11-15']),'admin',0,null,'2026-11-15');
$pur=array_values(array_filter($report['rows'],fn($r)=>(int)$r['id']===72))[0];
qc(count($pur['details'])===2,'Report resolves Bapak and Ibu separately within one week');

// Exact-byte merge/undo drill with both colliding and source-only rows.
$pdo->exec("ALTER TABLE wali_santri ADD extension_bytes VARBINARY(30) NULL;
ALTER TABLE presensi ADD decimal_note DECIMAL(10,3) NULL, ADD extension_bytes VARBINARY(30) NULL;
INSERT INTO wali_santri(id,nama_bapak,no_hp,alamat,extension_bytes) VALUES(10,'Keeper','08111111111',NULL,0x00ff5c),(11,'Source','08222222222','',0xff0001);
INSERT INTO santri_detail(id,wali_santri_id,nama_anak,kelas) VALUES(10,10,'Same Child','1A'),(11,11,'Same Child','2A');
INSERT INTO halaqoh_members(id,halaqoh_id,wali_santri_id) VALUES(10,1,10),(11,2,11);
INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status,alasan,decimal_note,extension_bytes) VALUES(10,1,10,'2026-11-15','I','Keeper original',1.230,0x00ff),(11,2,11,'2026-11-15','A',NULL,2.340,0xff00);
INSERT INTO tahsin_capaian_awal VALUES(10,'Iqro',1,NULL,'1',1,'2026-11-15 01:00:00'),(11,'Iqro',2,NULL,'2',1,'2026-11-15 02:00:00');
INSERT INTO tahsin_autosave_drafts(user_id,halaqoh_id,session_date,wali_id,revision,payload_json,official_revision,context_revision,state) VALUES(3,1,'2026-11-15',10,2,'{\"status\":\"I\"}','old1','ctx1','draft'),(3,1,'2026-11-15',11,4,'{\"status\":\"A\"}','old2','ctx2','draft');");
attendanceHistoryApplyMigration($pdo);
function qmerge(PDO $pdo,int $keep,int $source):string {
    $plan=participantMergePlan($pdo,$keep,$source); $choices=['confirmed'=>'1','baseline'=>'source','memberships'=>['bapak'=>'keep','ibu'=>'combine'],'drafts'=>[]];
    foreach (participantMergeDrafts($plan) as $k=>$pair) $choices['drafts'][$k]=isset($pair['source'])?'source':'keep';
    if (!($plan['related']['tahsin_capaian_awal']??[])) unset($choices['baseline']);
    $op=bin2hex(random_bytes(16)); participantMergeApply($pdo,1,$plan,$choices,$op); return $op;
}
$before=mergeUndoCapture($pdo,10,11,str_repeat('0',32));
$merge=qmerge($pdo,10,11); $pdo->exec("SET time_zone='+00:00'"); $preview=mergeUndoInspect($pdo,$merge);
qc(!$preview['issues'],'Unchanged merged identity supports undo without manual row choices');
qreject($pdo,fn()=>mergeUndoApply($pdo,2,$merge,$preview['fingerprint'],['confirmed'=>'1','choices'=>[]],bin2hex(random_bytes(16))),'PJ cannot undo identity merge');
$undo=bin2hex(random_bytes(16)); $input=['confirmed'=>'1','choices'=>[]]; $result=mergeUndoApply($pdo,1,$merge,$preview['fingerprint'],$input,$undo);
qc($pdo->query('SELECT @@SESSION.time_zone')->fetchColumn()==='+00:00','Undo normalizes snapshot timestamps and restores caller timezone');
$pdo->exec("SET time_zone='+07:00'");
$restored=mergeUndoCapture($pdo,10,11,$merge);
foreach (['wali_santri','santri_detail','halaqoh_members','presensi','tahsin_capaian_awal','tahsin_roster_history'] as $table) qc($before['tables'][$table]['rows']===$restored['tables'][$table]['rows'],'Undo exactly restores original IDs/bytes/NULL/decimal/relations: '.$table);
qc(participantMergeTarget($pdo,11)===null,'Undo removes only its own source redirect');
qc((int)$pdo->query('SELECT COUNT(*) FROM tahsin_autosave_drafts WHERE wali_id IN(10,11)')->fetchColumn()===2,'Both original server drafts restored');
qc((int)$pdo->query("SELECT MIN(revision) FROM tahsin_autosave_drafts WHERE wali_id IN(10,11)")->fetchColumn()>4,'Draft revisions never go backward after undo');
$hash=qhash($pdo); qc(mergeUndoApply($pdo,1,$merge,$preview['fingerprint'],$input,$undo)===$result && $hash===qhash($pdo),'Undo retry returns receipt without repeating mutations');

$merge=qmerge($pdo,10,11);
$pdo->exec("UPDATE santri_detail SET kelas='3A' WHERE id=11;
INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status) VALUES(12,1,10,'2026-11-16','A');");
$preview=mergeUndoInspect($pdo,$merge); qc(count($preview['issues'])>=2,'Changes and new attendance after merge appear in three-way preview');
qreject($pdo,fn()=>mergeUndoApply($pdo,1,$merge,$preview['fingerprint'],['confirmed'=>'1','choices'=>[]],bin2hex(random_bytes(16))),'Unassigned post-merge changes are rejected');
$choices=[]; foreach ($preview['issues'] as $key=>$issue) $choices[$key]=$issue['kind']==='deleted'?'preserve':'source';
mergeUndoApply($pdo,1,$merge,$preview['fingerprint'],['confirmed'=>'1','choices'=>$choices],bin2hex(random_bytes(16)));
qc($pdo->query('SELECT kelas FROM santri_detail WHERE id=11')->fetchColumn()==='3A','Later child edit survives on its selected original identity');
qc((int)$pdo->query('SELECT wali_santri_id FROM presensi WHERE id=12')->fetchColumn()===11,'New attendance is assigned to explicitly selected identity');
$merge=qmerge($pdo,10,11); $preview=mergeUndoInspect($pdo,$merge); $pdo->exec("UPDATE santri_detail SET kelas='4A' WHERE id=10");
qreject($pdo,fn()=>mergeUndoApply($pdo,1,$merge,$preview['fingerprint'],['confirmed'=>'1','choices'=>[]],bin2hex(random_bytes(16))),'Concurrent edit invalidates stale undo preview');
$preview=mergeUndoInspect($pdo,$merge); $choices=[]; foreach ($preview['issues'] as $key=>$issue) $choices[$key]='keep';
$pdo->exec("CREATE TRIGGER quality_fail_undo BEFORE INSERT ON participant_merge_undo FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic receipt failure'");
qreject($pdo,fn()=>mergeUndoApply($pdo,1,$merge,$preview['fingerprint'],['confirmed'=>'1','choices'=>$choices],bin2hex(random_bytes(16))),'Late receipt failure rolls back source resurrection and every relation');
$pdo->exec('DROP TRIGGER quality_fail_undo');
$pdo->exec("INSERT INTO wali_santri(id,nama_bapak) VALUES(12,'Third identity')");
$chain=qmerge($pdo,12,10);
qreject($pdo,fn()=>mergeUndoInspect($pdo,$merge),'Dependent merge must be undone first');
$p=mergeUndoInspect($pdo,$chain); mergeUndoApply($pdo,1,$chain,$p['fingerprint'],['confirmed'=>'1','choices'=>[]],bin2hex(random_bytes(16)));
qc(mergeUndoInspect($pdo,$merge)['completed']===null,'Earlier merge becomes available after undoing the dependent merge');

// Existing deletion, ID reuse, and unhandled cascading references must stay safe.
$pdo->exec("INSERT INTO wali_santri(id,nama_bapak) VALUES(100,'Undo deletion keeper'),(101,'Undo deletion source');
INSERT INTO santri_detail(id,wali_santri_id,nama_anak,kelas) VALUES(100,100,'Keeper child','1A'),(101,101,'Source child','2A');
INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(1,100),(3,101)");
$m=qmerge($pdo,100,101); $pdo->exec('DELETE FROM santri_detail WHERE id=101'); $p=mergeUndoInspect($pdo,$m);
$choices=[]; foreach ($p['issues'] as $k=>$i) $choices[$k]=$i['kind']==='deleted'?'preserve':'keep';
mergeUndoApply($pdo,1,$m,$p['fingerprint'],['confirmed'=>'1','choices'=>$choices],bin2hex(random_bytes(16)));
qc((int)$pdo->query('SELECT COUNT(*) FROM santri_detail WHERE id=101')->fetchColumn()===0,'Explicit preserve-deletion choice does not resurrect a later deleted child');
$m=qmerge($pdo,100,101); $p=mergeUndoInspect($pdo,$m);
$pdo->exec("INSERT INTO wali_santri(id,nama_bapak) VALUES(101,'Wrong reuse')");
qreject($pdo,fn()=>mergeUndoInspect($pdo,$m),'Reused source identity blocks undo'); $pdo->exec('DELETE FROM wali_santri WHERE id=101');
$beforeRows=mergeUndoDecodeRows($p['before']['tables']['tahsin_roster_history']); $afterRows=mergeUndoDecodeRows($p['current']['tables']['tahsin_roster_history']);
$extra=array_diff_key($afterRows,$beforeRows);
if ($extra) {
    $row=array_values($extra)[0];
    $pdo->exec('CREATE TABLE history_extension_probe(id INT PRIMARY KEY,history_id BIGINT,FOREIGN KEY(history_id) REFERENCES tahsin_roster_history(id) ON DELETE CASCADE) ENGINE=InnoDB');
    $pdo->prepare('INSERT INTO history_extension_probe VALUES(1,?)')->execute([$row['id']]);
    $p=mergeUndoInspect($pdo,$m);
    qreject($pdo,fn()=>mergeUndoApply($pdo,1,$m,$p['fingerprint'],['confirmed'=>'1','choices'=>[]],bin2hex(random_bytes(16))),'Unknown cascading history reference blocks undo without deleting extension data');
    $pdo->exec('DELETE FROM history_extension_probe');
}
$instance=$pdo->query('SELECT instance_key FROM presensi_history_state WHERE id=1')->fetchColumn();
$pdo->exec("UPDATE presensi_history_state SET instance_key=REPEAT('f',32) WHERE id=1");
qreject($pdo,fn()=>mergeUndoInspect($pdo,$m),'Snapshot from another database instance cannot be applied');
$pdo->prepare('UPDATE presensi_history_state SET instance_key=? WHERE id=1')->execute([$instance]);
$tables=discoverBackupTables($pdo);
foreach (['data_quality_reviews','participant_merge_undo','tahsin_program_revisions'] as $table) qc(in_array($table,$tables,true),'Backup includes '.$table);
echo "PASS $checks data quality/undo assertions\nQUALITY_DATABASE=$database\n";
