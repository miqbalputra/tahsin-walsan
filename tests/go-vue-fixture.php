<?php
/** Extends only the existing localhost autosave test fixture. */
if(PHP_SAPI!=='cli')exit;
$db=$argv[1]??'';
if(!preg_match('/^tahsin_test_autosave_[a-z0-9_]+$/D',$db))throw new RuntimeException('Synthetic autosave fixture required');
$pdo=new PDO('mysql:host=127.0.0.1;port=13317;dbname='.$db.';charset=utf8mb4','root','tahsin_local_test',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($pdo->query("SELECT username FROM users WHERE id=1")->fetchColumn()!=='admin_test')throw new RuntimeException('Not the expected fixture');
$user='next_ro_'.substr(hash('sha256',$db),0,12);
$pdo->exec("CREATE USER IF NOT EXISTS '$user'@'127.0.0.1' IDENTIFIED BY 'tahsin_next_test'");
$pdo->exec("GRANT SELECT ON `$db`.* TO '$user'@'127.0.0.1'");
if(in_array('--family',$argv,true)){
    putenv('DATA_INDUK_ENABLED=0');
    require __DIR__.'/../includes/family_helper.php';require __DIR__.'/../includes/family_migration_helper.php';
    familyApplyMigration($pdo);
    $g=fn($id,$name,$type)=>['source_id'=>$id,'name'=>$name,'type'=>$type,'phone'=>'','address'=>'Alamat contoh','active'=>true,'account_active'=>true];
    $s=fn($id,$name,$status,$kelas)=>['source_id'=>$id,'nis'=>'0000'.$id,'name'=>$name,'status'=>$status,'class_name'=>$kelas];
    $data=['format'=>'tahsin-family-v1','source'=>'next-fixture','snapshot_date'=>'2026-10-21',
        'guardians'=>[$g('300','Bapak Ahmad','father'),$g('301','Ibu Aminah','mother')],
        'students'=>[$s('500','Ahmad','active','Mustawa 2 Ikhwan'),$s('501','Kakak Ahmad Lulus','graduated','')],
        'links'=>[]];
    foreach($data['students'] as $child)foreach($data['guardians'] as $guardian)$data['links'][]=['guardian_id'=>$guardian['source_id'],'student_id'=>$child['source_id'],'relationship'=>$guardian['type'],'primary_contact'=>$guardian['type']==='father'];
    $before=$pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll();
    familyImportApply($pdo,$data,['300'=>'1','301'=>'10']);
    if($before!==$pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll())throw new RuntimeException('Historical attendance changed');
    $output=__DIR__.'/../.sessions/go-vue-expected.json';
    file_put_contents($output,json_encode(familyFetchStudents($pdo,100,0),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}
echo 'STAGING_DATABASE='.$db."\nREADONLY_USER=".$user."\n";
