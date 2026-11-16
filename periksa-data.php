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
    $total=count($rows); $pages=max(1,(int)ceil($total/20)); $page=min($page,$pages); $rows=array_slice($rows,($page-1)*20,20);
    $ready=dataQualitySchemaReady($pdo);
} catch (Throwable $e) {
    if ($e instanceof ParticipantManagementException || $e instanceof InvalidArgumentException) { http_response_code(422); $error=$e->getMessage(); }
    else { reportApplicationError($e,'data-quality'); http_response_code(503); $error='Pemeriksaan belum dapat diselesaikan. Data sumber belum diubah; periksa riwayat lalu coba kembali.'; }
    $rows=[]; $total=0; $page=$pages=1; $filters=$filters??[]; $groups=$groups??[]; $counts=$counts??[]; $ready=false;
}
$labels=['identity'=>'Dugaan identitas ganda','membership'=>'Keanggotaan aktif ganda','attendance'=>'Presensi berulang / berbeda','draft'=>'Konflik draft server'];
$states=['pending'=>'Perlu ditinjau','resolved'=>'Catatan resmi dipilih','distinct'=>'Identitas berbeda'];
$pageTitle='Pemeriksaan Data Ganda'; require __DIR__.'/includes/header.php'; require __DIR__.'/includes/sidebar.php';
?>
<main class="max-w-5xl mx-auto">
<a class="underline text-blue-700 inline-block py-3" href="peserta.php">Kembali ke peserta</a>
<h1 class="text-2xl font-bold mb-4">Pemeriksaan data ganda</h1>
<p class="mb-4">Nama atau nomor keluarga yang sama belum membuktikan orang yang sama. Pemeriksaan tidak menggabungkan atau menghapus data otomatis. Draft yang hanya ada di HP tidak dapat diperiksa server.</p>
<?php if ($error): ?><p role="alert" class="bg-red-50 text-red-800 p-4 rounded-xl mb-4"><?= appEscape($error) ?></p><?php endif; ?>
<?php if (($message??'')==='saved'): ?><p role="status" class="bg-blue-50 p-4 mb-4">Keputusan tersimpan. Catatan sumber tetap tersedia.</p><?php endif; ?>
<?php if (!$ready): ?><p class="bg-amber-50 p-4 mb-4">Mode pemeriksaan saja. Pengelola perlu memasang migrasi pemeriksaan data sebelum menyimpan keputusan atau melakukan undo.</p><?php endif; ?>
<p class="mb-4"><?php foreach ($labels as $kind=>$label): ?><?= appEscape($label) ?>: <strong><?= (int)($counts[$kind]??0) ?></strong>. <?php endforeach; ?></p>
<form method="get" class="bg-white border rounded-xl p-4 mb-5 space-y-3">
<label class="block">Nama / ID peserta <input class="border rounded-xl p-3 w-full" name="search" maxlength="100" value="<?= appEscape($filters['search']??'') ?>"></label>
<div class="flex flex-wrap gap-3">
<?php foreach (['program'=>[''=>'Semua program','bapak'=>'Bapak','ibu'=>'Ibu','unknown'=>'Program belum diketahui'],'kind'=>[''=>'Semua jenis']+$labels,'status'=>['pending'=>'Perlu ditinjau','all'=>'Semua status','resolved'=>'Catatan resmi dipilih','distinct'=>'Identitas berbeda']] as $field=>$options): ?>
<label><?= appEscape(['program'=>'Program','kind'=>'Jenis','status'=>'Status'][$field]) ?><select class="border rounded-xl p-3 block" name="<?= $field ?>"><?php foreach ($options as $value=>$label): ?><option value="<?= appEscape($value) ?>" <?= ($filters[$field]??'')===$value?'selected':'' ?>><?= appEscape($label) ?></option><?php endforeach; ?></select></label>
<?php endforeach; ?>
<label>Halaqoh<select class="border rounded-xl p-3 block" name="halaqoh"><option value="">Semua halaqoh</option><?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= ($filters['halaqoh']??'')===(string)$g['id']?'selected':'' ?>><?= appEscape($g['nama_halaqoh']) ?></option><?php endforeach; ?></select></label>
<?php foreach (['from'=>'Dari tanggal','to'=>'Sampai tanggal'] as $field=>$label): ?><label><?= $label ?><input class="border rounded-xl p-3 block" type="date" name="<?= $field ?>" value="<?= appEscape($filters[$field]??'') ?>"></label><?php endforeach; ?>
</div><button class="bg-blue-600 text-white rounded-xl px-5 py-3">Periksa</button>
<a class="underline text-blue-700 inline-block p-3" href="?<?= appEscape(http_build_query($filters+['download'=>'json'])) ?>">Unduh hasil JSON (data pribadi)</a>
</form>
<p class="mb-4"><?= $total ?> temuan cocok · halaman <?= $page ?> dari <?= $pages ?>.</p>
<?php if (!$rows): ?><p class="p-4 bg-white rounded-xl">Tidak ada temuan pada filter ini. Ini bukan bukti bahwa draft semua perangkat sudah tersimpan.</p><?php endif; ?>
<?php foreach ($rows as $f):
    $op=bin2hex(random_bytes(16));
    $_SESSION['quality_previews'][$op]=['actor'=>$actor,'expires'=>time()+1800,'kind'=>$f['kind'],'key'=>$f['key'],'fingerprint'=>$f['fingerprint'],
        'wali'=>$f['wali_ids'][0],'program'=>$f['program'],'session'=>$f['session'],'source_fingerprint'=>tahsinFingerprint($f['records']),'decision_id'=>(int)($f['decision']['id']??0)];
    while (count($_SESSION['quality_previews'])>80) array_shift($_SESSION['quality_previews']);
