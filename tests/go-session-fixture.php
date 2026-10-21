<?php
/** Extends only a newly created local synthetic autosave fixture. */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/autosave_helper.php';
$database = $argv[1] ?? '';
if (!preg_match('/^tahsin_test_autosave_[a-z0-9_]+$/D', $database)) throw new RuntimeException('Synthetic fixture required.');
$port = (int)(getenv('TAHSIN_TEST_DB_PORT') ?: 13319);
if ($port !== 13319) throw new RuntimeException('Owned local port required.');
$pdo = new PDO('mysql:host=127.0.0.1;port=13319;dbname='.$database.';charset=utf8mb4', 'root', 'tahsin_local_test', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("SET time_zone='+07:00'");
if ($pdo->query('SELECT username FROM users WHERE id=1')->fetchColumn() !== 'admin_test') throw new RuntimeException('Wrong fixture.');
$now=tahsinNow(); $base=tahsinLatestSession($now->format('Y-m-d'));
$older=tahsinLatestSession((new DateTimeImmutable($base))->modify('-1 day')->format('Y-m-d'));
$legacy=(new DateTimeImmutable($base))->modify('-140 days')->format('Y-m-d');
$pdo->exec('SET timestamp='.(time()-90*86400));
$pdo->exec("INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id,program_code) VALUES(11,'Kontrak Bapak',2,'bapak'),(12,'Kontrak Ibu',2,'ibu'),(13,'Draft Parsial',2,'ibu')");
foreach ([21,22,23,24,25,26,31,32,33,34,41] as $id) {
    $pdo->prepare('INSERT INTO wali_santri(id,nama_bapak) VALUES(?,?)')->execute([$id,'Peserta Contoh '.$id]);
    $pdo->prepare('INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(?,?)')->execute([$id<30?11:($id<40?12:13),$id]);
}
$pdo->exec('SET timestamp=0');
function gsWrite(PDO $pdo,int $group,string $date,int $wali,array $values): array {
    $state=autosaveSessionState($pdo,2,$group,$date);
    foreach($state['entries'] as $entry)if($entry['wali_id']===$wali) {
        return autosaveWrite($pdo,2,$group,$date,['operation_id'=>bin2hex(random_bytes(16)),'program'=>$state['program'],'wali_id'=>$wali,'payload'=>array_replace($entry['payload'],$values),'draft_revision'=>$entry['draft_revision'],'official_revision'=>$entry['official_revision'],'context_revision'=>$state['context_revision']]);
    }
    throw new RuntimeException('Fixture member absent.');
}
$complete=['status'=>'H','jenis_materi'=>'Iqro','jilid'=>'3','halaman_dari'=>'9','halaman_sampai'=>'10','hasil_talaqqi'=>'Lulus'];
gsWrite($pdo,11,$base,21,$complete);
gsWrite($pdo,11,$base,21,['halaman_sampai'=>'']);
gsWrite($pdo,11,$base,22,['status'=>'S','alasan'=>"Kegiatan <ibu> / belajar 😀\nbaris\u{2028}kedua"]);
gsWrite($pdo,13,$base,41,['status'=>'H','jenis_materi'=>'Al Quran','nama_surat_dari'=>'Al-Fatihah','halaman_dari'=>'1']);
$insert=$pdo->prepare("INSERT INTO presensi(halaqoh_id,wali_santri_id,tanggal,status,jenis_materi,jilid,halaman,hasil_talaqqi) VALUES(?,?,?,'H','Iqro',3,?,'Lulus')");
foreach([[11,24,$base,'1-2'],[11,24,$base,'4-5'],[11,25,$base,'1-2'],[11,25,$base,'4-5'],[11,26,$base,'1-2'],[11,21,$legacy,'4-5']] as $values)$insert->execute($values);
$records=$pdo->query('SELECT * FROM presensi WHERE wali_santri_id=25 ORDER BY id')->fetchAll();
$pdo->prepare('INSERT INTO tahsin_verifications(wali_santri_id,session_date,fingerprint,selected_presensi_id,decided_by,note) VALUES(?,?,?,?,1,?)')->execute([25,$base,tahsinFingerprint($records),$records[1]['id'],'Tinjauan <contoh> / 😀']);
$pdo->prepare("INSERT INTO activity_logs(user_id,username,action,description,created_at) VALUES(1,'admin_test','INIT_CAPAIAN','Wali ID: 26',?)")->execute([$base.' 08:00:00']);
$pdo->prepare('UPDATE presensi SET created_at=? WHERE wali_santri_id=26')->execute([$base.' 08:00:15']);
foreach([31,32,33,34] as $id) gsWrite($pdo,12,$older,$id,['status'=>'A']);
$done=autosaveSessionState($pdo,2,12,$older);
autosaveFinalize($pdo,2,12,$older,['context_revision'=>$done['context_revision'],'revisions'=>array_column($done['entries'],'official_revision','wali_id')]);
// History must preserve the old teacher and archived participant at Sunday's boundary.
$pdo->exec('UPDATE halaqoh SET ustadz_id=4 WHERE id=11');
$pdo->exec('UPDATE wali_santri SET status_aktif=0 WHERE id=23');
$holiday=(new DateTimeImmutable($older))->modify('+1 day')->format('Y-m-d');
$pdo->prepare('INSERT INTO holidays(tanggal,keterangan) VALUES(?,?)')->execute([$holiday,'Libur contoh']);
$cases=[];
foreach([['historical-owner-and-conflicts',2,11,$base],['new-owner-cannot-see-old-roster',4,11,$base],['wrong-teacher',4,12,$older],['ibu-finalized',2,12,$older],['partial-draft',2,13,$base],['holiday-execution-date',2,13,$holiday],['legacy-recorded-roster',4,11,$legacy],['empty-roster',2,13,$legacy],['future-date',2,13,$now->modify('+2 days')->format('Y-m-d')]] as [$name,$user,$group,$date]) {
    $case=compact('name','user','group','date');
    try{$case['data']=autosaveSessionState($pdo,$user,$group,$date);$case['status']=200;}catch(AutosaveException $e){$case['status']=$e->httpStatus;}
    $cases[]=$case;
}
$user='session_ro_'.substr(hash('sha256',$database),0,10);
$pdo->exec("CREATE USER '$user'@'127.0.0.1' IDENTIFIED BY 'tahsin_session_test'");
$pdo->exec("GRANT SELECT ON `$database`.* TO '$user'@'127.0.0.1'");
$file=__DIR__.'/../.sessions/go-session-expected.json';
file_put_contents($file,json_encode(['now'=>$now->format(DATE_ATOM),'cases'=>$cases],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
echo "READONLY_USER=$user\nEXPECTED_FILE=$file\nCASES=".count($cases)."\n";
