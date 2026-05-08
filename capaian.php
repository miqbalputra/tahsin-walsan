<?php
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

$pageTitle = 'Daftar Capaian Peserta';
checkLogin();

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Authorization check
if (!in_array($role, ['admin', 'pj_tahfidz', 'ustadz'])) {
    header("Location: dashboard.php");
    exit();
}

$message = $_GET['msg'] ?? '';
$error = $_GET['err'] ?? '';

// Handle Update (Admin & PJ Tahfidz only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($role, ['admin', 'pj_tahfidz'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $presensi_id = $_POST['presensi_id'] ?? null;
    $wali_id = $_POST['wali_id'] ?? null;
    $halaqoh_id = $_POST['halaqoh_id'] ?? null;

    $jenis_materi = $_POST['jenis_materi'] ?? 'Al Quran';
    $jilid = ($jenis_materi === 'Iqro') ? ($_POST['jilid'] ?? null) : null;
    $nama_surat = ($jenis_materi === 'Al Quran') ? ($_POST['nama_surat'] ?? '') : null;
    $halaman = $_POST['halaman'] ?? '';

    try {
        if ($presensi_id) {
            // Update the existing latest record
            $stmt = $pdo->prepare("UPDATE presensi SET jenis_materi = ?, jilid = ?, nama_surat = ?, halaman = ? WHERE id = ?");
            $stmt->execute([$jenis_materi, $jilid, $nama_surat, $halaman, $presensi_id]);
            addLog($pdo, 'UPDATE_CAPAIAN', "Update capaian via List Capaian: ID $presensi_id");
        } else {
            // If no previous record, we create a 'dummy' or initial record with current date
            // This allows initializing progress for someone who has never attended
            $stmt = $pdo->prepare("INSERT INTO presensi (halaqoh_id, wali_santri_id, tanggal, status, jenis_materi, jilid, nama_surat, halaman, hasil_talaqqi) 
                                   VALUES (?, ?, CURRENT_DATE, 'H', ?, ?, ?, ?, 'Lulus')");
            $stmt->execute([$halaqoh_id, $wali_id, $jenis_materi, $jilid, $nama_surat, $halaman]);
            addLog($pdo, 'INIT_CAPAIAN', "Inisialisasi capaian via List Capaian: Wali ID $wali_id");
        }
        $message = "Capaian berhasil diperbarui!";
    } catch (Exception $e) {
        $error = "Gagal memperbarui: " . $e->getMessage();
    }
}

// Fetch Filters
$halaqoh_filter = $_GET['halaqoh_id'] ?? '';
$search = $_GET['search'] ?? '';

// Build Query
$sql = "SELECT 
            w.id as wali_id,
            w.nama_bapak,
            w.no_hp,
            h.id as halaqoh_id,
            h.nama_halaqoh,
            u.nama_lengkap as ustadz_name,
            GROUP_CONCAT(DISTINCT s.nama_anak SEPARATOR ', ') as children_names,
            GROUP_CONCAT(DISTINCT s.kelas SEPARATOR ', ') as classes,
            p.id as presensi_id,
            p.jenis_materi,
            p.jilid,
            p.nama_surat,
            p.halaman,
            p.tanggal as last_date
        FROM wali_santri w
        JOIN halaqoh_members hm ON w.id = hm.wali_santri_id
        JOIN halaqoh h ON hm.halaqoh_id = h.id
        JOIN users u ON h.ustadz_id = u.id
        LEFT JOIN santri_detail s ON w.id = s.wali_santri_id
        LEFT JOIN (
            SELECT p1.*
            FROM presensi p1
            INNER JOIN (
                SELECT wali_santri_id, MAX(tanggal) as max_date, MAX(id) as max_id
                FROM presensi
                WHERE status = 'H'
                GROUP BY wali_santri_id
            ) p2 ON p1.wali_santri_id = p2.wali_santri_id AND p1.tanggal = p2.max_date AND p1.id = p2.max_id
        ) p ON w.id = p.wali_santri_id
        WHERE 1=1";

$params = [];

if ($role === 'ustadz') {
    $sql .= " AND h.ustadz_id = ?";
    $params[] = $user_id;
}

if (!empty($halaqoh_filter)) {
    $sql .= " AND h.id = ?";
    $params[] = $halaqoh_filter;
}

