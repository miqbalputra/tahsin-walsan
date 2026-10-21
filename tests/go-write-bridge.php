<?php
/** CLI-only companion to Go integration tests, pinned to the owned synthetic instance. */
if(PHP_SAPI!=='cli')exit;
require_once __DIR__.'/../includes/writer_fence_helper.php';
$database=$argv[1]??'';if(!preg_match('/^tahsin_test_autosave_[a-z0-9_]+$/D',$database))throw new RuntimeException('Synthetic fixture required.');
$input=json_decode(trim(fgets(STDIN)),true,32,JSON_THROW_ON_ERROR);$action=$input['action']??'';$suffix=substr(hash('sha256',$database),0,10);
$root=in_array($action,['transfer','fail_receipt','clear_failure','probe_barrier'],true);
$pdo=new PDO('mysql:host=127.0.0.1;port=13319;dbname='.$database.';charset=utf8mb4',$root?'root':'php_w_'.$suffix,$root?'tahsin_local_test':'tahsin_writer_test',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$pdo->exec("SET time_zone='+07:00'");
if($pdo->query('SELECT username FROM users WHERE id=1')->fetchColumn()!=='admin_test')throw new RuntimeException('Wrong fixture.');
try{
 $date=$input['date']??tahsinLatestSession(tahsinNow()->format('Y-m-d'));$group=(int)($input['group']??3);$teacher=(int)($input['teacher']??2);
 if($action==='state')$data=autosaveSessionState($pdo,$teacher,$group,$date);
 elseif($action==='write')$data=autosaveWrite($pdo,$teacher,$group,$date,$input['request']);
 elseif($action==='finalize')$data=autosaveFinalize($pdo,$teacher,$group,$date,$input['request']);
 elseif($action==='transfer')$data=['generation'=>writerTransferGroup($pdo,$teacher,$group,$date,$input['owner'])];
 elseif($action==='route')$data=writerRoute($pdo,$group,$date,isset($input['wali'])?(int)$input['wali']:null);
 elseif($action==='canonical'){
   $request=$input['request'];unset($request['csrf_token']);$wire=$input['request'];if(($wire['action']??'save')==='save')$wire['payload']=autosavePayload($wire['payload']);
   $data=['hash'=>autosaveHash([$group,$date,$request]),'wire'=>json_encode($wire,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
 }
 elseif($action==='fail_receipt'){$pdo->exec("CREATE TRIGGER test_fail_writer_receipt BEFORE INSERT ON tahsin_autosave_operations FOR EACH ROW BEGIN IF NEW.operation_id='force_receipt_rollback_001' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic receipt failure'; END IF; END");$data=['installed'=>true];}
 elseif($action==='clear_failure'){$pdo->exec('DROP TRIGGER test_fail_writer_receipt');$data=['removed'=>true];}
 elseif($action==='direct_sql'){
   $stmt=$pdo->prepare("UPDATE presensi SET halaman='must-never-write' WHERE wali_santri_id=? AND tanggal=?");$stmt->execute([(int)$input['wali'],$date]);$data=['changed'=>$stmt->rowCount()];
 }
 elseif($action==='metadata'){$pdo->exec("UPDATE halaqoh SET nama_halaqoh='must-never-change' WHERE id=3");$data=['changed'=>true];}
 elseif($action==='hold'){
   $pdo->beginTransaction();$stmt=$pdo->prepare('INSERT INTO tahsin_autosave_revisions(wali_id,session_date,revision) VALUES(10,?,0) ON DUPLICATE KEY UPDATE revision=revision');$stmt->execute([$date]);
   echo "LOCKED\n";fflush(STDOUT);fgets(STDIN);$pdo->commit();$data=['committed'=>true];
 }
 elseif($action==='probe_barrier'){
   $pdo->exec('SET innodb_lock_wait_timeout=1');$pdo->beginTransaction();
   try{$pdo->query('SELECT generation FROM tahsin_writer_policy WHERE id=1 FOR UPDATE');$data=['blocked'=>false];}
   catch(PDOException $e){if((int)($e->errorInfo[1]??0)!==1205)throw $e;$data=['blocked'=>true];}
   finally{if($pdo->inTransaction())$pdo->rollBack();}
 }
 else throw new RuntimeException('Unsupported synthetic bridge command.');
 echo json_encode(['status'=>200,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){$status=$e instanceof AutosaveException?$e->httpStatus:($e instanceof PDOException&&str_contains($e->getMessage(),'TAHSIN_WRITER_RETRY')?503:500);echo json_encode(['status'=>$status,'error'=>$e->getMessage()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
