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

$message = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $judul = $_POST['judul'] ?? '';
        $isi = $_POST['isi'] ?? '';
        $stmt = $pdo->prepare("INSERT INTO pengumuman (judul, isi) VALUES (?, ?)");
        if ($stmt->execute([$judul, $isi])) {
            $message = "Pengumuman berhasil disebarkan!";
        }
    } elseif ($action === 'toggle') {
        $id = $_POST['id'] ?? 0;
        $status = $_POST['status'] ?? 0;
        $stmt = $pdo->prepare("UPDATE pengumuman SET is_aktif = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("DELETE FROM pengumuman WHERE id = ?");
        $stmt->execute([$id]);
        $message = "Pengumuman berhasil dihapus.";
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
            <?php echo $message; ?>
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
                    class="w-full px-5 py-3 rounded-2xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition font-medium">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase mb-2 tracking-widest">Isi Pesan</label>
                <textarea name="isi" rows="4" placeholder="Tuliskan detail pengumuman di sini..." required
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
                    <form method="POST" onsubmit="return confirm('Hapus permanen?')" class="inline">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                        <button type="submit"
                            class="px-4 py-2 rounded-xl text-[10px] font-bold uppercase bg-red-50 text-red-600 border border-red-100 hover:bg-red-600 hover:text-white transition">Hapus</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>