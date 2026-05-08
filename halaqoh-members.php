<?php
$pageTitle = 'Kelola Anggota Halaqoh';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

checkRole(['admin', 'pj_tahfidz']);

$halaqoh_id = $_GET['id'] ?? null;
if (!$halaqoh_id) {
    header("Location: halaqoh.php");
    exit();
}

// Fetch halaqoh details
$stmt = $pdo->prepare("SELECT h.*, u.nama_lengkap as nama_ustadz FROM halaqoh h JOIN users u ON h.ustadz_id = u.id WHERE h.id = ?");
$stmt->execute([$halaqoh_id]);
$h = $stmt->fetch();

if (!$h) {
    header("Location: halaqoh.php");
    exit();
}

$message = '';

// Handle Add/Remove Member
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Validation
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $action = $_POST['action'] ?? '';
    $wali_id = $_POST['wali_santri_id'] ?? null;

    if ($action === 'add' && $wali_id) {
        // Check if already in this halaqoh
        $check = $pdo->prepare("SELECT id FROM halaqoh_members WHERE halaqoh_id = ? AND wali_santri_id = ?");
        $check->execute([$halaqoh_id, $wali_id]);
        if (!$check->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO halaqoh_members (halaqoh_id, wali_santri_id) VALUES (?, ?)");
            $stmt->execute([$halaqoh_id, $wali_id]);
            $message = "Anggota berhasil ditambahkan!";
        }
    } elseif ($action === 'remove' && $wali_id) {
        $stmt = $pdo->prepare("DELETE FROM halaqoh_members WHERE halaqoh_id = ? AND wali_santri_id = ?");
        $stmt->execute([$halaqoh_id, $wali_id]);
        $message = "Anggota berhasil dihapus dari kelompok!";
    }
}

// Fetch current members with children details
$stmt = $pdo->prepare("SELECT w.*, 
                      (SELECT GROUP_CONCAT(CONCAT(nama_anak, ' [', kelas, ']') SEPARATOR ' | ') 
                       FROM santri_detail WHERE wali_santri_id = w.id) as daftar_anak
                      FROM wali_santri w 
                      JOIN halaqoh_members hm ON w.id = hm.wali_santri_id 
                      WHERE hm.halaqoh_id = ? 
                      ORDER BY w.nama_bapak");
$stmt->execute([$halaqoh_id]);
$members = $stmt->fetchAll();

// Fetch available wali santri with children details
$stmt = $pdo->prepare("SELECT w.id, w.nama_bapak, 
                      (SELECT GROUP_CONCAT(CONCAT(nama_anak, ' (', kelas, ')') SEPARATOR ', ') 
                       FROM santri_detail WHERE wali_santri_id = w.id) as daftar_anak
                      FROM wali_santri w 
                      WHERE w.id NOT IN (SELECT wali_santri_id FROM halaqoh_members) 
                      ORDER BY w.nama_bapak");
$stmt->execute();
$available_wali = $stmt->fetchAll();
?>

<div class="mb-6 flex items-center gap-4">
    <a href="halaqoh.php"
        class="bg-white p-2 rounded-xl border border-slate-100 text-slate-400 hover:text-blue-600 transition">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
        </svg>
    </a>
    <div>
        <h2 class="text-2xl font-bold text-slate-800">Kelola Anggota:
            <?php echo htmlspecialchars($h['nama_halaqoh']); ?>
        </h2>
        <p class="text-slate-500 text-sm">Ustadz:
            <?php echo htmlspecialchars($h['nama_ustadz']); ?>
        </p>
    </div>
</div>

<?php if ($message): ?>
    <div class="bg-emerald-50 text-emerald-600 p-4 rounded-xl mb-6 border border-emerald-100 italic">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <!-- List Members -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
            <div class="px-6 py-4 bg-slate-50 border-b border-slate-100">
                <h3 class="font-bold text-slate-800 uppercase text-xs tracking-wider">Daftar Anggota Saat Ini (
                    <?php echo count($members); ?>)
                </h3>
            </div>
            <table class="w-full text-left">
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($members)): ?>
                        <tr>
                            <td class="px-6 py-8 text-center text-slate-400 italic text-sm">Belum ada anggota di halaqoh
                                ini.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($members as $m): ?>
                        <tr class="hover:bg-slate-50/50 transition">
                            <td class="px-6 py-4 align-middle">
                                <div class="font-bold text-slate-800">
                                    <?php echo htmlspecialchars($m['nama_bapak']); ?>
                                </div>
                                <div class="flex flex-wrap gap-2 mt-1">
                                    <span
                                        class="text-[10px] text-slate-400 font-bold bg-slate-100 px-2 py-0.5 rounded-lg border border-slate-200">
                                        <?php echo htmlspecialchars($m['no_hp']); ?>
                                    </span>
                                    <?php if ($m['daftar_anak']): ?>
                                        <span
                                            class="text-[10px] text-blue-600 font-bold bg-blue-50 px-2 py-0.5 rounded-lg border border-blue-100">
                                            Anak: <?php echo htmlspecialchars($m['daftar_anak']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-right align-middle">
                                <form method="POST" onsubmit="return confirm('Keluarkan dari halaqoh?')"
                                    class="inline-flex justify-end">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="wali_santri_id" value="<?php echo $m['id']; ?>">
                                    <button type="submit"
                                        class="text-red-500 hover:bg-red-600 hover:text-white font-bold text-[10px] uppercase transition bg-red-50 px-3 py-1.5 rounded-xl border border-red-100">Keluarkan</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add Member Form -->
    <div>
        <div class="bg-white rounded-3xl border border-slate-100 shadow-sm p-6 sticky top-6">
            <h3 class="font-bold text-slate-800 mb-4">Tambah Anggota Baru</h3>
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="add">
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Cari Wali Santri</label>
                    <select name="wali_santri_id" required
                        class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition bg-slate-50 text-sm">
                        <option value="">-- Pilih Nama Bapak --</option>
                        <?php foreach ($available_wali as $w): ?>
                            <option value="<?php echo $w['id']; ?>">
                                <?php echo htmlspecialchars($w['nama_bapak']); ?>
                                <?php echo $w['daftar_anak'] ? "— [Anak: " . htmlspecialchars($w['daftar_anak']) . "]" : ""; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit"
                    class="w-full bg-blue-600 text-white font-bold py-3 rounded-xl hover:bg-blue-700 transition shadow-lg shadow-blue-100 flex justify-center items-center">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                    Tambahkan ke Grup
                </button>
            </form>
            <div class="mt-6 p-4 bg-blue-50 rounded-2xl text-[11px] text-blue-600 leading-relaxed italic">
                *Hanya Wali Santri yang belum memiliki kelompok halaqoh yang muncul di daftar pilihan di atas.
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>