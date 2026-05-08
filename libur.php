<?php
$pageTitle = 'Manajemen Libur Kajian';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

checkRole(['admin', 'pj_tahfidz']);

$message = '';
$error = '';

// Ensure table exists (Lazy Migration)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS holidays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tanggal DATE NOT NULL,
        keterangan VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_tanggal (tanggal)
    ) ENGINE=InnoDB");
} catch (Exception $e) {
    $error = "Gagal inisialisasi tabel: " . $e->getMessage();
}

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $tanggal = $_POST['tanggal'] ?? '';
        $keterangan = $_POST['keterangan'] ?? '';
        $clean_alfa = isset($_POST['clean_alfa']) && $_POST['clean_alfa'] == '1';

        if ($tanggal) {
            try {
                $stmt = $pdo->prepare("INSERT INTO holidays (tanggal, keterangan) VALUES (?, ?) ON DUPLICATE KEY UPDATE keterangan = ?");
                $stmt->execute([$tanggal, $keterangan, $keterangan]);
                
                $message = "Hari libur pada tanggal " . date('d/m/Y', strtotime($tanggal)) . " berhasil disimpan.";

                if ($clean_alfa) {
                    $stmtClean = $pdo->prepare("DELETE FROM presensi WHERE tanggal = ? AND status = 'A'");
                    $stmtClean->execute([$tanggal]);
                    $count = $stmtClean->rowCount();
                    if ($count > 0) {
                        $message .= " Dan berhasil menghapus $count data Alfa yang terlanjur terinput.";
                    }
                }
                
                addLog($pdo, 'ADD_HOLIDAY', "Menambah libur kajian tanggal $tanggal. Clean Alfa: " . ($clean_alfa ? 'Ya' : 'Tidak'));
            } catch (Exception $e) {
                $error = "Gagal menyimpan: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? null;
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM holidays WHERE id = ?");
            $stmt->execute([$id]);
            $message = "Hari libur berhasil dihapus.";
        }
    } elseif ($action === 'clean_bulk') {
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        
        if ($start_date && $end_date) {
            try {
                // Delete Alpha records for existing holiday dates in range
                $stmt = $pdo->prepare("DELETE FROM presensi 
                                     WHERE status = 'A' 
                                     AND tanggal BETWEEN ? AND ? 
                                     AND tanggal IN (SELECT tanggal FROM holidays)");
                $stmt->execute([$start_date, $end_date]);
                $count = $stmt->rowCount();
                $message = "Berhasil membersihkan $count data Alfa pada hari-hari libur di rentang tersebut.";
                addLog($pdo, 'CLEAN_ALFA_HOLIDAY', "Membersihkan $count data Alfa dari $start_date sampai $end_date");
            } catch (Exception $e) {
                $error = "Gagal membersihkan: " . $e->getMessage();
            }
        }
    }
}

// Fetch holidays
$holidays = $pdo->query("SELECT * FROM holidays ORDER BY tanggal DESC")->fetchAll();
?>

