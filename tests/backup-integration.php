<?php
/** Recovery drills against new localhost-only databases, never production. */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/backup_helper.php';
require_once __DIR__ . '/../includes/participant_import_helper.php';
require_once __DIR__ . '/../includes/participant_helper.php';
require_once __DIR__ . '/../includes/participant_merge_helper.php';
function reportApplicationError(Throwable $error,$context=''): void { $GLOBALS['backup_test_last_error']='[' . $context . '] ' . $error->getMessage(); }
foreach (['tahsin','autosave','family'] as $name) require_once __DIR__ . '/../includes/' . $name . '_migration_helper.php';
putenv('DATA_INDUK_ENABLED=0');
$database = 'tahsin_test_backup_' . getmypid() . '_' . bin2hex(random_bytes(4));
$pdo = new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4','root','tahsin_local_test',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$database`");
$pdo->exec(file_get_contents(__DIR__ . '/fixtures/tahsin-schema.sql'));
$pdo->exec("CREATE TABLE settings(id INT AUTO_INCREMENT PRIMARY KEY,setting_key VARCHAR(50) UNIQUE NOT NULL,setting_value LONGTEXT) ENGINE=InnoDB;
CREATE TABLE holidays(id INT AUTO_INCREMENT PRIMARY KEY,tanggal DATE NOT NULL UNIQUE,keterangan VARCHAR(255)) ENGINE=InnoDB;
CREATE TABLE activity_logs(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,username VARCHAR(50),action VARCHAR(100),description TEXT,ip_address VARCHAR(45),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE pengumuman(id INT AUTO_INCREMENT PRIMARY KEY,judul VARCHAR(255),isi TEXT,is_aktif TINYINT DEFAULT 1) ENGINE=InnoDB;
CREATE TABLE login_attempts(id INT AUTO_INCREMENT PRIMARY KEY,ip_address VARCHAR(45),username VARCHAR(50),attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
INSERT INTO users(id,username,password,nama_lengkap,role) VALUES(1,'fixture_admin','synthetic-hash','Admin Uji','admin'),(2,'fixture_teacher','synthetic-hash','Guru Uji','ustadz');
INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(1,'Kelompok Uji',2);
INSERT INTO wali_santri(id,nama_bapak) VALUES(1,'Wali Uji');
INSERT INTO santri_detail(id,wali_santri_id,nama_anak,kelas) VALUES(1,1,'Anak Uji','1A');
INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(1,1);
INSERT INTO presensi(halaqoh_id,wali_santri_id,tanggal,status,alasan) VALUES(1,1,'2026-10-18','I','Catatan awal');
CREATE TABLE backup_probe(id INT PRIMARY KEY,nullable_text LONGTEXT,literal_text LONGTEXT,binary_value LONGBLOB) ENGINE=InnoDB;
CREATE TABLE legacy_probe(id INT PRIMARY KEY,value TEXT) ENGINE=InnoDB;
CREATE TABLE composite_probe(a INT,b INT,value TEXT,PRIMARY KEY(a,b)) ENGINE=InnoDB;
CREATE TABLE composite_child(id INT PRIMARY KEY,a INT,b INT,FOREIGN KEY(a,b) REFERENCES composite_probe(a,b)) ENGINE=InnoDB;
CREATE TABLE generated_probe(id INT PRIMARY KEY,value INT,doubled INT AS (value*2) STORED) ENGINE=InnoDB;
CREATE TABLE auto_probe(id INT AUTO_INCREMENT PRIMARY KEY,value TEXT) ENGINE=InnoDB;
SET @fixture_mode=@@SESSION.sql_mode; SET SESSION sql_mode=CONCAT(@@SESSION.sql_mode,',NO_AUTO_VALUE_ON_ZERO');
INSERT INTO auto_probe(id,value) VALUES(0,'Explicit zero identifier'); SET SESSION sql_mode=@fixture_mode;
INSERT INTO generated_probe(id,value) VALUES(1,5);
INSERT INTO composite_probe VALUES(1,2,'Original'); INSERT INTO composite_child VALUES(1,1,2);");
tahsinApplyMigration($pdo);
autosaveApplyMigration($pdo);
familyApplyMigration($pdo);
participantImportApplyMigration($pdo);
participantApplyMigration($pdo);
participantMergeApplyMigration($pdo);
$largeJournalRows=[];
for ($index=0;$index<1500;$index++) $largeJournalRows[]=['nama_bapak'=>'Keluarga '.($index%500),'no_hp'=>'08123456789','alamat'=>str_repeat('x',1024),'kategori'=>'reguler','nama_anak'=>'Anak '.$index,'kelas'=>'1A','tempat_tahsin'=>'','ustadz_luar'=>''];
$largeJournalJson=json_encode($largeJournalRows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$pdo->prepare('INSERT INTO participant_import_batches(id,user_id,actor_name,source_name,source_sha256,request_hash,source_rows_json,changes_json,summary_json) VALUES(?,?,?,?,?,?,?,?,?)')->execute([str_repeat('a',32),1,'Admin Uji','large-fixture.csv',str_repeat('b',64),str_repeat('c',64),$largeJournalJson,'[]','{"fixture":true}']);
unset($largeJournalRows,$largeJournalJson);
$pdo->exec("INSERT INTO settings(setting_key,setting_value) VALUES('rapor_template','<p>{{nama_wali}}</p>');
INSERT INTO holidays(tanggal,keterangan) VALUES('2026-10-25','Libur fixture');
INSERT INTO pengumuman(judul,isi,is_aktif) VALUES('Pengumuman fixture','Isi dengan kutip dan baris baru',1);
INSERT INTO activity_logs(user_id,username,action,description,ip_address) VALUES(1,'fixture_admin','FIXTURE','Data sintetis','127.0.0.1');
INSERT INTO login_attempts(ip_address,username) VALUES('127.0.0.1','fixture_login');
INSERT INTO wali_santri(id,nama_bapak) VALUES(2,'Ibu Uji');
INSERT INTO family_students(id,source_namespace,source_student_id,nis,nama_anak,kelas,school_status,snapshot_date) VALUES(1,'fixture','student-1','001','Anak Uji','1A','active','2026-10-18');
INSERT INTO family_guardians(id,source_namespace,source_parent_id,wali_santri_id,guardian_type,source_name,source_phone,source_address,source_active,account_active,snapshot_date) VALUES
(1,'fixture','father-1',1,'father','Wali Uji','','Alamat fixture',1,1,'2026-10-18'),(2,'fixture','mother-1',2,'mother','Ibu Uji','','Alamat fixture',1,1,'2026-10-18');
INSERT INTO family_relations(guardian_id,student_id,relationship,is_primary,is_current) VALUES(1,1,'father',1,1),(2,1,'mother',0,1);
INSERT INTO family_legacy_children(santri_detail_id,student_id) VALUES(1,1);
INSERT INTO family_import_state(source_namespace,snapshot_date,package_hash) VALUES('fixture','2026-10-18',REPEAT('a',64));
INSERT INTO tahsin_verifications(wali_santri_id,session_date,fingerprint,selected_presensi_id,decided_by,note) VALUES(1,'2026-10-18',REPEAT('b',64),1,1,'Keputusan fixture');
INSERT INTO tahsin_capaian_awal(wali_santri_id,jenis_materi,jilid,halaman,updated_by) VALUES(1,'Iqro',2,'1-2',1);
INSERT INTO tahsin_autosave_drafts(user_id,halaqoh_id,session_date,wali_id,revision,payload_json,official_revision,context_revision,state) VALUES(2,1,'2026-10-18',1,1,'{\"status\":\"H\",\"jenis_materi\":\"Iqro\",\"jilid\":\"2\"}',REPEAT('c',64),REPEAT('d',64),'draft');
INSERT INTO tahsin_autosave_operations(user_id,operation_id,request_hash,response_json) VALUES(2,'fixture-operation',REPEAT('e',64),'{\"ok\":true}');
INSERT INTO tahsin_autosave_revisions(wali_id,session_date,revision) VALUES(1,'2026-10-18',3);
INSERT INTO tahsin_autosave_sessions(halaqoh_id,session_date,completed_by,completed_at,fingerprint) VALUES(1,'2026-10-11',2,'2026-10-11 10:00:00',REPEAT('f',64));");
participantApply($pdo,1,'save',1,['kategori'=>'reguler','tempat_tahsin'=>'','ustadz_luar'=>''],participantFingerprint(participantSnapshot($pdo,1)),bin2hex(random_bytes(16)));
$pdo->exec("INSERT INTO wali_santri(id,nama_bapak) VALUES(3,'Wali gabungan'),(4,'Wali sumber'); INSERT INTO santri_detail(wali_santri_id,nama_anak,kelas) VALUES(4,'Anak gabungan','1A')");
$mergeFixture=participantMergePlan($pdo,3,4);
participantMergeApply($pdo,1,$mergeFixture,['confirmed'=>'1'],bin2hex(random_bytes(16)));
$insert = $pdo->prepare('INSERT INTO backup_probe VALUES(?,?,?,?)');
foreach ([null,'','\\N','\\\\N','\\','trailing \\','quote \\" test',"baris pertama\nbaris kedua\r\nأحمد, \"Uji\"",'0'] as $index=>$value) $insert->execute([$index+1,$value,$value,"\0\xff\"binary,\n\\"]);
$pdo->prepare('INSERT INTO legacy_probe VALUES(?,?)')->execute([1,'legacy \\" quote']);
$directory = __DIR__ . '/../.sessions/audit-20261023/backup-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($directory,0700,true);
$checks = 0;
function bkCheck(bool $condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException($message . ' ' . ($GLOBALS['backup_test_last_error'] ?? '')); $checks++; }
function bkSnapshot(PDO $connection): array {
    $result=[];
    foreach (discoverBackupTables($connection) as $table) {
        $rows=$connection->query("SELECT * FROM `$table`" . tableOrderClause($connection,$table,tableColumns($connection,$table)))->fetchAll(PDO::FETCH_ASSOC);
        $result[$table]=['rows'=>count($rows),'sha256'=>hash('sha256',serialize($rows))];
    }
    return $result;
}
function bkCsv(array $rows,int $version=3): string {
    $stream=fopen('php://temp','w+');
    foreach ($rows as $row) fputcsv($stream,$row,',','"',$version<3?'\\':'');
    rewind($stream); $csv=stream_get_contents($stream); fclose($stream); return $csv;
}
function bkCase(string $name,callable $mutate): string {
    global $directory;
    $path=$directory.'/'.$name.'.zip'; copy($directory.'/original.zip',$path);
    $zip=new ZipArchive(); if ($zip->open($path)!==true) throw new RuntimeException('Fixture ZIP unavailable');
    $manifest=json_decode($zip->getFromName('manifest.json'),true,512,JSON_THROW_ON_ERROR);
    $mutate($zip,$manifest);
    $zip->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR)); $zip->close(); return $path;
}
function bkReplace(ZipArchive $zip,array &$manifest,string $table,string $csv,int $count): void {
    $zip->addFromString($table.'.csv',$csv); $manifest['tables'][$table]=$count; $manifest['checksums'][$table]=hash('sha256',$csv);
}
$tables=discoverBackupTables($pdo);
$before=bkSnapshot($pdo);
$recoveryBaseline=$before;
bkCheck(count(array_filter($before,static fn($table)=>$table['rows']===0))===0,'Every base table has synthetic content, including family links, drafts, revisions, decisions and logs');
$export=createBackupZip($pdo,$directory.'/original.zip',$tables,'\\N');
bkCheck($export['ok'],'Full backup export succeeds');
$originalArchiveHash=hash_file('sha256',$directory.'/original.zip');
bkCheck(!createBackupZip($pdo,$directory.'/original.zip',$tables,'\\N')['ok'],'Export refuses to overwrite an existing backup archive');
bkCheck(hash_file('sha256',$directory.'/original.zip')===$originalArchiveHash,'Rejected overwrite keeps the previous archive byte-for-byte intact');
bkCheck(bkSnapshot($pdo)===$before,'Export preserves every source table');
$preview=analyzeRestoreZip($pdo,$directory.'/original.zip',$tables,'\\N');
bkCheck($preview['error']===null && !$preview['legacy'],'New backup passes strict preview');
bkCheck(count($export['counts'])===count($tables),'Every base table is included');
$restoredDatabase=$database.'_restored';
$pdo->exec("CREATE DATABASE `$restoredDatabase` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$target=new PDO('mysql:host=127.0.0.1;port=13317;dbname='.$restoredDatabase.';charset=utf8mb4','root','tahsin_local_test',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach ($tables as $table) $target->exec($pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC)['Create Table']);
$restore=executeMergeRestore($target,$directory.'/original.zip',discoverBackupTables($target),'\\N');
bkCheck($restore['error']===null,'Fresh separate database recovery succeeds');
bkCheck(bkSnapshot($target)===$before,'All source rows, binary values, NULL, literal backslashes, quotes, Unicode and relations recover exactly');
$freshRecovery=bkSnapshot($target);
$repeat=executeMergeRestore($target,$directory.'/original.zip',$tables,'\\N');
bkCheck($repeat['error']===null && array_sum($repeat['counts'])===0,'Repeated merge is idempotent');
bkCheck(bkSnapshot($target)===$before,'Repeated merge preserves every existing row');
tahsinApplyMigration($target); autosaveApplyMigration($target); familyApplyMigration($target); participantImportApplyMigration($target); participantApplyMigration($target); participantMergeApplyMigration($target);
bkCheck(bkSnapshot($target)===$before,'Reinstalling views and triggers after fresh recovery preserves table contents');
bkCheck($target->query('SELECT * FROM family_children ORDER BY wali_santri_id,nama_anak')->fetchAll(PDO::FETCH_ASSOC)===$pdo->query('SELECT * FROM family_children ORDER BY wali_santri_id,nama_anak')->fetchAll(PDO::FETCH_ASSOC),'Recovered family view retains shared father/mother child links without duplication');
$revivedAlias=bkCase('revived_merged_participant',function($z,&$m){ bkReplace($z,$m,'wali_santri',bkCsv([['id','nama_bapak'],[4,'Old source resurrected']]),1); });
bkCheck(executeMergeRestore($target,$revivedAlias,$tables,'\\N')['error']!==null,'A legacy backup cannot resurrect a merged participant ID');
bkCheck(bkSnapshot($target)===$before,'Rejected source resurrection rolls back every insert');
$aliasOperation=$pdo->query('SELECT operation_id FROM participant_merge_aliases WHERE source_id=4')->fetchColumn();
$liveAlias=bkCase('live_participant_alias',function($z,&$m)use($aliasOperation){ bkReplace($z,$m,'participant_merge_aliases',bkCsv([['source_id','target_id','operation_id'],[1,3,$aliasOperation]]),1); });
bkCheck(executeMergeRestore($target,$liveAlias,$tables,'\\N')['error']!==null,'A restored alias cannot redirect a live participant identity');
bkCheck(bkSnapshot($target)===$before,'Rejected live identity alias preserves every table');
$cases=[
    'bad_checksum'=>function($z,&$m){$m['checksums']['backup_probe']=str_repeat('0',64);},
    'missing_checksum'=>function($z,&$m){unset($m['checksums']['backup_probe']);},
    'bad_count'=>function($z,&$m){$m['tables']['backup_probe']++;},
    'missing_csv'=>function($z,&$m){$z->deleteName('backup_probe.csv');},
    'unknown_table'=>function($z,&$m){bkReplace($z,$m,'unknown_table',"id\n1\n",1);},
    'duplicate_header'=>function($z,&$m){bkReplace($z,$m,'legacy_probe',"id,id\n1,1\n",1);},
    'unknown_column'=>function($z,&$m){bkReplace($z,$m,'legacy_probe',"id,unknown\n1,x\n",1);},
    'missing_pk'=>function($z,&$m){bkReplace($z,$m,'legacy_probe',"value\nx\n",1);},
    'missing_required'=>function($z,&$m){bkReplace($z,$m,'wali_santri',"id\n1\n",1);},
    'row_width'=>function($z,&$m){bkReplace($z,$m,'legacy_probe',"id,value\n1,x,extra\n",1);},
    'unclosed_quote'=>function($z,&$m){bkReplace($z,$m,'legacy_probe',"id,value\n1,\"unfinished\n",1);},
    'quote_in_plain'=>function($z,&$m){bkReplace($z,$m,'legacy_probe',"id,value\n1,un\"quoted\n",1);},
    'oversize_row'=>function($z,&$m){bkReplace($z,$m,'legacy_probe',"id,value\n1,".str_repeat('x',BACKUP_MAX_ROW_BYTES+1)."\n",1);},
    'traversal'=>function($z,&$m){$z->addFromString('../escape.csv','bad');},
    'unlisted_csv'=>function($z,&$m){$z->addFromString('unlisted.csv','bad');},
    'bad_version'=>function($z,&$m){$m['version']=99;},
    'bad_encoding'=>function($z,&$m){$m['value_encoding']='unknown';},
    'bad_app'=>function($z,&$m){$m['app']='other-app';},
];
foreach ($cases as $name=>$mutate) {
    $path=bkCase($name,$mutate);
    bkCheck(analyzeRestoreZip($target,$path,$tables,'\\N')['error']!==null,'Preview rejects '.$name);
    bkCheck(executeMergeRestore($target,$path,$tables,'\\N')['error']!==null,'Execution revalidates '.$name.' even without preview');
    bkCheck(bkSnapshot($target)===$before,'Rejected '.$name.' preserves all data');
}
$fkFailure=bkCase('foreign_key_failure',function($z,&$m){
    $csv=$z->getFromName('backup_probe.csv');
    bkReplace($z,$m,'backup_probe',$csv.bkCsv([[99,'new','new','new']]),$m['tables']['backup_probe']+1);
    $csv=$z->getFromName('santri_detail.csv');
    $stream=fopen('php://temp','w+'); fwrite($stream,$csv); rewind($stream); $rows=iterator_to_array(backupCsvRows($stream,3)); fclose($stream);
    $record=array_fill_keys($rows[0],'\\N'); $record['id']='999'; $record['wali_santri_id']='99999'; $record['nama_anak']='Invalid reference'; $record['kelas']='1A';
    bkReplace($z,$m,'santri_detail',$csv.bkCsv([array_values($record)]),$m['tables']['santri_detail']+1);
});
bkCheck(executeMergeRestore($target,$fkFailure,$tables,'\\N')['error']!==null,'Unresolved foreign key aborts the transaction');
bkCheck(bkSnapshot($target)===$before,'Earlier inserts roll back when a later child FK fails');
$identityCollision=bkCase('identity_collision',function($z,&$m){
    $csv=$z->getFromName('wali_santri.csv');
    $csv=str_replace('Wali Uji','Different Person',$csv);
    bkReplace($z,$m,'wali_santri',$csv,$m['tables']['wali_santri']);
    $csv=$z->getFromName('santri_detail.csv');
    $stream=fopen('php://temp','w+'); fwrite($stream,$csv); rewind($stream); $rows=iterator_to_array(backupCsvRows($stream,3)); fclose($stream);
    $record=array_fill_keys($rows[0],'\\N'); $record['id']='999'; $record['wali_santri_id']='1'; $record['nama_anak']='Different family child'; $record['kelas']='1A';
    bkReplace($z,$m,'santri_detail',$csv.bkCsv([array_values($record)]),$m['tables']['santri_detail']+1);
});
bkCheck(str_contains(executeMergeRestore($target,$identityCollision,$tables,'\\N')['error'] ?? '', 'ID induk berbeda'),'New children cannot attach to a different existing identity sharing the same ID');
bkCheck(bkSnapshot($target)===$before,'Identity collision leaves every row and relationship intact');
$paddedCollision=$directory.'/padded_collision.zip'; copy($identityCollision,$paddedCollision);
$zip=new ZipArchive(); $zip->open($paddedCollision);
$manifest=json_decode($zip->getFromName('manifest.json'),true);
$csv=$zip->getFromName('santri_detail.csv'); $csv=str_replace('999,1,','999,0001,',$csv);
bkReplace($zip,$manifest,'santri_detail',$csv,$manifest['tables']['santri_detail']);
$zip->addFromString('manifest.json',json_encode($manifest)); $zip->close();
bkCheck(str_contains(executeMergeRestore($target,$paddedCollision,$tables,'\\N')['error'] ?? '', 'ID induk berbeda'),'Padded numeric foreign keys cannot bypass parent identity protection');
bkCheck(bkSnapshot($target)===$before,'Padded-key rejection preserves all data');
$truncation=bkCase('strict_truncation',function($z,&$m){
    $csv=$z->getFromName('wali_santri.csv');
    $stream=fopen('php://temp','w+'); fwrite($stream,$csv); rewind($stream); $rows=iterator_to_array(backupCsvRows($stream,3)); fclose($stream);
    $row=array_combine($rows[0],$rows[1]); $row['id']='888'; $row['nama_bapak']=str_repeat('x',101);
    bkReplace($z,$m,'wali_santri',$csv.bkCsv([array_values($row)]),$m['tables']['wali_santri']+1);
});
$savedMode=$target->query('SELECT @@SESSION.sql_mode')->fetchColumn(); $target->exec("SET SESSION sql_mode=''");
bkCheck(executeMergeRestore($target,$truncation,$tables,'\\N')['error']!==null,'Overlong values cannot be silently truncated even if the server normally uses permissive SQL mode');
bkCheck($target->query('SELECT @@SESSION.sql_mode')->fetchColumn()==='','Original connection SQL mode is restored after a failed merge');
$target->prepare('SET SESSION sql_mode=?')->execute([$savedMode]);
bkCheck(bkSnapshot($target)===$before,'Strict-value failure rolls back all partial writes');
$compositeCollision=bkCase('composite_collision',function($z,&$m){
    bkReplace($z,$m,'composite_probe',bkCsv([['a','b','value'],[1,2,'Different parent']]),1);
    bkReplace($z,$m,'composite_child',$z->getFromName('composite_child.csv').bkCsv([[2,1,2]]),2);
});
bkCheck(str_contains(executeMergeRestore($target,$compositeCollision,$tables,'\\N')['error'] ?? '', 'ID induk berbeda'),'Composite foreign keys also protect changed parent identities');
bkCheck(bkSnapshot($target)===$before,'Composite identity collision rolls back without changing data');
$generatedMismatch=bkCase('generated_mismatch',function($z,&$m){bkReplace($z,$m,'generated_probe',bkCsv([['id','value','doubled'],[1,5,10],[2,6,999]]),2);});
bkCheck(executeMergeRestore($target,$generatedMismatch,$tables,'\\N')['error']!==null,'Generated values must match the backup after computation');
bkCheck(bkSnapshot($target)===$before,'Generated-value mismatch rolls back the new row');
$newRelated=bkCase('new_related',function($z,&$m){
    $csv=$z->getFromName('composite_probe.csv'); bkReplace($z,$m,'composite_probe',$csv.bkCsv([[9,10,'New parent']]),$m['tables']['composite_probe']+1);
    $csv=$z->getFromName('composite_child.csv'); bkReplace($z,$m,'composite_child',$csv.bkCsv([[2,9,10]]),$m['tables']['composite_child']+1);
});
bkCheck(executeMergeRestore($target,$newRelated,$tables,'\\N')['error']===null,'New rows and composite foreign keys merge normally');
bkCheck((int)$target->query('SELECT COUNT(*) FROM composite_child WHERE id=2 AND a=9 AND b=10')->fetchColumn()===1,'New child retains its original parent relationship');
$afterRelated=bkSnapshot($target);
bkCheck($afterRelated['wali_santri']===$before['wali_santri'] && $afterRelated['presensi']===$before['presensi'],'Valid merge preserves existing participant and attendance contents');
$before=$afterRelated;
foreach ([1,2] as $version) {
    $path=$directory.'/legacy-'.$version.'.zip'; $zip=new ZipArchive(); $zip->open($path,ZipArchive::CREATE);
    $csv=bkCsv([['id','value'],[1,'legacy \\" quote']],$version);
    $manifest=['app'=>'tahsin-walsan','version'=>$version,'tables'=>['legacy_probe'=>1]];
    if ($version===2) $manifest['checksums']=['legacy_probe'=>hash('sha256',$csv)];
    $zip->addFromString('legacy_probe.csv',$csv); $zip->addFromString('manifest.json',json_encode($manifest)); $zip->close();
    $preview=analyzeRestoreZip($target,$path,$tables,'\\N');
    bkCheck($preview['error']===null && $preview['legacy'],'Legacy format '.$version.' is recognized with an explicit notice');
    bkCheck(executeMergeRestore($target,$path,$tables,'\\N')['error']===null,'Legacy format '.$version.' remains usable');
    bkCheck(bkSnapshot($target)===$before,'Legacy format '.$version.' preserves existing data');
}
putenv('BACKUP_MAX_EXPANDED_MB=1');
$bomb=bkCase('expanded_limit',function($z,&$m){$z->addFromString('schema.sql',str_repeat('x',2*1024*1024));});
bkCheck(analyzeRestoreZip($target,$bomb,$tables,'\\N')['error']!==null,'Compressed ZIP cannot bypass expanded-size limit');
putenv('BACKUP_MAX_EXPANDED_MB');
$target->exec('CREATE TABLE non_atomic_probe(id INT PRIMARY KEY) ENGINE=MyISAM');
bkCheck(str_contains(analyzeRestoreZip($target,$directory.'/original.zip',discoverBackupTables($target),'\\N')['error'] ?? '', 'atomik'),'Nontransactional storage is rejected before promising rollback');
$target->exec('DROP TABLE non_atomic_probe');
$pdo->exec('CREATE TABLE no_key_probe(value TEXT) ENGINE=InnoDB; INSERT INTO no_key_probe VALUES("Do not duplicate")');
$target->exec('CREATE TABLE no_key_probe(value TEXT) ENGINE=InnoDB');
$noKeyTables=discoverBackupTables($pdo);
bkCheck(createBackupZip($pdo,$directory.'/no-key.zip',$noKeyTables,'\\N')['ok'],'Tables without primary keys are still included in the archive');
$noKey=executeMergeRestore($target,$directory.'/no-key.zip',discoverBackupTables($target),'\\N');
bkCheck($noKey['error']===null && isset($noKey['skipped']['no_key_probe']),'Merge reports an explicit skip for tables without primary keys');
bkCheck((int)$target->query('SELECT COUNT(*) FROM no_key_probe')->fetchColumn()===0,'No-key table is not silently duplicated during merge');
$before['no_key_probe']=bkSnapshot($target)['no_key_probe'];
bkCheck((int)$target->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn()===1,'Foreign key checks stay enabled throughout recovery');
bkCheck(!$target->inTransaction() && !$pdo->inTransaction(),'No transaction is left open');
$previousStorage=getenv('BACKUP_STORAGE_PATH');
putenv('BACKUP_STORAGE_PATH='.$directory.'/configured-storage');
bkCheck(is_dir(resolveTempDir()),'A configured private backup folder can be created');
file_put_contents($directory.'/not-a-directory','fixture');
putenv('BACKUP_STORAGE_PATH='.$directory.'/not-a-directory');
try { resolveTempDir(); throw new LogicException('Unavailable persistent storage silently fell back'); }
catch (RuntimeException $error) { bkCheck(true,'Unavailable configured storage fails clearly instead of falling back to ephemeral storage'); }
if ($previousStorage===false) putenv('BACKUP_STORAGE_PATH'); else putenv('BACKUP_STORAGE_PATH='.$previousStorage);
file_put_contents($directory.'/results.json',json_encode(['passed'=>true,'checks'=>$checks,'source'=>$database,'restored'=>$restoredDatabase,'php'=>PHP_VERSION,'peak_memory_bytes'=>memory_get_peak_usage(true),'tables'=>count($tables),'source_baseline'=>$recoveryBaseline,'fresh_recovery'=>$freshRecovery,'final'=>bkSnapshot($target)],JSON_PRETTY_PRINT));
echo "PASS $checks backup/recovery assertions\nBACKUP_EVIDENCE=$directory\n";
