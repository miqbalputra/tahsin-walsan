<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/data_quality_helper.php';
try {
    $pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
    $findings=dataQualityAudit($pdo); $pdo->commit();
    $counts=array_count_values(array_column($findings,'kind'));
    echo json_encode(['generated_at'=>date('c'),'read_only'=>true,'counts'=>$counts,'findings'=>$findings,
        'limitations'=>'Draft yang hanya ada di HP tidak dapat diperiksa oleh server.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR),PHP_EOL;
    exit(array_filter($findings,static fn($f)=>$f['status']==='pending')?2:0);
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); fwrite(STDERR,"Audit belum selesai: ".$e->getMessage()."\n"); exit(1); }
