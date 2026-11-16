<?php
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/includes/auth_helper.php';
require_once __DIR__.'/includes/session_decision_helper.php';
require_once __DIR__.'/includes/participant_merge_view.php';
checkRole(['admin','pj_tahfidz']);
$error=''; $actor=(int)$_SESSION['user_id'];
foreach ($_SESSION['quality_previews']??[] as $key=>$p) if ($p['expires']<time()) unset($_SESSION['quality_previews'][$key]);
try {
    $message=appRequestString($_GET,'msg');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        requireValidCsrfToken($_POST['csrf_token']??null);
        $operation=appRequestString($_POST,'operation'); $action=appRequestString($_POST,'action');
        $p=$_SESSION['quality_previews'][$operation]??null;
        if (!$p || $p['actor']!==$actor || $p['expires']<time()) throw new ParticipantManagementException('Pratinjau kedaluwarsa. Buka pemeriksaan terbaru.');
        $note=participantText($_POST,'note',2000,true);
        if (in_array($action,['distinct','pending'],true) && $p['kind']==='identity') dataQualityReview($pdo,$actor,$p['key'],$p['fingerprint'],$action,$note,$operation);
        elseif (in_array($action,['select','undo_decision'],true) && $p['kind']==='attendance') {
            $selected=appRequestString($_POST,'selected_id');
            if ($action==='select' && ($selected==='' || !ctype_digit($selected) || filter_var($selected,FILTER_VALIDATE_INT)===false)) throw new ParticipantManagementException('Pilih catatan yang sah.');
            dataQualityChooseRecord($pdo,$actor,$p['wali'],$p['session'],$p['program'],$p['source_fingerprint'],$selected===''||$selected==='0'?null:(int)$selected,$note,$operation,$action==='undo_decision'?($p['decision_id']??0):null);
        } else throw new ParticipantManagementException('Tindakan tidak sesuai pratinjau.');
        header('Location: periksa-data.php?'.http_build_query(['search'=>(string)$p['wali'],'status'=>'all','msg'=>'saved'])); exit;
    }
    $filters=[];
    foreach (['search','program','kind','status','from','to','halaqoh'] as $key) $filters[$key]=participantText($_GET,$key,100);
    $filters['status']=$filters['status']?:'pending';
    foreach (['from','to'] as $date) if ($filters[$date]!=='' && !tahsinDate($filters[$date])) throw new ParticipantManagementException('Tanggal filter tidak valid.');
    $findings=dataQualityAudit($pdo); $counts=array_count_values(array_column($findings,'kind'));
    $groups=$pdo->query('SELECT * FROM halaqoh ORDER BY nama_halaqoh,id')->fetchAll(PDO::FETCH_ASSOC); $groupNames=array_column($groups,'nama_halaqoh','id');
    $rows=array_values(array_filter($findings,static function($f)use($filters){
        return ($filters['status']==='all'||$f['status']===$filters['status']) && (!$filters['kind']||$f['kind']===$filters['kind'])
            && (!$filters['program']||$f['program']===null||$f['program']===$filters['program'])
            && (!$filters['halaqoh']||in_array((int)$filters['halaqoh'],$f['group_ids'],true))
            && (!$filters['from']||!$f['session']||$f['session']>=tahsinSessionDate($filters['from']))
            && (!$filters['to']||!$f['session']||$f['session']<=tahsinSessionDate($filters['to']))
            && (!$filters['search']||in_array((int)$filters['search'],$f['wali_ids'],true)||tahsinTextContains(implode(' ',$f['names']),$filters['search']));
    }));
    if (appRequestString($_GET,'download')==='json') {
        header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="pemeriksaan-data-'.date('Ymd-His').'.json"');
        echo json_encode(['generated_at'=>date('c'),'filters'=>$filters,'findings'=>$rows],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR); exit;
    }
    $page=filter_var(appRequestString($_GET,'page','1'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
    if (!$page) throw new ParticipantManagementException('Halaman tidak valid.');
    // Show unresolved placement/identity issues first; source rows stay untouched.
    $priority=['membership'=>0,'identity'=>1,'attendance'=>2,'draft'=>3];
    usort($rows,static fn($a,$b)=>[($a['status']==='pending'?0:1),$priority[$a['kind']],$a['session']??'',$a['key']]<=>[($b['status']==='pending'?0:1),$priority[$b['kind']],$b['session']??'',$b['key']]);
    $total=count($rows); $pages=max(1,(int)ceil($total/20)); $page=min($page,$pages); $rows=array_slice($rows,($page-1)*20,20);
    $ready=dataQualitySchemaReady($pdo);
} catch (Throwable $e) {
    if ($e instanceof ParticipantManagementException || $e instanceof InvalidArgumentException) { http_response_code(422); $error=$e->getMessage(); }
    else { reportApplicationError($e,'data-quality'); http_response_code(503); $error='Pemeriksaan belum dapat diselesaikan. Data sumber belum diubah; periksa riwayat lalu coba kembali.'; }
    $rows=[]; $total=0; $page=$pages=1; $filters=$filters??[]; $groups=$groups??[]; $counts=$counts??[]; $ready=false;
}
$labels=['identity'=>'Dugaan identitas ganda','membership'=>'Keanggotaan aktif ganda','attendance'=>'Presensi berulang / berbeda','draft'=>'Konflik draft server'];
$states=['pending'=>'Perlu ditinjau','resolved'=>'Catatan resmi dipilih','distinct'=>'Identitas berbeda'];
$pageTitle='Periksa Data';
require __DIR__.'/includes/header.php';
require __DIR__.'/includes/sidebar.php';
require __DIR__.'/includes/data_quality_view.php';
require __DIR__.'/includes/footer.php';
