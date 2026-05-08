<?php
$pageTitle = 'Dashboard Admin';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

// Proteksi: Jika Ustadz tersasar ke sini, kembalikan ke dashboard ustadz
if ($_SESSION['role'] === 'ustadz') {
    header("Location: ustadz/dashboard.php");
    exit();
}

// 1. Simple Stats
$countUser = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$countWali = $pdo->query("SELECT COUNT(*) FROM wali_santri")->fetchColumn();
$countHalaqoh = $pdo->query("SELECT COUNT(*) FROM halaqoh")->fetchColumn();

// 2. Attendance Stats (Last 30 Days)
$days = 30;
$stmtAttendance = $pdo->prepare("SELECT status, COUNT(*) as total FROM presensi WHERE tanggal >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY status");
$stmtAttendance->execute([$days]);
$attendanceStats = $stmtAttendance->fetchAll(PDO::FETCH_KEY_PAIR);
$totalPresensi = array_sum($attendanceStats);

// 3. Category Breakdown
$categoryStats = $pdo->query("SELECT kategori, COUNT(*) as total FROM wali_santri GROUP BY kategori")->fetchAll(PDO::FETCH_KEY_PAIR);

// 4. Early Warning: Alpha >= 3
$warningQuery = "SELECT w.id, w.nama_bapak, COUNT(p.id) as total_alpha, h.nama_halaqoh FROM wali_santri w JOIN presensi p ON w.id = p.wali_santri_id JOIN halaqoh h ON h.id = p.halaqoh_id WHERE p.status = 'A' GROUP BY w.id, w.nama_bapak, h.nama_halaqoh HAVING total_alpha >= 3 ORDER BY total_alpha DESC LIMIT 5";
$warnings = $pdo->query($warningQuery)->fetchAll();

// 5. Recent Activity
$recentQuery = "SELECT p.*, w.nama_bapak, h.nama_halaqoh FROM presensi p JOIN wali_santri w ON p.wali_santri_id = w.id JOIN halaqoh h ON p.halaqoh_id = h.id ORDER BY p.id DESC LIMIT 5";
$recentActivities = $pdo->query($recentQuery)->fetchAll();

// 6. Chart Data
$stmtTrend = $pdo->query("SELECT tanggal, SUM(CASE WHEN status = 'H' THEN 1 ELSE 0 END) as hadir FROM presensi WHERE tanggal >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) GROUP BY tanggal ORDER BY tanggal ASC");
$trendData = $stmtTrend->fetchAll(PDO::FETCH_ASSOC);
$labels = [];
$hadirCount = [];
foreach ($trendData as $t) {
    $labels[] = date('d/m', strtotime($t['tanggal']));
    $hadirCount[] = $t['hadir'];
}
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- Header Content -->
<div class="mb-10">
    <h2 class="text-3xl font-black text-slate-800">Dashboard Admin</h2>
    <p class="text-slate-500">Ringkasan aktivitas dan statistik Tahsin Bapak.</p>
</div>

<!-- 1. Stats Row -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm flex items-center">
        <div class="bg-blue-50 p-4 rounded-2xl text-blue-600 mr-4"><svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg></div>
        <div><p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Total User</p><h3 class="text-2xl font-black text-slate-800"><?php echo $countUser; ?></h3></div>
    </div>
    <div class="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm flex items-center">
        <div class="bg-emerald-50 p-4 rounded-2xl text-emerald-600 mr-4"><svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg></div>
        <div><p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Wali Santri</p><h3 class="text-2xl font-black text-slate-800"><?php echo $countWali; ?></h3></div>
    </div>
    <div class="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm flex items-center">
        <div class="bg-purple-50 p-4 rounded-2xl text-purple-600 mr-4"><svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg></div>
        <div><p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Halaqoh</p><h3 class="text-2xl font-black text-slate-800"><?php echo $countHalaqoh; ?></h3></div>
    </div>
</div>

<!-- 2. Analytics Row -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
    <div class="lg:col-span-2 bg-white p-8 rounded-3xl border border-slate-100 shadow-sm">
        <div class="flex justify-between items-center mb-8">
            <h3 class="text-lg font-bold text-slate-800 flex items-center"><svg class="w-5 h-5 text-indigo-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path></svg> Tren Kehadiran</h3>
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest flex items-center"><span class="w-2 h-2 bg-blue-500 rounded-full mr-1.5"></span> Hadir</span>
        </div>
        <div class="h-[250px]"><canvas id="attendanceTrendChart"></canvas></div>
    </div>

    <div class="bg-white p-8 rounded-3xl border border-slate-100 shadow-sm">
        <h3 class="text-lg font-bold text-slate-800 mb-6 flex items-center"><svg class="w-5 h-5 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg> Rasio 30 Hari</h3>
        <?php if ($totalPresensi > 0): ?>
                <div class="space-y-4">
                    <?php foreach (['H' => 'Hadir', 'S' => 'Sakit', 'I' => 'Izin', 'A' => 'Alpha'] as $code => $label):
                        $color = match ($code) { 'H' => 'bg-emerald-500', 'S' => 'bg-blue-500', 'I' => 'bg-amber-500', 'A' => 'bg-red-500'};
                        $bg = match ($code) { 'H' => 'bg-emerald-50', 'S' => 'bg-blue-50', 'I' => 'bg-amber-50', 'A' => 'bg-red-50'};
                        $count = $attendanceStats[$code] ?? 0;
                        $percent = round(($count / $totalPresensi) * 100);
                        ?>
                        <div>
                            <div class="flex justify-between text-[10px] font-black uppercase mb-1">
                                <span class="text-slate-400"><?php echo $label; ?></span>
                                <span class="text-slate-700"><?php echo $percent; ?>%</span>
                            </div>
                            <div class="w-full h-1.5 <?php echo $bg; ?> rounded-full overflow-hidden"><div class="h-full <?php echo $color; ?> rounded-full" style="width: <?php echo $percent; ?>%"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
        <?php else: ?><p class="text-center text-slate-400 italic text-sm py-10">Belum ada data.</p><?php endif; ?>
    </div>
