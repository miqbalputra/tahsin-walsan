<?php
function dataQualityRevisionTriggers(): array
{
    $bump=static fn($r)=>"INSERT INTO tahsin_program_revisions(wali_id,program_code,session_date,revision)
        SELECT $r.wali_santri_id,COALESCE(h.program_code,'unknown'),DATE_SUB($r.tanggal,INTERVAL (DAYOFWEEK($r.tanggal)-1) DAY),1
        FROM (SELECT 1) seed LEFT JOIN halaqoh h ON h.id=$r.halaqoh_id
        ON DUPLICATE KEY UPDATE revision=revision+1;";
    $out=[];
    foreach (['ai'=>['INSERT',$bump('NEW')],'au'=>['UPDATE',$bump('OLD').$bump('NEW')],'ad'=>['DELETE',$bump('OLD')]] as $suffix=>[$event,$body]) $out['tahsin_program_'.$suffix]=['event'=>$event,'statement'=>'BEGIN '.$body.' END'];
    return $out;
}

/** Additive only. Existing participants, attendance and decisions are never rewritten. */
function dataQualityApplyMigration(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new RuntimeException('Jalankan migrasi pemeriksaan data di luar transaksi.');
    appRequireTransactionalTables($pdo);
    foreach (['program_code'=>"VARCHAR(20) NULL", 'decision_action'=>"VARCHAR(12) NOT NULL DEFAULT 'select'",
        'previous_id'=>'BIGINT NULL', 'operation_id'=>'CHAR(32) NULL', 'request_hash'=>'CHAR(64) NULL'] as $name=>$definition) {
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='tahsin_verifications' AND column_name=?"); $q->execute([$name]);
        if (!(int)$q->fetchColumn()) $pdo->exec("ALTER TABLE tahsin_verifications ADD COLUMN `$name` $definition");
    }
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='tahsin_verifications' AND index_name='uq_quality_verification_operation'");
    if (!(int)$q->fetchColumn()) $pdo->exec('ALTER TABLE tahsin_verifications ADD UNIQUE KEY uq_quality_verification_operation(operation_id)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS data_quality_reviews (
        operation_id CHAR(32) PRIMARY KEY, finding_key CHAR(64) NOT NULL, fingerprint CHAR(64) NOT NULL,
        decision VARCHAR(20) NOT NULL, note TEXT NOT NULL, actor_id INT NOT NULL,
        review_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), KEY idx_quality_finding(finding_key,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='data_quality_reviews' AND column_name='review_id'");
    if (!(int)$q->fetchColumn()) $pdo->exec('ALTER TABLE data_quality_reviews ADD COLUMN review_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE');
    $pdo->exec("CREATE TABLE IF NOT EXISTS participant_merge_undo (
        merge_operation CHAR(32) PRIMARY KEY, undo_operation CHAR(32) NOT NULL UNIQUE,
        actor_id INT NOT NULL, request_hash CHAR(64) NOT NULL, result_json TEXT NOT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tahsin_program_revisions (
        wali_id INT NOT NULL, program_code VARCHAR(20) NOT NULL, session_date DATE NOT NULL,
        revision BIGINT NOT NULL DEFAULT 0, PRIMARY KEY(wali_id,program_code,session_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (dataQualityRevisionTriggers() as $name=>$definition) {
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name=?'); $q->execute([$name]);
        if (!(int)$q->fetchColumn()) $pdo->exec("CREATE TRIGGER $name AFTER ".$definition['event'].' ON presensi FOR EACH ROW '.$definition['statement']);
    }
    appRequireTransactionalTables($pdo,['data_quality_reviews','participant_merge_undo','tahsin_program_revisions']);
    if (!dataQualitySchemaReady($pdo)) throw new RuntimeException('Definisi struktur/trigger pemeriksaan tidak sesuai. Periksa instalasi sebelum mengaktifkan merge.');
}

function dataQualitySchemaReady(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT finding_key,fingerprint,decision,note,actor_id,review_id FROM data_quality_reviews LIMIT 0');
        $pdo->query('SELECT merge_operation,undo_operation,request_hash,result_json FROM participant_merge_undo LIMIT 0');
        $pdo->query('SELECT program_code,decision_action,previous_id,operation_id,request_hash FROM tahsin_verifications LIMIT 0');
        $pdo->query('SELECT wali_id,program_code,session_date,revision FROM tahsin_program_revisions LIMIT 0');
        $index=$pdo->query("SELECT COLUMN_NAME,NON_UNIQUE FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='tahsin_verifications' AND index_name='uq_quality_verification_operation'")->fetchAll(PDO::FETCH_ASSOC);
        if (count($index)!==1 || $index[0]['COLUMN_NAME']!=='operation_id' || (int)$index[0]['NON_UNIQUE']!==0) return false;
        $triggers=$pdo->query("SELECT TRIGGER_NAME,ACTION_STATEMENT,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name IN ('tahsin_program_ai','tahsin_program_au','tahsin_program_ad')")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
        $normalize=static fn($s)=>preg_replace('/\s+/',' ',trim($s));
        foreach (dataQualityRevisionTriggers() as $name=>$expected) {
            $actual=$triggers[$name]??null;
            if (!$actual || $actual['ACTION_TIMING']!=='AFTER' || $actual['EVENT_OBJECT_TABLE']!=='presensi' || $actual['EVENT_MANIPULATION']!==$expected['event'] || $normalize($actual['ACTION_STATEMENT'])!==$normalize($expected['statement'])) return false;
        }
        return true;
    } catch (PDOException $e) { if (in_array($e->getCode(),['42S02','42S22'],true)) return false; throw $e; }
}
