<?php
$pageTitle = 'Editor Template Rapor';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

checkRole(['admin']);

$message = '';
$error = '';

// Handle Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $new_template = $_POST['template_content'] ?? '';
        $pj_name = $_POST['pj_tahfidz_name'] ?? '';
        $pj_title = $_POST['pj_tahfidz_title'] ?? '';

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'rapor_template'");
            $stmt->execute([$new_template]);

            $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'pj_tahfidz_name'");
            $stmt->execute([$pj_name]);

            $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'pj_tahfidz_title'");
            $stmt->execute([$pj_title]);

            $pdo->commit();

            addLog($pdo, 'UPDATE_TEMPLATE', "Memperbarui desain rapor dan identitas pengesah.");
            $message = "Pengaturan rapor berhasil diperbarui!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Gagal menyimpan: " . $e->getMessage();
        }
    }
}

// Fetch Current Settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('rapor_template', 'pj_tahfidz_name', 'pj_tahfidz_title')");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$template = $settings['rapor_template'] ?? '';
$pj_tahfidz_name = $settings['pj_tahfidz_name'] ?? 'Admin Sekolah';
$pj_tahfidz_title = $settings['pj_tahfidz_title'] ?? 'PJ Tahfidz';

// Default if empty
if (!$template) {
    if (file_exists('templates/rapor_template.php')) {
        $template = file_get_contents('templates/rapor_template.php');
    } else {
        $template = "<!-- Masukkan Kode HTML Rapor Anda di sini -->";
    }
}
?>

<div class="mb-8">
    <h2 class="text-3xl font-black text-slate-800">Editor Template Rapor</h2>
    <p class="text-slate-500">Kustomisasi desain rapor Bapak langsung dari sini tanpa perlu buka cPanel.</p>
</div>

<?php if ($message): ?>
    <div class="bg-emerald-50 text-emerald-600 p-4 rounded-2xl mb-6 border border-emerald-100 italic font-medium">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-20">
    <!-- Editor Column -->
    <div class="lg:col-span-2">
        <div
            class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden flex flex-col h-[700px]">
            <div class="bg-slate-800 px-6 py-3 flex justify-between items-center">
                <div class="flex items-center gap-2">
                    <div class="flex gap-1.5 mr-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-red-400"></div>
                        <div class="w-2.5 h-2.5 rounded-full bg-amber-400"></div>
                        <div class="w-2.5 h-2.5 rounded-full bg-emerald-400"></div>
                    </div>
                    <span
                        class="text-slate-400 text-[10px] font-black uppercase tracking-widest">rapor_template.php</span>
                </div>
                <div class="text-slate-500 text-[10px] font-bold">HTML / CSS Editor</div>
            </div>

            <form action="" method="POST" class="flex-1 flex flex-col">
                <?php csrfField(); ?>
                
                <!-- Added Identity Settings -->
                <div class="p-6 bg-slate-50 border-b border-slate-100 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2 tracking-widest">Nama Pengesah (PJ Tahfidz)</label>
                        <input type="text" name="pj_tahfidz_name" value="<?php echo htmlspecialchars($pj_tahfidz_name); ?>" 
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition text-sm font-bold">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2 tracking-widest">Jabatan / Gelar</label>
                        <input type="text" name="pj_tahfidz_title" value="<?php echo htmlspecialchars($pj_tahfidz_title); ?>" 
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition text-sm font-bold">
                    </div>
                </div>

                <textarea name="template_content" style="font-family: 'Fira Code', 'Monaco', 'Consolas', monospace;"
                    class="flex-1 w-full p-8 border-none outline-none text-sm text-slate-700 leading-relaxed bg-slate-50/30 font-mono resize-none focus:ring-0"
                    placeholder="Tulis kode HTML/CSS rapor di sini..."><?php echo htmlspecialchars($template); ?></textarea>

                <div class="p-6 bg-white border-t border-slate-100 flex justify-between items-center">
                    <p class="text-[10px] text-slate-400 font-bold uppercase italic">*Hati-hati saat mengedit tag PHP (
                        <?php echo '<?php'; ?> ...?>)
                    </p>
                    <button type="submit"
                        class="bg-blue-600 text-white px-8 py-3 rounded-2xl font-black uppercase tracking-widest text-xs hover:bg-blue-700 shadow-lg shadow-blue-100 transition-all active:scale-95">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Help / Variables Column -->
    <div class="space-y-6">
        <div class="bg-blue-600 p-8 rounded-[2.5rem] text-white shadow-xl shadow-blue-100">
            <h3 class="font-black text-lg mb-4 flex items-center gap-2">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Panduan Cepat
            </h3>
            <p class="text-blue-100 text-sm leading-relaxed mb-6">Bapak bisa menggunakan <b>"Tag Data"</b> di bawah ini
                di dalam desain Bapak agar data peserta muncul otomatis.</p>

            <div class="space-y-3">
                <div class="bg-blue-700/50 p-3 rounded-xl border border-blue-400/30">
                    <code
                        class="text-[10px] text-yellow-300 font-black tracking-wider"><?php echo '<?php echo $peserta["nama_bapak"]; ?>'; ?></code>
                    <p class="text-[10px] opacity-70 mt-1">Menampilkan nama Wali Santri.</p>
                </div>
                <div class="bg-blue-700/50 p-3 rounded-xl border border-blue-400/30">
                    <code
                        class="text-[10px] text-yellow-300 font-black tracking-wider"><?php echo '<?php echo $stats["H"]; ?>'; ?></code>
                    <p class="text-[10px] opacity-70 mt-1">Menampilkan total kehadiran.</p>
                </div>
                <div class="bg-blue-700/50 p-3 rounded-xl border border-blue-400/30">
                    <code
                        class="text-[10px] text-yellow-300 font-black tracking-wider"><?php echo '<?php echo $peserta["nama_halaqoh"]; ?>'; ?></code>
                    <p class="text-[10px] opacity-70 mt-1">Menampilkan nama kelompok.</p>
                </div>
            </div>

            <a href="print-rapor.php?wali_santri_id=1" target="_blank"
                class="mt-8 block text-center py-3 bg-white/10 hover:bg-white/20 border border-white/20 rounded-xl text-xs font-black uppercase tracking-widest transition">
                Lihat Preview Rapor
            </a>
        </div>

        <div class="bg-white p-8 rounded-[2.5rem] border border-slate-100">
            <h4 class="font-black text-slate-800 mb-4">Tips Desain</h4>
            <ul class="text-sm text-slate-500 space-y-4">
                <li class="flex gap-3">
                    <span class="text-blue-500">1.</span>
                    <span>Gunakan satuan <b>mm</b> (milimeter) agar ukuran pas saat di-print ke kertas A4.</span>
                </li>
                <li class="flex gap-3">
                    <span class="text-blue-500">2.</span>
                    <span>Bapak bisa menyisipkan logo eksternal menggunakan tag
                        <code>&lt;img src="..."&gt;</code>.</span>
                </li>
                <li class="flex gap-3">
                    <span class="text-blue-500">3.</span>
                    <span>Selalu gunakan <code>htmlspecialchars()</code> untuk keamanan data teks.</span>
                </li>
            </ul>
        </div>
    </div>
</div>

<style>
    /* Font untuk editor agar nyaman dilihat */
    textarea[name="template_content"] {
        font-family: "Fira Code", Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    }
</style>
<?php require_once 'includes/footer.php'; ?>
