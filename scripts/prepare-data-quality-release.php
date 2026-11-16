<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$options=getopt('',['verify','apply','state:']);
if ((!isset($options['verify']) && !isset($options['apply'])) || isset($options['verify'],$options['apply']) || empty($options['state'])) {
    echo "Verify backup + migrations in a NEW isolated schema: --verify --state=/private/path/release.json\n";
    echo "Apply verified metadata migrations: --apply --state=/private/path/release.json\n"; exit(2);
}
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/release_preparation_helper.php';
$stateFile=$options['state'];
try {
    // State and ZIP contain private data; refuse all application webroot paths.
    $directory=dirname($stateFile);
    if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new RuntimeException('Folder state tidak dapat dibuat.');
    $real=realpath($directory); $webroot=realpath(__DIR__.'/..');
    if (!$real || $real===$webroot || str_starts_with($real,$webroot.DIRECTORY_SEPARATOR)) throw new RuntimeException('State dan backup harus di luar webroot.');
    chmod($directory,0700);
    $code=[]; foreach (glob(__DIR__.'/../includes/*.php') as $file) $code[basename($file)]=hash_file('sha256',$file);
    $codeHash=hash('sha256',serialize($code));
    if (isset($options['verify'])) {
        if (file_exists($stateFile)) throw new RuntimeException('State sudah ada. Gunakan lokasi baru; backup lama tidak ditimpa.');
        $stageName='tahsin_release_stage_'.date('Ymd_His').'_'.bin2hex(random_bytes(4));
        $pdo->exec('CREATE DATABASE '.attendanceHistoryQuote($stageName).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $settings=appDatabaseConnectionSettings();
        $dsn=preg_replace('/;dbname=[^;]*/',';dbname='.$stageName,$settings['dsn']);
        $stage=new PDO($dsn,$settings['username'],$settings['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $state=releaseVerifyClone($pdo,$stage,$directory); $state['code_hash']=$codeHash;
        file_put_contents($stateFile,json_encode($state,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX); chmod($stateFile,0600);
        echo json_encode(['verified'=>true,'stage_database'=>$stageName,'backup_sha256'=>$state['backup_sha256'],'tables'=>count($state['baseline']),'original_rows_unchanged'=>true]),PHP_EOL;
    } else {
        $state=json_decode(file_get_contents($stateFile),true,128,JSON_THROW_ON_ERROR);
        if (empty($state['verified']) || $state['code_hash']!==$codeHash || $state['source_database']!==$pdo->query('SELECT DATABASE()')->fetchColumn() || !is_file($state['backup']) || !hash_equals($state['backup_sha256'],hash_file('sha256',$state['backup']))) throw new RuntimeException('Bukti verifikasi/kode/database/backup tidak cocok.');
        if (releaseDatabaseSnapshot($pdo,$state['baseline'])!==$state['baseline']) throw new RuntimeException('Data berubah setelah verifikasi. Ulangi verifikasi sebelum apply.');
        releaseInstallJournals($pdo);
        if (releaseDatabaseSnapshot($pdo,$state['baseline'])!==$state['baseline']) throw new RuntimeException('Perbandingan nilai lama berbeda. Jangan cutover; periksa backup dan penulisan bersamaan.');
        echo json_encode(['applied'=>true,'original_rows_unchanged'=>true,'tables_compared'=>count($state['baseline']),'quality_ready'=>dataQualitySchemaReady($pdo)]),PHP_EOL;
    }
} catch (Throwable $e) { fwrite(STDERR,'Release stopped: '.$e->getMessage()."\n"); exit(1); }
