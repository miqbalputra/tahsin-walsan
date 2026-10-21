<?php
/** Explicit operator CLI. No production DB env fallback; no HTTP deployment path. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/writer_fence_helper.php';
try{
 $action=$argv[1]??'';if(!in_array($action,['install','transfer','inspect'],true))throw new RuntimeException('Use install, transfer or inspect. This CLI supports synthetic staging only.');
 $config=[];foreach(['HOST','PORT','DATABASE','USERNAME','PASSWORD'] as $key){$value=getenv('TAHSIN_STAGING_DB_'.$key);if($value===false||($value===''&&$key!=='PASSWORD'))throw new RuntimeException('Explicit TAHSIN_STAGING_DB_'.$key.' required.');$config[$key]=$value;}
 if(!preg_match('/^tahsin_test_autosave_[a-z0-9_]+$/D',$config['DATABASE']))throw new RuntimeException('Production database names are refused.');
 $options=[];foreach(array_slice($argv,2) as $argument){if(!preg_match('/^--([a-z-]+)=(.*)$/D',$argument,$match)||isset($options[$match[1]]))throw new RuntimeException('Use unique --option=value arguments.');$options[$match[1]]=$match[2];}
 $pdo=new PDO('mysql:host='.$config['HOST'].';port='.$config['PORT'].';dbname='.$config['DATABASE'].';charset=utf8mb4',$config['USERNAME'],$config['PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$pdo->exec("SET time_zone='+07:00'");writerAssertStaging($pdo);
 if($action==='install')$result=['installed'=>count(writerApplyMigration($pdo,$options['go-user']??'')),'ready'=>writerSchemaReady($pdo)];
 elseif($action==='transfer'){
  $group=filter_var($options['group']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$teacher=filter_var($options['teacher']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$date=$options['date']??'';
  if(!$group||!$teacher||!tahsinDate($date))throw new RuntimeException('Valid --group, --teacher and --date required.');
  $result=['generation'=>writerTransferGroup($pdo,$teacher,$group,$date,$options['owner']??'')];
 }else $result=['ready'=>writerSchemaReady($pdo),'policy'=>$pdo->query('SELECT schema_version,generation FROM tahsin_writer_policy WHERE id=1')->fetch(),'groups'=>$pdo->query('SELECT * FROM tahsin_writer_groups ORDER BY session_date,halaqoh_id')->fetchAll()];
 echo json_encode(['ok'=>true,'data'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
}catch(Throwable $e){fwrite(STDERR,json_encode(['ok'=>false,'message'=>$e instanceof PDOException?'Staging database operation failed; no production cutover performed.':$e->getMessage()],JSON_UNESCAPED_UNICODE)."\n");exit(1);}
