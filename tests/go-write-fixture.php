<?php
if(PHP_SAPI!=='cli')exit;
require_once __DIR__.'/../includes/writer_fence_helper.php';
$database=$argv[1]??'';if(!preg_match('/^tahsin_test_autosave_[a-z0-9_]+$/D',$database))throw new RuntimeException('Synthetic fixture required.');
$pdo=new PDO('mysql:host=127.0.0.1;port=13319;dbname='.$database.';charset=utf8mb4','root','tahsin_local_test',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$pdo->exec("SET time_zone='+07:00'");
if($pdo->query('SELECT username FROM users WHERE id=1')->fetchColumn()!=='admin_test')throw new RuntimeException('Wrong fixture.');
$suffix=substr(hash('sha256',$database),0,10);$go='go_w_'.$suffix;$php='php_w_'.$suffix;$read='read_w_'.$suffix;
$triggers=writerApplyMigration($pdo,$go);writerApplyMigration($pdo,$go);
if(!writerSchemaReady($pdo))throw new RuntimeException('Fence registry mismatch.');
foreach([$go,$php,$read] as $account){$pdo->exec("CREATE USER '$account'@'127.0.0.1' IDENTIFIED BY 'tahsin_writer_test'");$pdo->exec("GRANT SELECT ON `$database`.* TO '$account'@'127.0.0.1'");}
foreach(['presensi','tahsin_autosave_drafts','tahsin_autosave_sessions'] as $table)$pdo->exec("GRANT INSERT,UPDATE ON `$database`.`$table` TO '$go'@'127.0.0.1'");
foreach(['tahsin_autosave_operations','activity_logs'] as $table)$pdo->exec("GRANT INSERT ON `$database`.`$table` TO '$go'@'127.0.0.1'");
$pdo->exec("GRANT UPDATE(mutex) ON `$database`.tahsin_writer_locks TO '$go'@'127.0.0.1'");
$tables=$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as $table)if(!str_starts_with($table,'tahsin_writer_'))$pdo->exec("GRANT INSERT,UPDATE,DELETE,TRIGGER ON `$database`.`$table` TO '$php'@'127.0.0.1'");
$result=['database'=>$database,'go_user'=>$go,'php_user'=>$php,'read_user'=>$read,'date'=>tahsinLatestSession(tahsinNow()->format('Y-m-d')),'triggers'=>count($triggers)];
file_put_contents(__DIR__.'/../.sessions/go-write-fixture.json',json_encode($result,JSON_THROW_ON_ERROR));echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
