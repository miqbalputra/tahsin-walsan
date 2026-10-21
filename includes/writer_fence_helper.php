<?php
/** Staging-only ownership fence. Never invoked by application startup. */
require_once __DIR__ . '/autosave_helper.php';

function writerAssertStaging(PDO $pdo): string
{
    $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!preg_match('/^tahsin_test_autosave_[a-z0-9_]+$/D', $database)) throw new RuntimeException('Writer migration is restricted to synthetic staging databases.');
    return $database;
}

function writerBarrierSql(): string
{
    return "DECLARE v_go VARCHAR(64); DECLARE v_owner VARCHAR(20) DEFAULT 'php'; DECLARE v_count INT DEFAULT 0;
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_owner='php';
        SELECT go_user INTO v_go FROM tahsin_writer_policy WHERE id=1 LOCK IN SHARE MODE;
        IF v_go IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TAHSIN_WRITER_RETRY: policy unavailable'; END IF;";
}
function writerRecordSql(string $id, string $date): string
{
    return "SET v_owner='php'; SELECT owner INTO v_owner FROM tahsin_writer_fences WHERE wali_id={$id} AND session_date=DATE_SUB({$date},INTERVAL (DAYOFWEEK({$date})-1) DAY) LOCK IN SHARE MODE;
        IF v_owner='paused' OR ((v_owner='go') <> (BINARY SUBSTRING_INDEX(USER(),'@',1)=BINARY v_go)) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TAHSIN_WRITER_RETRY: owner changed or paused'; END IF;";
}
function writerMetadataSql(): string
{
    return "SELECT COUNT(*) INTO v_count FROM tahsin_writer_fences WHERE owner<>'php' LOCK IN SHARE MODE;
        IF v_count>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TAHSIN_WRITER_RETRY: staging metadata frozen'; END IF;";
}