</div>

<!-- 3. Warning Row -->
<div class="bg-white p-8 rounded-3xl border border-slate-100 shadow-sm mb-8">
    <div class="flex justify-between items-center mb-6">
        <h3 class="text-lg font-bold text-slate-800 flex items-center"><svg class="w-5 h-5 text-red-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg> Peringatan Dini (Alpha >= 3x)</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="text-[10px] font-black text-slate-400 uppercase border-b border-slate-50">
                <tr><th class="py-3 px-2">Nama Wali</th><th class="py-3 px-2">Halaqoh</th><th class="py-3 px-2 text-center">Alpha</th><th class="py-3 px-2 text-right">Aksi</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php if (empty($warnings)): ?><tr><td colspan="4" class="py-8 text-center text-slate-400 italic">Data aman.</td></tr><?php endif; ?>
                <?php foreach ($warnings as $w): ?>
                    <tr>
                        <td class="py-4 px-2 font-bold text-slate-700"><?php echo htmlspecialchars($w['nama_bapak']); ?></td>
                        <td class="py-4 px-2 text-slate-500"><?php echo htmlspecialchars($w['nama_halaqoh']); ?></td>
                        <td class="py-4 px-2 text-center"><span class="bg-red-500 text-white w-6 h-6 inline-flex items-center justify-center rounded-full text-[10px] font-black"><?php echo $w['total_alpha']; ?></span></td>
                        <td class="py-4 px-2 text-right"><a href="laporan.php?wali_santri_id=<?php echo $w['id']; ?>" class="text-[10px] font-bold text-blue-600 uppercase border border-blue-100 px-3 py-1 rounded-lg">Detail</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 4. Lower Row -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-12">
    <div class="bg-white p-8 rounded-3xl border border-slate-100 shadow-sm">
        <h3 class="text-lg font-bold text-slate-800 mb-6 flex items-center"><svg class="w-5 h-5 text-indigo-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg> Aktivitas Terbaru</h3>
        <div class="space-y-4">
            <?php foreach ($recentActivities as $act): ?>
                <div class="flex items-center gap-4 p-3 rounded-2xl hover:bg-slate-50 transition">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center font-black text-[10px] <?php echo match ($act['status']) { 'H' => 'bg-emerald-100 text-emerald-600', 'S' => 'bg-blue-100 text-blue-600', 'I' => 'bg-amber-100 text-amber-600', 'A' => 'bg-red-100 text-red-600'}; ?>"><?php echo $act['status']; ?></div>
                    <div class="flex-1 text-sm"><p class="font-bold text-slate-700"><?php echo htmlspecialchars($act['nama_bapak']); ?></p><p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($act['nama_halaqoh']); ?></p></div>
                    <div class="text-[10px] text-slate-400 font-bold"><?php echo date('d/m', strtotime($act['tanggal'])); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="flex flex-col gap-6">
        <div class="bg-slate-900 p-8 rounded-3xl text-white relative overflow-hidden flex-1">
            <div class="relative z-10">
                <h3 class="text-2xl font-black mb-2">Ahlan wa Sahlan, <?php echo $_SESSION['nama_lengkap']; ?>!</h3>
                <p class="text-slate-400 text-sm mb-6 uppercase tracking-widest font-bold">Role: <?php echo $_SESSION['role']; ?></p>
                <div class="grid grid-cols-2 gap-3">
                    <a href="peserta.php" class="bg-white/5 border border-white/10 p-4 rounded-2xl text-center hover:bg-white/10 transition"><p class="text-[10px] text-slate-400 font-bold uppercase mb-1">Data</p><p class="font-bold text-sm">Peserta</p></a>
                    <a href="halaqoh.php" class="bg-white/5 border border-white/10 p-4 rounded-2xl text-center hover:bg-white/10 transition"><p class="text-[10px] text-slate-400 font-bold uppercase mb-1">Data</p><p class="font-bold text-sm">Halaqoh</p></a>
                </div>
            </div>
        </div>
        <div class="bg-blue-600 p-8 rounded-3xl text-white">
            <h4 class="text-lg font-bold mb-1">Laporan Global</h4>
            <p class="text-blue-100 text-xs mb-6">Siap untuk diekspor ke PDF/Excel.</p>
            <a href="laporan.php" class="inline-block bg-white text-blue-600 px-6 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-lg">Download Laporan</a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('attendanceTrendChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($labels); ?>,
            datasets: [{
                label: 'Hadir',
                data: <?php echo json_encode($hadirCount); ?>,
                borderColor: '#6366f1',
                backgroundColor: 'rgba(99, 102, 241, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointBackgroundColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: '#f8fafc' }, ticks: { font: { size: 9, weight: 'bold' } } },
                x: { grid: { display: false }, ticks: { font: { size: 9, weight: 'bold' } } }
            }
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>