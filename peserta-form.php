<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_helper.php';
require_once __DIR__ . '/includes/participant_merge_helper.php';
checkRole(['admin', 'pj_tahfidz']);

$error = '';
$input = [];
$snapshot = null;
$ready = participantSchemaReady($pdo);
$actorId = (int) $_SESSION['user_id'];
$_SESSION['participant_forms'] ??= [];
foreach ($_SESSION['participant_forms'] as $key => $saved) {
    if (($saved['expires'] ?? 0) < time() || ($saved['actor'] ?? 0) !== $actorId) unset($_SESSION['participant_forms'][$key]);
}
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireValidCsrfToken($_POST['csrf_token'] ?? null);
        $token = appRequestString($_POST, 'form_token');
        $context = $_SESSION['participant_forms'][$token] ?? null;
        if (!$context) throw new ParticipantManagementException('Formulir kedaluwarsa. Buka kembali dari daftar peserta; perubahan belum disimpan.');
        $id = $context['id'];
        $mode = $context['mode'];
        if ($id!==null && ($redirectId=participantMergeTarget($pdo,$id))!==null) throw new ParticipantManagementException('ID peserta ini sudah digabung ke ID '.$redirectId.'. Isian lama belum disimpan. Buka formulir terbaru untuk meninjau data hasil penggabungan.');
        if ($id !== null) $snapshot = participantSnapshot($pdo, $id);
        $input = array_intersect_key($_POST, array_flip(['nama_bapak', 'no_hp', 'alamat', 'kategori', 'tempat_tahsin', 'ustadz_luar', 'anak', 'remove_children', 'confirm_remove', 'memberships', 'reactivate', 'confirmed']));
        $intent = array_key_exists('add_child',$_POST) && appRequestString($_POST,'add_child')==='1' ? 'add_child' : appRequestString($_POST, 'intent');
        if ($intent === 'add_child' && $mode === 'save') {
            if (!is_array($input['anak'] ?? null) || count($input['anak']) >= 100) throw new ParticipantManagementException('Maksimal 100 baris anak.');
            $input['anak'][] = ['id' => '', 'nama' => '', 'kelas' => ''];
        } elseif ($intent === 'save') {
            $result = participantApply($pdo, $actorId, $mode, $id, $input, $context['fingerprint'], $token);
            $_SESSION['participant_flash'] = $mode === 'archive'
                ? 'Peserta diarsipkan. Data anak, keanggotaan dan riwayat presensi tetap tersimpan.'
                : 'Data peserta berhasil disimpan.' . ($result['auto_archived'] ? ' Wali alumni otomatis diarsipkan karena seluruh anak sudah Lulus.' : '');
            header('Location: peserta.php');
            exit;
        } else throw new ParticipantManagementException('Aksi formulir tidak dikenali.');
    } else {
        $id = participantId($_GET['id'] ?? '', true);
        $mode = appRequestString($_GET, 'mode', 'save');
        if (!in_array($mode, ['save', 'archive'], true) || ($mode === 'archive' && $id === null)) throw new ParticipantManagementException('Halaman peserta tidak ditemukan.');
        if ($id!==null && ($redirectId=participantMergeTarget($pdo,$id))!==null) {
            $_SESSION['participant_form_notice']='ID '.$id.' sudah digabung ke ID '.$redirectId.'. Periksa data hasil penggabungan sebelum melakukan perubahan baru.';
            header('Location: peserta-form.php?id='.$redirectId); exit;
        }
        if ($id !== null) $snapshot = participantSnapshot($pdo, $id);
        $input = $snapshot['parent'] ?? ['kategori' => 'reguler'];
        $input['anak'] = array_map(static fn($row) => ['id' => (string) $row['id'], 'nama' => $row['nama_anak'], 'kelas' => $row['kelas']], $snapshot['children'] ?? []);
        if (count($input['anak']) < 100) $input['anak'][] = ['id' => '', 'nama' => '', 'kelas' => ''];
        $token = bin2hex(random_bytes(16));
        if (count($_SESSION['participant_forms']) >= 10) array_shift($_SESSION['participant_forms']);
        $_SESSION['participant_forms'][$token] = ['id' => $id, 'mode' => $mode, 'fingerprint' => participantFingerprint($snapshot), 'actor' => $actorId, 'expires' => time() + 3600];
    }
} catch (Throwable $failure) {
    $expected = $failure instanceof ParticipantManagementException || $failure instanceof InvalidArgumentException;
    if (!$expected) reportApplicationError($failure, 'participant-form');
    if (!isset($context) && $_SERVER['REQUEST_METHOD'] === 'POST') appRequestError($expected ? 422 : 503, 'Formulir belum dapat diproses', $expected ? $failure->getMessage() : 'Buka kembali daftar peserta lalu coba lagi.');
    if (!isset($token)) appRequestError($expected ? 422 : 503, 'Peserta belum dapat dibuka', $expected ? $failure->getMessage() : 'Coba lagi atau hubungi pengelola.');
    http_response_code($expected ? 422 : 503);
    $error = $expected ? $failure->getMessage() : 'Perubahan belum disimpan. Coba lagi atau hubungi pengelola.';
}
$id ??= null;
$mode ??= 'save';
$locked = $snapshot && ($snapshot['managed'] || $snapshot['central']);
$creationBlocked = !$snapshot && dataIndukConfig()['enabled'];
$field = static fn($key, $default = '') => is_string($input[$key] ?? null) || is_int($input[$key] ?? null) ? (string) $input[$key] : $default;
$childRows = is_array($input['anak'] ?? null) ? array_values(array_filter($input['anak'], 'is_array')) : [];
$remove = is_array($input['remove_children'] ?? null) ? array_filter($input['remove_children'], 'is_string') : [];
$sourceChildren = [];
if ($locked && $id !== null) {
    $table = familyChildrenTable($pdo);
    $statement = $pdo->prepare("SELECT nama_anak,kelas FROM $table WHERE wali_santri_id=? ORDER BY nama_anak,kelas");
    $statement->execute([$id]); $sourceChildren = $statement->fetchAll(PDO::FETCH_ASSOC);
}
$programColumn = participantHasPrograms($pdo) ? 'h.program_code' : "'bapak'";
$groups = $pdo->query("SELECT h.id,h.nama_halaqoh,$programColumn AS program_code,u.nama_lengkap FROM halaqoh h JOIN users u ON u.id=h.ustadz_id ORDER BY h.nama_halaqoh,h.id")->fetchAll(PDO::FETCH_ASSOC);
$pageTitle = $mode === 'archive' ? 'Arsipkan Peserta' : ($id ? 'Edit Peserta' : 'Tambah Peserta');
$notice=is_string($_SESSION['participant_form_notice']??null)?$_SESSION['participant_form_notice']:'';
unset($_SESSION['participant_form_notice']);
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<div class="max-w-3xl mx-auto">
    <a href="peserta.php" class="inline-block underline text-blue-700 py-3">Kembali ke daftar peserta</a>
    <h1 class="text-2xl font-bold mb-4"><?= appEscape($pageTitle) ?></h1>
    <?php if ($notice): ?><p role="status" class="p-4 mb-5 bg-blue-50 text-blue-800 rounded-xl"><?= appEscape($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p role="alert" class="p-4 mb-5 rounded-xl bg-red-50 text-red-800"><?= appEscape($error) ?> <a class="underline" href="peserta-form.php<?= $id ? '?id=' . $id . ($mode === 'archive' ? '&amp;mode=archive' : '') : '' ?>">Buka formulir terbaru</a></p><?php endif; ?>
    <?php if (!$ready): ?><p role="alert" class="p-4 mb-5 rounded-xl bg-amber-50 text-amber-900">Pengelola perlu menyiapkan jurnal perubahan peserta. Data yang sudah ada tetap dapat dibaca; penyimpanan belum tersedia.</p><?php endif; ?>
    <?php if ($creationBlocked): ?><p class="p-4 mb-5 rounded-xl bg-blue-50 text-blue-800">Peserta baru dibuat di Data Induk. Gunakan sinkronisasi dari sumber tersebut.</p><?php endif; ?>
    <form method="post" class="space-y-6 bg-white border border-slate-200 rounded-2xl p-5">
        <?php csrfField(); ?><input type="hidden" name="form_token" value="<?= appEscape($token ?? '') ?>"><input type="hidden" name="intent" value="save">
        <?php if ($mode === 'archive'): ?>
            <p>Arsipkan <strong><?= appEscape($snapshot['parent']['nama_bapak'] ?? '') ?></strong>? Peserta keluar dari roster aktif. Anak dan riwayat presensi tetap tersimpan.</p>
            <?php if ($locked): ?><p class="text-blue-800">Arsip peserta ini harus dilakukan melalui master keluarga atau Data Induk.</p><?php endif; ?>
            <label class="flex items-start gap-3"><input type="checkbox" name="confirmed" value="1" required class="mt-1"> Saya sudah memeriksa identitas peserta dan ingin mengarsipkannya.</label>
        <?php else: ?>
            <?php if ($locked): ?><p class="p-4 bg-blue-50 text-blue-800 rounded-xl">Nama, kontak, alamat dan anak dikelola master keluarga / Data Induk. Pengaturan Tahsin dan penempatan halaqoh dapat diubah di sini.</p><?php endif; ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <?php foreach (['nama_bapak' => ['Nama peserta (Bapak / Ibu)',100], 'no_hp' => ['Nomor HP / WhatsApp (opsional)',20]] as $key => [$label,$max]): ?>
                <div><label for="participant-<?= $key ?>" class="block font-bold mb-2"><?= $label ?></label><input id="participant-<?= $key ?>" name="<?= $key ?>" value="<?= appEscape($locked ? ($snapshot['parent'][$key] ?? '') : $field($key)) ?>" <?= $key === 'nama_bapak' ? 'required' : '' ?> <?= $locked ? 'readonly' : '' ?> maxlength="<?= $max ?>" class="w-full p-3 border border-slate-300 rounded-xl" <?= $key === 'no_hp' ? 'type="tel"' : 'type="text"' ?>></div>
                <?php endforeach; ?>
            </div>
            <div><label for="participant-alamat" class="block font-bold mb-2">Alamat (opsional)</label><textarea id="participant-alamat" name="alamat" rows="3" maxlength="5000" <?= $locked ? 'readonly' : '' ?> class="w-full p-3 border border-slate-300 rounded-xl"><?= appEscape($locked ? ($snapshot['parent']['alamat'] ?? '') : $field('alamat')) ?></textarea></div>
            <div><label for="participant-kategori" class="block font-bold mb-2">Kategori program</label><select id="participant-kategori" name="kategori" class="w-full p-3 border border-slate-300 rounded-xl"><?php foreach (['reguler'=>'Reguler','askar'=>'Askar','tahsin_luar'=>'Tahsin Luar'] as $key=>$label): ?><option value="<?= $key ?>" <?= $field('kategori','reguler') === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
            <fieldset class="space-y-4 border border-slate-200 p-4 rounded-xl"><legend class="font-bold px-2">Tahsin Luar</legend><p class="text-sm text-slate-600">Tempat dan pengajar wajib diisi jika kategori Tahsin Luar dipilih.</p>
                <?php foreach (['tempat_tahsin'=>['Tempat tahsin',255], 'ustadz_luar'=>['Nama pengajar luar',100]] as $key=>[$label,$max]): ?><div><label for="participant-<?= $key ?>" class="block font-bold mb-2"><?= $label ?></label><input id="participant-<?= $key ?>" name="<?= $key ?>" value="<?= appEscape($field($key)) ?>" maxlength="<?= $max ?>" class="w-full p-3 border border-slate-300 rounded-xl"></div><?php endforeach; ?>
            </fieldset>
            <?php if (!$locked): ?>
            <fieldset class="space-y-4 border border-slate-200 p-4 rounded-xl"><legend class="font-bold px-2">Anak</legend>
                <p class="text-sm text-slate-600">Anak yang diedit tetap memakai ID lama. Baris baru yang kosong dilewati. Centang hapus hanya untuk data anak yang keliru; anak yang lulus cukup diubah kelasnya menjadi Lulus.</p>
                <?php foreach ($childRows as $index=>$child): $childId = is_string($child['id'] ?? null) || is_int($child['id'] ?? null) ? (string) $child['id'] : ''; ?>
                <fieldset class="border border-slate-200 p-4 rounded-xl space-y-3"><legend class="px-2">Anak <?= $index+1 ?><?= $childId !== '' ? ' · ID ' . appEscape($childId) : ' · baru' ?></legend>
                    <input type="hidden" name="anak[<?= $index ?>][id]" value="<?= appEscape($childId) ?>">
                    <div><label for="child-name-<?= $index ?>" class="block font-bold mb-2">Nama anak</label><input id="child-name-<?= $index ?>" name="anak[<?= $index ?>][nama]" value="<?= appEscape($child['nama'] ?? '') ?>" maxlength="100" class="w-full p-3 border border-slate-300 rounded-xl"></div>
                    <div><label for="child-class-<?= $index ?>" class="block font-bold mb-2">Kelas</label><input id="child-class-<?= $index ?>" name="anak[<?= $index ?>][kelas]" value="<?= appEscape($child['kelas'] ?? '') ?>" maxlength="20" class="w-full p-3 border border-slate-300 rounded-xl"></div>
                    <?php if ($childId !== ''): ?><label class="flex items-start gap-3"><input type="checkbox" name="remove_children[]" value="<?= appEscape($childId) ?>" <?= in_array($childId,$remove,true) ? 'checked' : '' ?> class="mt-1"> Hapus data anak ini setelah disimpan</label><?php endif; ?>
                </fieldset>
                <?php endforeach; ?>
                <button name="add_child" value="1" formnovalidate <?= count($childRows)>=100?'disabled':'' ?> class="border border-blue-300 text-blue-700 rounded-xl p-3">Tambah baris anak</button>
                <label class="flex items-start gap-3"><input type="checkbox" name="confirm_remove" value="1" <?= $field('confirm_remove') === '1' ? 'checked' : '' ?> class="mt-1"> Jika ada anak yang saya centang hapus, saya sudah memeriksa pilihannya. Salinan sebelum perubahan tetap tersimpan dalam riwayat.</label>
            </fieldset>
            <?php else: ?><section class="border border-slate-200 p-4 rounded-xl"><h2 class="font-bold mb-3">Anak dari sumber identitas</h2><?php if (!$sourceChildren): ?><p>Belum ada anak yang terbaca dari sumber. Hubungi pengelola bila sinkronisasi belum lengkap.</p><?php else: ?><ul class="list-disc pl-5"><?php foreach ($sourceChildren as $child): ?><li><?= appEscape($child['nama_anak']) ?> — <?= appEscape($child['kelas']) ?></li><?php endforeach; ?></ul><?php endif; ?><p class="mt-3"><a href="<?= $snapshot['central']?'data-induk.php':'keluarga.php' ?>" class="underline text-blue-700">Lihat sumber identitas</a>. Data anak tidak diubah dari formulir ini.</p></section><?php endif; ?>
            <fieldset class="space-y-5 border border-slate-200 p-4 rounded-xl"><legend class="font-bold px-2">Penempatan halaqoh</legend>
                <?php foreach (['bapak'=>'Bapak','ibu'=>'Ibu'] as $program=>$label):
                    $programGroups = array_filter($groups,static fn($row)=>$row['program_code']===$program);
                    if (!$programGroups && $program==='ibu') continue;
                    $selected = is_string($input['memberships'][$program] ?? null) ? $input['memberships'][$program] : 'keep';
                    $activeIds = array_map(static fn($row)=>(int)$row['halaqoh_id'],array_filter($snapshot['memberships']??[],static fn($row)=>$row['participant_program']===$program && $row['archived_at']===null));
                    $activeNames = array_map(static fn($row)=>$row['nama_halaqoh'],array_filter($groups,static fn($row)=>in_array((int)$row['id'],$activeIds,true))); ?>
                    <div><label for="membership-<?= $program ?>" class="block font-bold mb-2">Program <?= $label ?></label><p class="text-sm mb-2">Saat ini: <?= appEscape($activeNames ? implode(', ',$activeNames) : 'belum ditempatkan') ?></p><select id="membership-<?= $program ?>" name="memberships[<?= $program ?>]" class="w-full p-3 border border-slate-300 rounded-xl"><option value="keep" <?= $selected==='keep'?'selected':'' ?>>Pertahankan penempatan saat ini</option><option value="none" <?= $selected==='none'?'selected':'' ?>>Tanpa penempatan aktif pada program ini</option><?php foreach ($programGroups as $group): ?><option value="<?= (int)$group['id'] ?>" <?= $selected===(string)$group['id']?'selected':'' ?>><?= appEscape($group['nama_halaqoh'].' — '.$group['nama_lengkap']) ?></option><?php endforeach; ?></select></div>
                <?php endforeach; ?>
                <p class="text-sm text-slate-600">Perubahan satu program mempertahankan program lainnya. Riwayat keanggotaan sebelumnya disimpan.</p>
            </fieldset>
            <?php if ($snapshot && (int)$snapshot['parent']['status_aktif']===0 && !$locked): ?><label class="flex items-start gap-3"><input type="checkbox" name="reactivate" value="1" <?= $field('reactivate')==='1'?'checked':'' ?> class="mt-1"> Aktifkan peserta kembali. Memerlukan anak dengan kelas sekolah aktif; halaqoh lama tidak diaktifkan otomatis.</label><?php endif; ?>
            <p class="text-sm text-slate-600">Wali yang seluruh anaknya Lulus otomatis diarsipkan. Catatan Lanjut Tahsin lama tetap disimpan.</p>
        <?php endif; ?>
        <div class="flex flex-wrap gap-4"><button type="submit" <?= !$ready || $creationBlocked || ($mode==='archive' && $locked) ? 'disabled' : '' ?> class="bg-blue-600 text-white rounded-xl px-5 py-3 disabled:opacity-50"><?= $mode==='archive'?'Arsipkan peserta':'Simpan data' ?></button><a href="peserta.php" class="border border-slate-300 rounded-xl px-5 py-3">Batal</a></div>
    </form>
    <?php if ($id): ?><a href="peserta-riwayat.php?id=<?= $id ?>" class="inline-block underline text-blue-700 py-4">Lihat riwayat perubahan peserta</a><?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
