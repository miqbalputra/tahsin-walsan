<?php
$pageTitle = 'Manajemen Halaqoh';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

checkRole(['admin', 'pj_tahfidz']);

$message = '';
$error = '';

// Handle Create/Update/Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Validation
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $action = $_POST['action'] ?? '';
    $id = $_POST['id'] ?? null;
    $nama_halaqoh = $_POST['nama_halaqoh'] ?? '';
    $ustadz_id = $_POST['ustadz_id'] ?? '';

    if ($action === 'save') {
        if ($id) {
            $stmt = $pdo->prepare("UPDATE halaqoh SET nama_halaqoh=?, ustadz_id=? WHERE id=?");
            $stmt->execute([$nama_halaqoh, $ustadz_id, $id]);
            $message = "Halaqoh diperbarui!";
        } else {
            $stmt = $pdo->prepare("INSERT INTO halaqoh (nama_halaqoh, ustadz_id) VALUES (?, ?)");
            $stmt->execute([$nama_halaqoh, $ustadz_id]);
            $message = "Halaqoh baru dibuat!";
        }
    } elseif ($action === 'delete' && $id) {
        $pdo->beginTransaction();
        try {
            // Temporarily disable FK checks for safe deletion
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            // 1. Set halaqoh_id to NULL in presensi (preserve capaian data)
            $stmt = $pdo->prepare("UPDATE presensi SET halaqoh_id = NULL WHERE halaqoh_id = ?");
            $stmt->execute([$id]);

            // 2. Delete halaqoh members mapping
            $stmt = $pdo->prepare("DELETE FROM halaqoh_members WHERE halaqoh_id = ?");
            $stmt->execute([$id]);

            // 3. Delete the halaqoh itself
            $stmt = $pdo->prepare("DELETE FROM halaqoh WHERE id = ?");
            $stmt->execute([$id]);

            // Re-enable FK checks
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            $pdo->commit();
            addLog($pdo, 'DELETE_HALAQOH', "Menghapus halaqoh ID: $id (data capaian tetap tersimpan)");
            $message = "Halaqoh berhasil dihapus! Data capaian peserta tetap tersimpan.";
        } catch (Exception $e) {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            $pdo->rollBack();
            $error = "Gagal menghapus halaqoh: " . $e->getMessage();
        }
    }
}

// Fetch all Halaqoh with Ustadz names and member counts
$query = "SELECT h.*, u.nama_lengkap as nama_ustadz, 
          (SELECT COUNT(*) FROM halaqoh_members WHERE halaqoh_id = h.id) as total_member 
          FROM halaqoh h 
          JOIN users u ON h.ustadz_id = u.id 
          ORDER BY h.nama_halaqoh";
$halaqoh = $pdo->query($query)->fetchAll();

// Fetch all ustadz for select dropdown
$ustadz_list = $pdo->query("SELECT id, nama_lengkap FROM users WHERE role = 'ustadz'")->fetchAll();
?>