if (!empty($search)) {
    $sql .= " AND (w.nama_bapak LIKE ? OR h.nama_halaqoh LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " GROUP BY w.id, h.id ORDER BY h.nama_halaqoh, w.nama_bapak";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

// Fetch Halaqohs for dropdown
if ($role === 'ustadz') {
    $stmtH = $pdo->prepare("SELECT id, nama_halaqoh FROM halaqoh WHERE ustadz_id = ? ORDER BY nama_halaqoh");
    $stmtH->execute([$user_id]);
} else {
    $stmtH = $pdo->query("SELECT id, nama_halaqoh FROM halaqoh ORDER BY nama_halaqoh");
}
$halaqohs = $stmtH->fetchAll();

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="mb-8">
    <h2 class="text-2xl font-bold text-slate-800">Daftar Capaian Peserta</h2>
    <p class="text-slate-500 text-sm">Rekapitulasi pencapaian terakhir materi Tahsin seluruh peserta</p>
</div>

<div x-data="{ 
    showEditModal: false,
    editData: { presensi_id: '', wali_id: '', halaqoh_id: '', nama_bapak: '', jenis_materi: 'Al Quran', jilid: '', nama_surat: '', halaman: '' },
    openEdit(row) {
        this.editData = {
            presensi_id: row.presensi_id || '',
            wali_id: row.wali_id,
            halaqoh_id: row.halaqoh_id,
            nama_bapak: row.nama_bapak,
            jenis_materi: row.jenis_materi || 'Al Quran',
            jilid: row.jilid || '',
            nama_surat: row.nama_surat || '',
            halaman: row.halaman || ''
        };
        this.showEditModal = true;
    }
}">
    <!-- Filters -->
    <div class="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm mb-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">Halaqoh</label>
                <select name="halaqoh_id"
                    class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition text-sm font-semibold">
                    <option value="">Semua Halaqoh</option>
                    <?php foreach ($halaqohs as $h): ?>
                        <option value="<?php echo $h['id']; ?>" <?php echo $halaqoh_filter == $h['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($h['nama_halaqoh']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">Cari Nama</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                    placeholder="Nama bapak..."
                    class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition text-sm">
            </div>
            <div>
                <button type="submit"
                    class="w-full bg-blue-600 text-white font-bold py-2.5 rounded-xl hover:bg-blue-700 transition shadow-lg shadow-blue-100 uppercase text-xs">Filter
                    Data</button>
            </div>
        </form>
    </div>

    <?php if ($message): ?>
        <div class="bg-emerald-50 text-emerald-600 p-4 rounded-xl border border-emerald-100 mb-6 italic text-sm">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="bg-red-50 text-red-600 p-4 rounded-xl border border-red-100 mb-6 font-medium text-sm">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Table Card -->
    <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead
                    class="bg-slate-50 border-b border-slate-100 text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                    <tr>
                        <th class="px-6 py-4">Nama Peserta</th>
                        <th class="px-6 py-4">Halaqoh & Ustadz</th>
                        <th class="px-6 py-4">Anak & Kelas</th>
                        <th class="px-6 py-4">Capaian Terakhir</th>
                        <?php if (in_array($role, ['admin', 'pj_tahfidz'])): ?>
                            <th class="px-6 py-4 text-center">Aksi</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50 text-sm">
                    <?php if (empty($results)): ?>
                        <tr>
                            <td colspan="<?php echo in_array($role, ['admin', 'pj_tahfidz']) ? '5' : '4'; ?>"
                                class="px-6 py-12 text-center text-slate-400 italic">Data tidak ditemukan.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($results as $row): ?>
                        <tr class="hover:bg-slate-50 transition">
                            <td class="px-6 py-4 font-bold text-slate-800">
                                <div class="flex items-center gap-2">
                                    <span><?php echo htmlspecialchars($row['nama_bapak']); ?></span>
                                    <?php if (!empty($row['no_hp'])): 
                                        $wa_no = preg_replace('/[^0-9]/', '', $row['no_hp']);
                                        if (str_starts_with($wa_no, '0')) $wa_no = '62' . substr($wa_no, 1);
                                        $wa_msg = urlencode("Assalamualaikum Pak " . $row['nama_bapak'] . ",\n\nKami dari Tahsin Bapak ingin menginfokan terkait capaian terakhir Bapak yaitu: " . ($row['jenis_materi'] ?: '-') . " " . (($row['jilid'] ?? $row['nama_surat']) ?: '-') . " halaman/ayat " . ($row['halaman'] ?: '-') . ".");
                                    ?>
                                        <a href="https://wa.me/<?php echo $wa_no; ?>?text=<?php echo $wa_msg; ?>" target="_blank" class="p-1 px-2 rounded-lg bg-emerald-50 text-emerald-600 hover:bg-emerald-600 hover:text-white transition shadow-sm border border-emerald-100 flex items-center gap-1 group">
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 448 512"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.7 17.8 69.4 27.2 106.2 27.2 122.4 0 222-99.6 222-222 0-59.3-23-115.1-65-157.1zM223.9 446.3c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3 18.7-68.1-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-5.5-2.8-23.4-8.6-44.6-27.5-16.4-14.7-27.5-32.8-30.7-38.4-3.2-5.6-.3-8.6 2.5-11.4 2.6-2.5 5.5-6.5 8.3-9.8 2.8-3.3 3.7-5.6 5.6-9.3 1.9-3.7.9-6.9-.5-9.8-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 13.2 5.7 23.5 9.2 31.5 11.8 13.3 4.2 25.4 3.6 35 2.2 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>
                                            <span class="text-[9px] font-bold uppercase hidden group-hover:inline">Chat</span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-semibold text-blue-600 text-xs tracking-tight">
                                    <?php echo htmlspecialchars($row['nama_halaqoh']); ?>
                                </div>
                                <div class="text-[10px] text-slate-400">Pengampu:
                                    <?php echo htmlspecialchars($row['ustadz_name']); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-xs text-slate-700">
                                    <?php echo htmlspecialchars($row['children_names'] ?: '-'); ?>
                                </div>
                                <div class="text-[10px] text-slate-400">
                                    <?php echo htmlspecialchars($row['classes'] ?: '-'); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($row['jenis_materi']): ?>
                                    <div class="flex flex-col">
                                        <span class="font-bold text-slate-700">
                                            <?php echo $row['jenis_materi']; ?>
                                            <?php echo ($row['jenis_materi'] === 'Iqro') ? 'Jilid ' . $row['jilid'] : htmlspecialchars($row['nama_surat']); ?>
                                        </span>
                                        <span class="text-[10px] text-slate-400">
                                            <?php echo ($row['jenis_materi'] === 'Al Quran' ? 'Ayat ' : 'Hal ') . $row['halaman']; ?>
                                        </span>
                                        <span class="text-[9px] mt-1 text-slate-300 italic">Update:
                                            <?php echo date('d/m/y', strtotime($row['last_date'])); ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <span class="text-xs italic text-slate-300">Belum ada data</span>
                                <?php endif; ?>
                            </td>
                            <?php if (in_array($role, ['admin', 'pj_tahfidz'])): ?>
                                <td class="px-6 py-4 text-center">
                                    <button @click="openEdit(<?php echo htmlspecialchars(json_encode($row)); ?>)"
                                        class="bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white px-3 py-1.5 rounded-xl font-bold text-[10px] transition uppercase tracking-wider border border-blue-100">
                                        Ubah Capaian
                                    </button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit Modal -->
    <div x-show="showEditModal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm" x-cloak>
        <div class="bg-white rounded-3xl w-full max-w-md p-8 shadow-2xl" @click.away="showEditModal = false">
            <h3 class="text-xl font-bold text-slate-800 mb-2">Ubah Capaian Terakhir</h3>
            <p class="text-xs text-slate-500 mb-6" x-text="editData.nama_bapak"></p>

            <form method="POST" action="" class="space-y-4">
                <?php csrfField(); ?>
                <input type="hidden" name="presensi_id" x-model="editData.presensi_id">
                <input type="hidden" name="wali_id" x-model="editData.wali_id">
                <input type="hidden" name="halaqoh_id" x-model="editData.halaqoh_id">

                <div>
                    <label class="block text-xs font-bold text-slate-400 uppercase mb-1">Materi</label>
                    <select name="jenis_materi" x-model="editData.jenis_materi"
                        class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none text-sm">
                        <option value="Iqro">Iqro</option>
                        <option value="Al Quran">Al Quran</option>
                    </select>
                </div>

                <div x-show="editData.jenis_materi === 'Iqro'">
                    <label class="block text-xs font-bold text-slate-400 uppercase mb-1">Jilid</label>
                    <select name="jilid" x-model="editData.jilid"
                        class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none text-sm">
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <option value="<?php echo $i; ?>">Jilid
                                <?php echo $i; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div x-show="editData.jenis_materi === 'Al Quran'">
                    <label class="block text-xs font-bold text-slate-400 uppercase mb-1">Nama Surat</label>
                    <input type="text" name="nama_surat" x-model="editData.nama_surat"
                        class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none text-sm"
                        placeholder="Contoh: Al-Baqarah">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-400 uppercase mb-1"
                        x-text="editData.jenis_materi === 'Al Quran' ? 'Ayat' : 'Halaman'"></label>
                    <input type="text" name="halaman" x-model="editData.halaman" required
                        class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none text-sm"
                        placeholder="Contoh: 1-10">
                </div>

                <div class="flex gap-3 pt-4">
                    <button type="button" @click="showEditModal = false"
                        class="flex-1 px-4 py-3 rounded-2xl border border-slate-200 font-bold text-slate-600 hover:bg-slate-50 transition text-sm">Batal</button>
                    <button type="submit"
                        class="flex-1 px-4 py-3 rounded-2xl bg-blue-600 text-white font-bold hover:bg-blue-700 transition shadow-lg shadow-blue-200 text-sm">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>