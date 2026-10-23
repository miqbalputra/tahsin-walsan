<?php
if (PHP_SAPI!=='cli') exit;
putenv('DATA_INDUK_ENABLED=0');
require_once __DIR__.'/../includes/participant_merge_helper.php';
require_once __DIR__.'/../includes/backup_helper.php';
foreach (['tahsin','autosave','family'] as $name) require_once __DIR__.'/../includes/'.$name.'_migration_helper.php';
$database='tahsin_test_merge_'.getmypid().'_'.bin2hex(random_bytes(4));
$pdo=new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4','root','tahsin_local_test',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `$database`");
$pdo->exec(file_get_contents(__DIR__.'/fixtures/tahsin-schema.sql'));
$pdo->exec("INSERT INTO users(id,username,password,nama_lengkap,role) VALUES(1,'merge_admin','synthetic','Admin Gabung','admin'),(2,'merge_admin2','synthetic','Admin Dua','admin'),(3,'merge_pj','synthetic','PJ Gabung','pj_tahfidz'),(4,'merge_teacher','synthetic','Guru Gabung','ustadz');
INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(1,'Bapak Satu',4),(2,'Bapak Dua',4),(3,'Ibu Satu',4);
INSERT INTO wali_santri(id,nama_bapak,no_hp,alamat,kategori,lanjut_tahsin) VALUES(1,'Wali Tetap','08111','Alamat tetap','reguler',0),(2,'Wali Sumber <script>','08222','Alamat sumber','askar',1);
INSERT INTO santri_detail(id,wali_santri_id,nama_anak,kelas) VALUES(1,1,'Nama Sama','1A'),(2,2,'Nama Sama','2B'),(3,2,'Anak Sumber',NULL);
INSERT INTO halaqoh_members(id,halaqoh_id,wali_santri_id) VALUES(1,1,1),(2,2,2),(3,3,2),(4,1,2);
INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status,alasan,jenis_materi,jilid,halaman,hasil_talaqqi,created_at) VALUES(1,2,2,'2026-10-18','H',NULL,'Iqro',1,'1-2','Lulus','2026-10-18 08:00:00'),(2,1,1,'2026-10-18','I','Berbeda',NULL,NULL,NULL,NULL,'2026-10-18 08:01:00');
CREATE TABLE activity_logs(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,username VARCHAR(50),action VARCHAR(100),description TEXT,ip_address VARCHAR(45),created_at DATETIME) ENGINE=InnoDB;
INSERT INTO activity_logs(user_id,action,description,created_at) VALUES(1,'INIT_CAPAIAN','Wali ID: 2','2026-10-18 08:00:00');");
tahsinApplyMigration($pdo); autosaveApplyMigration($pdo); familyApplyMigration($pdo);
$pdo->exec("UPDATE halaqoh SET program_code='ibu' WHERE id=3;
INSERT INTO tahsin_capaian_awal VALUES(1,'Iqro',1,NULL,'1',1,'2026-10-18 08:00:00'),(2,'Al Quran',NULL,'Al Fatihah','1-7',1,'2026-10-18 08:01:00');
INSERT INTO tahsin_autosave_drafts(user_id,halaqoh_id,session_date,wali_id,revision,payload_json,official_revision,context_revision,state) VALUES(4,1,'2026-10-18',1,3,'{\"status\":\"H\"}','old-keep','ctx-keep','draft'),(4,1,'2026-10-18',2,7,'{\"status\":\"I\",\"alasan\":\"Sumber\"}','old-source','ctx-source','draft'),(4,2,'2026-10-25',2,2,'{\"status\":\"S\"}','source-only','ctx-source','draft');
INSERT INTO tahsin_autosave_revisions VALUES(1,'2026-10-18',4),(2,'2026-10-18',8);
INSERT INTO tahsin_verifications(wali_santri_id,session_date,fingerprint,selected_presensi_id,decided_by,note) VALUES(2,'2026-10-18',REPEAT('a',64),NULL,1,'Bukti awal sebelum gabung');");
$checks=0;
function mgCheck(bool $condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function mgSnapshot(PDO $pdo,bool $journal=true): string {
    $rows=[]; foreach (discoverBackupTables($pdo) as $table) { if (!$journal && in_array($table,['participant_changes','participant_merge_aliases'],true)) continue; $rows[$table]=$pdo->query("SELECT * FROM `$table`".tableOrderClause($pdo,$table,tableColumns($pdo,$table)))->fetchAll(); }
    return hash('sha256',serialize($rows));
}
function mgReject(PDO $pdo,callable $operation,string $message): void { $before=mgSnapshot($pdo); try { $operation(); } catch (ParticipantManagementException|InvalidArgumentException $failure) { mgCheck(mgSnapshot($pdo)===$before,$message); return; } throw new RuntimeException($message); }
function mgChoices(array $plan): array {
    $input=['confirmed'=>'1','fields'=>[],'memberships'=>['bapak'=>'keep','ibu'=>'combine'],'baseline'=>'source','drafts'=>[]];
    foreach (participantMergeDrafts($plan) as $key=>$pair) $input['drafts'][$key]=isset($pair['source'])?'source':'keep';
    return $input;
}
function mgPair(PDO $pdo): array {
    $pdo->exec("INSERT INTO wali_santri(nama_bapak) VALUES('Pair keeper')"); $keep=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO wali_santri(nama_bapak) VALUES('Pair source')"); $source=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO santri_detail(wali_santri_id,nama_anak,kelas) VALUES(?,?,?)')->execute([$source,'Pair child','1A']);
    return [$keep,$source];
}
mgReject($pdo,fn()=>participantMergePlan($pdo,1,2),'Merge explains missing migration without writes');
$before=mgSnapshot($pdo,false); participantMergeApplyMigration($pdo); participantMergeApplyMigration($pdo);
mgCheck(mgSnapshot($pdo,false)===$before,'Additive merge migration and retry preserve all domain data');
$pdo->beginTransaction(); try { participantMergeApplyMigration($pdo); throw new LogicException('Migration committed a transaction'); } catch (RuntimeException $error) { mgCheck($pdo->inTransaction(),'Merge migration refuses an active transaction'); } $pdo->rollBack();
$before=mgSnapshot($pdo); $plan=participantMergePlan($pdo,1,2); $input=mgChoices($plan);
mgCheck(mgSnapshot($pdo)===$before && count($plan['source']['children'])===2,'Merge preview is read-only and retains distinct child identities');
mgCheck(isset($plan['suspects'][1]),'Positive INIT_CAPAIAN evidence is captured before moving guardian IDs');
$bad=$input; $bad['confirmed']=''; mgReject($pdo,fn()=>participantMergeApply($pdo,1,$plan,$bad,bin2hex(random_bytes(16))),'Merge requires explicit review confirmation');
$bad=$input; $bad['baseline']=''; mgReject($pdo,fn()=>participantMergeApply($pdo,1,$plan,$bad,bin2hex(random_bytes(16))),'Two baseline rows require a primary baseline choice');
$bad=$input; $bad['drafts']=[]; mgReject($pdo,fn()=>participantMergeApply($pdo,1,$plan,$bad,bin2hex(random_bytes(16))),'Conflicting draft payloads require an explicit choice');
$bad=$input; $bad['memberships']=[]; mgReject($pdo,fn()=>participantMergeApply($pdo,1,$plan,$bad,bin2hex(random_bytes(16))),'Different active Bapak groups require an explicit roster choice');
$bad=$input; $bad['fields']=['alamat'=>['bad']]; mgReject($pdo,fn()=>participantMergeApply($pdo,1,$plan,$bad,bin2hex(random_bytes(16))),'Array field choice is rejected');
foreach ([3,4] as $actor) mgReject($pdo,fn()=>participantMergeApply($pdo,$actor,$plan,$input,bin2hex(random_bytes(16))),'Only current administrators can merge');
$input['fields']=['no_hp'=>'source','alamat'=>'source','lanjut_tahsin'=>'source'];
$operation=bin2hex(random_bytes(16)); $result=participantMergeApply($pdo,1,$plan,$input,$operation);
mgCheck((int)$pdo->query('SELECT COUNT(*) FROM wali_santri WHERE id=2')->fetchColumn()===0 && $result['id']===1,'Successful merge leaves one canonical participant');
mgCheck($pdo->query('SELECT id FROM santri_detail WHERE wali_santri_id=1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)===[1,2,3],'All original child IDs survive, including same-name different-class children');
mgCheck($pdo->query('SELECT kelas FROM santri_detail WHERE id=2')->fetchColumn()==='2B','Same-name child class is not silently replaced or collapsed');
mgCheck($pdo->query('SELECT wali_santri_id FROM presensi ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)===[1,1],'All attendance records move without deleting original record IDs');
mgCheck((int)$pdo->query('SELECT COUNT(*) FROM halaqoh_members')->fetchColumn()===4,'Every membership ID survives deduplication as active or archived');
mgCheck($pdo->query('SELECT id FROM halaqoh_members WHERE archived_at IS NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)===[1,3],'Selected Bapak and source Ibu rosters remain active; duplicate group membership is archived');
mgCheck((int)$pdo->query('SELECT COUNT(*) FROM tahsin_roster_history WHERE wali_santri_id=2')->fetchColumn()===0,'No roster history remains orphaned under removed participant ID');
mgCheck($pdo->query('SELECT jenis_materi FROM tahsin_capaian_awal WHERE wali_santri_id=1')->fetchColumn()==='Al Quran' && (int)$pdo->query('SELECT COUNT(*) FROM tahsin_capaian_awal')->fetchColumn()===1,'Chosen baseline is active with no orphan source baseline');
$drafts=$pdo->query('SELECT * FROM tahsin_autosave_drafts ORDER BY halaqoh_id')->fetchAll();
mgCheck(count($drafts)===2 && $drafts[0]['wali_id']===1 && $drafts[1]['wali_id']===1,'Source-only and colliding drafts move to the canonical participant');
mgCheck(json_decode($drafts[0]['payload_json'],true)['status']==='I' && $drafts[0]['revision']>7 && $drafts[0]['state']==='conflict','Chosen draft survives with a newer revision and mandatory review');
mgCheck((int)$pdo->query('SELECT COUNT(*) FROM tahsin_autosave_revisions WHERE wali_id=2')->fetchColumn()===0 && (int)$pdo->query("SELECT revision FROM tahsin_autosave_revisions WHERE wali_id=1 AND session_date='2026-10-18'")->fetchColumn()>8,'Revision collision is combined monotonically without leaving source rows');
$records=$pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll(); $verification=$pdo->query('SELECT * FROM tahsin_verifications ORDER BY id DESC LIMIT 1')->fetch();
mgCheck(tahsinResolveSession($records,$verification)['state']==='conflict','INIT_CAPAIAN suspicion stays blocked for review after the original log ID no longer matches');
$journal=$pdo->query("SELECT * FROM participant_changes WHERE operation_id='$operation'")->fetch(); $old=json_decode($journal['before_json'],true);
mgCheck(count($old['merge_related']['tahsin_capaian_awal'])===2 && count($old['merge_related']['tahsin_autosave_drafts'])===3,'Journal retains both original baselines and every original draft payload');
mgCheck($old['merge_source']['parent']['nama_bapak']==='Wali Sumber <script>' && $pdo->query('SELECT no_hp FROM wali_santri WHERE id=1')->fetchColumn()==='08222','Unselected source biography remains in the journal while chosen fields become active');
mgCheck(participantMergeTarget($pdo,2)===1,'Removed ID has a durable redirect to the surviving identity');
$after=mgSnapshot($pdo); mgCheck(participantMergeApply($pdo,1,$plan,$input,$operation)===$result && mgSnapshot($pdo)===$after,'Retry after source deletion returns its receipt without duplicate writes');
$bad=$input; $bad['fields']['alamat']='keep'; mgReject($pdo,fn()=>participantMergeApply($pdo,1,$plan,$bad,$operation),'A merge receipt cannot be reused for changed choices');
[$next,$unused]=mgPair($pdo); $chain=participantMergePlan($pdo,$next,1); $chainInput=['confirmed'=>'1'];
foreach (participantMergeDrafts($chain) as $key=>$pair) $chainInput['drafts'][$key]=isset($pair['source'])?'source':'keep';
participantMergeApply($pdo,1,$chain,$chainInput,bin2hex(random_bytes(16)));
mgCheck(participantMergeTarget($pdo,2)===$next && participantMergeTarget($pdo,1)===$next,'Repeated merges flatten old aliases to the final live identity');
[$keep,$source]=mgPair($pdo); $stale=participantMergePlan($pdo,$keep,$source);
$pdo->prepare('UPDATE santri_detail SET kelas=? WHERE wali_santri_id=?')->execute(['2B',$source]);
mgReject($pdo,fn()=>participantMergeApply($pdo,1,$stale,['confirmed'=>'1'],bin2hex(random_bytes(16))),'A concurrent child edit invalidates merge preview');
putenv('DATA_INDUK_ENABLED=1'); mgReject($pdo,fn()=>participantMergePlan($pdo,$keep,$source),'Incomplete central configuration still blocks local merge'); putenv('DATA_INDUK_ENABLED=0');
$pdo->exec('CREATE TABLE additional_reference(id INT PRIMARY KEY,participant INT,FOREIGN KEY(participant) REFERENCES wali_santri(id) ON DELETE CASCADE) ENGINE=InnoDB');
$pdo->exec("INSERT INTO additional_reference VALUES(1,$source)");
mgReject($pdo,fn()=>participantMergePlan($pdo,$keep,$source),'Unknown cascading parent reference fails before writes'); $pdo->exec('DELETE FROM additional_reference');
$pdo->exec('CREATE TABLE undeclared_reference(id INT PRIMARY KEY,wali_id INT) ENGINE=InnoDB'); $pdo->exec("INSERT INTO undeclared_reference VALUES(1,$source)");
mgReject($pdo,fn()=>participantMergePlan($pdo,$keep,$source),'Unknown conventional reference without a foreign key is not orphaned'); $pdo->exec('DELETE FROM undeclared_reference');
$pdo->exec('ALTER TABLE santri_detail ADD data_induk_enrollment_id VARCHAR(64) NULL'); $pdo->exec("UPDATE santri_detail SET data_induk_enrollment_id='enrollment-only' WHERE wali_santri_id=$source");
mgReject($pdo,fn()=>participantMergePlan($pdo,$keep,$source),'Partial enrollment mapping protects identities even without student UUID columns'); $pdo->exec("UPDATE santri_detail SET data_induk_enrollment_id=NULL WHERE wali_santri_id=$source");
$pdo->exec("INSERT INTO family_students(id,source_namespace,source_student_id,nis,nama_anak,kelas,school_status,snapshot_date) VALUES(1,'test','bridge-only','','Bridge','1A','active','2026-10-23')");
$child=(int)$pdo->query("SELECT id FROM santri_detail WHERE wali_santri_id=$source LIMIT 1")->fetchColumn(); $pdo->exec("INSERT INTO family_legacy_children VALUES($child,1)");
mgReject($pdo,fn()=>participantMergePlan($pdo,$keep,$source),'A legacy child bridge is protected even without a parent guardian mapping'); $pdo->exec('DELETE FROM family_legacy_children');
$pdo->exec("CREATE TABLE data_induk_sync_conflicts(id INT PRIMARY KEY,entity_type VARCHAR(30),local_id INT,status VARCHAR(30)) ENGINE=InnoDB; INSERT INTO data_induk_sync_conflicts VALUES(1,'guardian',$source,'pending')");
mgReject($pdo,fn()=>participantMergePlan($pdo,$keep,$source),'Pending guardian source mapping must be resolved before local merge'); $pdo->exec("UPDATE data_induk_sync_conflicts SET status='ignored'");
$plan=participantMergePlan($pdo,$keep,$source); $before=mgSnapshot($pdo); $oldMode=$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
$pdo->exec("CREATE TRIGGER merge_test_failure BEFORE INSERT ON participant_merge_aliases FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic alias failure'");
try { participantMergeApply($pdo,1,$plan,['confirmed'=>'1'],bin2hex(random_bytes(16))); throw new LogicException('Late failure was ignored'); }
catch (PDOException $error) { mgCheck(mgSnapshot($pdo)===$before,'Failure after source deletion rolls back source, child IDs, journal, aliases and all trigger writes'); }
mgCheck($pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn()===$oldMode,'Merge restores SQL mode after late failure');
$statement=$pdo->prepare('SELECT IS_FREE_LOCK(?)'); $statement->execute([participantWriteLockName($pdo)]); mgCheck((int)$statement->fetchColumn()===1,'Merge releases participant lock after failure');
$pdo->exec('DROP TRIGGER merge_test_failure');
[$keep,$source]=mgPair($pdo); $child=(int)$pdo->query("SELECT id FROM santri_detail WHERE wali_santri_id=$source")->fetchColumn();
$pdo->exec('ALTER TABLE santri_detail ADD UNIQUE KEY child_parent_key(id,wali_santri_id); CREATE TABLE composite_child_reference(id INT PRIMARY KEY,child_id INT,parent_id INT,FOREIGN KEY(child_id,parent_id) REFERENCES santri_detail(id,wali_santri_id) ON UPDATE CASCADE ON DELETE CASCADE) ENGINE=InnoDB');
$pdo->exec("INSERT INTO composite_child_reference VALUES(1,$child,$source)");
mgReject($pdo,fn()=>participantMergePlan($pdo,$keep,$source),'Composite child references are not silently changed by cascading parent reassignment'); $pdo->exec('DELETE FROM composite_child_reference');
$pdo->exec('CREATE TABLE stable_child_reference(id INT PRIMARY KEY,child_id INT,FOREIGN KEY(child_id) REFERENCES santri_detail(id) ON DELETE CASCADE) ENGINE=InnoDB'); $pdo->exec("INSERT INTO stable_child_reference VALUES(1,$child)");
$plan=participantMergePlan($pdo,$keep,$source); participantMergeApply($pdo,1,$plan,['confirmed'=>'1'],bin2hex(random_bytes(16)));
mgCheck((int)$pdo->query('SELECT child_id FROM stable_child_reference WHERE id=1')->fetchColumn()===$child && (int)$pdo->query("SELECT wali_santri_id FROM santri_detail WHERE id=$child")->fetchColumn()===$keep,'References to a stable child ID survive reassignment without cascading deletion');
[$keep,$source]=mgPair($pdo); $pdo->exec("UPDATE wali_santri SET status_aktif=0 WHERE id=$keep; INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(1,$source)");
$plan=participantMergePlan($pdo,$keep,$source); participantMergeApply($pdo,1,$plan,['confirmed'=>'1'],bin2hex(random_bytes(16)));
mgCheck((int)$pdo->query("SELECT COUNT(*) FROM halaqoh_members WHERE wali_santri_id=$keep AND archived_at IS NULL")->fetchColumn()===0,'Keeping an archived survivor does not accidentally reactivate source membership');
[$keep,$source]=mgPair($pdo); $pdo->exec("INSERT INTO tahsin_autosave_drafts(user_id,halaqoh_id,session_date,wali_id,payload_json,official_revision,context_revision) VALUES(4,1,'2026-10-18',$source,'{}','old','ctx')");
$plan=participantMergePlan($pdo,$keep,$source); $pdo->exec("UPDATE tahsin_autosave_drafts SET revision=revision+1 WHERE wali_id=$source");
mgReject($pdo,fn()=>participantMergeApply($pdo,1,$plan,['confirmed'=>'1'],bin2hex(random_bytes(16))),'A concurrent draft update invalidates the merge preview');
$pdo->exec("INSERT INTO family_guardians(source_namespace,source_parent_id,wali_santri_id,guardian_type,source_name,source_phone,source_address,source_active,account_active,snapshot_date) VALUES('test','guardian-map',$source,'father','Mapped','','',1,1,'2026-10-23'); DROP VIEW family_children");
mgReject($pdo,fn()=>participantMergePlan($pdo,$keep,$source),'Direct family guardian mapping is protected even with a missing family view');
[$keep,$source]=mgPair($pdo); $plan=participantMergePlan($pdo,$keep,$source); participantMergeApply($pdo,1,$plan,['confirmed'=>'1'],bin2hex(random_bytes(16)));
$pdo->exec("ALTER TABLE wali_santri AUTO_INCREMENT=$source");
$newInput=['nama_bapak'=>'New after merge','no_hp'=>'','alamat'=>'','kategori'=>'reguler','tempat_tahsin'=>'','ustadz_luar'=>'','anak'=>[]];
mgReject($pdo,fn()=>participantApply($pdo,1,'save',null,$newInput,participantFingerprint(null),bin2hex(random_bytes(16))),'An allocator reusing an aliased source ID is rejected without recreating that identity');
$newResult=participantApply($pdo,1,'save',null,$newInput,participantFingerprint(null),bin2hex(random_bytes(16)));
mgCheck($newResult['id']>$source && participantMergeTarget($pdo,$source)===$keep,'Retry allocates a fresh participant ID and preserves the old alias');
$directory=__DIR__.'/../.sessions/audit-20261023/participant-merge-'.getmypid().'-'.bin2hex(random_bytes(4)); mkdir($directory,0700,true);
file_put_contents($directory.'/results.json',json_encode(['passed'=>true,'checks'=>$checks,'database'=>$database,'php'=>PHP_VERSION,'peak_memory_bytes'=>memory_get_peak_usage(true)],JSON_PRETTY_PRINT));
echo "PASS $checks participant merge assertions\nMERGE_EVIDENCE=$directory\n";
