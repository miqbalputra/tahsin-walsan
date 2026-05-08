<?php
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

checkRole(['admin', 'pj_tahfidz', 'kepsek', 'ustadz']);

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

$params = [
    ':start' => $start_date,
    ':end' => $end_date,
];
$where = [];
if ($role === 'ustadz') {
    $where[] = 'h.ustadz_id = :ustadz_id';
    $params[':ustadz_id'] = $user_id;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sqlHalaqoh = "
    SELECT
        h.id,
        h.nama_halaqoh,
        u.nama_lengkap AS nama_ustadz,
        COUNT(DISTINCT hm.wali_santri_id) AS total_anggota,
        COUNT(p.id) AS total_input,
        COALESCE(SUM(p.status = 'H'), 0) AS hadir,
        COALESCE(SUM(p.status = 'S'), 0) AS sakit,
        COALESCE(SUM(p.status = 'I'), 0) AS izin,
        COALESCE(SUM(p.status = 'A'), 0) AS alpha,
        COALESCE(SUM(p.hasil_talaqqi = 'Lulus'), 0) AS lulus,
        COALESCE(SUM(p.hasil_talaqqi = 'Ulang'), 0) AS ulang,
        MAX(p.tanggal) AS terakhir_input
    FROM halaqoh h
    JOIN users u ON u.id = h.ustadz_id
    LEFT JOIN halaqoh_members hm ON hm.halaqoh_id = h.id
    LEFT JOIN presensi p ON p.halaqoh_id = h.id
        AND p.wali_santri_id = hm.wali_santri_id
        AND p.tanggal BETWEEN :start AND :end
    {$whereSql}
    GROUP BY h.id, h.nama_halaqoh, u.nama_lengkap
    ORDER BY alpha DESC, total_input ASC, h.nama_halaqoh ASC
";

$stmt = $pdo->prepare($sqlHalaqoh);
$stmt->execute($params);
$halaqohRows = $stmt->fetchAll();

$sqlUstadz = "
    SELECT
        u.id,
        u.nama_lengkap AS nama_ustadz,
        COUNT(DISTINCT h.id) AS total_halaqoh,
        COUNT(DISTINCT hm.wali_santri_id) AS total_anggota,
        COUNT(p.id) AS total_input,
        COALESCE(SUM(p.status = 'H'), 0) AS hadir,
        COALESCE(SUM(p.status = 'A'), 0) AS alpha,
        COALESCE(SUM(p.hasil_talaqqi = 'Lulus'), 0) AS lulus,
        MAX(p.tanggal) AS terakhir_input
    FROM users u
    JOIN halaqoh h ON h.ustadz_id = u.id
    LEFT JOIN halaqoh_members hm ON hm.halaqoh_id = h.id
    LEFT JOIN presensi p ON p.halaqoh_id = h.id
        AND p.wali_santri_id = hm.wali_santri_id
        AND p.tanggal BETWEEN :start AND :end
    " . ($role === 'ustadz' ? 'WHERE u.id = :ustadz_id' : "WHERE u.role = 'ustadz'") . "
    GROUP BY u.id, u.nama_lengkap
    ORDER BY alpha DESC, total_input ASC, u.nama_lengkap ASC
";

$stmt = $pdo->prepare($sqlUstadz);
$stmt->execute($params);
$ustadzRows = $stmt->fetchAll();

$totalHalaqoh = count($halaqohRows);
$totalAnggota = array_sum(array_column($halaqohRows, 'total_anggota'));
$totalInput = array_sum(array_column($halaqohRows, 'total_input'));
$totalAlpha = array_sum(array_column($halaqohRows, 'alpha'));

$pageTitle = 'Rekap Halaqoh & Ustadz';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="mb-8 flex flex-col lg:flex-row lg:items-end justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold text-slate-800">Rekap Per Halaqoh dan Ustadz</h2>
        <p class="text-slate-500 text-sm">Pantau performa input presensi, kehadiran, dan capaian setiap kelompok.</p>
    </div>
    <form method="GET" class="bg-white rounded-2xl border border-slate-100 p-3 flex flex-col sm:flex-row gap-3 shadow-sm">
        <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="px-3 py-2 rounded-xl border border-slate-200 text-sm">
        <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="px-3 py-2 rounded-xl border border-slate-200 text-sm">
        <button class="px-4 py-2 rounded-xl bg-slate-800 text-white text-xs font-black uppercase tracking-widest">Terapkan</button>
    </form>
</div>

<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-sm">
        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Total Halaqoh</p>
        <p class="text-3xl font-black text-slate-800 mt-2"><?php echo $totalHalaqoh; ?></p>
    </div>
    <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-sm">
        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Anggota</p>
        <p class="text-3xl font-black text-blue-600 mt-2"><?php echo $totalAnggota; ?></p>
    </div>
    <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-sm">
        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Input Presensi</p>
        <p class="text-3xl font-black text-emerald-600 mt-2"><?php echo $totalInput; ?></p>
    </div>
    <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-sm">
        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Alpha</p>
        <p class="text-3xl font-black text-red-500 mt-2"><?php echo $totalAlpha; ?></p>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100">
            <h3 class="font-black text-slate-800">Rekap Halaqoh</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="bg-slate-50 text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                    <tr>
                        <th class="px-5 py-3">Halaqoh</th>
                        <th class="px-5 py-3 text-center">Anggota</th>
                        <th class="px-5 py-3 text-center">Hadir</th>
                        <th class="px-5 py-3 text-center">Alpha</th>
                        <th class="px-5 py-3 text-center">Capaian</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50 text-sm">
                    <?php foreach ($halaqohRows as $row): ?>
                        <tr>
                            <td class="px-5 py-4">
                                <p class="font-bold text-slate-800"><?php echo htmlspecialchars($row['nama_halaqoh']); ?></p>
                                <p class="text-xs text-slate-400">Ust. <?php echo htmlspecialchars($row['nama_ustadz']); ?></p>
                            </td>
                            <td class="px-5 py-4 text-center font-black"><?php echo (int) $row['total_anggota']; ?></td>
                            <td class="px-5 py-4 text-center text-emerald-600 font-black"><?php echo (int) $row['hadir']; ?></td>
                            <td class="px-5 py-4 text-center text-red-500 font-black"><?php echo (int) $row['alpha']; ?></td>
                            <td class="px-5 py-4 text-center">
                                <p class="text-xs text-emerald-600 font-bold">L <?php echo (int) $row['lulus']; ?></p>
                                <p class="text-xs text-red-500 font-bold">U <?php echo (int) $row['ulang']; ?></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100">
            <h3 class="font-black text-slate-800">Rekap Ustadz</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="bg-slate-50 text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                    <tr>
                        <th class="px-5 py-3">Ustadz</th>
                        <th class="px-5 py-3 text-center">Halaqoh</th>
                        <th class="px-5 py-3 text-center">Anggota</th>
                        <th class="px-5 py-3 text-center">Input</th>
                        <th class="px-5 py-3 text-center">Alpha</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50 text-sm">
                    <?php foreach ($ustadzRows as $row): ?>
                        <tr>
                            <td class="px-5 py-4">
                                <p class="font-bold text-slate-800"><?php echo htmlspecialchars($row['nama_ustadz']); ?></p>
                                <p class="text-xs text-slate-400">Terakhir: <?php echo $row['terakhir_input'] ? date('d/m/Y', strtotime($row['terakhir_input'])) : '-'; ?></p>
                            </td>
                            <td class="px-5 py-4 text-center font-black"><?php echo (int) $row['total_halaqoh']; ?></td>
                            <td class="px-5 py-4 text-center font-black"><?php echo (int) $row['total_anggota']; ?></td>
                            <td class="px-5 py-4 text-center text-emerald-600 font-black"><?php echo (int) $row['total_input']; ?></td>
                            <td class="px-5 py-4 text-center text-red-500 font-black"><?php echo (int) $row['alpha']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