function writerApplyMigration(PDO $pdo, string $goUser): array
{
    writerAssertStaging($pdo);
    if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/D',$goUser) || $goUser==='root') throw new RuntimeException('Dedicated staging writer account required.');
    if (!autosaveSchemaReady($pdo) || !tahsinSchemaReady($pdo)) throw new RuntimeException('Complete autosave/reporting migration required first.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS tahsin_writer_policy(id TINYINT PRIMARY KEY,schema_version INT NOT NULL,go_user VARCHAR(64) NOT NULL,generation BIGINT NOT NULL DEFAULT 1) ENGINE=InnoDB");
    $stmt=$pdo->prepare('INSERT IGNORE INTO tahsin_writer_policy(id,schema_version,go_user) VALUES(1,1,?)');$stmt->execute([$goUser]);
    if ($pdo->query('SELECT go_user FROM tahsin_writer_policy WHERE id=1')->fetchColumn()!==$goUser) throw new RuntimeException('Existing writer identity differs; refusing policy replacement.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS tahsin_writer_fences(wali_id INT NOT NULL,session_date DATE NOT NULL,owner VARCHAR(20) NOT NULL DEFAULT 'php',PRIMARY KEY(wali_id,session_date),KEY idx_writer_owner(owner)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tahsin_writer_groups(halaqoh_id INT NOT NULL,session_date DATE NOT NULL,owner VARCHAR(20) NOT NULL DEFAULT 'php',PRIMARY KEY(halaqoh_id,session_date)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tahsin_writer_locks(wali_id INT NOT NULL,session_date DATE NOT NULL,mutex TINYINT NOT NULL DEFAULT 0,PRIMARY KEY(wali_id,session_date)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tahsin_writer_audit(id BIGINT AUTO_INCREMENT PRIMARY KEY,halaqoh_id INT NOT NULL,session_date DATE NOT NULL,owner VARCHAR(20) NOT NULL,generation BIGINT NOT NULL,actor VARCHAR(255) NOT NULL,changed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tahsin_writer_registry(name VARCHAR(64) PRIMARY KEY,body_sha256 CHAR(64) NOT NULL) ENGINE=InnoDB");
    $tables=$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
    $installed=[];
    foreach($tables as $table) {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$table)) throw new RuntimeException('Unexpected table identity.');
        if (str_starts_with($table,'tahsin_writer_') || $table==='login_attempts') continue;
        foreach(['INSERT'=>'bi','UPDATE'=>'bu','DELETE'=>'bd'] as $event=>$suffix) {
            $body=writerBarrierSql();
            if (in_array($table,['presensi','tahsin_autosave_drafts','tahsin_autosave_revisions'],true)) {
                $id=$table==='presensi'?'wali_santri_id':'wali_id';$date=$table==='presensi'?'tanggal':'session_date';
                if($event!=='INSERT')$body.=writerRecordSql("OLD.{$id}","OLD.{$date}");
                if($event!=='DELETE')$body.=writerRecordSql("NEW.{$id}","NEW.{$date}");
            } elseif($table==='tahsin_autosave_sessions') {
                foreach($event==='INSERT'?['NEW']:($event==='DELETE'?['OLD']:['OLD','NEW']) as $r) {
                    $body.="SET v_owner='php'; SELECT owner INTO v_owner FROM tahsin_writer_groups WHERE halaqoh_id={$r}.halaqoh_id AND session_date={$r}.session_date LOCK IN SHARE MODE;
                        IF v_owner='paused' OR ((v_owner='go') <> (BINARY SUBSTRING_INDEX(USER(),'@',1)=BINARY v_go)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TAHSIN_WRITER_RETRY: session owner changed'; END IF;";
                }
            } elseif($table==='tahsin_autosave_operations' && $event==='INSERT') {
                // Receipts identify their scope through the draft written in the same transaction.
                $body.="SELECT COUNT(*) INTO v_count FROM tahsin_autosave_drafts WHERE user_id=NEW.user_id AND wali_id=JSON_UNQUOTE(JSON_EXTRACT(NEW.response_json,'$.wali_id')) AND context_revision=JSON_UNQUOTE(JSON_EXTRACT(NEW.response_json,'$.context_revision'));
                    IF v_count=1 THEN
                        SET v_owner='php';
                        SELECT COALESCE(f.owner,'php') INTO v_owner FROM tahsin_autosave_drafts d LEFT JOIN tahsin_writer_fences f ON f.wali_id=d.wali_id AND f.session_date=d.session_date
                        WHERE d.user_id=NEW.user_id AND d.wali_id=JSON_UNQUOTE(JSON_EXTRACT(NEW.response_json,'$.wali_id')) AND d.context_revision=JSON_UNQUOTE(JSON_EXTRACT(NEW.response_json,'$.context_revision')) LOCK IN SHARE MODE;
                        IF v_owner='paused' OR ((v_owner='go') <> (BINARY SUBSTRING_INDEX(USER(),'@',1)=BINARY v_go)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TAHSIN_WRITER_RETRY: receipt owner changed'; END IF;
                    ELSE ".writerMetadataSql()." END IF;";
            } elseif($table==='activity_logs') {
                if($event==='INSERT')$body.="IF NEW.action='INIT_CAPAIAN' THEN ".writerMetadataSql()." END IF;";
                else $body.=writerMetadataSql();
            } else $body.=writerMetadataSql();
            $name='tahsin_wf_'.substr(hash('sha256',$table),0,12).'_'.$suffix;
            $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name=?');$stmt->execute([$name]);
            if (!(int)$stmt->fetchColumn())$pdo->exec("CREATE TRIGGER `{$name}` BEFORE {$event} ON `{$table}` FOR EACH ROW BEGIN {$body} END");
            $stmt=$pdo->prepare('SELECT action_statement FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name=?');$stmt->execute([$name]);$digest=hash('sha256',$stmt->fetchColumn());
            $stmt=$pdo->prepare('INSERT IGNORE INTO tahsin_writer_registry(name,body_sha256) VALUES(?,?)');$stmt->execute([$name,$digest]);
            $installed[]=$name;
        }
    }
    return $installed;
}

function writerSchemaReady(PDO $pdo): bool
{
    $expected=$pdo->query('SELECT name,body_sha256 FROM tahsin_writer_registry')->fetchAll(PDO::FETCH_KEY_PAIR);
    if(count($expected)<12)return false;
    $actual=$pdo->query("SELECT trigger_name,action_statement FROM information_schema.triggers WHERE trigger_schema=DATABASE()")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach($expected as $name=>$digest)if(!isset($actual[$name])||!hash_equals($digest,hash('sha256',$actual[$name])))return false;
    return true;
}

/** Operator-only transaction. App accounts receive SELECT on control tables, never DML. */
function writerTransferGroup(PDO $pdo,int $teacher,int $group,string $date,string $owner): int
{
    writerAssertStaging($pdo);
    if(!in_array($owner,['php','go','paused'],true))throw new RuntimeException('Invalid writer owner.');
    $pdo->beginTransaction();
    try {
        $generation=(int)$pdo->query('SELECT generation FROM tahsin_writer_policy WHERE id=1 FOR UPDATE')->fetchColumn();
        if(!$generation)throw new RuntimeException('Writer policy missing.');
        $ctx=autosaveContext($pdo,$teacher,$group,$date,true);
        $ids=array_map('intval',array_column($ctx['members'],'id'));sort($ids,SORT_NUMERIC);
        if(!$ids)throw new RuntimeException('Cannot transfer an empty roster.');
        foreach($ids as $id) {
            // A participant appearing in another group must never receive divergent ownership.
            $stmt=$pdo->prepare("SELECT 1 FROM tahsin_writer_groups g JOIN tahsin_roster_history rh ON rh.halaqoh_id=g.halaqoh_id AND rh.wali_santri_id=? WHERE g.session_date=? AND g.halaqoh_id<>? AND g.owner<>? AND g.owner<>'php' LIMIT 1");$stmt->execute([$id,$ctx['session'],$group,$owner]);
            if($stmt->fetchColumn())throw new RuntimeException('Overlapping group owner requires coordinated transfer.');
            $stmt=$pdo->prepare('INSERT INTO tahsin_writer_fences(wali_id,session_date,owner) VALUES(?,?,?) ON DUPLICATE KEY UPDATE owner=VALUES(owner)');$stmt->execute([$id,$ctx['session'],$owner]);
            $stmt=$pdo->prepare('INSERT IGNORE INTO tahsin_writer_locks(wali_id,session_date) VALUES(?,?)');$stmt->execute([$id,$ctx['session']]);
        }
        $stmt=$pdo->prepare('INSERT INTO tahsin_writer_groups(halaqoh_id,session_date,owner) VALUES(?,?,?) ON DUPLICATE KEY UPDATE owner=VALUES(owner)');$stmt->execute([$group,$ctx['session'],$owner]);
        $generation++;
        $pdo->exec('UPDATE tahsin_writer_policy SET generation='.$generation.' WHERE id=1');
        $stmt=$pdo->prepare('INSERT INTO tahsin_writer_audit(halaqoh_id,session_date,owner,generation,actor) VALUES(?,?,?,?,USER())');$stmt->execute([$group,$ctx['session'],$owner,$generation]);
        $pdo->commit();return $generation;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function writerRoute(PDO $pdo,int $group,string $date,?int $wali=null): array
{
    writerAssertStaging($pdo);
    $session=tahsinSessionDate($date);
    $stmt=$pdo->prepare('SELECT p.generation,COALESCE(g.owner,\'php\') AS owner FROM tahsin_writer_policy p LEFT JOIN tahsin_writer_groups g ON g.halaqoh_id=? AND g.session_date=? WHERE p.id=1');$stmt->execute([$group,$session]);
    $route=$stmt->fetch(PDO::FETCH_ASSOC);if(!$route)throw new RuntimeException('Writer policy missing.');
    if($wali) {
        $stmt=$pdo->prepare("SELECT owner FROM tahsin_writer_fences WHERE wali_id=? AND session_date=?");$stmt->execute([$wali,$session]);
        $route['owner']=$stmt->fetchColumn()?:'php';
    }
    return $route;
}
