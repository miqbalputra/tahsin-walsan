<?php
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/auth_helper.php';
require_once __DIR__.'/participant_merge_helper.php';
require_once __DIR__.'/participant_merge_view.php';
checkRole(['admin']);
$error=''; $input=[]; $keepId=null; $query=''; $page=1; $duplicatePage=1;
$actorId=(int)$_SESSION['user_id'];
$pending=$_SESSION['participant_merge_pending']??null;
if ($pending && (($pending['actor']??0)!==$actorId || ($pending['expires']??0)<time())) { unset($_SESSION['participant_merge_pending']); $pending=null; }
$ready=participantMergeSchemaReady($pdo);
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        requireValidCsrfToken($_POST['csrf_token']??null);
        $action=appRequestString($_POST,'action'); $token=appRequestString($_POST,'merge_token');
        if (!$pending || !preg_match('/^[a-f0-9]{32}$/D',$token) || !hash_equals($pending['token'],$token)) throw new ParticipantManagementException('Pratinjau kedaluwarsa atau berasal dari sesi lain. Buat pratinjau baru.');
        if ($action==='cancel') { unset($_SESSION['participant_merge_pending']); header('Location: merge-peserta.php'); exit; }
        if ($action!=='apply') throw new ParticipantManagementException('Buka pratinjau penggabungan terbaru sebelum menyimpan.');
        $input=array_intersect_key($_POST,array_flip(['fields','memberships','drafts','baseline','confirmed']));
        $result=participantMergeApply($pdo,$actorId,$pending['plan'],$input,$pending['token']);
        $_SESSION['participant_merge_pending']['completed']=true;
        header('Location: peserta-riwayat.php?operation='.$result['operation_id']); exit;
    }
    $keepId=participantId($_GET['keep_id']??'',true);
    $sourceId=participantId($_GET['delete_id']??'',true);
    $query=participantText($_GET,'q',100);
    $page=filter_var(appRequestString($_GET,'page','1'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
    if (!$page) throw new ParticipantManagementException('Nomor halaman tidak valid.');
    $duplicatePage=filter_var(appRequestString($_GET,'dup_page','1'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
    if (!$duplicatePage) throw new ParticipantManagementException('Nomor halaman nama berulang tidak valid.');
    if ($pending && !empty($pending['completed'])) {
        if ($keepId===$pending['plan']['keep_id'] && $sourceId===$pending['plan']['source_id']) {
            header('Location: peserta-riwayat.php?operation='.$pending['token']); exit;
        }
        // Keep the receipt in the session for an immediate POST retry, while
        // allowing the operator to start selecting the next pair.
        $pending=null;
    }
    if ($sourceId!==null) {
        if ($keepId===null) throw new ParticipantManagementException('Pilih peserta yang dipertahankan terlebih dahulu.');
        $plan=participantMergePlan($pdo,$keepId,$sourceId);
        $pending=['token'=>bin2hex(random_bytes(16)),'actor'=>$actorId,'expires'=>time()+1800,'plan'=>$plan];
        $_SESSION['participant_merge_pending']=$pending;
    }
} catch (Throwable $failure) {
    $expected=$failure instanceof ParticipantManagementException || $failure instanceof InvalidArgumentException;
    if (!$expected) reportApplicationError($failure,'participant-merge');
    http_response_code($expected?422:503);
    $error=$expected?$failure->getMessage():'Status penyimpanan belum dapat dipastikan. Periksa riwayat perubahan, lalu coba lagi dengan formulir yang sama atau hubungi pengelola.';
}
$selection=null; $candidates=[]; $pages=1; $duplicates=[]; $duplicatePages=1; $duplicateCount=0;
if (!is_int($page) || $page<1) $page=1;
if (!is_int($duplicatePage) || $duplicatePage<1) $duplicatePage=1;
if (!$pending) {
    try {
        if ($keepId!==null) $selection=participantSnapshot($pdo,$keepId);
        $parameters=[$keepId??0]; $where='id<>?';
        if ($query!=='') { $where.=' AND (nama_bapak LIKE ? OR no_hp LIKE ?)'; $parameters[]='%'.$query.'%'; $parameters[]='%'.$query.'%'; }
        $statement=$pdo->prepare('SELECT COUNT(*) FROM wali_santri WHERE '.$where); $statement->execute($parameters);
        $pages=max(1,(int)ceil((int)$statement->fetchColumn()/50)); $page=min($page,$pages);
        $statement=$pdo->prepare('SELECT id,nama_bapak,no_hp,status_aktif FROM wali_santri WHERE '.$where.' ORDER BY nama_bapak,id LIMIT 50 OFFSET '.(($page-1)*50)); $statement->execute($parameters); $candidates=$statement->fetchAll(PDO::FETCH_ASSOC);
        $duplicateCount=(int)$pdo->query('SELECT COUNT(*) FROM (SELECT nama_bapak FROM wali_santri GROUP BY nama_bapak HAVING COUNT(*)>1) repeated_names')->fetchColumn();
        $duplicatePages=max(1,(int)ceil($duplicateCount/20)); $duplicatePage=min($duplicatePage,$duplicatePages);
        $duplicates=$pdo->query('SELECT nama_bapak,COUNT(*) AS total FROM wali_santri GROUP BY nama_bapak HAVING COUNT(*)>1 ORDER BY nama_bapak LIMIT 20 OFFSET '.(($duplicatePage-1)*20))->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $failure) {
        $expected=$failure instanceof ParticipantManagementException || $failure instanceof InvalidArgumentException;
        if (!$expected) reportApplicationError($failure,'merge-selection');
        $error=$expected?$failure->getMessage():'Daftar peserta belum dapat dibuka. Kembali ke daftar lalu coba lagi.'; http_response_code($expected?422:503);
    }
}
$pageUrl=static fn($target)=>'merge-peserta.php?'.http_build_query(['keep_id'=>$keepId??'','q'=>$query,'page'=>$target,'dup_page'=>$duplicatePage]);
$duplicateUrl=static fn($target)=>'merge-peserta.php?'.http_build_query(['keep_id'=>$keepId??'','q'=>$query,'page'=>$page,'dup_page'=>$target]);
$pageTitle='Gabung Peserta Duplikat'; require __DIR__.'/header.php'; require __DIR__.'/sidebar.php';
?>
<div class="max-w-5xl mx-auto">
<a href="peserta.php" class="inline-block underline text-blue-700 py-3">Kembali ke daftar peserta</a>
<h1 class="text-2xl font-bold mb-4">Gabung peserta duplikat</h1>
<p class="text-slate-600 mb-5">Gunakan hanya jika dua ID benar-benar milik orang yang sama. Nama yang sama belum membuktikan identitas. Anak dan presensi mempertahankan ID; data yang berbeda ditinjau sebelum disimpan.</p>
<?php if ($error): ?><p role="alert" class="rounded-xl bg-red-50 text-red-800 p-4 mb-5"><?= appEscape($error) ?> <a href="peserta-riwayat.php" class="underline">Buka riwayat perubahan</a></p><?php endif; ?>
<?php if (!$ready): ?><p class="rounded-xl bg-amber-50 text-amber-900 p-4 mb-5">Pengelola perlu menyiapkan jurnal dan pengalihan ID penggabungan. Data peserta yang sudah ada tetap tersedia.</p><?php endif; ?>
<?php if (dataIndukConfig()['enabled']): ?><p class="rounded-xl bg-blue-50 text-blue-800 p-4 mb-5">Penggabungan identitas dilakukan melalui Data Induk saat integrasi tersebut aktif.</p><?php endif; ?>
<?php if ($pending): $plan=$pending['plan']; ?>
    <h2 class="font-bold text-xl mb-3">Pratinjau — data belum diubah</h2>
    <p class="mb-4">ID <?= $plan['source_id'] ?> akan digabung ke ID <?= $plan['keep_id'] ?>. Tautan ID lama diarahkan ke peserta yang dipertahankan. Pratinjau berlaku 30 menit.</p>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6"><?php participantMergeRenderPerson($plan['keep'],'Peserta yang dipertahankan'); participantMergeRenderPerson($plan['source'],'Peserta sumber'); ?></div>
    <p class="rounded-xl bg-amber-50 text-amber-900 p-4 mb-5">Semua ID anak dipertahankan, termasuk nama anak yang sama. Periksa identitas anak sesudah penggabungan; jangan menghapus hanya karena namanya sama. Presensi yang berbeda tidak disatukan menjadi satu catatan otomatis. Draft perlu ditinjau ulang sebelum menjadi data resmi.</p>
    <form method="post" class="space-y-6">
        <?php csrfField(); ?><input type="hidden" name="action" value="apply"><input type="hidden" name="merge_token" value="<?= appEscape($pending['token']) ?>">
        <fieldset class="rounded-xl border border-slate-200 p-4 bg-white"><legend class="font-bold px-2">Biodata yang akan aktif</legend><p class="text-sm text-slate-600 mb-4">Pilih dari peserta yang dipertahankan atau peserta sumber. Kedua biodata asal disimpan dalam riwayat.</p>
            <?php foreach (PARTICIPANT_MERGE_FIELDS as $field): $selected=is_string($input['fields'][$field]??null)?$input['fields'][$field]:'keep'; ?>
            <fieldset class="mb-4 p-3 border border-slate-200 rounded-xl"><legend class="font-bold px-2"><?= appEscape(participantMergeFieldLabel($field)) ?></legend><div class="grid grid-cols-1 md:grid-cols-2 gap-3"><?php foreach (['keep'=>'Dipertahankan','source'=>'Sumber'] as $side=>$label): ?><label class="flex items-start gap-3 p-2"><input type="radio" name="fields[<?= $field ?>]" value="<?= $side ?>" <?= $selected===$side?'checked':'' ?> required class="mt-1"><span><strong><?= $label ?></strong><span class="block whitespace-pre-line break-words"><?= appEscape(participantMergeFieldValue($field,$plan[$side]['parent'][$field])) ?></span></span></label><?php endforeach; ?></div></fieldset>
            <?php endforeach; ?>
        </fieldset>
        <fieldset class="rounded-xl border border-slate-200 p-4 bg-white"><legend class="font-bold px-2">Keanggotaan aktif sesudah digabung</legend>
            <?php foreach (['bapak','ibu'] as $program): $groups=[]; foreach (['keep','source'] as $side) { $groups[$side]=[]; foreach ($plan[$side]['memberships'] as $row) if ($row['participant_program']===$program && $row['archived_at']===null) $groups[$side][(int)$row['halaqoh_id']]=$row['participant_group_name']; ksort($groups[$side]); } $conflict=$groups['keep'] && $groups['source'] && array_keys($groups['keep'])!==array_keys($groups['source']); $selected=is_string($input['memberships'][$program]??null)?$input['memberships'][$program]:($conflict?'':'combine'); ?>
                <div class="mb-4"><label for="merge-program-<?= $program ?>" class="font-bold block mb-2">Program <?= ucfirst($program) ?></label><p class="text-sm mb-2">Dipertahankan: <?= appEscape(implode(', ',$groups['keep'])?:'belum ditempatkan') ?>. Sumber: <?= appEscape(implode(', ',$groups['source'])?:'belum ditempatkan') ?>.</p><select id="merge-program-<?= $program ?>" name="memberships[<?= $program ?>]" required class="w-full border border-slate-300 p-3 rounded-xl"><option value="">Pilih penempatan program ini</option><?php foreach (['keep'=>'Gunakan penempatan peserta yang dipertahankan','source'=>'Gunakan penempatan peserta sumber','combine'=>'Pertahankan gabungan penempatan kedua peserta'] as $value=>$label): ?><option value="<?= $value ?>" <?= $selected===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
            <?php endforeach; ?><p class="text-sm text-slate-600">Keanggotaan lama tetap tercatat. Keanggotaan ganda pada halaqoh yang sama diarsipkan. Jika status hasil Arsip atau seluruh anak Lulus, seluruh keanggotaan aktif ditutup.</p>
        </fieldset>
        <?php $baselines=[]; foreach ($plan['related']['tahsin_capaian_awal']??[] as $row) $baselines[(int)$row['wali_santri_id']===$plan['keep_id']?'keep':'source']=$row; ?>
        <?php if ($baselines): ?><fieldset class="rounded-xl border border-slate-200 p-4 bg-white"><legend class="font-bold px-2">Capaian awal yang akan aktif</legend><p class="text-sm mb-4">Jika ada dua versi, pilih satu sebagai capaian awal aktif. Kedua versi asal tetap dapat dibaca pada riwayat.</p><div class="grid grid-cols-1 md:grid-cols-2 gap-4"><?php foreach ($baselines as $side=>$row): $selected=is_string($input['baseline']??null)?$input['baseline']:(count($baselines)===1?$side:''); ?><section class="border border-slate-200 rounded-xl p-4"><label class="flex gap-3 font-bold mb-3"><input type="radio" name="baseline" value="<?= $side ?>" <?= $selected===$side?'checked':'' ?> required> <?= $side==='keep'?'Versi peserta dipertahankan':'Versi peserta sumber' ?></label><?php participantMergeRenderPayload($row); ?></section><?php endforeach; ?></div></fieldset><?php endif; ?>
        <?php foreach (participantMergeDrafts($plan) as $key=>$pair): $conflict=isset($pair['keep'],$pair['source']) && $pair['keep']['payload_json']!==$pair['source']['payload_json']; $selected=is_string($input['drafts'][$key]??null)?$input['drafts'][$key]:($conflict?'':(isset($pair['keep'])?'keep':'source')); $first=reset($pair); ?>
        <fieldset class="rounded-xl border border-slate-200 p-4 bg-white"><legend class="font-bold px-2">Draft · akun <?= (int)$first['user_id'] ?> · halaqoh <?= (int)$first['halaqoh_id'] ?> · <?= appEscape($first['session_date']) ?></legend><p class="text-sm mb-4">Pilih draft yang akan tersedia untuk ditinjau ulang. Versi lainnya tersimpan pada riwayat penggabungan.</p><div class="grid grid-cols-1 md:grid-cols-2 gap-4"><?php foreach ($pair as $side=>$row): ?><section class="border border-slate-200 rounded-xl p-4"><label class="flex gap-3 font-bold mb-3"><input type="radio" name="drafts[<?= appEscape($key) ?>]" value="<?= $side ?>" <?= $selected===$side?'checked':'' ?> required> <?= $side==='keep'?'Draft peserta dipertahankan':'Draft peserta sumber' ?></label><?php participantMergeRenderPayload($row,true); ?></section><?php endforeach; ?></div></fieldset>
        <?php endforeach; ?>
        <label class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4"><input type="checkbox" name="confirmed" value="1" required class="mt-1"> Saya sudah memastikan kedua peserta adalah orang yang sama dan memeriksa biodata, anak, keanggotaan, capaian awal serta draft. Saya memahami bahwa data yang dipilih akan aktif dan versi asal tersimpan di riwayat.</label>
        <button class="bg-blue-600 text-white px-5 py-3 rounded-xl">Gabungkan ke ID <?= $plan['keep_id'] ?></button>
    </form>
    <form method="post" class="mt-4"><?php csrfField(); ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="merge_token" value="<?= appEscape($pending['token']) ?>"><button class="border border-slate-300 rounded-xl px-5 py-3">Batalkan pratinjau dan pilih ulang</button></form>
<?php else: ?>
    <?php if ($duplicates): ?><details class="border border-amber-200 bg-amber-50 rounded-xl p-4 mb-5"><summary class="font-bold cursor-pointer py-2">Nama berulang untuk ditinjau · <?= $duplicateCount ?> kelompok</summary><p class="text-sm my-3">Nama yang sama dapat dimiliki orang berbeda. Buka daftar kandidat dan periksa nomor HP serta anak sebelum memilih.</p><ul class="space-y-2"><?php foreach ($duplicates as $duplicate): ?><li><a class="underline text-blue-700 py-2 inline-block" href="<?= appEscape('merge-peserta.php?'.http_build_query(['keep_id'=>$keepId??'','q'=>$duplicate['nama_bapak']])) ?>"><?= appEscape($duplicate['nama_bapak']) ?> — <?= (int)$duplicate['total'] ?> peserta</a></li><?php endforeach; ?></ul><nav aria-label="Halaman nama berulang" class="flex flex-wrap gap-4 mt-4"><span>Halaman <?= $duplicatePage ?> dari <?= $duplicatePages ?></span><?php if ($duplicatePage>1): ?><a class="underline text-blue-700" href="<?= appEscape($duplicateUrl($duplicatePage-1)) ?>">Sebelumnya</a><?php endif; ?><?php if ($duplicatePage<$duplicatePages): ?><a class="underline text-blue-700" href="<?= appEscape($duplicateUrl($duplicatePage+1)) ?>">Berikutnya</a><?php endif; ?></nav></details><?php endif; ?>
    <h2 class="font-bold text-xl mb-3"><?= $selection?'Pilih peserta sumber yang akan digabung':'Pilih peserta yang dipertahankan' ?></h2>
    <?php if ($selection): ?><p class="mb-4">Dipertahankan: <strong><?= appEscape($selection['parent']['nama_bapak']) ?></strong>, ID <?= $keepId ?>. <a class="underline text-blue-700" href="merge-peserta.php">Ganti pilihan</a></p><?php endif; ?>
    <form method="get" class="flex flex-wrap items-end gap-3 mb-5"><?php if ($keepId!==null): ?><input type="hidden" name="keep_id" value="<?= $keepId ?>"><?php endif; ?><div class="flex-1"><label for="merge-search" class="block font-bold mb-2">Cari nama atau nomor HP</label><input id="merge-search" name="q" value="<?= appEscape($query) ?>" maxlength="100" class="w-full border border-slate-300 rounded-xl p-3"></div><button class="bg-blue-600 text-white px-5 py-3 rounded-xl">Cari peserta</button></form>
    <?php if (!$candidates): ?><p>Belum ada peserta yang cocok. Ubah pencarian atau kembali ke daftar.</p><?php endif; ?>
    <ul class="space-y-3"><?php foreach ($candidates as $candidate): ?><li class="border border-slate-200 bg-white rounded-xl p-4"><strong><?= appEscape($candidate['nama_bapak']) ?></strong><p class="text-sm mb-3">ID <?= (int)$candidate['id'] ?> · <?= appEscape($candidate['no_hp']?:'tanpa nomor HP') ?> · <?= (int)$candidate['status_aktif']===1?'aktif':'arsip' ?></p><a class="inline-block underline text-blue-700 py-2" href="<?= appEscape('merge-peserta.php?'.http_build_query($selection?['keep_id'=>$keepId,'delete_id'=>$candidate['id']]:['keep_id'=>$candidate['id']])) ?>"><?= $selection?'Buka pratinjau dengan peserta ini':'Pertahankan peserta ini' ?></a></li><?php endforeach; ?></ul>
    <nav aria-label="Halaman pilihan peserta" class="flex flex-wrap gap-4 py-5"><p>Halaman <?= $page ?> dari <?= $pages ?></p><?php if ($page>1): ?><a class="underline text-blue-700" href="<?= appEscape($pageUrl($page-1)) ?>">Sebelumnya</a><?php endif; ?><?php if ($page<$pages): ?><a class="underline text-blue-700" href="<?= appEscape($pageUrl($page+1)) ?>">Berikutnya</a><?php endif; ?></nav>
<?php endif; ?>
</div>
<?php require __DIR__.'/footer.php'; ?>
