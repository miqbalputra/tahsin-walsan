<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_helper.php';
require_once __DIR__ . '/includes/rapor_helper.php';
checkLogin();
checkRole(['admin']);

$error = '';
$message = '';
try {
    $settings = raporSettings($pdo);
    if (isset($_GET['download'])) {
        $key = appRequestString($_GET, 'download');
        if ($key === 'current') {
            $source = $settings['rapor_template'] ?? '';
        } elseif (preg_match('/^rapor_backup_[0-9]{14}_[a-f0-9]{8}$/D', $key)) {
            $query = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
            $query->execute([$key]);
            $source = $query->fetchColumn();
        } else {
            throw new InvalidArgumentException('Cadangan desain tidak ditemukan.');
        }
        if ($source === false || $source === '') appRequestError(404, 'Desain tidak ditemukan', 'Belum ada desain tersimpan yang dapat diunduh.');
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="desain-rapor-' . $key . '.txt"');
        header('X-Content-Type-Options: nosniff');
        echo $source;
        exit;
    }
    $selected = raporSelectTemplate($settings['rapor_template'] ?? '');
    $form = ['template_content' => $selected['html'], 'pj_tahfidz_name' => $settings['pj_tahfidz_name'] ?? 'Admin Sekolah',
        'pj_tahfidz_title' => $settings['pj_tahfidz_title'] ?? 'PJ Tahfidz', 'design_mode' => $selected['mode'] === 'custom' ? 'custom' : 'standard'];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        requireValidCsrfToken($_POST['csrf_token'] ?? null);
        try {
            foreach (array_keys($form) as $key) $form[$key] = appRequestString($_POST, $key, '', $key !== 'template_content');
            if (!in_array($form['design_mode'], ['standard', 'custom'], true)) throw new InvalidArgumentException('Pilih desain standar atau desain sendiri.');
            $save = $form;
            if ($form['design_mode'] === 'standard') $save['template_content'] = raporDefaultTemplate();
            raporSaveSettings($pdo, $save);
            addLog($pdo, 'UPDATE_TEMPLATE', 'Memperbarui desain rapor dan identitas pengesah; desain sebelumnya dicadangkan.');
            $_SESSION['rapor_message'] = 'Pengaturan rapor berhasil disimpan. Desain sebelumnya tetap tersedia pada daftar cadangan.';
            header('Location: ' . appUrl('pengaturan-rapor.php'));
            exit;
        } catch (InvalidArgumentException $validation) {
            http_response_code(422);
            $error = $validation->getMessage();
        } catch (Throwable $failure) {
            reportApplicationError($failure, 'save-rapor-settings');
            http_response_code(503);
            $error = 'Pengaturan belum tersimpan. Coba simpan lagi. Desain dan data sebelumnya tetap tersedia.';
        }
    } else {
        $message = $_SESSION['rapor_message'] ?? '';
        unset($_SESSION['rapor_message']);
    }
    $participants = $pdo->query('SELECT id,nama_bapak FROM wali_santri ORDER BY nama_bapak,id')->fetchAll(PDO::FETCH_ASSOC);
    // A literal prefix avoids LIKE interpreting underscores as wildcards.
    $backups = $pdo->query("SELECT setting_key FROM settings WHERE LEFT(setting_key,13)='rapor_backup_' ORDER BY setting_key DESC")->fetchAll(PDO::FETCH_COLUMN);
} catch (InvalidArgumentException $validation) {
    appRequestError(422, 'Periksa pilihan desain', $validation->getMessage());
} catch (Throwable $failure) {
    reportApplicationError($failure, 'rapor-settings');
    appRequestError(503, 'Pengaturan belum dapat dibuka', 'Coba buka kembali halaman ini. Jika masalah berlanjut, hubungi pengelola.');
}
$pageTitle = 'Pengaturan Rapor';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<header class="mb-8">
    <h2 class="text-3xl font-black text-slate-800">Pengaturan Rapor</h2>
    <p class="text-slate-600 mt-2">Atur nama pengesah, pilih desain, lalu buka contoh rapor sebelum mencetak.</p>
</header>
<?php if ($message !== ''): ?><p role="status" class="p-4 mb-6 rounded-xl bg-emerald-50 text-emerald-800"><?= appEscape($message) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p role="alert" class="p-4 mb-6 rounded-xl bg-red-50 text-red-800"><?= appEscape($error) ?></p><?php endif; ?>
<?php if ($selected['notice'] !== ''): ?>
<section class="p-4 mb-6 rounded-xl bg-amber-50 text-amber-900" aria-label="Desain lama">
    <p><?= appEscape($selected['notice']) ?> Simpan desain standar atau sesuaikan desain sendiri menggunakan penanda di bawah.</p>
    <a class="inline-block underline py-3" href="?download=current">Unduh desain lama untuk disesuaikan</a>
