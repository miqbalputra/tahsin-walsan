<?php
if (PHP_SAPI!=='cli') exit;
putenv('DATA_INDUK_ENABLED=0');
require_once __DIR__ . '/../includes/participant_import_helper.php';
require_once __DIR__ . '/../includes/backup_helper.php';
foreach (['tahsin','autosave','family'] as $name) require_once __DIR__ . '/../includes/' . $name . '_migration_helper.php';
$database='tahsin_test_import_' . getmypid() . '_' . bin2hex(random_bytes(4));
$pdo=new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4','root','tahsin_local_test',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("SET time_zone = '+07:00'");
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `$database`");
$pdo->exec(file_get_contents(__DIR__.'/fixtures/tahsin-schema.sql'));
$pdo->exec("INSERT INTO users(id,username,password,nama_lengkap,role) VALUES(1,'import_admin','synthetic','Admin Impor','admin'),(2,'import_pj','synthetic','PJ Impor','pj_tahfidz'),(3,'import_teacher','synthetic','Guru Impor','ustadz');
INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(1,'Kelompok Impor',3);
INSERT INTO wali_santri(id,nama_bapak,no_hp,alamat,status_aktif) VALUES(1,'Wali Lama','08111111111','Alamat lama',1),(2,'Wali Arsip','08222222222','Alamat arsip',0);
INSERT INTO santri_detail(id,wali_santri_id,nama_anak,kelas) VALUES(1,1,'Anak Lama','1A'),(2,2,'Anak Arsip','2A');
INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(1,1);
INSERT INTO presensi(halaqoh_id,wali_santri_id,tanggal,status,alasan) VALUES(1,1,'2026-10-18','I','Riwayat tidak diubah');
CREATE TABLE activity_logs(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,username VARCHAR(50),action VARCHAR(100),description TEXT,ip_address VARCHAR(45),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
tahsinApplyMigration($pdo); autosaveApplyMigration($pdo); familyApplyMigration($pdo);
$checks=0;
function imCheck(bool $condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function imSnapshot(PDO $pdo,bool $journal=false): string {
    $data=[];
    foreach (discoverBackupTables($pdo) as $table) {
        if (!$journal && $table==='participant_import_batches') continue;
        $data[$table]=$pdo->query("SELECT * FROM `$table`" . tableOrderClause($pdo,$table,tableColumns($pdo,$table)))->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256',serialize($data));
}
function imCsv(array $rows,string $delimiter=',',bool $bom=false,array $header=PARTICIPANT_IMPORT_COLUMNS): string {
    $stream=fopen('php://temp','w+'); if ($bom) fwrite($stream,"\xef\xbb\xbf");
    fputcsv($stream,$header,$delimiter,'"',''); foreach ($rows as $row) fputcsv($stream,$row,$delimiter,'"','');
    rewind($stream); $value=stream_get_contents($stream); fclose($stream); return $value;
}
function imRead(string $csv): array { $stream=fopen('php://temp','w+'); fwrite($stream,$csv); rewind($stream); try { return participantImportRead($stream); } finally { fclose($stream); } }
function imPlan(PDO $pdo,string $csv): array { return participantImportPlan($pdo,imRead($csv),hash('sha256',$csv),'fixture.csv'); }
function imChoices(array $plan,string $classPolicy='preserve',string $profilePolicy='preserve'): array {
    return ['targets'=>array_map(static fn($group)=>$group['default_target'],$plan['groups']),'class_policy'=>$classPolicy,'profile_policy'=>$profilePolicy];
}
function imReject(callable $operation,string $message): void {
    try { $operation(); } catch (InvalidArgumentException $error) { imCheck(true,$message); return; }
    throw new RuntimeException($message);
}
$beforeMigration=imSnapshot($pdo);
participantImportApplyMigration($pdo); participantImportApplyMigration($pdo);
imCheck(imSnapshot($pdo)===$beforeMigration,'Additive import migration and retry preserve every existing domain table');
$csv=imCsv([['Wali Baru','08333333333','Alamat baru','reguler','Anak Baru Satu','1A','',''],['WALI BARU','+628333333333','Alamat baru','reguler','Anak Baru Dua','2A','','']]);
$before=imSnapshot($pdo,true); $plan=imPlan($pdo,$csv);
imCheck(count($plan['groups'])===1,'Case and Indonesian phone formatting group siblings consistently');
imCheck(imSnapshot($pdo,true)===$before,'Preview does not change any database table');
$choices=imChoices($plan); $batch=bin2hex(random_bytes(16));
$result=participantImportApply($pdo,1,$plan,$choices,$batch);
imCheck($result['created_wali']===1 && $result['created_children']===2,'One new parent and two children are imported');
$newParent=(int)$pdo->query("SELECT id FROM wali_santri WHERE nama_bapak='Wali Baru'")->fetchColumn();
imCheck((int)$pdo->query('SELECT COUNT(*) FROM santri_detail WHERE wali_santri_id='.$newParent)->fetchColumn()===2,'Siblings retain a shared parent ID');
$after=imSnapshot($pdo,true);
imCheck(participantImportApply($pdo,1,$plan,$choices,$batch)===$result,'Retry with the same operation returns its stored receipt');
imCheck(imSnapshot($pdo,true)===$after,'Receipt replay writes no duplicate data or journal rows');
$different=$choices; $different['profile_policy']='update';
imReject(fn()=>participantImportApply($pdo,1,$plan,$different,$batch),'A used operation ID cannot be reused for different choices');
$domainBeforeRepeat=imSnapshot($pdo);
$planAgain=imPlan($pdo,$csv); $again=participantImportApply($pdo,2,$planAgain,imChoices($planAgain),bin2hex(random_bytes(16)));
imCheck($again['created_wali']===0 && $again['created_children']===0 && $again['skipped_children']===2,'Repeating the same CSV in a new import does not duplicate children');
imCheck(imSnapshot($pdo)===$domainBeforeRepeat,'Repeating CSV preserves every operational table apart from its new journal receipt');
$archiveCsv=imCsv([['Wali Arsip','08222222222','Alamat arsip','reguler','Anak Arsip','2A','','']]);
$archiveBefore=imSnapshot($pdo); $archivePlan=imPlan($pdo,$archiveCsv);
participantImportApply($pdo,1,$archivePlan,imChoices($archivePlan),bin2hex(random_bytes(16)));
imCheck(imSnapshot($pdo)===$archiveBefore,'Unchanged repeated rows do not reactivate an intentionally archived participant');
$classCsv=imCsv([['Wali Lama','08111111111','Alamat CSV baru','reguler','Anak Lama','2A','','']]);
$classPlan=imPlan($pdo,$classCsv); $classBefore=imSnapshot($pdo);
$preserved=participantImportApply($pdo,1,$classPlan,imChoices($classPlan),bin2hex(random_bytes(16)));
imCheck($preserved['skipped_children']===1 && imSnapshot($pdo)===$classBefore,'Default class policy preserves existing class and profile');
$classPlan=imPlan($pdo,$classCsv); $updated=participantImportApply($pdo,1,$classPlan,imChoices($classPlan,'update'),bin2hex(random_bytes(16)));
imCheck($updated['updated_classes']===1 && $pdo->query('SELECT kelas FROM santri_detail WHERE id=1')->fetchColumn()==='2A','Explicit class update keeps the original child ID');
$journal=$pdo->prepare('SELECT changes_json FROM participant_import_batches WHERE id=?'); $journal->execute([$updated['batch_id']]); $journal=json_decode($journal->fetchColumn(),true);
imCheck($journal[0]['before']['children'][0]['kelas']==='1A' && $journal[0]['after_children'][0]['id']===1,'Old class and the unchanged child identity remain in the atomic journal');
imCheck($pdo->query('SELECT alasan FROM presensi WHERE id=1')->fetchColumn()==='Riwayat tidak diubah','Import does not rewrite existing attendance');
$profilePlan=imPlan($pdo,$classCsv); $profile=participantImportApply($pdo,1,$profilePlan,imChoices($profilePlan,'preserve','update'),bin2hex(random_bytes(16)));
imCheck($profile['updated_profiles']===1 && $pdo->query('SELECT alamat FROM wali_santri WHERE id=1')->fetchColumn()==='Alamat CSV baru','Explicit profile update is applied separately from the default preserve policy');
$unicodeCsv=imCsv([['محمد "Uji"','08444444444',str_repeat('Alamat ',300)."\nbaris kedua",'reguler','Anak, "A"','3A','','']],';',true);
$unicodeRows=imRead($unicodeCsv);
imCheck(strlen($unicodeRows[0]['alamat'])>1000 && $unicodeRows[0]['nama_anak']==='Anak, "A"','UTF-8 BOM, semicolon, quotes and multiline fields longer than the old 1000-byte limit parse intact');
$six=imRead(imCsv([['Enam Kolom','08555555555','','reguler','Anak Enam','1A']],',',false,array_slice(PARTICIPANT_IMPORT_COLUMNS,0,6)));
imCheck($six[0]['tempat_tahsin']==='' && count($six)===1,'Six-column legacy template remains supported');
$pdo->exec("UPDATE wali_santri SET tempat_tahsin='Masjid Lama',ustadz_luar='Guru Lama' WHERE id=1");
$optional=imPlan($pdo,imCsv([['Wali Lama','08111111111','Alamat enam kolom','reguler','Anak Lama','2A']],',',false,array_slice(PARTICIPANT_IMPORT_COLUMNS,0,6)));
participantImportApply($pdo,1,$optional,imChoices($optional,'preserve','update'),bin2hex(random_bytes(16)));
imCheck($pdo->query('SELECT tempat_tahsin FROM wali_santri WHERE id=1')->fetchColumn()==='Masjid Lama' && $pdo->query('SELECT ustadz_luar FROM wali_santri WHERE id=1')->fetchColumn()==='Guru Lama','Omitted optional columns do not clear existing external-study data during an explicit profile update');
$alumni=imPlan($pdo,imCsv([['Wali Alumni','08666666666','','reguler','Anak Alumni','Lulus','','']]));
$alumniResult=participantImportApply($pdo,1,$alumni,imChoices($alumni),bin2hex(random_bytes(16)));
imCheck($alumniResult['archived_wali']===1 && (int)$pdo->query("SELECT status_aktif FROM wali_santri WHERE nama_bapak='Wali Alumni'")->fetchColumn()===0,'New all-graduated families are archived without deleting children');
$invalid=[
    "wrong,header\nx,y\n", "nama_bapak,no_hp,alamat,kategori,nama_anak,kelas\nX,081,,invalid,Y,1A\n",
    "nama_bapak,no_hp,alamat,kategori,nama_anak,kelas\n,081,,reguler,Y,1A\n",
    "nama_bapak,no_hp,alamat,kategori,nama_anak,kelas\nX,081,,reguler,Y,1A,extra\n",
    "nama_bapak,no_hp,alamat,kategori,nama_anak,kelas\nX,081,,reguler,\"unfinished\n",
    imCsv([[str_repeat('x',101),'081','','reguler','Y','1A','','']]),
    imCsv([['X','081','','reguler','','1A','','']]),
    imCsv([['X','081','','0','Anak','1A','','']]),
    "nama_bapak,no_hp,alamat,kategori,nama_anak,kelas\nX,081,,reguler,invalid\xff,1A\n",
];
foreach ($invalid as $index=>$bad) {
    $before=imSnapshot($pdo,true); imReject(fn()=>imRead($bad),'Invalid CSV rejected: '.$index);
    imCheck(imSnapshot($pdo,true)===$before,'Invalid input leaves every table unchanged: '.$index);
}
$sameNames=imPlan($pdo,imCsv([['Wali Anak Senama','08000000001','','reguler','Anak Senama','1A','',''],['Wali Anak Senama','08000000001','','reguler','Anak Senama','2A','','']]));
$sameNamesResult=participantImportApply($pdo,1,$sameNames,imChoices($sameNames),bin2hex(random_bytes(16)));
imCheck($sameNamesResult['created_children']===2,'New same-named children in different CSV classes are not collapsed into one identity');
$sameNamesAgain=imPlan($pdo,imCsv([['Wali Anak Senama','08000000001','','reguler','Anak Senama','1A','',''],['Wali Anak Senama','08000000001','','reguler','Anak Senama','2A','','']]));
$sameNamesRepeated=participantImportApply($pdo,1,$sameNamesAgain,imChoices($sameNamesAgain),bin2hex(random_bytes(16)));
imCheck($sameNamesRepeated['created_children']===0 && $sameNamesRepeated['skipped_children']===2,'Same-named siblings remain idempotent on repeated CSV');
$conflictingClasses=imPlan($pdo,imCsv([['Wali Lama','08111111111','','reguler','Anak Lama','2A','',''],['Wali Lama','08111111111','','reguler','Anak Lama','3A','','']]));
$before=imSnapshot($pdo,true);
imReject(fn()=>participantImportApply($pdo,1,$conflictingClasses,imChoices($conflictingClasses,'update'),bin2hex(random_bytes(16))),'Two incoming classes cannot silently overwrite the same existing child in sequence');
imCheck(imSnapshot($pdo,true)===$before,'Conflicting class choices roll back all changes');
$twoGroupsCsv=imCsv([['Wali Lama','08111111111','','reguler','Anak Lama','2A','',''],['Wali Lama','08000000111','','reguler','Anak Lama','3A','','']]);
$twoGroups=imPlan($pdo,$twoGroupsCsv); $twoChoices=imChoices($twoGroups,'update');
foreach ($twoChoices['targets'] as &$value) $value='1'; unset($value);
$before=imSnapshot($pdo,true);
imReject(fn()=>participantImportApply($pdo,1,$twoGroups,$twoChoices,bin2hex(random_bytes(16))),'Two CSV groups mapped to one participant cannot overwrite a child with conflicting classes');
imCheck(imSnapshot($pdo,true)===$before,'Cross-group class conflict preserves every table');
$twoProfiles=imChoices($twoGroups,'preserve','update'); foreach ($twoProfiles['targets'] as &$value) $value='1'; unset($value);
imReject(fn()=>participantImportApply($pdo,1,$twoGroups,$twoProfiles,bin2hex(random_bytes(16))),'Conflicting profiles cannot be applied in last-row-wins order to the same participant');
$combined=imPlan($pdo,imCsv([['Wali Lama','08111111111','','reguler','Child Combined A','1A','',''],['Wali Lama','08000000111','','reguler','Child Combined B','1A','','']]));
$combinedChoices=imChoices($combined); foreach ($combinedChoices['targets'] as &$value) $value='1'; unset($value);
$combinedResult=participantImportApply($pdo,1,$combined,$combinedChoices,bin2hex(random_bytes(16)));
imCheck($combinedResult['reused_wali']===1 && $combinedResult['created_children']===2,'Explicitly mapping two groups to one existing participant combines children and counts the participant once');
$archiveUpdate=imPlan($pdo,imCsv([['Wali Arsip','08222222222','','reguler','Anak Arsip','3A','','']]));
participantImportApply($pdo,1,$archiveUpdate,imChoices($archiveUpdate,'update'),bin2hex(random_bytes(16)));
imCheck((int)$pdo->query('SELECT status_aktif FROM wali_santri WHERE id=2')->fetchColumn()===0,'Routine class progression does not undo an intentional participant archive');
$ambiguousCsv=imCsv([['Nama Sama','08777777777','','reguler','Anak Pilihan','1A','','']]);
$pdo->exec("INSERT INTO wali_santri(id,nama_bapak,no_hp) VALUES(80,'Nama Sama','08777777777'),(81,'Nama Sama','08777777777')");
$ambiguous=imPlan($pdo,$ambiguousCsv); $ambiguousChoices=imChoices($ambiguous);
imCheck(reset($ambiguousChoices['targets'])==='','Duplicate matching identities require a selection');
imReject(fn()=>participantImportApply($pdo,1,$ambiguous,$ambiguousChoices,bin2hex(random_bytes(16))),'Ambiguous identity is never chosen arbitrarily');
$ambiguousChoices['targets'][array_key_first($ambiguous['groups'])]='81';
participantImportApply($pdo,1,$ambiguous,$ambiguousChoices,bin2hex(random_bytes(16)));
imCheck((int)$pdo->query('SELECT COUNT(*) FROM santri_detail WHERE wali_santri_id=80')->fetchColumn()===0 && (int)$pdo->query('SELECT COUNT(*) FROM santri_detail WHERE wali_santri_id=81')->fetchColumn()===1,'Only the explicitly selected duplicate receives the imported child');
$stale=imPlan($pdo,$classCsv); $pdo->exec("UPDATE wali_santri SET alamat='Changed after preview' WHERE id=1"); $before=imSnapshot($pdo,true);
imReject(fn()=>participantImportApply($pdo,1,$stale,imChoices($stale),bin2hex(random_bytes(16))),'Changed data invalidates the preview');
imCheck(imSnapshot($pdo,true)===$before,'Stale preview does not overwrite concurrent edits');
putenv('DATA_INDUK_ENABLED=1');
imReject(fn()=>imPlan($pdo,$csv),'Data Induk mode blocks CSV even when credentials or schema are not yet configured');
putenv('DATA_INDUK_ENABLED=0');
$pdo->exec("INSERT INTO family_guardians(source_namespace,source_parent_id,wali_santri_id,guardian_type,source_name,source_phone,source_address,source_active,account_active,snapshot_date) VALUES('fixture','parent-1',1,'father','Wali Lama','08111111111','Sumber',1,1,'2026-10-18')");
$managed=imPlan($pdo,$classCsv); $managedChoices=imChoices($managed);
imCheck(reset($managedChoices['targets'])==='skip','Managed identity is visibly excluded by default');
$managedChoices['targets'][array_key_first($managed['groups'])]='1'; $before=imSnapshot($pdo,true);
imReject(fn()=>participantImportApply($pdo,1,$managed,$managedChoices,bin2hex(random_bytes(16))),'Managed family profile and children cannot be modified by a forged selection');
imCheck(imSnapshot($pdo,true)===$before,'Rejected managed import preserves all records');
$managedChoices['targets'][array_key_first($managed['groups'])]='new';
imReject(fn()=>participantImportApply($pdo,1,$managed,$managedChoices,bin2hex(random_bytes(16))),'Managed identity cannot be shadowed by a new local duplicate');
$mixed=imPlan($pdo,imCsv([['Wali Lama','08111111111','','reguler','Anak Lama','9A','',''],['Keluarga Lain','08888888888','','reguler','Anak Lain','1A','','']]));
$mixedResult=participantImportApply($pdo,1,$mixed,imChoices($mixed),bin2hex(random_bytes(16)));
imCheck($mixedResult['skipped_wali']===1 && $mixedResult['created_wali']===1,'Mixed file can skip managed identities and import unrelated local families');
$pdo->exec('DROP VIEW family_children');
$partial=imPlan($pdo,$classCsv); $partialChoices=imChoices($partial); $partialChoices['targets'][array_key_first($partial['groups'])]='1';
imReject(fn()=>participantImportApply($pdo,1,$partial,$partialChoices,bin2hex(random_bytes(16))),'Missing family view cannot bypass mapping protection');
familyApplyMigration($pdo);
$pdo->exec("ALTER TABLE wali_santri ADD data_induk_guardian_id VARCHAR(36) NULL; ALTER TABLE santri_detail ADD data_induk_student_id VARCHAR(36) NULL;
INSERT INTO wali_santri(id,nama_bapak,no_hp,data_induk_guardian_id) VALUES(90,'UUID Wali','08000000090','fixture-master-id');
INSERT INTO wali_santri(id,nama_bapak,no_hp) VALUES(91,'UUID Anak Wali','08000000091');
INSERT INTO santri_detail(wali_santri_id,nama_anak,kelas,data_induk_student_id) VALUES(91,'UUID Anak','1A','fixture-student-id');");
foreach ([['UUID Wali','08000000090','90'],['UUID Anak Wali','08000000091','91']] as [$name,$phone,$id]) {
    $uuidPlan=imPlan($pdo,imCsv([[$name,$phone,'','reguler','Anak UUID','2A','','']]));
    $uuidChoices=imChoices($uuidPlan); $uuidChoices['targets'][array_key_first($uuidPlan['groups'])]=$id;
    $before=imSnapshot($pdo,true);
    imReject(fn()=>participantImportApply($pdo,1,$uuidPlan,$uuidChoices,bin2hex(random_bytes(16))),'Stored central parent or child mapping blocks local modification even with integration disabled');
    imCheck(imSnapshot($pdo,true)===$before,'Central mapping rejection preserves all records');
}
$rollback=imPlan($pdo,imCsv([['Rollback Family','08999999999','','reguler','Rollback Child','1A','',''],['Wali Lama','08111111111','','reguler','Anak Lama','3A','','']]));
$rollbackChoices=imChoices($rollback); foreach ($rollback['groups'] as $key=>$group) if ($group['profile']['nama_bapak']==='Wali Lama') $rollbackChoices['targets'][$key]='1';
$before=imSnapshot($pdo,true);
imReject(fn()=>participantImportApply($pdo,1,$rollback,$rollbackChoices,bin2hex(random_bytes(16))),'Later failure rejects the entire batch');
imCheck(imSnapshot($pdo,true)===$before,'Earlier parent and child inserts roll back on a later managed-identity failure');
$permission=imPlan($pdo,$unicodeCsv); $before=imSnapshot($pdo,true);
imReject(fn()=>participantImportApply($pdo,3,$permission,imChoices($permission),bin2hex(random_bytes(16))),'Teacher role cannot invoke import service');
imCheck(imSnapshot($pdo,true)===$before && !$pdo->inTransaction(),'Rejected service call preserves every table and closes its transaction');
$failureCsv=imCsv([['Failure Wali','08000000100','','reguler','First Child','1A','',''],['Failure Wali','08000000100','','reguler','TriggerFail','2A','','']]);
$failurePlan=imPlan($pdo,$failureCsv);
$pdo->exec("CREATE TRIGGER fixture_import_failure BEFORE INSERT ON santri_detail FOR EACH ROW BEGIN IF NEW.nama_anak='TriggerFail' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic insert failure'; END IF; END");
$originalMode=$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn(); $pdo->exec("SET SESSION sql_mode=''");
$before=imSnapshot($pdo,true); $caught=false;
try { participantImportApply($pdo,1,$failurePlan,imChoices($failurePlan),bin2hex(random_bytes(16))); }
catch (PDOException $expected) { $caught=true; }
imCheck($caught && imSnapshot($pdo,true)===$before,'A database failure after the first child rolls back parent, earlier child and journal writes');
imCheck($pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn()==='','Connection SQL mode is restored after a database failure');
$pdo->prepare('SET SESSION sql_mode=?')->execute([$originalMode]); $pdo->exec('DROP TRIGGER fixture_import_failure');
imCheck(!$pdo->inTransaction(),'Database-error path leaves no transaction open');
$largeRows=[]; $insert=$pdo->prepare('INSERT INTO wali_santri(id,nama_bapak,no_hp,alamat) VALUES(?,?,?,?)');
for ($index=0;$index<100;$index++) {
    $name='Journal Size '.$index; $phone='08900'.str_pad((string)$index,5,'0',STR_PAD_LEFT);
    $insert->execute([1000+$index,$name,$phone,str_repeat('x',65000)]);
    $largeRows[]=[$name,$phone,'','reguler','New Size Child '.$index,'1A','',''];
}
$largeCsv=imCsv($largeRows); $largePlan=imPlan($pdo,$largeCsv); $before=imSnapshot($pdo,true);
imReject(fn()=>participantImportApply($pdo,1,$largePlan,imChoices($largePlan),bin2hex(random_bytes(16))),'An oversized before/after journal stops the batch with a split-file instruction');
imCheck(imSnapshot($pdo,true)===$before,'Journal-size failure rolls back every inserted child and keeps all old addresses intact');
foreach ([array_slice($largeRows,0,50),array_slice($largeRows,50)] as $part) {
    $partPlan=imPlan($pdo,imCsv($part)); $partResult=participantImportApply($pdo,1,$partPlan,imChoices($partPlan),bin2hex(random_bytes(16)));
    imCheck($partResult['created_children']===50,'Splitting the large batch completes the intended import safely');
}
$unchangedBefore=imSnapshot($pdo); $repeatLarge=imPlan($pdo,$largeCsv);
$repeatLargeResult=participantImportApply($pdo,1,$repeatLarge,imChoices($repeatLarge),bin2hex(random_bytes(16)));
imCheck($repeatLargeResult['skipped_children']===100 && imSnapshot($pdo)===$unchangedBefore,'Unchanged repeated CSV does not copy large unchanged profiles or rewrite data');
$repeatJournal=$pdo->prepare('SELECT changes_json FROM participant_import_batches WHERE id=?'); $repeatJournal->execute([$repeatLargeResult['batch_id']]);
imCheck(strlen($repeatJournal->fetchColumn())<20000,'Unchanged rows use compact journal markers without losing source rows or changed-record history');
$evidence=__DIR__.'/../.sessions/audit-20261023/participant-import-'.getmypid().'-'.bin2hex(random_bytes(4)); mkdir($evidence,0700,true);
file_put_contents($evidence.'/results.json',json_encode(['passed'=>true,'checks'=>$checks,'database'=>$database,'php'=>PHP_VERSION,'peak_memory_bytes'=>memory_get_peak_usage(true),'journal_count'=>(int)$pdo->query('SELECT COUNT(*) FROM participant_import_batches')->fetchColumn(),'final_domain_sha256'=>imSnapshot($pdo)],JSON_PRETTY_PRINT));
echo "PASS $checks participant import assertions\nSTAGING_DATABASE=$database\n";
echo "IMPORT_EVIDENCE=$evidence\n";
