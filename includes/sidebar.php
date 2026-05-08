<?php
$role = $_SESSION['role'] ?? '';
$menus = [];

if ($role === 'admin' || $role === 'pj_tahfidz') {
    $menus = [
        ['label' => 'Dashboard', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6', 'url' => 'dashboard.php'],
        ['label' => 'Data User', 'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z', 'url' => 'users.php'],
        ['label' => 'Data Peserta', 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z', 'url' => 'peserta.php'],
        ['label' => 'Data Halaqoh', 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4', 'url' => 'halaqoh.php'],
        ['label' => 'Progress Wali', 'icon' => 'M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z', 'url' => 'progress-wali.php'],
        ['label' => 'Rekap Halaqoh', 'icon' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'url' => 'rekap-halaqoh.php'],
        ['label' => 'Broadcast Info', 'icon' => 'M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z', 'url' => 'pengumuman.php'],
        ['label' => 'Log Aktivitas', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01', 'url' => 'logs.php'],
        ['label' => 'Editor Rapor', 'icon' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z', 'url' => 'pengaturan-rapor.php'],
        ['label' => 'Laporan', 'icon' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'url' => 'laporan.php'],
        ['label' => 'Laporan Bulanan', 'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', 'url' => 'laporan-bulanan.php'],
        ['label' => 'Libur Kajian', 'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', 'url' => 'libur.php'],
        ['label' => 'Daftar Capaian', 'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'url' => 'capaian.php'],
    ];
} elseif ($role === 'kepsek') {
    $menus = [
        ['label' => 'Dashboard', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6', 'url' => 'dashboard.php'],
        ['label' => 'Progress Wali', 'icon' => 'M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z', 'url' => 'progress-wali.php'],
        ['label' => 'Rekap Halaqoh', 'icon' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'url' => 'rekap-halaqoh.php'],
        ['label' => 'Laporan', 'icon' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'url' => 'laporan.php'],
        ['label' => 'Laporan Bulanan', 'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', 'url' => 'laporan-bulanan.php'],
    ];
} elseif ($role === 'ustadz') {
    $menus = [
        ['label' => 'Dashboard', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6', 'url' => 'dashboard.php'],
        ['label' => 'Input Presensi', 'icon' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z', 'url' => 'input-presensi.php'],
        ['label' => 'Riwayat Presensi', 'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'url' => 'riwayat.php'],
        ['label' => 'Laporan', 'icon' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'url' => 'laporan.php'],
        ['label' => 'Progress Wali', 'icon' => 'M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z', 'url' => '../progress-wali.php'],
        ['label' => 'Rekap Halaqoh', 'icon' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'url' => '../rekap-halaqoh.php'],
        ['label' => 'Laporan Bulanan', 'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', 'url' => '../laporan-bulanan.php'],
        ['label' => 'Daftar Capaian', 'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'url' => 'capaian.php'],
    ];
}

$current_page = basename($_SERVER['PHP_SELF']);
$is_ustadz_path = strpos($_SERVER['REQUEST_URI'], '/ustadz/') !== false;

// Correction for menu URLs based on role and current location
foreach ($menus as &$m) {
    if ($role === 'ustadz') {
        if (str_starts_with($m['url'], '../')) {
            $m['final_url'] = $is_ustadz_path ? $m['url'] : substr($m['url'], 3);
        } elseif ($is_ustadz_path) {
            $m['final_url'] = $m['url'];
        } else {
            $m['final_url'] = 'ustadz/' . $m['url'];
        }
    } else {
        if ($is_ustadz_path) {
            $m['final_url'] = '../' . $m['url'];
        } else {
            $m['final_url'] = $m['url'];
        }
    }
}
unset($m);

$base_url = $is_ustadz_path ? '../' : '';
?>

<!-- Mobile Sidebar Backdrop -->
<div x-show="sidebarOpen" class="fixed inset-0 z-20 transition-opacity bg-black opacity-50 lg:hidden"
    @click="sidebarOpen = false" x-cloak></div>

<!-- Sidebar -->
<div :class="sidebarOpen ? 'translate-x-0 ease-out' : '-translate-x-full ease-in'"
    class="fixed inset-y-0 left-0 z-30 w-64 overflow-y-auto transition duration-300 transform bg-white lg:translate-x-0 lg:static lg:inset-0 border-r border-slate-100">
    <div class="flex items-center justify-center mt-8">
        <div class="flex items-center">
            <div class="bg-blue-600 p-2 rounded-lg text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253">
                    </path>
                </svg>
            </div>
            <span class="ml-3 text-xl font-bold text-slate-800">Tahsin Apps</span>
        </div>
    </div>

    <nav class="mt-10 px-4">
        <?php foreach ($menus as $menu): ?>
            <a class="flex items-center px-4 py-3 mt-2 text-sm font-medium transition-all duration-200 rounded-xl <?php echo $current_page == $menu['url'] ? 'bg-blue-50 text-blue-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-700'; ?>"
                href="<?php echo $menu['final_url']; ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?php echo $menu['icon']; ?>">
                    </path>
                </svg>
                <span class="mx-4">
                    <?php echo $menu['label']; ?>
                </span>
            </a>
        <?php endforeach; ?>

        <div x-show="canInstall" x-cloak>
            <div class="border-t border-slate-50 my-6"></div>
            <button @click="installPWA()"
                class="flex items-center w-full px-4 py-3 mt-2 text-sm font-medium text-blue-600 transition-all duration-200 rounded-xl hover:bg-blue-50">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                </svg>
                <span class="mx-4 text-left">Install ke Perangkat</span>
            </button>
        </div>

        <div class="border-t border-slate-50 my-6"></div>

        <a class="flex items-center px-4 py-3 mt-2 text-sm font-medium text-red-500 transition-all duration-200 rounded-xl hover:bg-red-50"
            href="<?php echo $base_url; ?>logout.php">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1">
                </path>
            </svg>
            <span class="mx-4">Keluar</span>
        </a>

        <div class="mt-10 mb-6 px-4 text-center">
            <p class="text-[10px] font-bold text-slate-300 uppercase tracking-widest">&copy; 2026 Tahsin Apps</p>
            <p class="text-[9px] text-slate-300 font-medium mt-1">Developed by <button @click="aboutOpen = true"
                    class="font-bold hover:text-blue-400 transition">SistemFlow</button></p>
        </div>
    </nav>
</div>

<!-- About Modal -->
<div x-show="aboutOpen"
    class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm"
    x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95"
    x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" x-cloak>
    <div class="bg-white/90 backdrop-blur-xl rounded-[2.5rem] w-full max-w-sm p-8 shadow-2xl border border-white/50 text-center relative overflow-hidden"
        @click.away="aboutOpen = false">
        <!-- Decoration -->
        <div class="absolute -top-24 -right-24 w-48 h-48 bg-blue-100 rounded-full blur-3xl opacity-50"></div>
        <div class="absolute -bottom-24 -left-24 w-48 h-48 bg-indigo-100 rounded-full blur-3xl opacity-50"></div>

        <div class="relative">
            <div
                class="inline-flex p-4 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-3xl shadow-xl shadow-blue-500/30 mb-6">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253">
                    </path>
                </svg>
            </div>

            <h3 class="text-xl font-black text-slate-800 tracking-tight uppercase">Presensi Bapak <br><span
                    class="text-blue-600">Tahsin Wali Santri</span></h3>
            <p class="text-[11px] font-bold text-slate-400 mt-2 uppercase tracking-widest italic">v1.0 (Build 2026)</p>

            <div class="my-6 border-t border-slate-100/50"></div>

            <p class="text-sm text-slate-600 leading-relaxed px-4">
                Program Peningkatan Kualitas Bacaan Al-Qur'an untuk Bapak/Wali Santri Griya Qur'an.
            </p>

            <div class="mt-8 space-y-3">
                <a href="https://wa.me/6281390292177" target="_blank"
                    class="block w-full py-3 rounded-2xl bg-slate-50 text-slate-700 text-[10px] font-bold hover:bg-blue-50 hover:text-blue-600 transition border border-slate-100 uppercase tracking-wider">
                    Bantuan: 0813 9029 2177
                </a>
                <button @click="aboutOpen = false"
                    class="block w-full py-3 rounded-2xl bg-blue-600 text-white text-[10px] font-black shadow-lg shadow-blue-200 hover:bg-blue-700 transition uppercase tracking-widest">
                    Tutup
                </button>
            </div>

            <p class="mt-8 text-[10px] text-slate-400 font-medium">Developed by <a href="https://sistemflow.com"
                    target="_blank"
                    class="font-bold text-slate-500 hover:text-blue-600 underline transition">SistemFlow</a></p>
        </div>
    </div>
</div>

<!-- Main Content Area Wrapper -->
<div class="flex flex-col flex-1 overflow-hidden">
    <!-- Top Navbar -->
    <header class="flex items-center justify-between px-6 py-4 bg-white border-b border-slate-100">
        <div class="flex items-center">
            <button @click="sidebarOpen = true" class="text-slate-500 focus:outline-none lg:hidden">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16">
                    </path>
                </svg>
            </button>
            <h2 class="text-lg font-semibold text-slate-800 ml-4 lg:ml-0">
                <?php echo $pageTitle ?? 'Dashboard'; ?>
            </h2>
        </div>

        <div class="flex items-center">
            <div class="text-right mr-4 hidden sm:block">
                <p class="text-sm font-semibold text-slate-800">
                    <?php echo $_SESSION['nama_lengkap']; ?>
                </p>
                <p class="text-xs text-slate-500 capitalize">
                    <?php echo $_SESSION['role']; ?>
                </p>
            </div>
            <div class="bg-blue-100 p-2 rounded-full text-blue-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
            </div>
        </div>
    </header>

    <!-- Main Page Content -->
    <main class="flex-1 overflow-x-hidden overflow-y-auto bg-slate-50 p-6">
