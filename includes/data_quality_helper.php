<?php
require_once __DIR__.'/participant_merge_helper.php';
require_once __DIR__.'/data_quality_migration_helper.php';
require_once __DIR__.'/autosave_helper.php';

function dataQualityName(string $value): string
{
    if (class_exists('Normalizer')) $value=Normalizer::normalize($value,Normalizer::FORM_KC)?:$value;
    $value=preg_replace('/[^\p{L}\p{N}]+/u',' ',mb_strtolower(trim($value),'UTF-8'));
    return trim(preg_replace('/\s+/u',' ',$value));
}

function dataQualityPhone(?string $value): string
{
    $value=preg_replace('/\D/','',$value??'');
    if (str_starts_with($value,'00')) $value=substr($value,2);
    if (str_starts_with($value,'0')) $value='62'.substr($value,1);
    return strlen($value)>=9 && strlen($value)<=15?$value:'';
}

/** Shared deterministic detector: UI and CLI never use different matching rules. */
function dataQualityDetect(array $parents, array $children, array $memberships, array $records, array $groups, array $drafts=[]): array
{
    $byId=[]; foreach ($parents as $p) $byId[(int)$p['id']]=$p;
    $groupMap=[]; foreach ($groups as $g) $groupMap[(int)$g['id']]=$g;
    $findings=[];
    $add=static function(string $kind,array $identity,array $ids,array $evidence,array $source,?string $program=null,?string $session=null) use (&$findings,$byId): void {
        sort($ids,SORT_NUMERIC); $ids=array_values(array_unique($ids));
        $names=array_map(static fn($id)=>($byId[$id]['nama_bapak']??'Peserta hilang').' (#'.$id.')',$ids);
        $findings[]=['key'=>hash('sha256',serialize([$kind,$identity])),'kind'=>$kind,'wali_ids'=>$ids,'names'=>$names,
            'program'=>$program,'session'=>$session,'evidence'=>$evidence,'fingerprint'=>hash('sha256',serialize($source)),
            'records'=>$kind==='attendance'?$source:[],'group_ids'=>[], 'status'=>'pending'];
    };
    $bins=[]; $kids=[];
    foreach ($children as $c) $kids[(int)$c['wali_santri_id']][]=$c;
    foreach ($parents as $p) {
        $id=(int)$p['id']; $name=dataQualityName($p['nama_bapak']); $phone=dataQualityPhone($p['no_hp']??null);
        if ($name!=='') $bins['name:'.$name][]=$id;
        if ($phone!=='') $bins['phone:'.$phone][]=$id;
    }
    $pairs=[];
    foreach ($bins as $bin=>$ids) for ($i=0;$i<count($ids);$i++) for ($j=$i+1;$j<count($ids);$j++) {
        $pair=[$ids[$i],$ids[$j]]; sort($pair); $pairs[implode(':',$pair)]=$pair;
    }
    foreach ($pairs as $pair) {
        [$a,$b]=$pair; $e=[];
        if (dataQualityName($byId[$a]['nama_bapak'])===dataQualityName($byId[$b]['nama_bapak'])) $e[]='Nama setelah normalisasi sama';
        if (($phone=dataQualityPhone($byId[$a]['no_hp']??null))!=='' && $phone===dataQualityPhone($byId[$b]['no_hp']??null)) $e[]='Nomor HP setelah normalisasi sama (bisa nomor keluarga bersama)';
        $ak=array_map(static fn($c)=>dataQualityName($c['nama_anak']),$kids[$a]??[]);
        $bk=array_map(static fn($c)=>dataQualityName($c['nama_anak']),$kids[$b]??[]);
        if (array_intersect(array_filter($ak),array_filter($bk))) $e[]='Ada nama anak sama; identitas anak tetap perlu diperiksa';
        $add('identity',$pair,$pair,$e,[$byId[$a],$byId[$b],$kids[$a]??[],$kids[$b]??[]]);
    }
    $placements=[];
    foreach ($memberships as $m) {
        if (($m['archived_at']??null)!==null || (int)($byId[(int)$m['wali_santri_id']]['status_aktif']??0)!==1) continue;
        $program=$groupMap[(int)$m['halaqoh_id']]['program_code']??'unknown';
        $placements[$m['wali_santri_id'].':'.$program][]=$m;
    }
    foreach ($placements as $rows) if (count($rows)>1) {
        $first=$rows[0]; $program=$groupMap[(int)$first['halaqoh_id']]['program_code']??'unknown';
        $labels=array_map(static fn($r)=>($groupMap[(int)$r['halaqoh_id']]['nama_halaqoh']??'Halaqoh hilang').' (#'.$r['halaqoh_id'].')',$rows);
        $add('membership',[(int)$first['wali_santri_id'],$program],[(int)$first['wali_santri_id']],['Lebih dari satu keanggotaan aktif: '.implode(', ',$labels)],$rows,$program);
        $findings[array_key_last($findings)]['group_ids']=array_values(array_unique(array_map(static fn($r)=>(int)$r['halaqoh_id'],$rows)));
    }
    $weeks=[];
    foreach ($records as $r) {
        $program=$groupMap[(int)($r['halaqoh_id']??0)]['program_code']??'unknown'; $session=tahsinSessionDate($r['tanggal']);
        $weeks[$r['wali_santri_id'].':'.$program.':'.$session][]=$r;
    }
    foreach ($weeks as $rows) if (count($rows)>1) {
        $first=$rows[0]; $program=$groupMap[(int)($first['halaqoh_id']??0)]['program_code']??'unknown'; $session=tahsinSessionDate($first['tanggal']);
        $payloads=[]; $exact=[];
        foreach ($rows as $r) { $payloads[serialize(tahsinRecordPayload($r))]=true; $k=($r['halaqoh_id']??'null').':'.$r['tanggal']; $exact[$k]=($exact[$k]??0)+1; }
        $e=[count($payloads)>1?'Isi presensi berbeda; pilih catatan resmi setelah konfirmasi guru':'Beberapa baris dengan isi kehadiran sama; baris asal tetap disimpan'];
        $exactCount=count(array_filter($exact,static fn($n)=>$n>1));
        if ($exactCount) $e[]=$exactCount.' kombinasi peserta–halaqoh–tanggal memiliki lebih dari satu baris';
        $add('attendance',[(int)$first['wali_santri_id'],$program,$session],[(int)$first['wali_santri_id']],$e,$rows,$program,$session);
        $findings[array_key_last($findings)]['group_ids']=array_values(array_unique(array_map(static fn($r)=>(int)($r['halaqoh_id']??0),$rows)));
    }
    foreach ($drafts as $d) if ($d['state']==='conflict') {
        $program=$groupMap[(int)$d['halaqoh_id']]['program_code']??'unknown';
        $add('draft',[(int)$d['user_id'],(int)$d['halaqoh_id'],$d['session_date'],(int)$d['wali_id']],[(int)$d['wali_id']],['Draft server perlu ditinjau akun pengajar #'.$d['user_id'].'. Ini bukan bukti identitas ganda.'],[$d],$program,$d['session_date']);
        $findings[array_key_last($findings)]['group_ids']=[(int)$d['halaqoh_id']];
    }
    usort($findings,static fn($a,$b)=>[$a['kind'],$a['names'][0],$a['session']??'',$a['key']]<=>[$b['kind'],$b['names'][0],$b['session']??'',$b['key']]);
    return $findings;
}

