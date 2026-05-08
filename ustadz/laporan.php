<?php
require_once '../config/database.php';
require_once '../includes/auth_helper.php';
require_once '../includes/report_helper.php';

// Must be at the very top
checkRole(['ustadz']);

$ustadz_id = $_SESSION['user_id'];
$message = $_GET['msg'] ?? '';
$error = $_GET['err'] ?? '';

$filters = getReportFilters();
$start_date = $filters['start_date'];
$end_date = $filters['end_date'];
$halaqoh_id = $filters['halaqoh_id'];
$wali_santri_id = $filters['wali_santri_id'];
$kelas_filter = $filters['kelas'];
$search = $filters['search'];

try {
    $results = fetchPresensiReport($pdo, $filters, 'ustadz', $ustadz_id, ['include_phone' => true]);

    // 2. Fetch Filter Options (Ustadz Restricted)
    $stmtH = $pdo->prepare("SELECT id, nama_halaqoh FROM halaqoh WHERE ustadz_id = ? ORDER BY nama_halaqoh");
    $stmtH->execute([$ustadz_id]);
    $halaqohs = $stmtH->fetchAll();

    $stmtW = $pdo->prepare("SELECT DISTINCT w.id, w.nama_bapak FROM wali_santri w JOIN halaqoh_members hm ON w.id = hm.wali_santri_id JOIN halaqoh h ON hm.halaqoh_id = h.id WHERE h.ustadz_id = ? ORDER BY w.nama_bapak");
    $stmtW->execute([$ustadz_id]);
    $all_wali = $stmtW->fetchAll();

    $stmtK = $pdo->prepare("SELECT DISTINCT s.kelas FROM santri_detail s JOIN halaqoh_members hm ON s.wali_santri_id = hm.wali_santri_id JOIN halaqoh h ON hm.halaqoh_id = h.id WHERE h.ustadz_id = ? AND s.kelas IS NOT NULL AND s.kelas != '' ORDER BY s.kelas");
    $stmtK->execute([$ustadz_id]);
    $daftar_kelas = $stmtK->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $error = "Kesalahan Database: " . $e->getMessage();
    $results = [];
    $halaqohs = [];
    $all_wali = [];
    $daftar_kelas = [];
}