<div x-data="{ showAddModal: false, showCleanModal: false }">
    <div class="flex justify-between items-center mb-8">
        <div>
            <h2 class="text-3xl font-black text-slate-800">Libur Kajian</h2>
            <p class="text-slate-500">Kelola tanggal libur untuk mencegah input Alfa otomatis.</p>
        </div>
        <div class="flex gap-3">
            <button @click="showCleanModal = true"
                class="bg-amber-100 text-amber-700 px-5 py-2.5 rounded-2xl font-bold flex items-center hover:bg-amber-200 transition shadow-sm text-sm">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                </svg>
                Bersihkan Alfa
            </button>
            <button @click="showAddModal = true"
                class="bg-blue-600 text-white px-5 py-2.5 rounded-2xl font-bold flex items-center hover:bg-blue-700 transition shadow-lg shadow-blue-200 text-sm">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Tambah Libur
            </button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="bg-emerald-50 text-emerald-600 p-4 rounded-2xl mb-6 border border-emerald-100 italic font-medium flex items-center">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="bg-red-50 text-red-600 p-4 rounded-2xl mb-6 border border-red-100 font-medium">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Summary Info -->
    <div class="bg-blue-600 rounded-[2rem] p-8 mb-8 text-white relative overflow-hidden shadow-xl shadow-blue-100">
        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div>
                <h3 class="text-xl font-bold mb-2">Cara Kerja Fitur Libur</h3>
                <ul class="text-blue-100 text-sm space-y-2 list-disc list-inside">
                    <li>Tanggal yang terdaftar di sini akan dianggap <strong>Libur Nasional/Kajian</strong>.</li>
                    <li>Pada tanggal tersebut, Ustadz tidak akan bisa menginput presensi (Alfa otomatis dicegah).</li>
                    <li>Sistem akan mengabaikan perhitungan statistik kehadiran pada tanggal tersebut.</li>
                </ul>
            </div>
            <div class="bg-white/10 backdrop-blur-md rounded-3xl p-6 border border-white/20">
                <p class="text-[10px] font-bold text-blue-200 uppercase tracking-widest mb-1">Total Hari Libur</p>
                <p class="text-4xl font-black"><?php echo count($holidays); ?></p>
            </div>
        </div>
        <svg class="absolute -right-10 -bottom-10 w-64 h-64 text-white/5" fill="currentColor" viewBox="0 0 24 24"><path d="M19 19H5V8h14v11zM7 10v7h10v-7H7zm15-3h-3V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2H7V5a2 2 0 0 0-2-2H1a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h20a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zM9 5h6v2H9V5z"/></svg>
    </div>

    <!-- Holidays List -->
    <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
        <table class="w-full text-left">
            <thead class="bg-slate-50 border-b border-slate-100 text-[10px] font-black text-slate-400 uppercase tracking-widest">
                <tr>
                    <th class="px-8 py-5">Tanggal</th>
                    <th class="px-8 py-5">Hari</th>
                    <th class="px-8 py-5">Keterangan</th>
                    <th class="px-8 py-5 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php if (empty($holidays)): ?>
                    <tr><td colspan="4" class="px-8 py-12 text-center text-slate-400 italic">Belum ada hari libur yang terdaftar.</td></tr>
                <?php endif; ?>
                <?php foreach ($holidays as $h): 
                    $dateObj = new DateTime($h['tanggal']);
                    $daysIndo = ['Sunday' => 'Ahad', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'];
                    $hari = $daysIndo[$dateObj->format('l')];
                ?>
                    <tr class="hover:bg-slate-50/50 transition group">
                        <td class="px-8 py-5 font-bold text-slate-700"><?php echo date('d M Y', strtotime($h['tanggal'])); ?></td>
                        <td class="px-8 py-5"><span class="bg-slate-100 text-slate-600 px-3 py-1 rounded-lg text-xs font-bold uppercase"><?php echo $hari; ?></span></td>
                        <td class="px-8 py-5 text-slate-500 text-sm"><?php echo htmlspecialchars($h['keterangan'] ?: '-'); ?></td>
                        <td class="px-8 py-5 text-right">
                            <form method="POST" onsubmit="return confirm('Hapus libur ini?')" class="inline">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $h['id']; ?>">
                                <button type="submit" class="text-red-400 hover:text-red-600 p-2 rounded-xl hover:bg-red-50 transition opacity-0 group-hover:opacity-100">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Modal Add -->
    <div x-show="showAddModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" x-cloak>
        <div class="bg-white rounded-[2.5rem] w-full max-w-md p-10 shadow-2xl" @click.away="showAddModal = false">
            <h3 class="text-2xl font-black text-slate-800 mb-6">Tambah Hari Libur</h3>
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="add">
                <div class="space-y-6">
                    <div>
                        <label class="block text-xs font-bold text-slate-400 uppercase mb-2">Tanggal Libur</label>
                        <input type="date" name="tanggal" required
                            class="w-full px-5 py-3 rounded-2xl border border-slate-200 focus:ring-4 focus:ring-blue-100 outline-none transition font-semibold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-400 uppercase mb-2">Keterangan (Opsional)</label>
                        <input type="text" name="keterangan" placeholder="Contoh: Libur Idul Fitri"
                            class="w-full px-5 py-3 rounded-2xl border border-slate-200 focus:ring-4 focus:ring-blue-100 outline-none transition text-sm">
                    </div>
                    <div class="bg-amber-50 p-4 rounded-2xl border border-amber-100">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" name="clean_alfa" value="1" checked class="w-5 h-5 rounded-lg text-amber-600 border-amber-300 focus:ring-amber-500 mr-3">
                            <span class="text-xs font-bold text-amber-700">Hapus otomatis data Alfa yang sudah ada di tanggal ini</span>
                        </label>
                    </div>
                </div>
                <div class="mt-10 flex gap-3">
                    <button type="button" @click="showAddModal = false"
                        class="flex-1 px-6 py-4 rounded-2xl border border-slate-200 font-bold text-slate-400 hover:bg-slate-50 transition uppercase text-[10px] tracking-widest">Batal</button>
                    <button type="submit"
                        class="flex-1 px-6 py-4 rounded-2xl bg-blue-600 text-white font-black hover:bg-blue-700 transition shadow-lg shadow-blue-200 uppercase text-[10px] tracking-widest">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Clean Bulk -->
    <div x-show="showCleanModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" x-cloak>
        <div class="bg-white rounded-[2.5rem] w-full max-w-md p-10 shadow-2xl" @click.away="showCleanModal = false">
            <div class="w-20 h-20 bg-amber-50 rounded-full flex items-center justify-center mx-auto mb-6">
                <svg class="w-10 h-10 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
            </div>
            <h3 class="text-2xl font-black text-center text-slate-800 mb-2">Bersihkan Data Alfa</h3>
            <p class="text-center text-slate-500 text-sm mb-8 leading-relaxed">Gunakan fitur ini untuk menghapus data Alfa yang tidak sengaja terinput pada hari-hari yang sudah ditandai sebagai libur.</p>
            
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="clean_bulk">
                <div class="grid grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-xs font-bold text-slate-400 uppercase mb-2">Dari</label>
                        <input type="date" name="start_date" required
                            class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none text-sm font-semibold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-400 uppercase mb-2">Sampai</label>
                        <input type="date" name="end_date" required
                            class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none text-sm font-semibold">
                    </div>
                </div>
                <div class="flex gap-3">
                    <button type="button" @click="showCleanModal = false"
                        class="flex-1 px-6 py-4 rounded-2xl border border-slate-200 font-bold text-slate-400 hover:bg-slate-50 transition uppercase text-[10px] tracking-widest">Tutup</button>
                    <button type="submit"
                        class="flex-1 px-6 py-4 rounded-2xl bg-amber-500 text-white font-black hover:bg-amber-600 transition shadow-lg shadow-amber-200 uppercase text-[10px] tracking-widest">Eksekusi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
