<?php
/** Program-aware reads also work before the optional quality migration. */
function tahsinGroupPrograms(PDO $pdo): array
{
    try { return $pdo->query('SELECT id,program_code FROM halaqoh')->fetchAll(PDO::FETCH_KEY_PAIR); }
    catch (PDOException $e) { if ($e->getCode()!=='42S22') throw $e; return array_fill_keys($pdo->query('SELECT id FROM halaqoh')->fetchAll(PDO::FETCH_COLUMN),'bapak'); }
}

function tahsinRecordsForProgram(array $records, array $programs, string $program): array
{
    return array_values(array_filter($records,static fn($r)=>($programs[(int)($r['halaqoh_id']??0)]??'unknown')===$program));
}

function tahsinDecisionForProgram(array $decisions, array $allRecords, array $programs, string $program): ?array
{
    $result=null;
    $recordPrograms=[]; foreach ($allRecords as $r) $recordPrograms[(int)$r['id']]=$programs[(int)($r['halaqoh_id']??0)]??'unknown';
    $unique=array_values(array_unique(array_values($recordPrograms)));
    foreach ($decisions as $v) {
        $scope=$v['program_code']??null;
        if ($scope===null) $scope=$recordPrograms[(int)($v['selected_presensi_id']??0)]??(count($unique)===1?$unique[0]:null);
        if ($scope===$program) $result=$v;
    }
    return $result;
}

function tahsinScopedSession(PDO $pdo, int $waliId, string $session, string $program, bool $lock=false): array
{
    $q=$pdo->prepare('SELECT * FROM presensi WHERE wali_santri_id=? AND tanggal BETWEEN ? AND ? ORDER BY id'.($lock?' FOR UPDATE':''));
    $q->execute([$waliId,$session,(new DateTimeImmutable($session))->modify('+6 days')->format('Y-m-d')]); $all=$q->fetchAll(PDO::FETCH_ASSOC);
    $programs=tahsinGroupPrograms($pdo); $records=tahsinRecordsForProgram($all,$programs,$program);
    $decisions=[];
    if (tahsinSchemaReady($pdo)) { $q=$pdo->prepare('SELECT * FROM tahsin_verifications WHERE wali_santri_id=? AND session_date=? ORDER BY id'.($lock?' FOR UPDATE':'')); $q->execute([$waliId,$session]); $decisions=$q->fetchAll(PDO::FETCH_ASSOC); }
    return ['records'=>$records,'verification'=>tahsinDecisionForProgram($decisions,$all,$programs,$program),'all_records'=>$all];
}
