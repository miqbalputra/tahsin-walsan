<?php
if (PHP_SAPI !== 'cli') exit;
putenv('DATA_INDUK_ENABLED=0');
require_once __DIR__ . '/../includes/achievement_helper.php';
require_once __DIR__ . '/../includes/achievement_history_view.php';
require_once __DIR__ . '/../includes/backup_helper.php';
foreach (['tahsin', 'autosave', 'family'] as $migration) require_once __DIR__ . '/../includes/' . $migration . '_migration_helper.php';
$database = 'tahsin_test_achievement_' . getmypid() . '_' . bin2hex(random_bytes(4));
$pdo = new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4', 'root', 'tahsin_local_test', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `$database`; SET time_zone='+07:00'");
$pdo->exec(file_get_contents(__DIR__ . '/fixtures/tahsin-schema.sql'));
$pdo->exec("INSERT INTO users(id,username,password,nama_lengkap,role) VALUES(1,'admin','synthetic','Admin Uji','admin'),(2,'pj','synthetic','PJ Uji','pj_tahfidz'),(3,'teacher','synthetic','Guru Uji','ustadz'),(4,'other','synthetic','Guru Lain','ustadz');
INSERT INTO wali_santri(id,nama_bapak,no_hp) VALUES(1,'Wali Lama','08111111111'),(2,'Capaian Awal','08222222222'),(3,'Catatan Nol',NULL);
INSERT INTO santri_detail(id,wali_santri_id,nama_anak,kelas) VALUES(1,1,'Anak Lama','Mustawa 3');
INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(1,'Kelompok Lama',3),(2,'Kelompok Lain',4);
INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(1,1),(1,2),(1,3);
ALTER TABLE presensi ADD extra_bytes VARBINARY(50), ADD extra_number DECIMAL(20,4), ADD extra_generated INT GENERATED ALWAYS AS (OCTET_LENGTH(alasan)) STORED;
INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status,alasan,jenis_materi,jilid,nama_surat,halaman,hasil_talaqqi,extra_bytes,extra_number) VALUES
(1,1,1,'2026-10-11','H','Catatan  ','Iqro',2,'Sisa lama  ',' 1-2 ','Lulus',UNHEX('00FF5C0041'),12345678901234.5678),
(2,1,1,'2026-10-17','H',NULL,'Iqro',2,NULL,'1-2','Lulus',NULL,NULL),
(3,2,1,'2026-10-25','H','Lain','Al Quran',NULL,'Surat Lain','10-11','Ulang',NULL,NULL);");
tahsinApplyMigration($pdo); autosaveApplyMigration($pdo); familyApplyMigration($pdo);
$pdo->exec("ALTER TABLE tahsin_capaian_awal ADD extra_bytes VARBINARY(50);
INSERT INTO tahsin_autosave_drafts(user_id,halaqoh_id,session_date,wali_id,payload_json,official_revision,context_revision) VALUES(3,1,'2026-10-11',1,'{\"status\":\"H\"}','official','context');");
$checks=0;
function acCheck(bool $value, string $message): void { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
function acDigest(PDO $pdo): string {
    $rows=[]; foreach (discoverBackupTables($pdo) as $table) $rows[$table]=$pdo->query("SELECT * FROM `$table`" . tableOrderClause($pdo,$table,tableColumns($pdo,$table)))->fetchAll();
    return hash('sha256',serialize($rows));
}
function acApply(PDO $pdo, array $context, array $overrides=[], ?string $operation=null, int $actor=1): array {
    $previous=$context['mode']==='attendance'?$context['selected']:$context['baseline'];
    $input=['jenis_materi'=>'Iqro','jilid'=>'2','nama_surat'=>'','halaman'=>'3-4','hasil_talaqqi'=>$context['mode']==='attendance'?'Lulus':''];
    if ($previous) foreach (array_keys($input) as $field) $input[$field]=(string)($previous[$field]??'');
    return achievementApply($pdo,$actor,$context['mode'],$context['waliId'],$context['recordId'],array_replace($input,$overrides),$context['fingerprint'],$operation??bin2hex(random_bytes(16)));
}
function acReject(PDO $pdo, callable $action, string $message): void {
    $before=acDigest($pdo);
    try { $action(); } catch (AchievementException|AttendanceHistoryException|ParticipantManagementException|InvalidArgumentException $error) { acCheck(acDigest($pdo)===$before,$message); return; }
    throw new LogicException('Expected refusal: '.$message);
}
$context=achievementReadContext($pdo,'attendance',1,1);
acReject($pdo,fn()=>acApply($pdo,$context,['halaman'=>'3-4']),'Missing participant journal blocks changes without writes');
participantApplyMigration($pdo);
acReject($pdo,fn()=>acApply($pdo,$context,['halaman'=>'3-4']),'Missing attendance history blocks attendance edits');
acApply($pdo,achievementReadContext($pdo,'initial',2,null),['halaman'=>'7-8']);
acCheck((int)$pdo->query('SELECT COUNT(*) FROM presensi')->fetchColumn()===3,'Initial achievement needs no fake attendance or attendance-history installation');
$original=$pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll();
attendanceHistoryApplyMigration($pdo);
acCheck($original===$pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll(),'History setup preserves all original attendance bytes');
acReject($pdo,fn()=>acApply($pdo,$context,[],null,3),'Teacher cannot use the administrative mutation service');
$unchanged=[]; foreach (['wali_santri','santri_detail','halaqoh','halaqoh_members','tahsin_autosave_drafts','tahsin_capaian_awal'] as $table) $unchanged[$table]=$pdo->query("SELECT * FROM `$table` ORDER BY 1")->fetchAll();
$operation=bin2hex(random_bytes(16)); $mode=$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
$result=acApply($pdo,$context,['halaman'=>'3-4'],$operation,2);
$after=$pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll();
$expected=$original; $expected[0]['halaman']='3-4'; $expected[1]['halaman']='3-4';
acCheck($after===$expected,'Only requested material changes on identical same-session copies; dates, IDs, NULL, legacy text, binary, decimal, generated and foreign-session values survive');
foreach ($unchanged as $table=>$rows) acCheck($pdo->query("SELECT * FROM `$table` ORDER BY 1")->fetchAll()===$rows,'Achievement edit preserves '.$table);
$history=$pdo->query('SELECT * FROM presensi_history ORDER BY id')->fetchAll();
acCheck(count($history)===2 && (int)$history[0]['actor_id']===2 && attendanceHistoryDecode($history[0]['before_json'])['extra_bytes']===hex2bin('00FF5C0041'),'Every edited copy has its original full row and PJ identity in attendance history');
$journal=$pdo->query("SELECT * FROM participant_changes WHERE action='achievement'")->fetch();
$beforePack=achievementHistorySnapshot(json_decode($journal['before_json'],true)); $afterPack=achievementHistorySnapshot(json_decode($journal['after_json'],true));
acCheck($beforePack['records'][0]['halaman']===' 1-2 ' && $afterPack['records'][0]['halaman']==='3-4' && $beforePack['records'][0]['extra_bytes']===hex2bin('00FF5C0041'),'Participant journal decodes complete binary-safe old and new session snapshots');
$digest=acDigest($pdo);
acCheck(acApply($pdo,$context,['halaman'=>'3-4'],$operation,2)===$result && acDigest($pdo)===$digest,'A repeated operation returns its receipt without changes');
acReject($pdo,fn()=>acApply($pdo,$context,['halaman'=>'5-6'],$operation,2),'Receipt cannot be reused for different input');
acReject($pdo,fn()=>acApply($pdo,$context,['halaman'=>'5-6']),'A stale source cannot overwrite newer material');
$fresh=achievementReadContext($pdo,'attendance',1,1);
foreach ([['halaman'=>['nested']],['halaman'=>'6-2'],['halaman'=>'0'],['halaman'=>"1\x00"],['nama_surat'=>"\xff"],['halaman'=>str_repeat('1',21)],['jenis_materi'=>'invented'],['jilid'=>'7'],['jenis_materi'=>'Al Quran','nama_surat'=>''],['hasil_talaqqi'=>'']] as $invalid) acReject($pdo,fn()=>acApply($pdo,$fresh,$invalid),'Malformed material is rejected without writes: '.bin2hex(serialize($invalid)));
acReject($pdo,fn()=>achievementReadContext($pdo,'attendance',2,1),'A forged participant/source pair cannot open the editor');
$pdo->exec("UPDATE presensi SET halaman='9-10' WHERE id=2");
acReject($pdo,fn()=>achievementReadContext($pdo,'attendance',1,1),'Different unverified session copies must be reviewed before editing');
$conflict=achievementContext($pdo,'attendance',1,1);
$pdo->prepare('INSERT INTO tahsin_verifications(wali_santri_id,session_date,fingerprint,selected_presensi_id,decided_by,note) VALUES(?,?,?,?,?,?)')->execute([1,'2026-10-11',tahsinFingerprint($conflict['records']),1,1,'Synthetic decision']);
$verified=achievementReadContext($pdo,'attendance',1,1); $decision=$verified['verification'];
$other=$pdo->query('SELECT * FROM presensi WHERE id=2')->fetch();
$edited=acApply($pdo,$verified,['halaman'=>'11-12']);
acCheck($edited['requires_review'] && $pdo->query('SELECT * FROM presensi WHERE id=2')->fetch()===$other && $pdo->query('SELECT * FROM tahsin_verifications ORDER BY id DESC LIMIT 1')->fetch()===$decision,'Verified edits only change the selected source and retain the old decision for renewed review');
acReject($pdo,fn()=>achievementReadContext($pdo,'attendance',1,1),'A stale verification blocks subsequent editing');
$baseline=achievementReadContext($pdo,'initial',2,null);
$pdo->exec("UPDATE tahsin_capaian_awal SET extra_bytes=UNHEX('FF0041'),nama_surat='Unused  ',halaman=' 7-8 ' WHERE wali_santri_id=2");
acReject($pdo,fn()=>acApply($pdo,$baseline),'An old baseline form cannot overwrite a concurrent update');
$baseline=achievementReadContext($pdo,'initial',2,null); $beforeBaseline=$baseline['baseline'];
$op=bin2hex(random_bytes(16)); acApply($pdo,$baseline,['jilid'=>'4'],$op);
$actual=$pdo->query('SELECT * FROM tahsin_capaian_awal WHERE wali_santri_id=2')->fetch();
acCheck($actual['extra_bytes']===$beforeBaseline['extra_bytes'] && $actual['nama_surat']==='Unused  ' && $actual['halaman']===' 7-8 ' && (int)$actual['jilid']===4,'Baseline correction preserves raw unchanged text, unused fields and unknown binary columns');
$record=$pdo->query("SELECT * FROM participant_changes WHERE operation_id='$op'")->fetch();
$pack=achievementHistorySnapshot(json_decode($record['before_json'],true));
acCheck($pack['baseline']['extra_bytes']===hex2bin('FF0041') && $pack['baseline']['halaman']===' 7-8 ','Old baseline bytes remain available in the downloadable journal');
$digest=acDigest($pdo); acApply($pdo,$baseline,['jilid'=>'4'],$op);
acCheck(acDigest($pdo)===$digest,'Baseline replay preserves the original updated_at and every receipt');
$noBaseline=achievementReadContext($pdo,'initial',3,null); acApply($pdo,$noBaseline);
acReject($pdo,fn()=>acApply($pdo,$noBaseline,['halaman'=>'5-6']),'Concurrent creation of a formerly absent baseline rejects an old form');
$longLegacy=str_repeat('lama ',6);
$pdo->prepare('UPDATE tahsin_capaian_awal SET halaman=? WHERE wali_santri_id=3')->execute([$longLegacy]);
acApply($pdo,achievementReadContext($pdo,'initial',3,null),['jilid'=>'4']);
acCheck($pdo->query('SELECT halaman FROM tahsin_capaian_awal WHERE wali_santri_id=3')->fetchColumn()===$longLegacy,'An unrelated baseline correction preserves all 30 allowed characters of unchanged legacy text');
$unchangedBaseline=achievementReadContext($pdo,'initial',2,null); $oldTimestamp=$unchangedBaseline['baseline']['updated_at'];
acApply($pdo,$unchangedBaseline);
acCheck($pdo->query('SELECT updated_at FROM tahsin_capaian_awal WHERE wali_santri_id=2')->fetchColumn()===$oldTimestamp,'A no-op baseline save does not re-date existing information');
$pdo->exec("CREATE TRIGGER reject_achievement_journal BEFORE INSERT ON participant_changes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic journal failure'");
foreach ([achievementReadContext($pdo,'attendance',1,3),achievementReadContext($pdo,'initial',2,null)] as $candidate) {
    $digest=acDigest($pdo);
    try { acApply($pdo,$candidate,['halaman'=>'15-16']); throw new LogicException('Required journal failure was ignored'); }
    catch (PDOException $error) { acCheck(acDigest($pdo)===$digest,'Mandatory journal failure rolls back material, baseline, attendance history and autosave revisions'); }
}
$pdo->exec('DROP TRIGGER reject_achievement_journal');
$pdo->exec("SET @previous_mode=@@SESSION.sql_mode; SET SESSION sql_mode=CONCAT(@@SESSION.sql_mode,',NO_AUTO_VALUE_ON_ZERO'); INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status,jenis_materi,jilid,halaman,hasil_talaqqi) VALUES(0,1,3,'2026-10-18','H','Iqro',2,'1-2','Lulus'); SET SESSION sql_mode=@previous_mode");
acApply($pdo,achievementReadContext($pdo,'attendance',3,0),['halaman'=>'3-4']);
acCheck($pdo->query('SELECT halaman FROM presensi WHERE id=0')->fetchColumn()==='3-4','An existing explicit zero attendance identifier is retained during editing');
$pdo->exec("UPDATE presensi SET halaman='<svg/onload=a()>' WHERE id=0");
$legacy=achievementReadContext($pdo,'attendance',3,0); acApply($pdo,$legacy,['jilid'=>'3']);
acCheck($pdo->query('SELECT halaman FROM presensi WHERE id=0')->fetchColumn()==='<svg/onload=a()>','An unrelated correction preserves unchanged legacy nonnumeric material text');
$last=$pdo->query("SELECT * FROM participant_changes WHERE action='achievement' ORDER BY created_at DESC LIMIT 1")->fetch();
ob_start(); achievementHistoryRender(achievementHistorySnapshot(json_decode($last['before_json'],true)),'Capaian sebelum perubahan'); $html=ob_get_clean();
acCheck(!str_contains($html,'<svg/onload=a()>') && str_contains($html,'&lt;svg/onload=a()&gt;'),'Journal rendering escapes legacy material text');
$bad=json_decode($last['before_json'],true); $bad['achievement']['records']=[[]];
try { achievementHistorySnapshot($bad); throw new LogicException('Malformed snapshot accepted'); } catch (RuntimeException $error) { acCheck(!($error instanceof LogicException),'Malformed journal extensions fail before rendering'); }
$pdo->exec("UPDATE users SET role='ustadz' WHERE id=2");
acReject($pdo,fn()=>acApply($pdo,achievementReadContext($pdo,'initial',2,null),[],null,2),'A revoked PJ role is rechecked within the write transaction');
acCheck(!$pdo->inTransaction() && (int)$pdo->query("SELECT IS_FREE_LOCK('".participantWriteLockName($pdo)."')")->fetchColumn()===1 && $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn()===$mode,'All success and failure paths release the write lock and restore SQL mode');
acCheck((int)$pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn()===1,'Foreign key protection remains enabled');
echo "PASS $checks achievement/data preservation assertions\nSTAGING_DATABASE=$database\n";
