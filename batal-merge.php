<?php
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/includes/auth_helper.php';
require_once __DIR__.'/includes/participant_merge_helper.php';
checkRole(['admin']);
$actor=(int)$_SESSION['user_id']; $error=''; $preview=null;
foreach ($_SESSION['merge_undo_previews']??[] as $key=>$p) if ($p['expires']<time()) unset($_SESSION['merge_undo_previews'][$key]);
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        requireValidCsrfToken($_POST['csrf_token']??null);
        $token=appRequestString($_POST,'undo_token'); $pending=$_SESSION['merge_undo_previews'][$token]??null;
        if (!$pending || $pending['actor']!==$actor || $pending['expires']<time()) throw new ParticipantManagementException('Pratinjau undo kedaluwarsa atau berasal dari sesi lain. Buka pratinjau baru.');
        $input=['confirmed'=>appRequestString($_POST,'confirmed'),'choices'=>$_POST['choices']??[]];
        $result=mergeUndoApply($pdo,$actor,$pending['operation'],$pending['fingerprint'],$input,$token);
        header('Location: peserta-riwayat.php?operation='.$result['operation_id']); exit;
    }
    $operation=appRequestString($_GET,'operation');
    $preview=mergeUndoInspect($pdo,$operation);
    if ($preview['completed']!==null) { header('Location: peserta-riwayat.php?operation='.$preview['completed']['operation_id']); exit; }
    $token=bin2hex(random_bytes(16));
    $_SESSION['merge_undo_previews'][$token]=['actor'=>$actor,'expires'=>time()+1800,'operation'=>$operation,'fingerprint'=>$preview['fingerprint']];
    while (count($_SESSION['merge_undo_previews'])>8) array_shift($_SESSION['merge_undo_previews']);
} catch (Throwable $e) {
    if ($e instanceof ParticipantManagementException || $e instanceof InvalidArgumentException) { $error=$e->getMessage(); http_response_code(422); }
    else { reportApplicationError($e,'merge-undo'); $error='Undo belum dapat dipastikan. Periksa riwayat sebelum mengulang tindakan. Seluruh perubahan dalam operasi yang gagal dibatalkan.'; http_response_code(503); }
}
$pageTitle='Batalkan Merge'; require __DIR__.'/includes/header.php'; require __DIR__.'/includes/sidebar.php';
$show=static function(?array $row): string { return $row===null?'Baris tidak ada':json_encode(json_decode(mergeUndoJson($row),true),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); };
?>
<main class="max-w-5xl mx-auto">
<a class="underline text-blue-700 inline-block py-3" href="peserta-riwayat.php">Riwayat perubahan peserta</a>
<h1 class="text-2xl font-bold mb-4">Pratinjau pembatalan merge</h1>
<?php if ($error): ?><p role="alert" class="bg-amber-50 rounded-xl p-4 mb-4"><?= appEscape($error) ?></p><?php endif; ?>
<?php if ($preview && $preview['completed']===null): ?>
<p class="mb-4">Data belum diubah. ID sumber <strong><?= $preview['source'] ?></strong> akan dipulihkan dan dipisahkan dari ID <strong><?= $preview['keep'] ?></strong>. ID anak dan presensi tetap dipertahankan. Jejak merge dan undo tetap tersedia.</p>
<p class="bg-blue-50 rounded-xl p-4 mb-4">Data asli tersedia dalam <a class="underline" href="peserta-riwayat.php?operation=<?= appEscape($preview['operation']) ?>">riwayat sebelum/sesudah merge</a>. Draft hasil pemulihan wajib ditinjau ulang; isian HP lama tidak dikirim otomatis ke identitas lain. Pratinjau berlaku 30 menit.</p>
<form method="post" class="space-y-5">
<?php csrfField(); ?><input type="hidden" name="undo_token" value="<?= appEscape($token) ?>">
<?php if (!$preview['issues']): ?><p class="bg-white border rounded-xl p-4">Tidak ada perubahan lanjutan yang memerlukan pilihan. Biodata dan hubungan data asal akan dikembalikan. Revisi tetap naik untuk melindungi catatan dari draft basi.</p><?php endif; ?>
<?php foreach ($preview['issues'] as $key=>$issue): ?>
<fieldset class="bg-white border rounded-xl p-4"><legend class="font-bold px-2"><?= appEscape($issue['label']) ?></legend>
<p class="mb-3"><?= appEscape(['new'=>'Data ditambahkan setelah merge. Pilih pemiliknya.','changed'=>'Data diubah setelah merge. Pilih identitas yang menerima perubahan tersebut, atau kembalikan nilai asal.','deleted'=>'Data dihapus setelah merge. Pilih pertahankan penghapusan atau pulihkan nilai asal.'][$issue['kind']]) ?></p>
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
<?php foreach (['Sebelum merge'=>$issue['before_merge'],'Sesudah merge'=>$issue['after_merge'],'Saat ini'=>$issue['current']] as $label=>$row): ?><details class="border rounded-xl p-3"><summary class="font-bold cursor-pointer py-2"><?= $label ?></summary><pre class="text-sm whitespace-pre-wrap break-words"><?= appEscape($show($row)) ?></pre></details><?php endforeach; ?>
</div>
<label class="block font-bold">Keputusan untuk data ini<select class="border rounded-xl p-3 w-full" name="choices[<?= appEscape($key) ?>]" required><option value="">Pilih setelah membandingkan</option>
<?php $options=$issue['kind']==='new'?['keep'=>'Simpan pada ID '.$preview['keep'],'source'=>'Simpan pada ID '.$preview['source']]:($issue['kind']==='deleted'?['preserve'=>'Pertahankan penghapusan sesudah merge','restore'=>'Pulihkan data sebelum merge']:['keep'=>'Pertahankan perubahan pada ID '.$preview['keep'],'source'=>'Pertahankan perubahan pada ID '.$preview['source'],'restore'=>'Kembalikan nilai sebelum merge']); foreach ($options as $value=>$label): ?><option value="<?= $value ?>"><?= appEscape($label) ?></option><?php endforeach; ?>
</select></label></fieldset>
<?php endforeach; ?>
<label class="flex gap-3 items-start bg-amber-50 rounded-xl p-4"><input class="mt-1" type="checkbox" name="confirmed" value="1" required><span>Saya telah memeriksa kedua identitas dan seluruh perubahan lanjutan. Saya menyetujui penempatan data sesuai pilihan di atas. Snapshot dan jejak perubahan tetap disimpan.</span></label>
<button class="bg-blue-600 text-white rounded-xl px-5 py-3">Batalkan merge dan pulihkan identitas asal</button>
</form>
<?php endif; ?>
</main>
<?php require __DIR__.'/includes/footer.php'; ?>