<div x-data="{ 
    showModal: false, 
    showDeleteConfirm: false,
    deleteId: null,
    deleteName: '',
    editMode: false,
    formData: { id: '', nama_halaqoh: '', ustadz_id: '' },
    openAdd() {
        this.editMode = false;
        this.formData = { id: '', nama_halaqoh: '', ustadz_id: '' };
        this.showModal = true;
    },
    openEdit(item) {
        this.editMode = true;
        this.formData = { ...item };
        this.showModal = true;
    },
    confirmDelete(id, name) {
        this.deleteId = id;
        this.deleteName = name;
        this.showDeleteConfirm = true;
    }
}">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-slate-800">Daftar Halaqoh</h2>
        <button @click="openAdd()"
            class="bg-blue-600 text-white px-4 py-2 rounded-xl flex items-center hover:bg-blue-700 transition shadow-sm font-semibold">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6">
                </path>
            </svg>
            Buat Halaqoh
        </button>
    </div>

    <?php if ($message): ?>
        <div class="bg-emerald-50 text-emerald-600 p-4 rounded-xl mb-6 border border-emerald-100 italic">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="bg-red-50 text-red-600 p-4 rounded-xl mb-6 border border-red-100 italic">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($halaqoh as $h): ?>
            <div class="bg-white rounded-3xl border border-slate-100 shadow-sm p-6 hover:shadow-md transition group">
                <div class="flex justify-between items-start mb-4">
                    <div class="bg-blue-50 text-blue-600 p-3 rounded-2xl">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z">
                            </path>
                        </svg>
                    </div>
                    <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition">
                        <button @click="openEdit(<?php echo htmlspecialchars(json_encode($h)); ?>)"
                            class="p-2 text-slate-400 hover:text-blue-500 hover:bg-blue-50 rounded-lg" title="Edit Halaqoh">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                </path>
                            </svg>
                        </button>
                        <button
                            @click="confirmDelete(<?php echo $h['id']; ?>, '<?php echo htmlspecialchars($h['nama_halaqoh'], ENT_QUOTES); ?>')"
                            class="p-2 text-slate-400 hover:text-red-500 hover:bg-red-50 rounded-lg" title="Hapus Halaqoh">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                                </path>
                            </svg>
                        </button>
                    </div>
                </div>
                <h3 class="text-xl font-bold text-slate-800 mb-1">
                    <?php echo htmlspecialchars($h['nama_halaqoh']); ?>
                </h3>
                <p class="text-slate-500 text-sm mb-4">Ustadz: <span class="text-slate-700 font-semibold">
                        <?php echo htmlspecialchars($h['nama_ustadz']); ?>
                    </span></p>

                <div class="flex items-center justify-between mt-6 pt-4 border-t border-slate-50">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">
                        <?php echo $h['total_member']; ?> Wali Santri
                    </span>
                    <a href="halaqoh-members.php?id=<?php echo $h['id']; ?>"
                        class="text-sm text-blue-600 font-bold hover:underline">Kelola Anggota &rarr;</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Modal Form -->
    <div x-show="showModal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm" x-cloak>
        <div class="bg-white rounded-3xl w-full max-w-md p-8 shadow-2xl" @click.away="showModal = false">
            <h3 class="text-xl font-bold text-slate-800 mb-6" x-text="editMode ? 'Edit Halaqoh' : 'Buat Halaqoh Baru'">
            </h3>
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" x-model="formData.id">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Nama Halaqoh</label>
                        <input type="text" name="nama_halaqoh" x-model="formData.nama_halaqoh" required
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition"
                            placeholder="Contoh: Abu Bakar Ash-Shiddiq">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Pilih Ustadz</label>
                        <select name="ustadz_id" x-model="formData.ustadz_id" required
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition">
                            <option value="">-- Pilih Ustadz --</option>
                            <?php foreach ($ustadz_list as $u): ?>
                                <option value="<?php echo $u['id']; ?>">
                                    <?php echo htmlspecialchars($u['nama_lengkap']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mt-8 flex gap-3">
                    <button type="button" @click="showModal = false"
                        class="flex-1 px-4 py-2 rounded-xl border border-slate-200 font-semibold text-slate-600 hover:bg-slate-50 transition">Batal</button>
                    <button type="submit"
                        class="flex-1 px-4 py-2 rounded-xl bg-blue-600 text-white font-semibold hover:bg-blue-700 transition">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div x-show="showDeleteConfirm"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm" x-cloak>
        <div class="bg-white rounded-3xl w-full max-w-md p-8 shadow-2xl" @click.away="showDeleteConfirm = false">
            <div class="text-center">
                <div class="mx-auto flex items-center justify-center w-16 h-16 rounded-full bg-red-100 mb-4">
                    <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z">
                        </path>
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-slate-800 mb-2">Hapus Halaqoh?</h3>
                <p class="text-slate-500 text-sm mb-2">Anda akan menghapus halaqoh <strong x-text="deleteName"
                        class="text-slate-800"></strong>.</p>
                <div class="bg-amber-50 border border-amber-100 rounded-xl p-3 mb-6">
                    <p class="text-amber-700 text-xs font-semibold">⚠️ Data peserta dan capaian di dalam halaqoh ini
                        <u>tidak akan terhapus</u>. Hanya data halaqoh saja yang dihapus.
                    </p>
                </div>
            </div>
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" x-bind:value="deleteId">
                <div class="flex gap-3">
                    <button type="button" @click="showDeleteConfirm = false"
                        class="flex-1 px-4 py-2 rounded-xl border border-slate-200 font-semibold text-slate-600 hover:bg-slate-50 transition">Batal</button>
                    <button type="submit"
                        class="flex-1 px-4 py-2 rounded-xl bg-red-600 text-white font-semibold hover:bg-red-700 transition">Ya,
                        Hapus</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>