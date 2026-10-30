<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_helper.php';
require_once __DIR__ . '/includes/participant_import_helper.php';
require_once __DIR__ . '/includes/participant_import_view.php';
checkLogin();
checkRole(['admin','pj_tahfidz']);
$error=''; $result=null; $viewRecord=null; $refreshPreview=false; $choices=['targets'=>[],'class_policy'=>'preserve','profile_policy'=>'preserve'];
$ready=participantImportSchemaReady($pdo);
$central=dataIndukConfig()['enabled'];
$pending=$_SESSION['participant_import_pending']??null;
if (!is_array($pending) || ($pending['actor_id']??0)!==(int)$_SESSION['user_id'] || ($pending['expires_at']??0)<time()) {
    $pending=null; unset($_SESSION['participant_import_pending']);
}
try {
    if (isset($_GET['download']) || isset($_GET['batch'])) {
        $id=appRequestString($_GET,isset($_GET['download'])?'download':'batch');
        if (!$ready || !preg_match('/^[a-f0-9]{32}$/D',$id)) appRequestError(404,'Rincian impor tidak ditemukan','Buka daftar riwayat impor untuk memilih berkas yang tersedia.');
        $query=$pdo->prepare('SELECT * FROM participant_import_batches WHERE id=?'); $query->execute([$id]); $record=$query->fetch(PDO::FETCH_ASSOC);
        if (!$record) appRequestError(404,'Rincian impor tidak ditemukan','Buka daftar riwayat impor untuk memilih berkas yang tersedia.');
        $download=['source_name'=>$record['source_name'],'source_sha256'=>$record['source_sha256'],'created_at'=>$record['created_at'],'actor'=>$record['actor_name']];
        foreach (['source_rows_json'=>'source_rows','changes_json'=>'changes','summary_json'=>'summary'] as $column=>$name) {
            $download[$name]=json_decode($record[$column],true,512,JSON_THROW_ON_ERROR);
            if (!is_array($download[$name])) throw new RuntimeException('Struktur jurnal impor tidak valid.');
        }
        if (isset($_GET['download'])) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="impor-peserta-' . $id . '.json"');
            header('X-Content-Type-Options: nosniff');
            echo json_encode($download,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit;
        }
        participantImportValidateChanges($download['changes']);
        $changePages=max(1,(int)ceil(count($download['changes'])/20));
        $changePage=filter_var(appRequestString($_GET,'change_page','1'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$changePage) throw new InvalidArgumentException('Pilih nomor halaman rincian yang valid.');
        $changePage=min($changePage,$changePages);
        $viewRecord=$download+['id'=>$id,'change_page'=>$changePage,'change_pages'=>$changePages,'page_changes'=>array_slice($download['changes'],($changePage-1)*20,20)];
    }
    if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
        requireValidCsrfToken($_POST['csrf_token']??null);
        $action=appRequestString($_POST,'action',isset($_FILES['file_csv'])?'preview':'');
        if ($action==='cancel') {
            unset($_SESSION['participant_import_pending']); header('Location: ' . appUrl('import-peserta.php')); exit;
        }
        if ($action==='preview') {
            $pending=null; unset($_SESSION['participant_import_pending']);
            $file=$_FILES['file_csv']??null;
            if (!is_array($file) || ($file['error']??null)!==UPLOAD_ERR_OK || !is_string($file['name']??null) || !is_string($file['tmp_name']??null)) throw new InvalidArgumentException('Unggahan belum diterima. Pilih CSV, maksimal 5 MB, lalu coba lagi.');
            if (strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))!=='csv' || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Pilih berkas CSV dari perangkat Anda.');
            if (!is_int($file['size']??null) || $file['size']<1 || $file['size']>PARTICIPANT_IMPORT_MAX_BYTES) throw new InvalidArgumentException('Pilih CSV yang tidak kosong, maksimal 5 MB.');
            $stream=fopen($file['tmp_name'],'rb');
            if (!$stream) throw new RuntimeException('Berkas unggahan belum dapat dibaca.');
            try { $rows=participantImportRead($stream); } finally { fclose($stream); }
            $plan=participantImportPlan($pdo,$rows,hash_file('sha256',$file['tmp_name']),$file['name']);
            $pending=['plan'=>$plan,'batch_id'=>bin2hex(random_bytes(16)),'actor_id'=>(int)$_SESSION['user_id'],'expires_at'=>time()+1800];
            $_SESSION['participant_import_pending']=$pending;
        } elseif ($action==='apply') {
            $refreshPreview=true;
            if (!$pending || !is_string($_POST['batch_id']??null) || !hash_equals($pending['batch_id'],$_POST['batch_id'])) throw new InvalidArgumentException('Pratinjau tidak tersedia atau sudah kedaluwarsa. Unggah ulang CSV. Data belum diproses.');
            if (($_POST['reviewed']??'')!=='1') throw new InvalidArgumentException('Periksa pengelompokan peserta, lalu centang konfirmasi sebelum menyimpan.');
            $choices=['targets'=>$_POST['targets']??[],'class_policy'=>appRequestString($_POST,'class_policy','preserve'),'profile_policy'=>appRequestString($_POST,'profile_policy','preserve')];
            $result=participantImportApply($pdo,(int)$_SESSION['user_id'],$pending['plan'],$choices,$pending['batch_id']);
            addLog($pdo,'IMPORT_PESERTA',json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            unset($_SESSION['participant_import_pending']); $_SESSION['participant_import_result']=$result;
            header('Location: ' . appUrl('import-peserta.php')); exit;
        } else throw new InvalidArgumentException('Pilih CSV untuk membuat pratinjau terlebih dahulu.');
    } else {
        $result=$_SESSION['participant_import_result']??null; unset($_SESSION['participant_import_result']);
    }
} catch (InvalidArgumentException $validation) {
    http_response_code(422); $error=$validation->getMessage();
    if ($pending && $refreshPreview) {
        try {
            $pending['plan']=participantImportPlan($pdo,$pending['plan']['rows'],$pending['plan']['source_sha256'],$pending['plan']['source_name']);
            $pending['batch_id']=bin2hex(random_bytes(16)); $pending['expires_at']=time()+1800;
            $_SESSION['participant_import_pending']=$pending;
        } catch (Throwable $ignored) { $pending=null; unset($_SESSION['participant_import_pending']); }
    }
} catch (Throwable $failure) {
    reportApplicationError($failure,'participant-import'); http_response_code(503);
    $error=($_SERVER['REQUEST_METHOD']??'GET')==='POST'
        ? 'Impor belum tersimpan. Coba lagi atau hubungi pengelola. Data sebelumnya tetap tersedia.'
        : 'Rincian impor belum dapat dibuka. Coba lagi atau hubungi pengelola; data tetap tersimpan.';
}
$history=[]; $historyPage=1; $historyPages=1;
try {
    if ($ready) {
        $historyTotal=(int)$pdo->query('SELECT COUNT(*) FROM participant_import_batches')->fetchColumn();
        $historyPages=max(1,(int)ceil($historyTotal/10));
        $page=filter_var(appRequestString($_GET,'page','1'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$page) throw new InvalidArgumentException('Pilih nomor halaman riwayat yang valid.');
        $historyPage=min($page,$historyPages);
        $history=$pdo->query('SELECT id,source_name,actor_name,created_at FROM participant_import_batches ORDER BY created_at DESC,id DESC LIMIT 10 OFFSET ' . (($historyPage-1)*10))->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (InvalidArgumentException $validation) {
    appRequestError(422,'Periksa pilihan riwayat',$validation->getMessage());
} catch (Throwable $failure) {
    reportApplicationError($failure,'participant-import-history');
    http_response_code(503); $error='Riwayat impor belum dapat dibuka. Coba lagi; data impor tetap tersimpan.';
}
$pageTitle='Impor Peserta';
require_once __DIR__ . '/includes/header.php'; require_once __DIR__ . '/includes/sidebar.php';
?>
<header class="mb-6">
    <a href="peserta.php" class="inline-block py-3 text-blue-700 underline">Kembali ke data peserta</a>
    <h2 class="text-2xl font-bold text-slate-800">Impor peserta dari CSV</h2>
    <p class="text-slate-600 mt-2">Unggah berkas, periksa pratinjau, lalu simpan. Baris anak yang sudah ada tidak ditambahkan ulang.</p>
</header>
<?php if ($error!==''): ?><p role="alert" class="p-4 mb-6 rounded-xl bg-red-50 text-red-800"><?= appEscape($error) ?></p><?php endif; ?>
<?php if (is_array($result)): ?>
<section role="status" class="p-5 mb-6 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-900">
    <h3 class="font-bold mb-2">Impor berhasil disimpan</h3>
    <p><?= (int)$result['created_wali'] ?> peserta baru; <?= (int)$result['reused_wali'] ?> peserta yang sudah ada digunakan.</p>
    <p><?= (int)($result['skipped_wali']??0) ?> keluarga dilewati sesuai pilihan pratinjau.</p>
    <p><?= (int)$result['created_children'] ?> anak ditambahkan; <?= (int)$result['skipped_children'] ?> baris anak dilewati; <?= (int)$result['updated_classes'] ?> kelas diperbarui.</p>
    <p><?= (int)$result['updated_profiles'] ?> biodata diperbarui; <?= (int)$result['archived_wali'] ?> wali alumni diarsipkan.</p>
    <a href="?download=<?= appEscape($result['batch_id']) ?>" class="inline-block underline py-3">Unduh rincian dan data sebelum/sesudah impor</a>
    <a href="?batch=<?= appEscape($result['batch_id']) ?>" class="inline-block underline py-3 ml-3">Lihat rincian di halaman ini</a>
</section>
<?php endif; ?>
<?php if ($viewRecord): ?>
<section class="bg-white border border-slate-200 rounded-2xl p-5 mb-8">
    <a href="import-peserta.php" class="inline-block underline py-3 text-blue-700">Kembali ke impor</a>
    <h3 class="text-xl font-bold mb-2">Rincian impor <?= appEscape($viewRecord['source_name']) ?></h3>
    <p class="text-slate-600 mb-4"><?= appEscape($viewRecord['created_at']) ?> · <?= appEscape($viewRecord['actor']) ?></p>
    <p class="mb-4">Data berikut menunjukkan kondisi pada saat impor. Perubahan yang dibuat setelahnya tidak mengubah catatan ini.</p>
    <p class="text-sm text-slate-600 mb-3">Halaman rincian <?= $viewRecord['change_page'] ?> dari <?= $viewRecord['change_pages'] ?></p>
    <?php foreach ($viewRecord['page_changes'] as $change) participantImportRenderChange($change); ?>
    <nav aria-label="Halaman rincian impor" class="flex flex-wrap gap-4">
        <?php if ($viewRecord['change_page']>1): ?><a class="underline text-blue-700 py-3" href="?batch=<?= appEscape($viewRecord['id']) ?>&amp;change_page=<?= $viewRecord['change_page']-1 ?>">Peserta sebelumnya</a><?php endif; ?>
        <?php if ($viewRecord['change_page']<$viewRecord['change_pages']): ?><a class="underline text-blue-700 py-3" href="?batch=<?= appEscape($viewRecord['id']) ?>&amp;change_page=<?= $viewRecord['change_page']+1 ?>">Peserta berikutnya</a><?php endif; ?>
    </nav>
    <a href="?download=<?= appEscape($viewRecord['id']) ?>" class="inline-block underline py-3 text-blue-700">Unduh arsip rincian lengkap</a>
</section>
<?php elseif (!$ready): ?>
<p class="p-4 rounded-xl bg-amber-50 text-amber-900">Impor perlu disiapkan oleh pengelola. Minta pengelola memasang pembaruan jurnal impor; data peserta yang sudah ada tetap dapat digunakan.</p>
<?php elseif ($central): ?>
<p class="p-4 rounded-xl bg-amber-50 text-amber-900">Biodata peserta dan anak dikelola Data Induk. Gunakan sinkronisasi dari sumber tersebut untuk memperbarui data.</p>
<?php elseif ($pending): $plan=$pending['plan']; ?>
<section class="bg-white border border-slate-200 rounded-2xl p-5 mb-8">
    <h3 class="text-xl font-bold mb-2">Pratinjau — data belum disimpan</h3>
    <p class="text-slate-600 mb-4"><?= appEscape($plan['source_name']) ?>: <?= count($plan['rows']) ?> baris, <?= count($plan['groups']) ?> keluarga. Pratinjau berlaku 30 menit.</p>
    <p class="p-4 mb-5 bg-amber-50 text-amber-900 rounded-xl">Periksa anak dan nomor telepon setiap wali. Nama yang sama belum tentu orang yang sama, terutama bila nomor telepon kosong. Pilih identitas yang benar atau tambah peserta baru.</p>
    <form method="post" class="space-y-5">
        <?php csrfField(); ?><input type="hidden" name="action" value="apply"><input type="hidden" name="batch_id" value="<?= appEscape($pending['batch_id']) ?>">
        <?php $number=0; foreach ($plan['groups'] as $key=>$group): $number++; $selected=is_string($choices['targets'][$key]??null)?$choices['targets'][$key]:$group['default_target']; ?>
        <article class="border border-slate-200 rounded-xl p-4">
            <h4 class="font-bold text-lg"><?= $number ?>. <?= appEscape($group['profile']['nama_bapak']) ?></h4>
            <p class="text-sm text-slate-600 mb-3">Telepon CSV: <?= appEscape($group['profile']['no_hp']?:'Tidak diisi') ?></p>
            <label for="import-target-<?= appEscape($key) ?>" class="block font-bold mb-2">Simpan ke peserta</label>
            <select id="import-target-<?= appEscape($key) ?>" name="targets[<?= appEscape($key) ?>]" required class="w-full border border-slate-300 rounded-xl p-3">
                <option value="">Pilih identitas peserta</option>
                <option value="new" <?= $selected==='new'?'selected':'' ?>>Tambah sebagai peserta baru</option>
                <option value="skip" <?= $selected==='skip'?'selected':'' ?>>Lewati keluarga ini; pertahankan seluruh data saat ini</option>
                <?php foreach ($group['candidates'] as $id=>$candidate): ?>
                <option value="<?= (int)$id ?>" <?= $selected===(string)$id?'selected':'' ?> <?= $candidate['managed']?'disabled':'' ?>>ID <?= (int)$id ?> · <?= appEscape($candidate['parent']['nama_bapak']) ?> · <?= appEscape($candidate['parent']['no_hp']?:'tanpa telepon') ?> · <?= (int)$candidate['parent']['status_aktif']===1?'aktif':'arsip' ?><?= $candidate['managed']?' · dikelola master keluarga/Data Induk':'' ?></option>
                <?php endforeach; ?>
            </select>
            <details class="mt-3"><summary class="cursor-pointer py-2 font-bold">Anak dan biodata untuk diperiksa</summary>
                <p class="font-bold mt-3">Dari CSV</p>
                <ul class="list-disc pl-5 text-sm"><?php foreach ($group['rows'] as $row): if ($row['nama_anak']==='') continue; ?><li><?= appEscape($row['nama_anak']) ?> — <?= appEscape($row['kelas']?:'kelas belum diisi') ?></li><?php endforeach; ?></ul>
                <p class="text-sm whitespace-pre-line break-words mt-3">Alamat CSV: <?= appEscape($group['profile']['alamat']?:'Tidak diisi') ?></p>
                <p class="text-sm">Kategori CSV: <?= appEscape($group['profile']['kategori']) ?>; tempat tahsin: <?= appEscape($group['profile']['tempat_tahsin']?:'—') ?>; pengajar luar: <?= appEscape($group['profile']['ustadz_luar']?:'—') ?>.</p>
                <?php foreach ($group['candidates'] as $id=>$candidate): ?><p class="font-bold mt-3">Data saat ini — ID <?= (int)$id ?></p>
                <ul class="list-disc pl-5 text-sm"><?php foreach ($candidate['children'] as $child): ?><li>ID anak <?= (int)$child['id'] ?>: <?= appEscape($child['nama_anak']) ?> — <?= appEscape($child['kelas']??'') ?></li><?php endforeach; ?></ul>
                <p class="text-sm whitespace-pre-line break-words">Alamat: <?= appEscape($candidate['parent']['alamat']??'') ?>; kategori: <?= appEscape($candidate['parent']['kategori']) ?>.</p>
                <?php endforeach; ?>
            </details>
        </article>
        <?php endforeach; ?>
        <div>
            <label for="import-class-policy" class="block font-bold mb-2">Jika anak bernama sama tetapi kelasnya berbeda</label>
            <select id="import-class-policy" name="class_policy" class="w-full border border-slate-300 rounded-xl p-3">
                <?php foreach (['preserve'=>'Pertahankan kelas lama; lewati baris anak tersebut','update'=>'Perbarui kelas dari CSV jika hanya ada satu anak bernama sama','new'=>'Tambahkan sebagai anak berbeda'] as $value=>$label): ?><option value="<?= $value ?>" <?= $choices['class_policy']===$value?'selected':'' ?>><?= appEscape($label) ?></option><?php endforeach; ?>
            </select>
            <p class="text-sm text-slate-600 mt-2">Nama dan kelas yang sudah cocok selalu dilewati. Pembaruan kelas mempertahankan ID anak; kelas kosong di CSV dapat mengosongkan kelas lama. Data sebelumnya disimpan dalam rincian impor.</p>
        </div>
        <div>
            <label for="import-profile-policy" class="block font-bold mb-2">Biodata peserta yang sudah ada</label>
            <select id="import-profile-policy" name="profile_policy" class="w-full border border-slate-300 rounded-xl p-3">
                <option value="preserve" <?= $choices['profile_policy']==='preserve'?'selected':'' ?>>Pertahankan biodata saat ini</option>
                <option value="update" <?= $choices['profile_policy']==='update'?'selected':'' ?>>Ganti biodata lokal dari CSV, termasuk kolom yang kosong</option>
            </select>
            <p class="text-sm text-slate-600 mt-2">Kolom yang tidak ada dalam CSV tetap dipertahankan. Biodata yang diganti dicatat sebelum dan sesudah impor. Identitas master keluarga/Data Induk diperbarui melalui sumbernya.</p>
        </div>
        <label class="flex gap-3 items-start"><input type="checkbox" name="reviewed" value="1" required class="mt-1"><span>Saya sudah memeriksa pengelompokan wali, anak, dan pilihan pembaruan di atas.</span></label>
        <p class="text-sm text-slate-600">Anak baru dengan kelas terisi selain Lulus, atau perubahan dari Lulus menjadi kelas aktif, dapat mengaktifkan kembali wali arsip. Wali yang seluruh anaknya Lulus diarsipkan. Keanggotaan halaqoh lama tidak diaktifkan otomatis.</p>
        <button type="submit" class="bg-blue-600 text-white font-bold rounded-xl px-6 py-3">Simpan hasil impor</button>
    </form>
    <form method="post" class="mt-4"><?php csrfField(); ?><button type="submit" name="action" value="cancel" class="border border-slate-300 rounded-xl px-6 py-3">Batalkan pratinjau</button></form>
</section>
<?php else: ?>
<section class="bg-white border border-slate-200 rounded-2xl p-5 mb-8">
    <h3 class="text-xl font-bold mb-3">1. Pilih berkas peserta</h3>
    <p id="import-file-help" class="text-slate-600 mb-4">Gunakan CSV UTF-8 dari template. Pemisah koma atau titik koma diterima; maksimal 5 MB dan 5.000 baris. Satu wali dapat memiliki beberapa baris anak.</p>
    <a href="template_import_peserta.csv" download class="inline-block underline text-blue-700 py-3 mb-3">Unduh template CSV</a>
    <form method="post" enctype="multipart/form-data" class="space-y-4">
        <?php csrfField(); ?><input type="hidden" name="action" value="preview">
        <label for="participant-csv" class="block font-bold">Berkas CSV</label>
        <input id="participant-csv" name="file_csv" type="file" accept=".csv,text/csv" required aria-describedby="import-file-help" class="block w-full border border-slate-300 rounded-xl p-3">
        <button type="submit" class="bg-blue-600 text-white font-bold rounded-xl px-6 py-3">Buka pratinjau</button>
    </form>
</section>
<?php endif; ?>
<?php if ($history): ?><section class="bg-white border border-slate-200 rounded-2xl p-5 mb-10">
    <h3 class="text-xl font-bold mb-3">Riwayat impor — halaman <?= $historyPage ?> dari <?= $historyPages ?></h3>
    <p class="text-sm text-slate-600 mb-3">Rincian berisi data peserta sebelum dan sesudah perubahan. Simpan berkas di tempat pribadi.</p>
    <ul class="divide-y divide-slate-200"><?php foreach ($history as $record): ?><li class="py-3">
        <p class="font-bold break-words"><?= appEscape($record['source_name']) ?></p><p class="text-sm text-slate-600"><?= appEscape($record['created_at']) ?> · <?= appEscape($record['actor_name']) ?></p>
        <a class="inline-block text-blue-700 underline py-3" href="?download=<?= appEscape($record['id']) ?>">Unduh rincian impor</a>
        <a class="inline-block text-blue-700 underline py-3 ml-3" href="?batch=<?= appEscape($record['id']) ?>">Lihat sebelum/sesudah</a>
    </li><?php endforeach; ?></ul>
    <nav aria-label="Halaman riwayat impor" class="flex flex-wrap gap-4 mt-3">
        <?php if ($historyPage>1): ?><a class="underline text-blue-700 py-3" href="?page=<?= $historyPage-1 ?>">Riwayat lebih baru</a><?php endif; ?>
        <?php if ($historyPage<$historyPages): ?><a class="underline text-blue-700 py-3" href="?page=<?= $historyPage+1 ?>">Riwayat lebih lama</a><?php endif; ?>
    </nav>
</section><?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