function dataQualityAudit(PDO $pdo, bool $lock=false): array
{
    $suffix=$lock?' FOR UPDATE':'';
    $parents=$pdo->query('SELECT * FROM wali_santri ORDER BY id'.$suffix)->fetchAll(PDO::FETCH_ASSOC);
    $children=$pdo->query('SELECT * FROM santri_detail ORDER BY id'.$suffix)->fetchAll(PDO::FETCH_ASSOC);
    $members=$pdo->query('SELECT * FROM halaqoh_members ORDER BY id'.$suffix)->fetchAll(PDO::FETCH_ASSOC);
    $records=$pdo->query('SELECT * FROM presensi ORDER BY id'.$suffix)->fetchAll(PDO::FETCH_ASSOC);
    $programs=tahsinGroupPrograms($pdo);
    $groups=$pdo->query('SELECT * FROM halaqoh ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($groups as &$g) $g['program_code']=$programs[(int)$g['id']]??'unknown'; unset($g);
    try { $drafts=$pdo->query('SELECT * FROM tahsin_autosave_drafts ORDER BY user_id,halaqoh_id,session_date,wali_id'.$suffix)->fetchAll(PDO::FETCH_ASSOC); }
    catch (PDOException $e) { if ($e->getCode()!=='42S02') throw $e; $drafts=[]; }
    $contexts=[];
    foreach ($drafts as &$draft) {
        try {
            $key=$draft['user_id'].':'.$draft['halaqoh_id'].':'.$draft['session_date'];
            $ctx=$contexts[$key]??=autosaveContext($pdo,(int)$draft['user_id'],(int)$draft['halaqoh_id'],$draft['session_date']);
            $entry=autosaveEntry($pdo,(int)$draft['user_id'],$ctx,(int)$draft['wali_id']);
            if ($entry['state']==='conflict' || !in_array((int)$draft['wali_id'],array_map('intval',array_column($ctx['members'],'id')),true)) {
                $draft['state']='conflict'; $draft['quality_official_revision']=$entry['official_revision']; $draft['quality_context_revision']=$ctx['revision'];
            }
        } catch (AutosaveException|JsonException $e) { $draft['state']='conflict'; $draft['quality_reason']='Penugasan, konteks sesi, atau format draft perlu diperiksa.'; }
    } unset($draft);
    $findings=dataQualityDetect($parents,$children,$members,$records,$groups,$drafts);
    $reviews=[];
    try { foreach ($pdo->query('SELECT * FROM data_quality_reviews ORDER BY review_id')->fetchAll(PDO::FETCH_ASSOC) as $r) $reviews[$r['finding_key']]=$r; }
    catch (PDOException $e) { if (!in_array($e->getCode(),['42S02','42S22'],true)) throw $e; }
    foreach ($findings as &$f) {
        $r=$reviews[$f['key']]??null;
        if ($r && hash_equals($r['fingerprint'],$f['fingerprint'])) { $f['status']=$r['decision']; $f['review']=$r; }
        if ($f['kind']==='attendance' && tahsinSchemaReady($pdo)) {
            $scope=tahsinScopedSession($pdo,$f['wali_ids'][0],$f['session'],$f['program']);
            $decision=$scope['verification'];
            if ($decision) {
                $f['decision']=$decision;
                if (($decision['decision_action']??'select')!=='reset' && hash_equals($decision['fingerprint'],tahsinFingerprint($f['records']))) $f['status']='resolved';
            }
        }
    } unset($f);
    return $findings;
}

function dataQualityReview(PDO $pdo,int $actorId,string $key,string $fingerprint,string $decision,string $note,string $operation): void
{
    if (!dataQualitySchemaReady($pdo)) throw new ParticipantManagementException('Migrasi pemeriksaan data belum siap.');
    if ($pdo->inTransaction() || !preg_match('/^[a-f0-9]{64}$/D',$key) || !preg_match('/^[a-f0-9]{64}$/D',$fingerprint) || !preg_match('/^[a-f0-9]{32}$/D',$operation) || !in_array($decision,['distinct','pending'],true) || trim($note)==='' || appTextLength($note)>2000) throw new ParticipantManagementException('Isian pemeriksaan tidak valid. Alasan wajib diisi.');
    $pdo->beginTransaction();
    try {
        attendanceHistoryActor($pdo,$actorId,['admin','pj_tahfidz']);
        $q=$pdo->prepare('SELECT * FROM data_quality_reviews WHERE operation_id=? FOR UPDATE'); $q->execute([$operation]);
        if ($old=$q->fetch(PDO::FETCH_ASSOC)) {
            if ((int)$old['actor_id']!==$actorId || $old['finding_key']!==$key || $old['fingerprint']!==$fingerprint || $old['decision']!==$decision || $old['note']!==$note) throw new ParticipantManagementException('Nomor operasi telah digunakan untuk pemeriksaan lain.');
            $pdo->commit(); return;
        }
        $matches=array_values(array_filter(dataQualityAudit($pdo,true),static fn($f)=>$f['key']===$key));
        if (!$matches || !hash_equals($matches[0]['fingerprint'],$fingerprint)) throw new ParticipantManagementException('Data sumber berubah. Buka pemeriksaan terbaru.');
        if ($decision==='distinct' && $matches[0]['kind']!=='identity') throw new ParticipantManagementException('Penempatan dan presensi diselesaikan melalui koreksi sumber, bukan disembunyikan.');
        $pdo->prepare('INSERT INTO data_quality_reviews(operation_id,finding_key,fingerprint,decision,note,actor_id) VALUES(?,?,?,?,?,?)')->execute([$operation,$key,$fingerprint,$decision,$note,$actorId]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
