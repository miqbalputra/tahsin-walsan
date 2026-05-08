<?php
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

checkRole(['admin', 'pj_tahfidz', 'kepsek', 'ustadz']);

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$month = $_GET['month'] ?? date('Y-m');
$start_date = date('Y-m-01', strtotime($month . '-01'));
$end_date = date('Y-m-t', strtotime($month . '-01'));
$bulanNama = [
    1 => 'Januari',
    2 => 'Februari',
    3 => 'Maret',
    4 => 'April',
    5 => 'Mei',
    6 => 'Juni',
    7 => 'Juli',
    8 => 'Agustus',
    9 => 'September',
    10 => 'Oktober',
    11 => 'November',
    12 => 'Desember',
];
$judulBulan = $bulanNama[(int) date('n', strtotime($start_date))] . ' ' . date('Y', strtotime($start_date));

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

$stmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT h.id) AS total_halaqoh,
        COUNT(DISTINCT hm.wali_santri_id) AS total_peserta,
        COUNT(p.id) AS total_presensi,
        COALESCE(SUM(p.status = 'H'), 0) AS hadir,
        COALESCE(SUM(p.status = 'S'), 0) AS sakit,
        COALESCE(SUM(p.status = 'I'), 0) AS izin,
        COALESCE(SUM(p.status = 'A'), 0) AS alpha,
        COALESCE(SUM(p.hasil_talaqqi = 'Lulus'), 0) AS lulus,
        COALESCE(SUM(p.hasil_talaqqi = 'Ulang'), 0) AS ulang
    FROM halaqoh h
    LEFT JOIN halaqoh_members hm ON hm.halaqoh_id = h.id
    LEFT JOIN presensi p ON p.halaqoh_id = h.id
        AND p.wali_santri_id = hm.wali_santri_id
        AND p.tanggal BETWEEN :start AND :end
    {$whereSql}
");
$stmt->execute($params);
$summary = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT
        h.nama_halaqoh,
        u.nama_lengkap AS nama_ustadz,
        COUNT(DISTINCT hm.wali_santri_id) AS total_anggota,
        COUNT(p.id) AS total_presensi,
        COALESCE(SUM(p.status = 'H'), 0) AS hadir,
        COALESCE(SUM(p.status = 'S'), 0) AS sakit,
        COALESCE(SUM(p.status = 'I'), 0) AS izin,
        COALESCE(SUM(p.status = 'A'), 0) AS alpha,
        COALESCE(SUM(p.hasil_talaqqi = 'Lulus'), 0) AS lulus,
        COALESCE(SUM(p.hasil_talaqqi = 'Ulang'), 0) AS ulang
    FROM halaqoh h
    JOIN users u ON u.id = h.ustadz_id
    LEFT JOIN halaqoh_members hm ON hm.halaqoh_id = h.id
    LEFT JOIN presensi p ON p.halaqoh_id = h.id
        AND p.wali_santri_id = hm.wali_santri_id
        AND p.tanggal BETWEEN :start AND :end
    {$whereSql}
    GROUP BY h.id, h.nama_halaqoh, u.nama_lengkap
    ORDER BY h.nama_halaqoh ASC
");
$stmt->execute($params);
$halaqohRows = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        w.nama_bapak,
        h.nama_halaqoh,
        u.nama_lengkap AS nama_ustadz,
        COUNT(p.id) AS total_presensi,
        COALESCE(SUM(p.status = 'H'), 0) AS hadir,
        COALESCE(SUM(p.status = 'A'), 0) AS alpha,
        COALESCE(SUM(p.hasil_talaqqi = 'Lulus'), 0) AS lulus,
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
    {$whereSql}
    GROUP BY w.id, w.nama_bapak, h.nama_halaqoh, u.nama_lengkap
    ORDER BY alpha DESC, hadir ASC, w.nama_bapak ASC
    LIMIT 25
");
$stmt->execute($params);
$pesertaRows = $stmt->fetchAll();

$hadirPercent = (int) $summary['total_presensi'] > 0 ? round(((int) $summary['hadir'] / (int) $summary['total_presensi']) * 100) : 0;

$pageTitle = 'Laporan Bulanan';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Bulanan Tahsin - <?php echo htmlspecialchars($judulBulan); ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        body {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            body {
                background: white !important;
            }

            .print-sheet {
                box-shadow: none !important;
                border: none !important;
                margin: 0 !important;
                width: 100% !important;
            }
        }
    </style>
</head>