$pageTitle = 'Rekap Presensi Halaqoh';
require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold text-slate-800">Rekapitulasi Halaqoh</h2>
        <p class="text-slate-500 text-sm">Monitoring kehadiran kelompok halaqoh Anda</p>
    </div>

    <div class="flex gap-2">
        <a href="../print-pdf.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&halaqoh_id=<?php echo $halaqoh_id; ?>&wali_santri_id=<?php echo $wali_santri_id; ?>&kelas=<?php echo urlencode($kelas_filter); ?>&search=<?php echo urlencode($search); ?>"
            target="_blank"
            class="bg-white text-slate-700 px-4 py-2 rounded-xl font-bold flex items-center shadow-sm border border-slate-200 hover:bg-slate-50 transition text-xs">
            <svg class="w-4 h-4 mr-2 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z">
                </path>
            </svg>
            PDF
        </a>
        <a href="../export-excel.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&halaqoh_id=<?php echo $halaqoh_id; ?>&wali_santri_id=<?php echo $wali_santri_id; ?>&kelas=<?php echo urlencode($kelas_filter); ?>&search=<?php echo urlencode($search); ?>"
            class="bg-emerald-600 text-white px-4 py-2 rounded-xl font-bold flex items-center shadow-lg shadow-emerald-100 hover:bg-emerald-700 transition text-xs">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                </path>
            </svg>
            Excel
        </a>
        <?php if (!empty($wali_santri_id)): ?>
            <a href="../print-rapor.php?wali_santri_id=<?php echo $wali_santri_id; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>"
                target="_blank"
                class="bg-blue-600 text-white px-4 py-2 rounded-xl font-bold flex items-center shadow-lg shadow-blue-100 hover:bg-blue-700 transition text-xs">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253">
                    </path>
                </svg>
                Cetak Rapor
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="space-y-6">
    <!-- Filters Card -->
    <div class="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4 items-end">
            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">Dari</label>
                <input type="date" name="start_date" value="<?php echo $start_date; ?>"
                    class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition text-sm">
            </div>
            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">Sampai</label>
                <input type="date" name="end_date" value="<?php echo $end_date; ?>"
                    class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition text-sm">
            </div>
            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">Halaqoh</label>
                <select name="halaqoh_id"
                    class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition text-sm">
                    <option value="">Semua</option>
                    <?php foreach ($halaqohs as $h): ?>
                        <option value="<?php echo $h['id']; ?>" <?php echo $halaqoh_id == $h['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($h['nama_halaqoh']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">Kelas Anak</label>
                <select name="kelas"
                    class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition text-sm">
                    <option value="">Semua</option>
                    <?php foreach ($daftar_kelas as $k): ?>
                        <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $kelas_filter === $k ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($k); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">Cari Nama</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                    placeholder="Bapak/Anak..."
                    class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition text-sm font-bold placeholder:font-normal">
            </div>
            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">Pilih Manual</label>
                <select name="wali_santri_id"
                    class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition text-sm">
                    <option value="">Semua</option>
                    <?php foreach ($all_wali as $w): ?>
                        <option value="<?php echo $w['id']; ?>" <?php echo $wali_santri_id == $w['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($w['nama_bapak']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit"
                    class="w-full bg-blue-600 text-white font-bold py-2.5 rounded-xl hover:bg-blue-700 transition shadow-lg shadow-blue-100 uppercase text-xs">
                    Cari </button>
            </div>
        </form>
    </div>

    <!-- Error/Notice -->
    <?php if ($error): ?>
        <div class="bg-red-50 text-red-600 p-4 rounded-xl border border-red-100 font-medium text-sm"> <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Table Card -->
    <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead
                    class="bg-slate-50 border-b border-slate-100 text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                    <tr>
                        <th class="px-6 py-4">Tanggal</th>
                        <th class="px-6 py-4">Halaqoh</th>
                        <th class="px-6 py-4">Wali Santri</th>
                        <th class="px-6 py-4 text-center">Status</th>
                        <th class="px-6 py-4">Materi</th>
                        <th class="px-6 py-4 text-center">Hasil</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50 text-sm">
                    <?php if (empty($results)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-slate-400 italic">Data tidak ditemukan.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($results as $row): ?>
                        <tr class="hover:bg-slate-50 transition">
                            <td class="px-6 py-4 text-slate-500 text-nowrap">
                                <?php echo date('d/m/y', strtotime($row['tanggal'])); ?>
                            </td>
                            <td class="px-6 py-4 font-semibold text-slate-700 text-xs text-nowrap">
                                <?php echo htmlspecialchars($row['nama_halaqoh']); ?>
                            </td>
                            <td class="px-6 py-4 align-middle">
                                <div class="flex items-center gap-2">
                                    <span><?php echo htmlspecialchars($row['nama_bapak']); ?></span>
                                    <?php if (!empty($row['no_hp'])):
                                        $wa_no = preg_replace('/[^0-9]/', '', $row['no_hp']);
                                        if (str_starts_with($wa_no, '0')) {
                                            $wa_no = '62' . substr($wa_no, 1);
                                        }
                                        $share_msg = urlencode("Assalamualaikum Pak " . $row['nama_bapak'] . ",\n\nSyukran Jazakumullah Khairan.");
                                        ?>
                                        <a href="https://wa.me/<?php echo $wa_no; ?>?text=<?php echo $share_msg; ?>"
                                            target="_blank"
                                            class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-50 text-emerald-600 hover:bg-emerald-600 hover:text-white transition-all shadow-sm border border-emerald-100"
                                            title="Kirim Pesan via WA">
                                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 448 512">
                                                <path
                                                    d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.7 17.8 69.4 27.2 106.2 27.2 122.4 0 222-99.6 222-222 0-59.3-23-115.1-65-157.1zM223.9 446.3c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3 18.7-68.1-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-5.5-2.8-23.4-8.6-44.6-27.5-16.4-14.7-27.5-32.8-30.7-38.4-3.2-5.6-.3-8.6 2.5-11.4 2.6-2.5 5.5-6.5 8.3-9.8 2.8-3.3 3.7-5.6 5.6-9.3 1.9-3.7.9-6.9-.5-9.8-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 13.2 5.7 23.5 9.2 31.5 11.8 13.3 4.2 25.4 3.6 35 2.2 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z" />
                                            </svg>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span
                                    class="inline-block px-2 py-1 rounded-lg text-[10px] font-bold <?php echo match ($row['status']) { 'H' => 'bg-emerald-100 text-emerald-700', 'S' => 'bg-blue-100 text-blue-700', 'I' => 'bg-amber-100 text-amber-700', 'A' => 'bg-red-100 text-red-700'}; ?>"><?php echo $row['status']; ?></span>
                            </td>
                            <td class="px-6 py-4 text-slate-600">
                                <?php if ($row['status'] === 'H'): ?>
                                    <span class="font-bold text-blue-600 text-xs"><?php echo $row['jenis_materi']; ?></span>
                                    <span
                                        class="text-xs"><?php echo $row['jenis_materi'] === 'Iqro' ? 'Jilid ' . $row['jilid'] : htmlspecialchars($row['nama_surat']); ?></span>
                                    <span
                                        class="text-[9px] bg-slate-100 px-1 rounded italic text-nowrap"><?php echo ($row['jenis_materi'] === 'Al Quran' ? 'Ayat ' : 'Hal ') . $row['halaman']; ?></span>
                                <?php else: ?>
                                    <span
                                        class="text-xs italic text-slate-400"><?php echo htmlspecialchars($row['alasan'] ?: '-'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <?php if ($row['hasil_talaqqi']): ?>
                                    <span
                                        class="px-2 py-1 rounded-lg text-[10px] font-black uppercase <?php echo $row['hasil_talaqqi'] === 'Lulus' ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600'; ?>"><?php echo $row['hasil_talaqqi']; ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
