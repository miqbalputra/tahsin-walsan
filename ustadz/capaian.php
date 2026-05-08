<?php
require_once '../config/database.php';
require_once '../includes/auth_helper.php';

$pageTitle = 'Daftar Capaian Peserta';
checkLogin();
checkRole(['ustadz']);

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Fetch Filters
$halaqoh_filter = $_GET['halaqoh_id'] ?? '';
$search = $_GET['search'] ?? '';

// Build Query - Show ALL groups, but track ustadz_id for edit permission
$sql = "SELECT 
            w.id as wali_id,
            w.nama_bapak,
            w.no_hp,
            h.id as halaqoh_id,
            h.nama_halaqoh,
            h.ustadz_id,
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

if (!empty($halaqoh_filter)) {
    $sql .= " AND h.id = ?";
    $params[] = $halaqoh_filter;
}

if (!empty($search)) {
    $sql .= " AND (w.nama_bapak LIKE ? OR h.nama_halaqoh LIKE ? OR u.nama_lengkap LIKE ?)";
    $term = "%$search%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql .= " GROUP BY w.id, h.id ORDER BY h.nama_halaqoh, w.nama_bapak";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

// Fetch ALL Halaqohs for dropdown
$stmtH = $pdo->query("SELECT id, nama_halaqoh FROM halaqoh ORDER BY nama_halaqoh");
$halaqohs = $stmtH->fetchAll();

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="mb-8">
    <h2 class="text-2xl font-bold text-slate-800">Daftar Capaian Peserta</h2>
    <p class="text-slate-500 text-sm">Rekapitulasi pencapaian terakhir materi Tahsin bapak-bapak di kelompok Anda</p>
</div>

<!-- Filters -->
<div class="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm mb-6">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
        <div>
            <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">Kelompok</label>
            <select name="halaqoh_id"
                class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition text-sm font-semibold">
                <option value="">Semua Kelompok</option>
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
                    <th class="px-6 py-4 text-center">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50 text-sm">
                <?php if (empty($results)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center text-slate-400 italic">Data tidak ditemukan.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($results as $row): 
                    $is_mine = ($row['ustadz_id'] == $user_id);
                    ?>
                    <tr class="hover:bg-slate-50 transition">
                        <td class="px-6 py-4 font-bold text-slate-800">
                            <div class="flex items-center gap-2">
                                <span><?php echo htmlspecialchars($row['nama_bapak']); ?></span>
                                <?php if (!empty($row['no_hp'])):
                                    $wa_no = preg_replace('/[^0-9]/', '', $row['no_hp']);
                                    if (str_starts_with($wa_no, '0'))
                                        $wa_no = '62' . substr($wa_no, 1);
                                    $wa_msg = urlencode("Assalamualaikum Pak " . $row['nama_bapak'] . ",\n\nKami dari Tahsin Bapak ingin menginfokan terkait capaian terakhir Bapak yaitu: " . ($row['jenis_materi'] ?: '-') . " " . (($row['jilid'] ?? $row['nama_surat']) ?: '-') . " halaman/ayat " . ($row['halaman'] ?: '-') . ".");
                                    ?>
                                    <a href="https://wa.me/<?php echo $wa_no; ?>?text=<?php echo $wa_msg; ?>" target="_blank"
                                        class="p-1 px-2 rounded-lg bg-emerald-50 text-emerald-600 hover:bg-emerald-600 hover:text-white transition shadow-sm border border-emerald-100 flex items-center gap-1 group"
                                        title="Kirim Pesan WA">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 448 512">
                                            <path
                                                d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.7 17.8 69.4 27.2 106.2 27.2 122.4 0 222-99.6 222-222 0-59.3-23-115.1-65-157.1zM223.9 446.3c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3 18.7-68.1-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-5.5-2.8-23.4-8.6-44.6-27.5-16.4-14.7-27.5-32.8-30.7-38.4-3.2-5.6-.3-8.6 2.5-11.4 2.6-2.5 5.5-6.5 8.3-9.8 2.8-3.3 3.7-5.6 5.6-9.3 1.9-3.7.9-6.9-.5-9.8-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 13.2 5.7 23.5 9.2 31.5 11.8 13.3 4.2 25.4 3.6 35 2.2 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z" />
                                        </svg>
                                        <span class="text-[9px] font-bold uppercase hidden group-hover:inline">Chat</span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="font-bold text-blue-600 text-[11px] tracking-tight">
                                <?php echo htmlspecialchars($row['nama_halaqoh']); ?>
                            </div>
                            <div class="text-[10px] text-slate-400">
                                Ust. <?php echo htmlspecialchars($row['ustadz_name']); ?>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-xs text-slate-700 font-medium">
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
                                    <span class="text-[9px] mt-1 text-slate-300 italic">Terakhir:
                                        <?php echo date('d/m/y', strtotime($row['last_date'])); ?>
                                    </span>
                                </div>
                            <?php else: ?>
                                <span class="text-xs italic text-slate-300">Belum ada data</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <?php if ($is_mine): ?>
                                <a href="form-presensi.php?id=<?php echo $row['halaqoh_id']; ?>" 
                                   class="inline-flex items-center gap-1.5 bg-blue-50 text-blue-600 px-3 py-1.5 rounded-lg font-bold text-[10px] hover:bg-blue-600 hover:text-white transition shadow-sm border border-blue-100">
                                    <svg class="w-3.4 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                    </svg>
                                    EDIT
                                </a>
                            <?php else: ?>
                                <span class="text-[10px] font-bold text-slate-300 uppercase tracking-wider bg-slate-50 px-2 py-1 rounded-md border border-slate-100">
                                    Hanya Lihat
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>