<body class="bg-slate-100 text-slate-800">
    <div class="no-print sticky top-0 z-10 bg-white border-b border-slate-200 px-6 py-4 flex flex-col sm:flex-row gap-3 justify-between items-start sm:items-center">
        <div>
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Laporan Bulanan</p>
            <h1 class="text-xl font-black">Tahsin Wali Santri - <?php echo htmlspecialchars($judulBulan); ?></h1>
        </div>
        <form method="GET" class="flex gap-2">
            <input type="month" name="month" value="<?php echo htmlspecialchars($month); ?>" class="px-3 py-2 rounded-xl border border-slate-200 text-sm">
            <button class="px-4 py-2 rounded-xl bg-slate-800 text-white text-xs font-black uppercase tracking-widest">Lihat</button>
            <button type="button" onclick="window.print()" class="px-4 py-2 rounded-xl bg-blue-600 text-white text-xs font-black uppercase tracking-widest">Cetak</button>
            <a href="dashboard.php" class="px-4 py-2 rounded-xl bg-white border border-slate-200 text-slate-600 text-xs font-black uppercase tracking-widest">Kembali</a>
        </form>
    </div>

    <main class="max-w-6xl mx-auto my-8 bg-white rounded-3xl border border-slate-200 shadow-sm print-sheet overflow-hidden">
        <section class="px-8 py-8 border-b border-slate-100">
            <p class="text-xs font-black text-blue-600 uppercase tracking-[0.25em]">Griya Qur'an</p>
            <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mt-2">
                <div>
                    <h2 class="text-3xl font-black text-slate-900">Laporan Bulanan Tahsin Wali Santri</h2>
                    <p class="text-slate-500 mt-1">Periode <?php echo htmlspecialchars($judulBulan); ?>, dicetak <?php echo date('d/m/Y H:i'); ?> WIB.</p>
                </div>
                <div class="text-left md:text-right">
                    <p class="text-sm font-bold text-slate-700"><?php echo htmlspecialchars($_SESSION['nama_lengkap']); ?></p>
                    <p class="text-xs text-slate-400 capitalize"><?php echo htmlspecialchars($_SESSION['role']); ?></p>
                </div>
            </div>
        </section>

        <section class="p-8 grid grid-cols-2 md:grid-cols-5 gap-4 border-b border-slate-100">
            <div class="bg-slate-50 rounded-2xl p-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Halaqoh</p>
                <p class="text-2xl font-black mt-2"><?php echo (int) $summary['total_halaqoh']; ?></p>
            </div>
            <div class="bg-slate-50 rounded-2xl p-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Peserta</p>
                <p class="text-2xl font-black mt-2"><?php echo (int) $summary['total_peserta']; ?></p>
            </div>
            <div class="bg-emerald-50 rounded-2xl p-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-emerald-600">Hadir</p>
                <p class="text-2xl font-black mt-2 text-emerald-700"><?php echo (int) $summary['hadir']; ?></p>
            </div>
            <div class="bg-red-50 rounded-2xl p-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-red-500">Alpha</p>
                <p class="text-2xl font-black mt-2 text-red-600"><?php echo (int) $summary['alpha']; ?></p>
            </div>
            <div class="bg-blue-50 rounded-2xl p-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-blue-600">Rasio Hadir</p>
                <p class="text-2xl font-black mt-2 text-blue-700"><?php echo $hadirPercent; ?>%</p>
            </div>
        </section>

        <section class="p-8 border-b border-slate-100">
            <h3 class="font-black text-lg mb-4">Rekap Per Halaqoh</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-slate-50 text-[10px] font-bold text-slate-500 uppercase tracking-widest">
                        <tr>
                            <th class="p-3 border border-slate-100">Halaqoh</th>
                            <th class="p-3 border border-slate-100">Ustadz</th>
                            <th class="p-3 border border-slate-100 text-center">Anggota</th>
                            <th class="p-3 border border-slate-100 text-center">Hadir</th>
                            <th class="p-3 border border-slate-100 text-center">S/I/A</th>
                            <th class="p-3 border border-slate-100 text-center">Lulus/Ulang</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm">
                        <?php foreach ($halaqohRows as $row): ?>
                            <tr>
                                <td class="p-3 border border-slate-100 font-bold"><?php echo htmlspecialchars($row['nama_halaqoh']); ?></td>
                                <td class="p-3 border border-slate-100"><?php echo htmlspecialchars($row['nama_ustadz']); ?></td>
                                <td class="p-3 border border-slate-100 text-center"><?php echo (int) $row['total_anggota']; ?></td>
                                <td class="p-3 border border-slate-100 text-center text-emerald-700 font-bold"><?php echo (int) $row['hadir']; ?></td>
                                <td class="p-3 border border-slate-100 text-center"><?php echo (int) $row['sakit']; ?>/<?php echo (int) $row['izin']; ?>/<?php echo (int) $row['alpha']; ?></td>
                                <td class="p-3 border border-slate-100 text-center"><?php echo (int) $row['lulus']; ?>/<?php echo (int) $row['ulang']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="p-8">
            <h3 class="font-black text-lg mb-4">Peserta Prioritas Follow-up</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-slate-50 text-[10px] font-bold text-slate-500 uppercase tracking-widest">
                        <tr>
                            <th class="p-3 border border-slate-100">Wali Santri</th>
                            <th class="p-3 border border-slate-100">Halaqoh</th>
                            <th class="p-3 border border-slate-100 text-center">Hadir</th>
                            <th class="p-3 border border-slate-100 text-center">Alpha</th>
                            <th class="p-3 border border-slate-100">Materi Terakhir</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm">
                        <?php foreach ($pesertaRows as $row): ?>
                            <tr>
                                <td class="p-3 border border-slate-100 font-bold"><?php echo htmlspecialchars($row['nama_bapak']); ?></td>
                                <td class="p-3 border border-slate-100"><?php echo htmlspecialchars($row['nama_halaqoh']); ?></td>
                                <td class="p-3 border border-slate-100 text-center text-emerald-700 font-bold"><?php echo (int) $row['hadir']; ?></td>
                                <td class="p-3 border border-slate-100 text-center text-red-600 font-bold"><?php echo (int) $row['alpha']; ?></td>
                                <td class="p-3 border border-slate-100"><?php echo htmlspecialchars($row['materi_terakhir'] ?: '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>

</html>
