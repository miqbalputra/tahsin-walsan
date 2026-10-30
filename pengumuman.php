<?php
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

checkRole(['admin', 'pj_tahfidz']);

// Auto-create table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS pengumuman (
    id INT AUTO_INCREMENT PRIMARY KEY,
    judul VARCHAR(200),
    isi TEXT NOT NULL,
    is_aktif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

$message = isset($_GET['msg']) && is_string($_GET['msg']) ? $_GET['msg'] : '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken($_POST['csrf_token'] ?? '');

    try {
        $action = appRequestString($_POST, 'action');
        if ($action === 'save') {
            $judul = appRequestString($_POST, 'judul');
            $isi = appRequestString($_POST, 'isi', '', false);
            if ($judul === '' || appTextLength($judul) > 200) throw new InvalidArgumentException('Judul wajib diisi, maksimal 200 karakter.');
            if (trim($isi) === '' || appTextLength($isi) > 20000 || strlen($isi) > 60000) throw new InvalidArgumentException('Isi pengumuman wajib diisi, maksimal 20.000 karakter dan 60 KB.');
            $stmt = $pdo->prepare("INSERT INTO pengumuman (judul, isi) VALUES (?, ?)");
            $stmt->execute([$judul, $isi]);
            addLog($pdo, 'CREATE_ANNOUNCEMENT', 'Pengumuman baru: ' . $judul);
            redirectTo('pengumuman.php?msg=' . rawurlencode('Pengumuman berhasil disebarkan.'));
        }
        if ($action === 'toggle') {
            $id = filter_var(appRequestString($_POST, 'id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $rawStatus = appRequestString($_POST, 'status');
            if ($id === false || !in_array($rawStatus, ['0', '1'], true)) throw new InvalidArgumentException('Pilihan pengumuman tidak valid. Muat ulang halaman lalu coba lagi.');
            $stmt = $pdo->prepare("UPDATE pengumuman SET is_aktif = ? WHERE id = ?");
            $stmt->execute([(int) $rawStatus, $id]);
            addLog($pdo, 'TOGGLE_ANNOUNCEMENT', "Status pengumuman $id diubah menjadi $rawStatus");
            redirectTo('pengumuman.php?msg=' . rawurlencode($rawStatus === '1' ? 'Pengumuman diaktifkan kembali.' : 'Pengumuman diarsipkan; isi lama tetap tersimpan.'));
        }
        if ($action === 'archive' || $action === 'delete') {
            $id = filter_var(appRequestString($_POST, 'id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new InvalidArgumentException('ID pengumuman tidak valid.');
            $stmt = $pdo->prepare('UPDATE pengumuman SET is_aktif = 0 WHERE id = ?');
            $stmt->execute([$id]);
            addLog($pdo, 'ARCHIVE_ANNOUNCEMENT', "Pengumuman $id diarsipkan; isi dipertahankan");
            redirectTo('pengumuman.php?msg=' . rawurlencode('Pengumuman diarsipkan; isi lama tetap tersimpan.'));
        }
        throw new InvalidArgumentException('Aksi pengumuman tidak dikenal. Muat ulang halaman lalu coba lagi.');
    } catch (InvalidArgumentException $failure) {
        appRequestError(422, 'Pengumuman belum diubah', $failure->getMessage());
    } catch (Throwable $failure) {
        reportApplicationError($failure, 'announcement-write');
        appRequestError(503, 'Pengumuman belum tersimpan', 'Perubahan belum dapat dipastikan. Muat ulang halaman untuk memeriksa status terbaru.');
    }
}

$pengumuman = $pdo->query("SELECT * FROM pengumuman ORDER BY created_at DESC")->fetchAll();

$pageTitle = 'Broadcast Pengumuman';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="mb-8">
        <h2 class="text-3xl font-black text-slate-800">Broadcast Pengumuman</h2>
        <p class="text-slate-500">Kirim pesan informasi ke seluruh Dashboard Ustadz.</p>
    </div>

    <?php if ($message): ?>
        <div class="bg-emerald-50 text-emerald-600 p-4 rounded-2xl border border-emerald-100 mb-6 italic text-sm">
            <?php echo appEscape($message); ?>
        </div>
    <?php endif; ?>

    <!-- Form Input -->
    <div class="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm mb-10">
        <h3 class="text-lg font-bold text-slate-800 mb-6">Buat Pengumuman Baru</h3>
        <form method="POST" class="space-y-4">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="save">
            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase mb-2 tracking-widest">Judul /
                    Subjek</label>
                <input type="text" name="judul" placeholder="Contoh: Libur Syawal / Info Rapat" required
                    maxlength="200" class="w-full px-5 py-3 rounded-2xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition font-medium">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase mb-2 tracking-widest">Isi Pesan</label>
                <textarea name="isi" rows="4" maxlength="20000" placeholder="Tuliskan detail pengumuman di sini..." required
                    class="w-full px-5 py-3 rounded-2xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition font-medium"></textarea>
            </div>
            <button type="submit"
                class="bg-blue-600 text-white px-8 py-3 rounded-2xl font-black uppercase tracking-widest text-xs hover:bg-blue-700 transition shadow-lg shadow-blue-100">
                Sebarkan Sekarang
            </button>
        </form>
    </div>

    <!-- Arsip Pengumuman -->
    <div class="space-y-4">
        <h3 class="text-lg font-bold text-slate-800 mb-6">Riwayat Broadcast</h3>
        <?php if (empty($pengumuman)): ?>
            <p class="text-slate-400 italic text-center py-10 bg-white rounded-3xl border border-dashed border-slate-200">
                Belum ada pengumuman.</p>
        <?php endif; ?>
        <?php foreach ($pengumuman as $p): ?>
            <div
                class="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div class="flex-1">
                    <div class="flex items-center gap-3 mb-1">
                        <span
                            class="px-2 py-0.5 rounded-lg text-[10px] font-black uppercase <?php echo $p['is_aktif'] ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-400'; ?>">
                            <?php echo $p['is_aktif'] ? 'Aktif' : 'Non-Aktif'; ?>
                        </span>
                        <h4 class="font-bold text-slate-800">
                            <?php echo htmlspecialchars($p['judul']); ?>
                        </h4>
                    </div>
                    <p class="text-sm text-slate-500 line-clamp-2">
                        <?php echo nl2br(htmlspecialchars($p['isi'])); ?>
                    </p>
                    <p class="text-[10px] text-slate-400 font-bold mt-2 uppercase">
                        <?php echo date('d M Y H:i', strtotime($p['created_at'])); ?>
                    </p>
                </div>
                <div class="flex gap-2">
                    <form method="POST" class="inline">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                        <input type="hidden" name="status" value="<?php echo $p['is_aktif'] ? 0 : 1; ?>">
                        <button type="submit"
                            class="px-4 py-2 rounded-xl text-[10px] font-bold uppercase transition <?php echo $p['is_aktif'] ? 'bg-amber-50 text-amber-600 border border-amber-100 hover:bg-amber-600 hover:text-white' : 'bg-emerald-50 text-emerald-600 border border-emerald-100 hover:bg-emerald-600 hover:text-white'; ?>">
                            <?php echo $p['is_aktif'] ? 'Arsipkan' : 'Aktifkan'; ?>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
