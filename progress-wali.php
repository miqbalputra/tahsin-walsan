<?php
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

checkRole(['admin', 'pj_tahfidz', 'kepsek', 'ustadz']);

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$search = trim($_GET['search'] ?? '');
$risk_filter = $_GET['risk'] ?? '';

$params = [
    ':start' => $start_date,
    ':end' => $end_date,
];

$where = ['w.status_aktif = 1'];
if ($role === 'ustadz') {
    $where[] = 'h.ustadz_id = :ustadz_id';
    $params[':ustadz_id'] = $user_id;
}

if ($search !== '') {
    $where[] = "(w.nama_bapak LIKE :search OR h.nama_halaqoh LIKE :search OR EXISTS (
        SELECT 1
        FROM santri_detail sd_search
        WHERE sd_search.wali_santri_id = w.id
        AND sd_search.nama_anak LIKE :search
    ))";
    $params[':search'] = '%' . $search . '%';
}

$whereSql = implode(' AND ', $where);

$sql = "
    SELECT
        w.id,
        w.nama_bapak,
        w.no_hp,
        w.kategori,
        h.nama_halaqoh,
        u.nama_lengkap AS nama_ustadz,
        (
            SELECT GROUP_CONCAT(CONCAT(sd.nama_anak, IF(sd.kelas IS NULL OR sd.kelas = '', '', CONCAT(' - ', sd.kelas))) ORDER BY sd.nama_anak SEPARATOR ', ')
            FROM santri_detail sd
            WHERE sd.wali_santri_id = w.id
        ) AS anak,
        COUNT(p.id) AS total_presensi,
        COALESCE(SUM(p.status = 'H'), 0) AS hadir,
        COALESCE(SUM(p.status = 'S'), 0) AS sakit,
        COALESCE(SUM(p.status = 'I'), 0) AS izin,
        COALESCE(SUM(p.status = 'A'), 0) AS alpha,
        COALESCE(SUM(p.hasil_talaqqi = 'Lulus'), 0) AS lulus,
        COALESCE(SUM(p.hasil_talaqqi = 'Ulang'), 0) AS ulang,
        MAX(p.tanggal) AS terakhir_hadir,
        (
            SELECT CONCAT_WS(' ',
                lp.jenis_materi,
                CASE
                    WHEN lp.jenis_materi = 'Iqro' THEN CONCAT('Jilid ', lp.jilid)
                    WHEN lp.jenis_materi = 'Al Quran' THEN lp.nama_surat
                    ELSE NULL
                END,
                CASE WHEN lp.halaman IS NULL OR lp.halaman = '' THEN NULL ELSE CONCAT('Hal/Ayat', lp.halaman) END
            )
            FROM presensi lp
            WHERE lp.wali_santri_id = w.id
            AND lp.status = 'H'
            ORDER BY lp.tanggal DESC, lp.id DESC
            LIMIT 1
        ) AS materi_terakhir
    FROM wali_santri w
    JOIN halaqoh_members hm ON hm.wali_santri_id = w.id
    JOIN halaqoh h ON h.id = hm.halaqoh_id
    JOIN users u ON u.id = h.ustadz_id
    LEFT JOIN presensi p ON p.wali_santri_id = w.id
        AND p.halaqoh_id = h.id
        AND p.tanggal BETWEEN :start AND :end
    WHERE {$whereSql}
    GROUP BY w.id, w.nama_bapak, w.no_hp, w.kategori, h.nama_halaqoh, u.nama_lengkap
    ORDER BY alpha DESC, terakhir_hadir ASC, w.nama_bapak ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$summary = [
    'peserta' => count($rows),
    'hadir' => array_sum(array_column($rows, 'hadir')),
    'alpha' => array_sum(array_column($rows, 'alpha')),
    'rawan' => 0,
];

foreach ($rows as &$row) {
    $total = (int) $row['total_presensi'];
    $row['hadir_percent'] = $total > 0 ? round(((int) $row['hadir'] / $total) * 100) : 0;
    $last = $row['terakhir_hadir'] ? strtotime($row['terakhir_hadir']) : null;
    $daysSince = $last ? floor((time() - $last) / 86400) : null;
    $row['risk_level'] = 'stabil';
    $row['risk_label'] = 'Stabil';

    if ((int) $row['alpha'] >= 3 || $daysSince === null || $daysSince >= 21) {
        $row['risk_level'] = 'rawan';
        $row['risk_label'] = 'Butuh Follow-up';
        $summary['rawan']++;
    } elseif ((int) $row['alpha'] >= 1 || $daysSince >= 14) {
        $row['risk_level'] = 'pantau';
        $row['risk_label'] = 'Pantau';
    }
}
unset($row);

if ($risk_filter !== '') {
    $rows = array_values(array_filter($rows, fn($row) => $row['risk_level'] === $risk_filter));
}

