<?php
if (PHP_SAPI !== 'cli') exit;
putenv('DATA_INDUK_ENABLED=0');
require_once __DIR__ . '/../includes/participant_helper.php';
require_once __DIR__ . '/../includes/backup_helper.php';
foreach (['tahsin','autosave','family'] as $name) require_once __DIR__ . '/../includes/' . $name . '_migration_helper.php';
$database = 'tahsin_test_participant_' . getmypid() . '_' . bin2hex(random_bytes(4));
$pdo = new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4','root','tahsin_local_test',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("SET time_zone = '+07:00'");
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `$database`");
$pdo->exec(file_get_contents(__DIR__ . '/fixtures/tahsin-schema.sql'));
$pdo->exec("INSERT INTO users(id,username,password,nama_lengkap,role) VALUES(1,'participant_admin','synthetic','Admin Peserta','admin'),(2,'participant_pj','synthetic','PJ Peserta','pj_tahfidz'),(3,'participant_teacher','synthetic','Guru Peserta','ustadz');
INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(1,'Bapak Lama',3),(2,'Bapak Baru',3),(3,'Ibu Tetap',3);
INSERT INTO wali_santri(id,nama_bapak,no_hp,alamat,lanjut_tahsin,status_aktif) VALUES(1,'Wali Lama','08111111111','Alamat lama',1,1),(2,'Wali Lain','08222222222','Alamat lain',0,1),(3,'Wali Arsip','08333333333','Alamat arsip',0,0);
INSERT INTO santri_detail(id,wali_santri_id,nama_anak,kelas) VALUES(1,1,'Anak Satu','1A'),(2,1,'Anak Dua','2A'),(3,2,'Anak Lain','3A'),(4,3,'Anak Arsip','4A');
INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(1,1),(3,1);
INSERT INTO presensi(halaqoh_id,wali_santri_id,tanggal,status,alasan) VALUES(1,1,'2026-10-18','I','Riwayat tetap');");
tahsinApplyMigration($pdo); autosaveApplyMigration($pdo); familyApplyMigration($pdo);
$pdo->exec("UPDATE halaqoh SET program_code='ibu' WHERE id=3;
INSERT INTO tahsin_autosave_drafts(user_id,halaqoh_id,session_date,wali_id,payload_json,official_revision,context_revision) VALUES(3,1,'2026-10-18',1,'{\"status\":\"H\"}','official','context');");
$checks = 0;
function pmCheck(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function pmSnapshot(PDO $pdo, bool $journal = true): string {
    $data=[];
    foreach (discoverBackupTables($pdo) as $table) {
        if (!$journal && $table==='participant_changes') continue;
        $data[$table]=$pdo->query("SELECT * FROM `$table`" . tableOrderClause($pdo,$table,tableColumns($pdo,$table)))->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256',serialize($data));
}
function pmInput(PDO $pdo, int $id): array {
    $snapshot=participantSnapshot($pdo,$id);
    $input=array_intersect_key($snapshot['parent'],array_flip(['nama_bapak','no_hp','alamat','kategori','tempat_tahsin','ustadz_luar']));
    foreach ($input as &$value) $value=(string)$value;
    unset($value);
    $input['anak']=array_map(static fn($row)=>['id'=>(string)$row['id'],'nama'=>$row['nama_anak'],'kelas'=>(string)$row['kelas']],$snapshot['children']);
    $input['memberships']=['bapak'=>'keep','ibu'=>'keep'];
    return $input;
}
function pmSave(PDO $pdo, ?int $id, array $input, ?string $fingerprint=null, ?string $operation=null, int $actor=1): array {
    return participantApply($pdo,$actor,'save',$id,$input,$fingerprint??participantFingerprint($id===null?null:participantSnapshot($pdo,$id)),$operation??bin2hex(random_bytes(16)));
}
function pmReject(PDO $pdo, callable $operation, string $message): void {
    $before=pmSnapshot($pdo);
    try { $operation(); } catch (ParticipantManagementException|InvalidArgumentException $failure) { pmCheck(pmSnapshot($pdo)===$before,$message); return; }
    throw new RuntimeException($message);
}
$initial=pmSnapshot($pdo,false);
pmReject($pdo,fn()=>pmSave($pdo,1,pmInput($pdo,1)),'Missing journal fails without writes');
participantApplyMigration($pdo); participantApplyMigration($pdo);
pmCheck(pmSnapshot($pdo,false)===$initial,'Additive migration and retry preserve every existing row');
$pdo->beginTransaction();
try { participantApplyMigration($pdo); throw new LogicException('Migration committed active transaction'); }
catch (RuntimeException $failure) { pmCheck($pdo->inTransaction(),'Migration refuses active transactions without implicit commit'); }
$pdo->rollBack();
$input=pmInput($pdo,1); $input['alamat']='Alamat diperbarui'; $input['anak'][0]['kelas']='2B';
$attendance=$pdo->query('SELECT * FROM presensi')->fetchAll(); $drafts=$pdo->query('SELECT * FROM tahsin_autosave_drafts')->fetchAll();
$history=$pdo->query('SELECT * FROM tahsin_roster_history')->fetchAll();
$result=pmSave($pdo,1,$input);
pmCheck((int)$pdo->query("SELECT id FROM santri_detail WHERE nama_anak='Anak Satu'")->fetchColumn()===1,'Editing a child preserves its original ID');
pmCheck($pdo->query('SELECT kelas FROM santri_detail WHERE id=1')->fetchColumn()==='2B','Class changes update the same child row');
pmCheck($pdo->query('SELECT * FROM presensi')->fetchAll()===$attendance && $pdo->query('SELECT * FROM tahsin_autosave_drafts')->fetchAll()===$drafts,'Editing a participant preserves attendance and autosave payloads');
pmCheck($pdo->query('SELECT * FROM tahsin_roster_history')->fetchAll()===$history,'Unchanged memberships do not close/reopen roster intervals');
pmCheck((int)$pdo->query('SELECT lanjut_tahsin FROM wali_santri WHERE id=1')->fetchColumn()===1,'Legacy continuation flag survives an ordinary save');
$journal=$pdo->query("SELECT * FROM participant_changes WHERE operation_id='".$result['operation_id']."'")->fetch();
pmCheck(json_decode($journal['before_json'],true)['children'][0]['kelas']==='1A' && json_decode($journal['after_json'],true)['children'][0]['kelas']==='2B','Journal retains before and after child identities/classes');
$input=pmInput($pdo,1); $input['memberships']['bapak']='2'; pmSave($pdo,1,$input);
pmCheck($pdo->query('SELECT halaqoh_id FROM halaqoh_members WHERE wali_santri_id=1 AND archived_at IS NULL ORDER BY halaqoh_id')->fetchAll(PDO::FETCH_COLUMN)===[2,3],'Moving Bapak preserves the active Ibu membership');
pmCheck((int)$pdo->query('SELECT COUNT(*) FROM halaqoh_members WHERE wali_santri_id=1')->fetchColumn()===3,'Old membership rows survive reassignment');
$history=$pdo->query('SELECT * FROM tahsin_roster_history')->fetchAll(); pmSave($pdo,1,$input);
pmCheck($pdo->query('SELECT * FROM tahsin_roster_history')->fetchAll()===$history,'Selecting the same active halaqoh creates no roster churn');
$input=pmInput($pdo,1); $input['memberships']['ibu']='1';
pmReject($pdo,fn()=>pmSave($pdo,1,$input),'A group from another program is rejected atomically');
$input=pmInput($pdo,1); $input['memberships']['bapak']='none'; pmSave($pdo,1,$input);
pmCheck($pdo->query('SELECT halaqoh_id FROM halaqoh_members WHERE wali_santri_id=1 AND archived_at IS NULL')->fetchAll(PDO::FETCH_COLUMN)===[3],'Clearing Bapak preserves Ibu');
$input=pmInput($pdo,1); $input['anak'][0]['id']='3';
pmReject($pdo,fn()=>pmSave($pdo,1,$input),'Child from another parent cannot be reassigned through a hidden ID');
$input=pmInput($pdo,1); array_pop($input['anak']);
pmReject($pdo,fn()=>pmSave($pdo,1,$input),'Omitted existing children reject truncated forms instead of deleting data');
$input=pmInput($pdo,1); $input['anak'][]=$input['anak'][0];
pmReject($pdo,fn()=>pmSave($pdo,1,$input),'Duplicate child IDs are rejected');
foreach (['nama_bapak'=>['bad'],'kategori'=>'bad','alamat'=>str_repeat('x',5001),'reactivate'=>['1']] as $key=>$value) {
    $input=pmInput($pdo,1); $input[$key]=$value; pmReject($pdo,fn()=>pmSave($pdo,1,$input),'Malformed or oversized '.$key.' is rejected without writes');
}
$input=pmInput($pdo,1); $input['remove_children']=['2'];
pmReject($pdo,fn()=>pmSave($pdo,1,$input),'Child removal requires explicit confirmation');
$pdo->exec('CREATE TABLE child_reference(id INT PRIMARY KEY,child_id INT NOT NULL,FOREIGN KEY(child_id) REFERENCES santri_detail(id) ON DELETE CASCADE) ENGINE=InnoDB; INSERT INTO child_reference VALUES(1,2)');
$input['confirm_remove']='1'; $input['nama_bapak']='Should rollback'; $input['anak'][0]['kelas']='8X';
pmReject($pdo,fn()=>pmSave($pdo,1,$input),'Even cascading child references block removal and roll back earlier field edits');
$pdo->exec('DELETE FROM child_reference');
$input=pmInput($pdo,1); $input['remove_children']=['2']; $input['confirm_remove']='1'; $removed=pmSave($pdo,1,$input);
pmCheck((int)$pdo->query('SELECT COUNT(*) FROM santri_detail WHERE id=2')->fetchColumn()===0,'Confirmed unreferenced child removal affects only the selected ID');
$old=json_decode($pdo->query("SELECT before_json FROM participant_changes WHERE operation_id='".$removed['operation_id']."'")->fetchColumn(),true);
pmCheck(array_column($old['children'],'id')===[1,2],'Removed child remains in a durable before snapshot with original ID');
$input=pmInput($pdo,1); $stale=participantFingerprint(participantSnapshot($pdo,1));
$pdo->exec("UPDATE santri_detail SET kelas='3C' WHERE id=1");
pmReject($pdo,fn()=>pmSave($pdo,1,$input,$stale),'Stale editor cannot overwrite a concurrent child edit');
$new=['nama_bapak'=>'Wali Baru <script>','no_hp'=>'','alamat'=>'Jalan 👨‍👩‍👧','kategori'=>'reguler','tempat_tahsin'=>'','ustadz_luar'=>'','anak'=>[['id'=>'','nama'=>'Anak Baru','kelas'=>'1A']]];
$operation=bin2hex(random_bytes(16)); $created=pmSave($pdo,null,$new,null,$operation,2); $after=pmSnapshot($pdo);
pmCheck(pmSave($pdo,null,$new,null,$operation,2)===$created && pmSnapshot($pdo)===$after,'Repeated create returns its receipt without duplicate parents or children');
$different=$new; $different['nama_bapak']='Changed request';
pmReject($pdo,fn()=>pmSave($pdo,null,$different,null,$operation,2),'A receipt cannot be reused for different input');
pmReject($pdo,fn()=>pmSave($pdo,1,pmInput($pdo,1),null,null,3),'Teacher cannot call participant management service');
$input=pmInput($pdo,3); pmSave($pdo,3,$input);
pmCheck((int)$pdo->query('SELECT status_aktif FROM wali_santri WHERE id=3')->fetchColumn()===0,'Saving an archived participant does not implicitly reactivate it');
$input['reactivate']='1'; pmSave($pdo,3,$input);
pmCheck((int)$pdo->query('SELECT status_aktif FROM wali_santri WHERE id=3')->fetchColumn()===1,'Explicit reactivation requires and retains active school child');
$input=pmInput($pdo,3); $input['anak'][0]['kelas']='Lulus'; $input['reactivate']='1';
pmReject($pdo,fn()=>pmSave($pdo,3,$input),'Graduated children cannot qualify for reactivation');
$input=pmInput($pdo,1); $input['anak'][0]['kelas']='Lulus'; pmSave($pdo,1,$input);
pmCheck((int)$pdo->query('SELECT status_aktif FROM wali_santri WHERE id=1')->fetchColumn()===0 && (int)$pdo->query('SELECT COUNT(*) FROM halaqoh_members WHERE wali_santri_id=1 AND archived_at IS NULL')->fetchColumn()===0,'Graduation archives participant and memberships without deleting history');
putenv('DATA_INDUK_ENABLED=1');
pmReject($pdo,fn()=>pmSave($pdo,null,$new),'Incomplete central configuration still blocks local identity creation');
$input=pmInput($pdo,2); $identity=participantSnapshot($pdo,2); $input['nama_bapak']='Overwrite master'; $input['anak']=[]; $input['kategori']='askar';
pmSave($pdo,2,$input);
pmCheck($pdo->query('SELECT nama_bapak FROM wali_santri WHERE id=2')->fetchColumn()===$identity['parent']['nama_bapak'] && $pdo->query('SELECT * FROM santri_detail WHERE wali_santri_id=2')->fetchAll()===$identity['children'],'Central mode ignores stale/malicious identity and child fields while allowing local category');
pmReject($pdo,fn()=>participantApply($pdo,1,'archive',2,['confirmed'=>'1'],participantFingerprint(participantSnapshot($pdo,2)),bin2hex(random_bytes(16))),'Central mode blocks local archive even without a mapped UUID');
putenv('DATA_INDUK_ENABLED=0');
$pdo->exec("INSERT INTO family_guardians(source_namespace,source_parent_id,wali_santri_id,guardian_type,source_name,source_phone,source_address,source_active,account_active,snapshot_date) VALUES('test','mapped',2,'father','Mapped','','',1,1,'2026-10-23'); DROP VIEW family_children");
$input=pmInput($pdo,2); $input['nama_bapak']='Overwrite family'; $input['anak']=[]; pmSave($pdo,2,$input);
pmCheck($pdo->query('SELECT nama_bapak FROM wali_santri WHERE id=2')->fetchColumn()==='Wali Lain','Direct family mapping protects identity even when its view is missing');
pmReject($pdo,fn()=>participantApply($pdo,1,'archive',2,['confirmed'=>'1'],participantFingerprint(participantSnapshot($pdo,2)),bin2hex(random_bytes(16))),'Direct family mapping protects archive while integration is disabled');
$pdo->exec('ALTER TABLE santri_detail ADD data_induk_student_id VARCHAR(64) NULL');
$pdo->exec("UPDATE santri_detail SET data_induk_student_id='uuid-child' WHERE id=4");
$input=pmInput($pdo,3); $input['anak']=[]; $input['nama_bapak']='Overwrite child source'; pmSave($pdo,3,$input);
pmCheck($pdo->query('SELECT nama_bapak FROM wali_santri WHERE id=3')->fetchColumn()==='Wali Arsip','Child UUID mapping protects the whole parent identity');
$newParent=(int)$created['id'];
$beforeArchive=participantSnapshot($pdo,$newParent);
participantApply($pdo,1,'archive',$newParent,['confirmed'=>'1'],participantFingerprint($beforeArchive),bin2hex(random_bytes(16)));
pmCheck(participantSnapshot($pdo,$newParent)['children']===$beforeArchive['children'],'Archive retains all children and their IDs');
$newChild=(int)$pdo->query('SELECT id FROM santri_detail WHERE wali_santri_id='.$newParent)->fetchColumn();
$pdo->prepare('UPDATE wali_santri SET no_hp=NULL,alamat=?,tempat_tahsin=NULL,ustadz_luar=NULL WHERE id=?')->execute(['  Alamat lama dengan spasi  ',$newParent]);
$pdo->prepare('UPDATE santri_detail SET nama_anak=?,kelas=NULL WHERE id=?')->execute(['  Anak lama  ',$newChild]);
$noOpBefore=pmSnapshot($pdo,false); pmSave($pdo,$newParent,pmInput($pdo,$newParent));
pmCheck(pmSnapshot($pdo,false)===$noOpBefore,'Unchanged editor preserves NULL values, significant stored whitespace and every domain table');
$input=pmInput($pdo,$newParent); $input['kategori']='tahsin_luar';
pmReject($pdo,fn()=>pmSave($pdo,$newParent,$input),'External Tahsin requires place and teacher on the server');
$input=pmInput($pdo,$newParent); $input['anak']=array_fill(0,101,['id'=>'','nama'=>'Extra','kelas'=>'1A']);
pmReject($pdo,fn()=>pmSave($pdo,$newParent,$input),'More than 100 submitted child rows are rejected');
$hundred=$new; $hundred['nama_bapak']='Seratus Anak';
$hundred['anak']=array_map(static fn($index)=>['id'=>'','nama'=>'Anak '.$index,'kelas'=>'1A'],range(1,100));
$large=pmSave($pdo,null,$hundred); $largeBefore=pmSnapshot($pdo,false); pmSave($pdo,$large['id'],pmInput($pdo,$large['id']));
pmCheck(pmSnapshot($pdo,false)===$largeBefore,'A complete 100-child form remains editable without removing or duplicating any child');
$pdo->exec('ALTER TABLE santri_detail ADD UNIQUE KEY child_parent_pair(id,wali_santri_id); CREATE TABLE composite_child_reference(id INT PRIMARY KEY,child_id INT,parent_id INT,FOREIGN KEY(child_id,parent_id) REFERENCES santri_detail(id,wali_santri_id) ON DELETE CASCADE) ENGINE=InnoDB; INSERT INTO composite_child_reference VALUES(1,1,1)');
$input=pmInput($pdo,1); $input['remove_children']=['1']; $input['confirm_remove']='1';
pmReject($pdo,fn()=>pmSave($pdo,1,$input),'Composite cascading foreign keys also block child removal without losing referenced rows');
$originalMode=$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
$pdo->exec("CREATE TRIGGER participant_test_failure BEFORE INSERT ON participant_changes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic journal failure'");
$before=pmSnapshot($pdo); $input=pmInput($pdo,$newParent); $input['alamat']='Should rollback'; $input['anak'][]=['id'=>'','nama'=>'Rollback child','kelas'=>'5A'];
try { pmSave($pdo,$newParent,$input); throw new LogicException('Journal failure was ignored'); }
catch (PDOException $failure) { pmCheck(pmSnapshot($pdo)===$before,'Late journal failure rolls back parent, children and all trigger writes'); }
pmCheck($pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn()===$originalMode,'SQL mode is restored after late failure');
$lock=$pdo->prepare('SELECT IS_FREE_LOCK(?)'); $lock->execute([participantWriteLockName($pdo)]);
pmCheck((int)$lock->fetchColumn()===1,'Participant write lock is released after failure');
$directory=__DIR__ . '/../.sessions/audit-20261023/participant-management-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($directory,0700,true);
file_put_contents($directory.'/results.json',json_encode(['passed'=>true,'checks'=>$checks,'database'=>$database,'php'=>PHP_VERSION,'peak_memory_bytes'=>memory_get_peak_usage(true),'journal_rows'=>(int)$pdo->query('SELECT COUNT(*) FROM participant_changes')->fetchColumn()],JSON_PRETTY_PRINT));
echo "PASS $checks participant management assertions\nPARTICIPANT_EVIDENCE=$directory\n";
