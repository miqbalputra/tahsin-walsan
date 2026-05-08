<?php
$pageTitle = 'Gabung Data Peserta Duplikat';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

checkRole(['admin']);

$message = '';
$error = '';
$mergeResult = null;

// Handle Merge Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $action = $_POST['action'] ?? '';
    $keep_id = intval($_POST['keep_id'] ?? 0);
    $delete_id = intval($_POST['delete_id'] ?? 0);

    if ($action === 'merge' && $keep_id && $delete_id && $keep_id !== $delete_id) {
        $pdo->beginTransaction();
        try {
            // Verify both exist
            $stmtA = $pdo->prepare("SELECT * FROM wali_santri WHERE id = ?");
            $stmtA->execute([$keep_id]);
            $keepData = $stmtA->fetch();

            $stmtB = $pdo->prepare("SELECT * FROM wali_santri WHERE id = ?");
            $stmtB->execute([$delete_id]);
            $deleteData = $stmtB->fetch();

            if (!$keepData || !$deleteData) {
                throw new Exception("Salah satu ID peserta tidak ditemukan.");
            }

            // 1. Transfer presensi (capaian) from duplicate to kept
            $stmtPresensi = $pdo->prepare("UPDATE presensi SET wali_santri_id = ? WHERE wali_santri_id = ?");
            $stmtPresensi->execute([$keep_id, $delete_id]);
            $presensiMoved = $stmtPresensi->rowCount();

            // 2. Transfer halaqoh membership (if not already member of the same halaqoh)
            $stmtHm = $pdo->prepare("
                UPDATE halaqoh_members SET wali_santri_id = ? 
                WHERE wali_santri_id = ? 
                AND halaqoh_id NOT IN (SELECT halaqoh_id FROM (SELECT halaqoh_id FROM halaqoh_members WHERE wali_santri_id = ?) AS tmp)
            ");
            $stmtHm->execute([$keep_id, $delete_id, $keep_id]);
            $hmMoved = $stmtHm->rowCount();

            // Delete remaining halaqoh_members of duplicate (if already exists in kept)
            $pdo->prepare("DELETE FROM halaqoh_members WHERE wali_santri_id = ?")->execute([$delete_id]);

            // 3. Merge santri_detail (children) — add children from duplicate that don't exist in kept
            $stmtChildren = $pdo->prepare("SELECT nama_anak, kelas FROM santri_detail WHERE wali_santri_id = ?");
            $stmtChildren->execute([$delete_id]);
            $dupeChildren = $stmtChildren->fetchAll();

            $stmtExisting = $pdo->prepare("SELECT nama_anak FROM santri_detail WHERE wali_santri_id = ?");
            $stmtExisting->execute([$keep_id]);
            $existingNames = array_column($stmtExisting->fetchAll(), 'nama_anak');

            $childrenAdded = 0;
            $stmtAddChild = $pdo->prepare("INSERT INTO santri_detail (wali_santri_id, nama_anak, kelas) VALUES (?, ?, ?)");
            foreach ($dupeChildren as $child) {
                if (!in_array($child['nama_anak'], $existingNames)) {
                    $stmtAddChild->execute([$keep_id, $child['nama_anak'], $child['kelas']]);
                    $childrenAdded++;
                }
            }

            // 4. Delete duplicate's santri_detail
            $pdo->prepare("DELETE FROM santri_detail WHERE wali_santri_id = ?")->execute([$delete_id]);

            // 5. Delete the duplicate wali_santri
            $pdo->prepare("DELETE FROM wali_santri WHERE id = ?")->execute([$delete_id]);

            $pdo->commit();

            addLog($pdo, 'MERGE_PESERTA', "Menggabungkan peserta duplikat ID:$delete_id ({$deleteData['nama_bapak']}) → ID:$keep_id ({$keepData['nama_bapak']}). Presensi dipindahkan: $presensiMoved, Halaqoh dipindahkan: $hmMoved, Anak ditambahkan: $childrenAdded");

            $message = "Berhasil! Data peserta <strong>{$deleteData['nama_bapak']}</strong> (ID: $delete_id) telah digabungkan ke <strong>{$keepData['nama_bapak']}</strong> (ID: $keep_id).";
            $mergeResult = [
                'presensi' => $presensiMoved,
                'halaqoh' => $hmMoved,
                'children' => $childrenAdded,
                'keep_name' => $keepData['nama_bapak'],
                'delete_name' => $deleteData['nama_bapak'],
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Gagal menggabungkan: " . $e->getMessage();
        }
    } else {
        $error = "Data tidak valid. Pastikan kedua ID berbeda dan terisi.";
    }
}

// Check for preview
$preview_keep = null;
$preview_delete = null;
if (isset($_GET['keep_id']) && isset($_GET['delete_id'])) {
    $kid = intval($_GET['keep_id']);
    $did = intval($_GET['delete_id']);

    if ($kid && $did && $kid !== $did) {
        // Fetch keep data
        $stmt = $pdo->prepare("SELECT w.*, 
            (SELECT GROUP_CONCAT(CONCAT(nama_anak, ' (', kelas, ')') SEPARATOR ', ') FROM santri_detail WHERE wali_santri_id = w.id) as daftar_anak,
            (SELECT COUNT(*) FROM presensi WHERE wali_santri_id = w.id) as total_presensi,
            (SELECT GROUP_CONCAT(h.nama_halaqoh SEPARATOR ', ') FROM halaqoh_members hm JOIN halaqoh h ON hm.halaqoh_id = h.id WHERE hm.wali_santri_id = w.id) as nama_halaqoh
            FROM wali_santri w WHERE w.id = ?");
        $stmt->execute([$kid]);
        $preview_keep = $stmt->fetch();

        $stmt->execute([$did]);
        $preview_delete = $stmt->fetch();
    }
}

// Fetch all peserta for dropdown
$allPeserta = $pdo->query("SELECT w.id, w.nama_bapak, w.no_hp, 
    (SELECT GROUP_CONCAT(CONCAT(nama_anak, ' (', kelas, ')') SEPARATOR ', ') FROM santri_detail WHERE wali_santri_id = w.id) as daftar_anak
    FROM wali_santri w ORDER BY w.nama_bapak")->fetchAll();

// Detect potential duplicates (same nama_bapak)
$duplicates = $pdo->query("
    SELECT nama_bapak, COUNT(*) as jumlah, GROUP_CONCAT(id ORDER BY id) as ids
    FROM wali_santri 
    GROUP BY nama_bapak 
    HAVING COUNT(*) > 1 
    ORDER BY nama_bapak
")->fetchAll();
?>

<div class="mb-6">
    <a href="peserta.php" class="inline-flex items-center text-slate-400 hover:text-blue-600 transition mb-3">
        <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
        </svg>
        Kembali ke Data Peserta
    </a>
    <h2 class="text-2xl font-bold text-slate-800">Gabung Data Peserta Duplikat</h2>
    <p class="text-slate-500 text-sm mt-1">Pindahkan semua data capaian dari peserta duplikat ke peserta yang
        dipertahankan, lalu hapus duplikatnya dengan aman.</p>
</div>

<?php if ($message): ?>
    <div class="bg-emerald-50 border border-emerald-100 rounded-2xl p-5 mb-6">
        <div class="flex items-start gap-3">
            <div class="bg-emerald-100 p-2 rounded-xl">
                <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <div>
                <p class="text-emerald-700 font-semibold">
                    <?php echo $message; ?>
                </p>
                <?php if ($mergeResult): ?>
                    <div class="mt-3 grid grid-cols-3 gap-3">
                        <div class="bg-white rounded-xl p-3 text-center border border-emerald-100">
                            <div class="text-2xl font-bold text-emerald-700">
                                <?php echo $mergeResult['presensi']; ?>
                            </div>
                            <div class="text-[10px] text-emerald-600 font-semibold uppercase">Presensi Dipindah</div>
                        </div>
                        <div class="bg-white rounded-xl p-3 text-center border border-emerald-100">
                            <div class="text-2xl font-bold text-emerald-700">
                                <?php echo $mergeResult['halaqoh']; ?>
                            </div>
                            <div class="text-[10px] text-emerald-600 font-semibold uppercase">Halaqoh Dipindah</div>
                        </div>
                        <div class="bg-white rounded-xl p-3 text-center border border-emerald-100">
                            <div class="text-2xl font-bold text-emerald-700">
                                <?php echo $mergeResult['children']; ?>
                            </div>
                            <div class="text-[10px] text-emerald-600 font-semibold uppercase">Anak Ditambahkan</div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="bg-red-50 text-red-600 p-4 rounded-xl mb-6 border border-red-100 font-semibold">
        ⚠️
        <?php echo $error; ?>
    </div>
<?php endif; ?>

<!-- Auto-Detected Duplicates -->
<?php if (!empty($duplicates) && !$preview_keep): ?>
    <div class="bg-amber-50 border border-amber-100 rounded-2xl p-5 mb-6">
        <h3 class="font-bold text-amber-800 flex items-center gap-2 mb-3">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z">
                </path>
            </svg>
            Terdeteksi
            <?php echo count($duplicates); ?> Peserta Duplikat
        </h3>
        <div class="space-y-2">
            <?php foreach ($duplicates as $d):
                $ids = explode(',', $d['ids']);
                ?>
                <div class="bg-white rounded-xl p-3 flex items-center justify-between border border-amber-100">
                    <div>
                        <span class="font-bold text-slate-800">
                            <?php echo htmlspecialchars($d['nama_bapak']); ?>
                        </span>
                        <span class="text-amber-600 text-xs font-semibold ml-2">
                            <?php echo $d['jumlah']; ?>x duplikat
                        </span>
                        <span class="text-slate-400 text-xs ml-1">(ID:
                            <?php echo $d['ids']; ?>)
                        </span>
                    </div>
                    <a href="?keep_id=<?php echo $ids[0]; ?>&delete_id=<?php echo $ids[1]; ?>"
                        class="bg-amber-100 text-amber-700 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-amber-200 transition">
                        Review & Gabungkan →
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($preview_keep && $preview_delete): ?>
    <!-- Preview Comparison -->
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6 mb-6">
        <h3 class="font-bold text-slate-800 text-lg mb-4">Preview Penggabungan</h3>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- KEEP -->
            <div class="rounded-2xl border-2 border-emerald-200 bg-emerald-50/30 p-5">
                <div class="flex items-center gap-2 mb-4">
                    <span
                        class="bg-emerald-100 text-emerald-700 text-[10px] font-bold px-2.5 py-1 rounded-lg uppercase tracking-wider">✓
                        Dipertahankan</span>
                    <span class="text-slate-400 text-xs">ID:
                        <?php echo $preview_keep['id']; ?>
                    </span>
                </div>
                <h4 class="text-xl font-bold text-slate-800 mb-3">
                    <?php echo htmlspecialchars($preview_keep['nama_bapak']); ?>
                </h4>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-slate-500">No. HP</span>
                        <span class="font-semibold text-slate-700">
                            <?php echo htmlspecialchars($preview_keep['no_hp'] ?: '-'); ?>
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">Kategori</span>
                        <span class="font-semibold text-slate-700">
                            <?php echo $preview_keep['kategori']; ?>
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">Anak</span>
                        <span class="font-semibold text-slate-700 text-right max-w-[200px]">
                            <?php echo htmlspecialchars($preview_keep['daftar_anak'] ?: '-'); ?>
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">Halaqoh</span>
                        <span class="font-semibold text-slate-700">
                            <?php echo htmlspecialchars($preview_keep['nama_halaqoh'] ?: '-'); ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center pt-2 border-t border-emerald-100">
                        <span class="text-slate-500">Data Presensi</span>
                        <span class="bg-emerald-100 text-emerald-700 font-bold text-xs px-2 py-0.5 rounded-lg">
                            <?php echo $preview_keep['total_presensi']; ?> record
                        </span>
                    </div>
                </div>
            </div>

            <!-- DELETE -->
            <div class="rounded-2xl border-2 border-red-200 bg-red-50/30 p-5">
                <div class="flex items-center gap-2 mb-4">
                    <span
                        class="bg-red-100 text-red-700 text-[10px] font-bold px-2.5 py-1 rounded-lg uppercase tracking-wider">✕
                        Akan Dihapus</span>
                    <span class="text-slate-400 text-xs">ID:
                        <?php echo $preview_delete['id']; ?>
                    </span>
                </div>
                <h4 class="text-xl font-bold text-slate-800 mb-3">
                    <?php echo htmlspecialchars($preview_delete['nama_bapak']); ?>
                </h4>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-slate-500">No. HP</span>
                        <span class="font-semibold text-slate-700">
                            <?php echo htmlspecialchars($preview_delete['no_hp'] ?: '-'); ?>
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">Kategori</span>
                        <span class="font-semibold text-slate-700">
                            <?php echo $preview_delete['kategori']; ?>
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">Anak</span>
                        <span class="font-semibold text-slate-700 text-right max-w-[200px]">
                            <?php echo htmlspecialchars($preview_delete['daftar_anak'] ?: '-'); ?>
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">Halaqoh</span>
                        <span class="font-semibold text-slate-700">
                            <?php echo htmlspecialchars($preview_delete['nama_halaqoh'] ?: '-'); ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center pt-2 border-t border-red-100">
                        <span class="text-slate-500">Data Presensi</span>
                        <span class="bg-red-100 text-red-700 font-bold text-xs px-2 py-0.5 rounded-lg">
                            <?php echo $preview_delete['total_presensi']; ?> record → dipindahkan
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Merge Flow Illustration -->
        <div class="mt-6 bg-blue-50 border border-blue-100 rounded-xl p-4">
            <h4 class="font-bold text-blue-800 text-sm mb-2">Yang akan terjadi saat digabungkan:</h4>
            <ul class="text-blue-700 text-xs space-y-1">
                <li>✅ <strong>
                        <?php echo $preview_delete['total_presensi']; ?> data presensi/capaian
                    </strong> dipindahkan ke <strong>
                        <?php echo htmlspecialchars($preview_keep['nama_bapak']); ?>
                    </strong></li>
                <li>✅ Keanggotaan halaqoh dipindahkan (jika belum ada)</li>
                <li>✅ Data anak yang belum ada akan ditambahkan</li>
                <li>✅ Data duplikat <strong>
                        <?php echo htmlspecialchars($preview_delete['nama_bapak']); ?>
                    </strong> (ID:
                    <?php echo $preview_delete['id']; ?>) dihapus
                </li>
                <li>⚠️ Proses ini <strong>tidak bisa dibatalkan</strong></li>
            </ul>
        </div>

        <!-- Action Buttons -->
        <div class="mt-6 flex gap-3">
            <form method="POST" class="flex-1"
                onsubmit="return confirm('Yakin ingin menggabungkan data peserta ini? Proses tidak bisa dibatalkan.')">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="merge">
                <input type="hidden" name="keep_id" value="<?php echo $preview_keep['id']; ?>">
                <input type="hidden" name="delete_id" value="<?php echo $preview_delete['id']; ?>">
                <button type="submit"
                    class="w-full bg-blue-600 text-white px-6 py-3 rounded-xl font-bold hover:bg-blue-700 transition shadow-lg shadow-blue-100 flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                    </svg>
                    Gabungkan Sekarang
                </button>
            </form>

            <!-- Swap Button -->
            <a href="?keep_id=<?php echo $preview_delete['id']; ?>&delete_id=<?php echo $preview_keep['id']; ?>"
                class="bg-slate-100 text-slate-600 px-4 py-3 rounded-xl font-semibold hover:bg-slate-200 transition flex items-center gap-2 text-sm"
                title="Tukar: pertahankan yang lain">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
                </svg>
                Tukar
            </a>

            <a href="merge-peserta.php"
                class="bg-slate-100 text-slate-600 px-4 py-3 rounded-xl font-semibold hover:bg-slate-200 transition text-sm flex items-center">
                Batal
            </a>
        </div>
    </div>

<?php else: ?>
    <!-- Manual Selection Form -->
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
        <h3 class="font-bold text-slate-800 mb-4">Pilih Peserta untuk Digabungkan</h3>
        <form method="GET" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Keep -->
                <div>
                    <label class="block text-sm font-bold text-emerald-700 mb-2">✓ Peserta yang DIPERTAHANKAN</label>
                    <select name="keep_id" required
                        class="w-full px-4 py-3 rounded-xl border-2 border-emerald-200 focus:ring-2 focus:ring-emerald-500 outline-none transition text-sm bg-emerald-50/30">
                        <option value="">-- Pilih peserta yang mau dipertahankan --</option>
                        <?php foreach ($allPeserta as $p): ?>
                            <option value="<?php echo $p['id']; ?>">
                                <?php echo htmlspecialchars($p['nama_bapak']); ?>
                                (
                                <?php echo $p['no_hp']; ?>)
                                <?php echo $p['daftar_anak'] ? '— Anak: ' . htmlspecialchars($p['daftar_anak']) : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-emerald-600 text-[10px] mt-1 font-semibold">Data ini akan dipertahankan + menerima
                        capaian dari duplikat</p>
                </div>

                <!-- Delete -->
                <div>
                    <label class="block text-sm font-bold text-red-700 mb-2">✕ Peserta DUPLIKAT (akan dihapus)</label>
                    <select name="delete_id" required
                        class="w-full px-4 py-3 rounded-xl border-2 border-red-200 focus:ring-2 focus:ring-red-500 outline-none transition text-sm bg-red-50/30">
                        <option value="">-- Pilih peserta duplikat yang akan dihapus --</option>
                        <?php foreach ($allPeserta as $p): ?>
                            <option value="<?php echo $p['id']; ?>">
                                <?php echo htmlspecialchars($p['nama_bapak']); ?>
                                (
                                <?php echo $p['no_hp']; ?>)
                                <?php echo $p['daftar_anak'] ? '— Anak: ' . htmlspecialchars($p['daftar_anak']) : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-red-600 text-[10px] mt-1 font-semibold">Data capaian akan dipindahkan, lalu data ini
                        dihapus</p>
                </div>
            </div>

            <button type="submit"
                class="bg-blue-600 text-white px-6 py-3 rounded-xl font-bold hover:bg-blue-700 transition shadow-sm flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                    </path>
                </svg>
                Preview Penggabungan
            </button>
        </form>
    </div>
<?php endif; ?>

<!-- Info Box -->
<div class="mt-6 bg-slate-50 border border-slate-100 rounded-2xl p-5">
    <h4 class="font-bold text-slate-700 text-sm mb-2">ℹ️ Cara Kerja Penggabungan</h4>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs text-slate-600">
        <div class="flex gap-2">
            <span class="text-blue-500 font-bold text-base">1</span>
            <div>
                <div class="font-semibold text-slate-700">Pilih 2 Peserta</div>
                <p>Pilih mana yang dipertahankan dan mana yang duplikat</p>
            </div>
        </div>
        <div class="flex gap-2">
            <span class="text-blue-500 font-bold text-base">2</span>
            <div>
                <div class="font-semibold text-slate-700">Preview & Verifikasi</div>
                <p>Bandingkan kedua data, pastikan sudah benar sebelum gabungkan</p>
            </div>
        </div>
        <div class="flex gap-2">
            <span class="text-blue-500 font-bold text-base">3</span>
            <div>
                <div class="font-semibold text-slate-700">Data Aman</div>
                <p>Semua capaian & presensi dipindahkan, tidak ada data yang hilang</p>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>