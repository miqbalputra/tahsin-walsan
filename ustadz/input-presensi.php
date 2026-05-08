<?php
$pageTitle = 'Pilih Halaqoh';
require_once '../includes/header.php';
require_once '../includes/sidebar.php';
require_once '../config/database.php';
require_once '../includes/auth_helper.php';

checkRole(['ustadz']);

$ustadz_id = $_SESSION['user_id'];

// Get all halaqoh assigned to this ustadz
$stmt = $pdo->prepare("SELECT h.*, 
                      (SELECT COUNT(*) FROM halaqoh_members WHERE halaqoh_id = h.id) as total_member 
                      FROM halaqoh h 
                      WHERE h.ustadz_id = ? 
                      ORDER BY h.nama_halaqoh");
$stmt->execute([$ustadz_id]);
$halaqohs = $stmt->fetchAll();
?>

<div class="mb-8">
    <h2 class="text-2xl font-bold text-slate-800">Pilih Kelompok Halaqoh</h2>
    <p class="text-slate-500">Silakan pilih halaqoh untuk mulai mengisi presensi.</p>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php if (empty($halaqohs)): ?>
        <div
            class="md:col-span-2 lg:col-span-3 bg-white p-8 rounded-3xl border border-slate-100 text-center italic text-slate-400 font-medium">
            Anda belum ditugaskan ke kelompok halaqoh manapun.
        </div>
    <?php endif; ?>

    <?php foreach ($halaqohs as $h): ?>
        <a href="form-presensi.php?id=<?php echo $h['id']; ?>"
            class="bg-white rounded-3xl border border-slate-100 shadow-sm p-6 hover:shadow-xl hover:-translate-y-1 transition-all duration-300 group">
            <div class="flex justify-between items-start mb-4">
                <div class="bg-blue-600 text-white p-4 rounded-2xl shadow-lg shadow-blue-100">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253">
                        </path>
                    </svg>
                </div>
                <div class="bg-slate-50 text-slate-400 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider">
                    <?php echo $h['total_member']; ?> Anggota
                </div>
            </div>
            <h3 class="text-xl font-bold text-slate-800 mb-1 group-hover:text-blue-600 transition-colors">
                <?php echo htmlspecialchars($h['nama_halaqoh']); ?>
            </h3>
            <p class="text-slate-500 text-sm mb-4">Mulai input presensi bapak-bapak hari ini.</p>
            <div class="mt-4 flex items-center text-blue-600 font-bold text-sm">
                Buka Form Presensi
                <svg class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor"
                    viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3">
                    </path>
                </svg>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<?php require_once '../includes/footer.php'; ?>