?>
<section class="bg-white border rounded-xl p-4 mb-5" id="finding-<?= appEscape($f['key']) ?>">
<h2 class="text-xl font-bold mb-2"><?= appEscape($labels[$f['kind']]) ?></h2>
<p class="font-bold"><?= appEscape(implode(' · ',$f['names'])) ?></p>
<p class="mb-3"><?= appEscape($states[$f['status']]??$f['status']) ?><?= $f['program']?' · '.appEscape($f['program']):'' ?><?= $f['session']?' · sesi '.appEscape($f['session']):'' ?></p>
<ul class="list-disc pl-5 mb-4"><?php foreach ($f['evidence'] as $e): ?><li><?= appEscape($e) ?></li><?php endforeach; ?></ul>
<?php if ($f['kind']==='identity'): ?>
<a class="underline text-blue-700 inline-block py-3" href="<?= appEscape('merge-peserta.php?'.http_build_query(['keep_id'=>$f['wali_ids'][0],'delete_id'=>$f['wali_ids'][1]])) ?>">Bandingkan untuk merge (admin)</a>
<?php if ($ready): ?><form method="post" class="space-y-3"><?php csrfField(); ?><input type="hidden" name="operation" value="<?= $op ?>"><input type="hidden" name="action" value="<?= $f['status']==='distinct'?'pending':'distinct' ?>"><label class="block">Alasan pemeriksaan<input class="border rounded-xl p-3 w-full" name="note" maxlength="2000" required></label><button class="border rounded-xl px-5 py-3"><?= $f['status']==='distinct'?'Buka pemeriksaan kembali':'Tandai sebagai orang berbeda' ?></button></form><?php endif; ?>
<?php elseif ($f['kind']==='membership'): ?>
<p class="mb-3">Pilih satu halaqoh pada program ini melalui formulir peserta. Penempatan lama diarsipkan; presensi dan riwayat sebelumnya tetap tersimpan.</p><a class="underline text-blue-700 inline-block py-3" href="peserta-form.php?id=<?= $f['wali_ids'][0] ?>">Tinjau penempatan peserta</a>
<?php elseif ($f['kind']==='attendance'): ?>
<div class="overflow-x-auto"><table class="w-full mb-4"><thead><tr><th>Catatan</th><th>Tanggal</th><th>Halaqoh</th><th>Isi sumber</th></tr></thead><tbody>
<?php foreach ($f['records'] as $r): ?><tr class="border-t"><td class="p-3">#<?= (int)$r['id'] ?></td><td class="p-3"><?= appEscape($r['tanggal']) ?></td><td class="p-3"><?= appEscape($groupNames[$r['halaqoh_id']]??'Halaqoh belum diketahui') ?></td><td class="p-3"><?php participantMergeRenderPayload($r); ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php if ($ready): ?><form method="post" class="space-y-3"><?php csrfField(); ?><input type="hidden" name="operation" value="<?= $op ?>">
<label class="block">Catatan resmi<select class="border rounded-xl p-3 w-full" name="selected_id"><option value="">Pilih setelah memeriksa sumber</option><?php foreach ($f['records'] as $r): ?><option value="<?= (int)$r['id'] ?>">#<?= (int)$r['id'] ?> · <?= appEscape($r['tanggal']) ?> · <?= appEscape($groupNames[$r['halaqoh_id']]??'Tanpa halaqoh') ?> · <?= appEscape($r['status']) ?></option><?php endforeach; ?><option value="0">Semua hanya capaian awal, bukan kehadiran</option></select></label>
<label class="block">Alasan / sumber konfirmasi<input class="border rounded-xl p-3 w-full" name="note" required maxlength="2000"></label>
<button class="bg-blue-600 text-white rounded-xl px-5 py-3" name="action" value="select">Simpan pilihan catatan resmi</button>
<?php if (!empty($f['decision']['operation_id'])): ?><button class="border rounded-xl px-5 py-3" name="action" value="undo_decision">Batalkan keputusan terakhir</button><p>Keputusan terakhir #<?= (int)$f['decision']['id'] ?>: <?= appEscape($f['decision']['note']) ?>. Pembatalan menambahkan jejak baru.</p><?php endif; ?>
</form><?php endif; ?>
<?php else: ?><p>Pengajar membuka kembali sesi untuk membandingkan isian HP, draft server, dan catatan resmi. Unduh draft HP sebelum mengganti perangkat.</p><?php endif; ?>
<a class="underline text-blue-700 inline-block py-3" href="peserta-riwayat.php?id=<?= $f['wali_ids'][0] ?>">Riwayat peserta</a>
</section>
<?php endforeach; ?>
<nav class="flex gap-4 py-5" aria-label="Halaman pemeriksaan"><?php if ($page>1): ?><a class="underline" href="?<?= appEscape(http_build_query($filters+['page'=>$page-1])) ?>">Sebelumnya</a><?php endif; ?><?php if ($page<$pages): ?><a class="underline" href="?<?= appEscape(http_build_query($filters+['page'=>$page+1])) ?>">Berikutnya</a><?php endif; ?></nav>
</main>
<?php require __DIR__.'/includes/footer.php'; ?>
