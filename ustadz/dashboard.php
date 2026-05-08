<?php
$pageTitle = 'Dashboard Ustadz';
require_once '../includes/header.php';
require_once '../includes/sidebar.php';
require_once '../config/database.php';
require_once '../includes/auth_helper.php';

checkRole(['ustadz']);
$ustadz_id = $_SESSION['user_id'];

// 1. Basic Stats
$stmtHCount = $pdo->prepare("SELECT COUNT(*) FROM halaqoh WHERE ustadz_id = ?");
$stmtHCount->execute([$ustadz_id]);
$myHalaqohCount = $stmtHCount->fetchColumn();

$stmtMCount = $pdo->prepare("SELECT COUNT(hm.id) FROM halaqoh_members hm 
                           JOIN halaqoh h ON hm.halaqoh_id = h.id 
                           WHERE h.ustadz_id = ?");
$stmtMCount->execute([$ustadz_id]);
$myMembersCount = $stmtMCount->fetchColumn();

// 2. My Halaqoh Detailed List
$stmtHList = $pdo->prepare("SELECT h.*, (SELECT COUNT(*) FROM halaqoh_members WHERE halaqoh_id = h.id) as total_member 
                           FROM halaqoh h WHERE h.ustadz_id = ? ORDER BY h.nama_halaqoh");
$stmtHList->execute([$ustadz_id]);
$myHalaqohList = $stmtHList->fetchAll();

// 3. Attendance Distribution (This Ustadz's groups, last 30 days)
$stmtAttendance = $pdo->prepare("SELECT p.status, COUNT(*) as total 
                                 FROM presensi p 
                                 JOIN halaqoh h ON p.halaqoh_id = h.id
                                 WHERE h.ustadz_id = ? AND p.tanggal >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                 GROUP BY p.status");
$stmtAttendance->execute([$ustadz_id]);
$attendanceStats = $stmtAttendance->fetchAll(PDO::FETCH_KEY_PAIR);
$totalPresensi = array_sum($attendanceStats);

// 4. Early Warning (Members in THIS Ustadz's halaqoh with Alpha >= 3)
$warningQuery = "SELECT w.nama_bapak, COUNT(p.id) as total_alpha, h.nama_halaqoh
                 FROM wali_santri w 
                 JOIN presensi p ON w.id = p.wali_santri_id 
                 JOIN halaqoh h ON p.halaqoh_id = h.id
                 WHERE h.ustadz_id = ? AND p.status = 'A' 
                 GROUP BY w.id, w.nama_bapak, h.nama_halaqoh
                 HAVING total_alpha >= 3
                 ORDER BY total_alpha DESC";
$stmtWarning = $pdo->prepare($warningQuery);
$stmtWarning->execute([$ustadz_id]);
$warnings = $stmtWarning->fetchAll();

// 5. Recent Inputs by this Ustadz
$recentQuery = "SELECT p.*, w.nama_bapak, h.nama_halaqoh 
                FROM presensi p 
                JOIN wali_santri w ON p.wali_santri_id = w.id 
                JOIN halaqoh h ON p.halaqoh_id = h.id 
                WHERE h.ustadz_id = ?
                ORDER BY p.id DESC LIMIT 5";
$stmtRecent = $pdo->prepare($recentQuery);
$stmtRecent->execute([$ustadz_id]);
$recentActivities = $stmtRecent->fetchAll();

// 6. Latest Announcement
$stmtAnnounce = $pdo->query("SELECT * FROM pengumuman WHERE is_aktif = 1 ORDER BY created_at DESC LIMIT 1");
$latestAnnounce = $stmtAnnounce->fetch();
?>

<!-- Announcement Banner (If any) -->
<?php if ($latestAnnounce): ?>
    <div class="mb-8 overflow-hidden bg-white border border-blue-100 rounded-[2.5rem] shadow-sm shadow-blue-50/50 flex flex-col md:flex-row items-center">
        <div class="bg-blue-600 px-6 py-4 flex items-center justify-center w-full md:w-auto h-full">
            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
        </div>
        <div class="px-8 py-4 flex-1">
            <div class="flex items-center gap-2 mb-1">
                <span class="text-[10px] font-black text-blue-600 uppercase tracking-[0.2em]">Pengumuman Baru</span>
                <span class="text-[10px] text-slate-400 font-bold">• <?php echo date('d M Y', strtotime($latestAnnounce['created_at'])); ?></span>
            </div>
            <h4 class="font-black text-slate-800 text-lg mb-1"><?php echo htmlspecialchars($latestAnnounce['judul']); ?></h4>
            <p class="text-sm text-slate-500 leading-relaxed"><?php echo nl2br(htmlspecialchars($latestAnnounce['isi'])); ?></p>
        </div>
    </div>
<?php endif; ?>

<!-- Welcome Banner -->
<div
    class="bg-gradient-to-br from-blue-600 to-indigo-700 p-8 rounded-[2.5rem] text-white shadow-xl shadow-blue-100 mb-8 relative overflow-hidden group">
    <div class="relative z-10 flex flex-col md:flex-row justify-between items-center gap-6">
        <div class="text-center md:text-left">
            <h3 class="text-2xl font-black mb-2 tracking-tight">Ahlan wa Sahlan, Ustadz
                <?php echo explode(' ', $_SESSION['nama_lengkap'])[0]; ?>! 👋</h3>
            <p class="text-blue-100 text-sm opacity-90 leading-relaxed max-w-lg">
                Semoga Allah memberkahi waktu pengajaran Anda. Pantau perkembangan halaqoh dan pastikan presensi terisi
                tepat waktu.
            </p>
        </div>
        <a href="input-presensi.php"
            class="bg-white text-blue-600 px-8 py-4 rounded-2xl font-black uppercase tracking-widest text-xs hover:bg-slate-50 transition-all shadow-lg active:scale-95 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                </path>
            </svg>
            Mulai Input Presensi
        </a>
    </div>
    <div class="absolute -right-10 -bottom-10 opacity-10 group-hover:scale-110 transition-transform duration-500">
        <svg class="w-64 h-64" fill="currentColor" viewBox="0 0 24 24">
            <path
                d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z">
            </path>
        </svg>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <!-- Left Column: Stats & Recent -->
    <div class="lg:col-span-2 space-y-8">

        <!-- Summary Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm flex items-center group">
                <div class="bg-blue-50 p-4 rounded-2xl text-blue-600 mr-4">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                        </path>
                    </svg>
                </div>
                <div>
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Halaqoh Saya</p>
                    <h3 class="text-3xl font-black text-slate-800 tracking-tight"><?php echo $myHalaqohCount; ?></h3>
                </div>
            </div>
            <div class="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm flex items-center">
                <div class="bg-emerald-50 p-4 rounded-2xl text-emerald-600 mr-4">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z">
                        </path>
                    </svg>
                </div>
                <div>
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Total Wali Santri</p>
                    <h3 class="text-3xl font-black text-slate-800 tracking-tight"><?php echo $myMembersCount; ?></h3>
                </div>
            </div>
        </div>

        <!-- Attendance Performance -->
        <div class="bg-white p-8 rounded-3xl border border-slate-100 shadow-sm">
            <h3 class="text-lg font-bold text-slate-800 mb-6 flex items-center">
                <svg class="w-5 h-5 text-indigo-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z">
                    </path>
                </svg>
                Performa Kehadiran Grup Saya (30 Hari Terakhir)
            </h3>

            <?php if ($totalPresensi > 0): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="space-y-4">
                        <?php
                        $statusConfig = [
                            'H' => ['label' => 'Hadir', 'color' => 'bg-emerald-500', 'bg' => 'bg-emerald-100'],
                            'S' => ['label' => 'Sakit', 'color' => 'bg-blue-500', 'bg' => 'bg-blue-100'],
                            'I' => ['label' => 'Izin', 'color' => 'bg-amber-500', 'bg' => 'bg-amber-100'],
                            'A' => ['label' => 'Alpha', 'color' => 'bg-red-500', 'bg' => 'bg-red-100'],
                        ];
                        foreach ($statusConfig as $code => $cfg):
                            $count = $attendanceStats[$code] ?? 0;
                            $percent = round(($count / $totalPresensi) * 100);
                            ?>
                            <div>
                                <div class="flex justify-between text-xs font-bold mb-1.5 uppercase tracking-wider">
                                    <span class="text-slate-500"><?php echo $cfg['label']; ?></span>
                                    <span class="text-slate-800"><?php echo $percent; ?>%</span>
                                </div>
                                <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                                    <div class="h-full <?php echo $cfg['color']; ?> rounded-full"
                                        style="width: <?php echo $percent; ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Quick Info -->
                    <div class="bg-slate-50 p-6 rounded-2xl flex flex-col justify-center text-center">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-2">Tingkat Kehadiran
                        </p>
                        <h4 class="text-5xl font-black text-blue-600">
                            <?php echo round(($attendanceStats['H'] ?? 0) / $totalPresensi * 100); ?>%</h4>
                        <p class="text-xs text-slate-500 font-medium mt-2">Dari total <?php echo $totalPresensi; ?> sesi
                            input</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="py-12 text-center text-slate-400 italic">
                    Belum ada data input presensi dalam 30 hari terakhir.
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Activities -->
        <div class="bg-white p-8 rounded-3xl border border-slate-100 shadow-sm">
            <h3 class="text-lg font-bold text-slate-800 mb-6 flex items-center">
                <svg class="w-5 h-5 text-purple-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Input Presensi Terakhir Anda
            </h3>
            <div class="space-y-4">
                <?php foreach ($recentActivities as $act): ?>
                    <div
                        class="flex items-center gap-4 p-4 rounded-2xl border border-slate-50 hover:bg-slate-50 transition group">
                        <div
                            class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center font-black text-sm group-hover:bg-blue-600 group-hover:text-white transition-all">
                            <?php echo $act['status']; ?>
                        </div>
                        <div class="flex-1">
                            <div class="flex justify-between items-center mb-0.5">
                                <h4 class="font-bold text-slate-700 text-sm">
                                    <?php echo htmlspecialchars($act['nama_bapak']); ?></h4>
                                <span
                                    class="text-[10px] text-slate-400 font-bold"><?php echo date('d/m/Y', strtotime($act['tanggal'])); ?></span>
                            </div>
                            <p class="text-xs text-slate-500"><?php echo htmlspecialchars($act['nama_halaqoh']); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <a href="riwayat.php"
                class="block text-center mt-6 py-3 bg-slate-50 rounded-2xl text-[10px] font-black text-slate-500 uppercase tracking-widest hover:bg-slate-100 transition">Lihat
                Riwayat Lengkap &rarr;</a>
        </div>
    </div>

    <!-- Right Column: Halaqoh & Warnings -->
    <div class="space-y-8">
        <!-- My Halaqoh List -->
        <div class="bg-white p-8 rounded-3xl border border-slate-100 shadow-sm">
            <h3 class="text-lg font-bold text-slate-800 mb-6">Daftar Halaqoh Saya</h3>
            <div class="space-y-3">
                <?php foreach ($myHalaqohList as $h): ?>
                    <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100 hover:border-blue-200 transition-all">
                        <h4 class="font-bold text-slate-800 mb-1"><?php echo htmlspecialchars($h['nama_halaqoh']); ?></h4>
                        <div class="flex justify-between items-center">
                            <span
                                class="text-[10px] font-bold text-slate-400 uppercase tracking-widest"><?php echo $h['total_member']; ?>
                                Wali Santri</span>
                            <a href="input-presensi.php?id=<?php echo $h['id']; ?>"
                                class="text-[10px] font-black text-blue-600 uppercase hover:underline">Isi Presensi</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Early Warnings -->
        <div class="bg-white p-8 rounded-3xl border border-red-100 shadow-sm shadow-red-50">
            <h3 class="text-lg font-bold text-red-800 mb-6 flex items-center">
                <svg class="w-5 h-5 text-red-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z">
                    </path>
                </svg>
                Peringatan Alpha (>= 3x)
            </h3>

            <?php if (empty($warnings)): ?>
                <div class="text-center py-6">
                    <p class="text-xs text-slate-400 italic">Alhamdulillah, semua peserta rajin hadir.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($warnings as $w): ?>
                        <div class="p-4 rounded-2xl bg-red-50 border border-red-100">
                            <div class="flex justify-between items-start mb-1">
                                <h4 class="font-bold text-slate-800 text-sm"><?php echo htmlspecialchars($w['nama_bapak']); ?>
                                </h4>
                                <span
                                    class="bg-red-500 text-white text-[10px] font-black px-2 py-0.5 rounded-lg"><?php echo $w['total_alpha']; ?>x</span>
                            </div>
                            <p class="text-[10px] text-red-700 font-bold uppercase tracking-widest">
                                <?php echo htmlspecialchars($w['nama_halaqoh']); ?></p>
                        </div>
                    <?php endforeach; ?>
                    <p class="text-[10px] text-slate-400 text-center font-medium mt-4 italic">*Mohon berikan bimbingan
                        khusus atau hubungi wali santri bersangkutan.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>