</section>
<?php endif; ?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-16">
    <form method="post" class="lg:col-span-2 bg-white border border-slate-200 p-6 rounded-2xl space-y-5">
        <?php csrfField(); ?>
        <div>
            <label for="rapor-name" class="block font-bold mb-2">Nama pengesah</label>
            <input id="rapor-name" name="pj_tahfidz_name" required maxlength="100" value="<?= appEscape($form['pj_tahfidz_name']) ?>" class="w-full border border-slate-300 rounded-xl p-3">
        </div>
        <div>
            <label for="rapor-title" class="block font-bold mb-2">Jabatan pengesah</label>
            <input id="rapor-title" name="pj_tahfidz_title" required maxlength="100" value="<?= appEscape($form['pj_tahfidz_title']) ?>" class="w-full border border-slate-300 rounded-xl p-3">
        </div>
        <div>
            <label for="rapor-design" class="block font-bold mb-2">Desain rapor</label>
            <select id="rapor-design" name="design_mode" aria-describedby="rapor-design-help" class="w-full border border-slate-300 rounded-xl p-3">
                <option value="standard" <?= $form['design_mode'] === 'standard' ? 'selected' : '' ?>>Desain standar — siap cetak</option>
                <option value="custom" <?= $form['design_mode'] === 'custom' ? 'selected' : '' ?>>Desain sendiri — gunakan editor di bawah</option>
            </select>
            <p id="rapor-design-help" class="text-sm text-slate-600 mt-2">Desain standar dapat digunakan tanpa mengedit kode.</p>
        </div>
        <details <?= $form['design_mode'] === 'custom' ? 'open' : '' ?> class="border border-slate-200 rounded-xl p-4">
            <summary class="font-bold cursor-pointer py-2">Editor desain sendiri (lanjutan)</summary>
            <label for="rapor-source" class="block font-bold mt-4 mb-2">HTML dan CSS rapor</label>
            <p id="rapor-source-help" class="text-sm text-slate-600 mb-3">Gunakan penanda seperti {{nama_wali}} di antara tag HTML. Penanda diganti otomatis dengan data peserta. Kode PHP dan JavaScript tidak didukung.</p>
            <textarea id="rapor-source" name="template_content" rows="20" spellcheck="false" aria-describedby="rapor-source-help" class="w-full border border-slate-300 p-3 rounded-xl font-mono text-sm"><?= appEscape($form['template_content']) ?></textarea>
        </details>
        <button class="w-full sm:w-auto bg-blue-600 text-white font-bold rounded-xl px-6 py-3" type="submit">Simpan pengaturan</button>
    </form>
    <aside class="space-y-6">
        <section class="bg-white border border-slate-200 p-6 rounded-2xl">
            <h3 class="font-bold text-lg mb-3">Lihat contoh rapor</h3>
            <?php if ($participants): ?>
            <form action="print-rapor.php" method="get" target="_blank" class="space-y-3">
                <label for="rapor-example" class="block font-bold">Peserta</label>
                <select id="rapor-example" name="wali_santri_id" required class="w-full border border-slate-300 rounded-xl p-3">
                    <?php foreach ($participants as $participant): ?><option value="<?= (int) $participant['id'] ?>"><?= appEscape($participant['nama_bapak']) ?></option><?php endforeach; ?>
                </select>
                <p class="text-sm text-slate-600">Contoh memakai pengaturan yang sudah disimpan dan periode bulan ini. Halaman dibuka pada tab baru.</p>
                <button type="submit" class="w-full border border-blue-600 text-blue-700 rounded-xl px-4 py-3 font-bold">Buka contoh rapor</button>
            </form>
            <?php else: ?><p>Belum ada peserta. Tambahkan peserta terlebih dahulu untuk melihat contoh rapor.</p><?php endif; ?>
        </section>
        <section class="bg-white border border-slate-200 p-6 rounded-2xl">
            <h3 class="font-bold text-lg mb-3">Cadangan desain</h3>
            <p class="text-sm text-slate-600 mb-3">Setiap desain yang diganti disimpan sebagai cadangan. Unduh untuk meninjau dan menyalinnya ke editor.</p>
            <?php if (!empty($settings['rapor_template'])): ?><a class="block underline py-3" href="?download=current">Unduh desain tersimpan</a><?php endif; ?>
            <?php if ($backups): ?>
            <form method="get" class="space-y-3">
                <label for="rapor-backup" class="block font-bold">Versi sebelumnya</label>
                <select id="rapor-backup" name="download" class="w-full border border-slate-300 rounded-xl p-3">
                <?php foreach ($backups as $key): $backupDate = DateTimeImmutable::createFromFormat('!YmdHis', substr($key,13,14)); ?>
                    <option value="<?= appEscape($key) ?>"><?= appEscape(($backupDate ? $backupDate->format('d/m/Y H:i:s') : $key) . ' · ' . substr($key,-4)) ?></option>
                <?php endforeach; ?>
                </select>
                <button type="submit" class="w-full border border-slate-400 rounded-xl px-4 py-3">Unduh cadangan terpilih</button>
            </form>
            <?php else: ?><p class="text-sm text-slate-600">Belum ada desain yang diganti.</p><?php endif; ?>
        </section>
        <details class="bg-white border border-slate-200 p-6 rounded-2xl">
            <summary class="font-bold cursor-pointer">Daftar penanda desain</summary>
            <ul class="mt-4 space-y-2 text-sm"><?php foreach (RAPOR_TOKENS as $token): ?><li><code>{{<?= appEscape($token) ?>}}</code></li><?php endforeach; ?></ul>
            <p class="mt-4 text-sm text-slate-600">{{riwayat_tabel}} berisi tabel riwayat lengkap. Gambar dapat memakai berkas aplikasi atau alamat HTTPS tetap. Penanda tidak boleh ditempatkan di alamat gambar, atribut, atau CSS.</p>
        </details>
    </aside>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