$pageTitle = 'Progress Wali Santri';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="mb-8 flex flex-col lg:flex-row lg:items-end justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold text-slate-800">Dashboard Progress Wali Santri</h2>
        <p class="text-slate-500 text-sm">Pantau kehadiran, capaian terakhir, dan peserta yang perlu follow-up.</p>
    </div>
    <a href="laporan-bulanan.php?month=<?php echo date('Y-m', strtotime($start_date)); ?>"
        class="inline-flex items-center justify-center px-4 py-2 rounded-xl bg-blue-600 text-white text-xs font-black uppercase tracking-widest shadow-lg shadow-blue-100 hover:bg-blue-700 transition">
        Laporan Bulanan
    </a>
</div>

<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-sm">
        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Peserta Aktif</p>
        <p class="text-3xl font-black text-slate-800 mt-2"><?php echo $summary['peserta']; ?></p>
    </div>
    <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-sm">
        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Total Hadir</p>
        <p class="text-3xl font-black text-emerald-600 mt-2"><?php echo $summary['hadir']; ?></p>
    </div>
    <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-sm">
        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Total Alpha</p>
        <p class="text-3xl font-black text-red-500 mt-2"><?php echo $summary['alpha']; ?></p>
    </div>
    <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-sm">
        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Butuh Follow-up</p>
        <p class="text-3xl font-black text-amber-600 mt-2"><?php echo $summary['rawan']; ?></p>
    </div>
</div>

<form method="GET" class="bg-white rounded-3xl border border-slate-100 p-5 shadow-sm mb-6 grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
    <div>
        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">Dari</label>
        <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="w-full px-4 py-2 rounded-xl border border-slate-200 text-sm">
    </div>
    <div>
        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">Sampai</label>
        <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="w-full px-4 py-2 rounded-xl border border-slate-200 text-sm">
    </div>
    <div>
        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">Cari</label>
        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Nama bapak/anak/halaqoh" class="w-full px-4 py-2 rounded-xl border border-slate-200 text-sm">
    </div>
    <div>
        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">Status Risiko</label>
        <select name="risk" class="w-full px-4 py-2 rounded-xl border border-slate-200 text-sm">
            <option value="">Semua</option>
            <option value="rawan" <?php echo $risk_filter === 'rawan' ? 'selected' : ''; ?>>Butuh Follow-up</option>
            <option value="pantau" <?php echo $risk_filter === 'pantau' ? 'selected' : ''; ?>>Pantau</option>
            <option value="stabil" <?php echo $risk_filter === 'stabil' ? 'selected' : ''; ?>>Stabil</option>
        </select>
    </div>
    <button class="bg-slate-800 text-white rounded-xl py-2.5 text-xs font-black uppercase tracking-widest">Terapkan</button>
</form>

<div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead class="bg-slate-50 border-b border-slate-100 text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                <tr>
                    <th class="px-6 py-4">Wali Santri</th>
                    <th class="px-6 py-4">Halaqoh</th>
                    <th class="px-6 py-4 text-center">Kehadiran</th>
                    <th class="px-6 py-4">Materi Terakhir</th>
                    <th class="px-6 py-4 text-center">Hasil</th>
                    <th class="px-6 py-4 text-center">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50 text-sm">
                <?php if (empty($rows)): ?>
                    <tr><td colspan="6" class="px-6 py-12 text-center text-slate-400 italic">Data tidak ditemukan.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr class="hover:bg-slate-50 transition">
                        <td class="px-6 py-4">
                            <p class="font-bold text-slate-800"><?php echo htmlspecialchars($row['nama_bapak']); ?></p>
                            <p class="text-xs text-slate-400"><?php echo htmlspecialchars($row['anak'] ?: '-'); ?></p>
                        </td>
                        <td class="px-6 py-4">
                            <p class="font-semibold text-slate-700"><?php echo htmlspecialchars($row['nama_halaqoh']); ?></p>
                            <p class="text-xs text-slate-400">Ust. <?php echo htmlspecialchars($row['nama_ustadz']); ?></p>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <p class="text-lg font-black text-slate-800"><?php echo $row['hadir_percent']; ?>%</p>
                            <p class="text-[10px] text-slate-400">H <?php echo (int) $row['hadir']; ?> / A <?php echo (int) $row['alpha']; ?></p>
                        </td>
                        <td class="px-6 py-4">
                            <p class="font-semibold text-blue-600"><?php echo htmlspecialchars($row['materi_terakhir'] ?: 'Belum ada materi'); ?></p>
                            <p class="text-xs text-slate-400">Terakhir: <?php echo $row['terakhir_hadir'] ? date('d/m/Y', strtotime($row['terakhir_hadir'])) : '-'; ?></p>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <p class="text-xs font-bold text-emerald-600">Lulus <?php echo (int) $row['lulus']; ?></p>
                            <p class="text-xs font-bold text-red-500">Ulang <?php echo (int) $row['ulang']; ?></p>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <?php
                            $riskClass = match ($row['risk_level']) {
                                'rawan' => 'bg-red-50 text-red-600 border-red-100',
                                'pantau' => 'bg-amber-50 text-amber-700 border-amber-100',
                                default => 'bg-emerald-50 text-emerald-600 border-emerald-100',
                            };
                            ?>
                            <span class="inline-flex px-3 py-1 rounded-full text-[10px] font-black uppercase border <?php echo $riskClass; ?>">
                                <?php echo $row['risk_label']; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
