<?php
$pageTitle = 'Manajemen User';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

checkRole(['admin', 'pj_tahfidz']);

$message = '';
$error = '';

// Handle Create/Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Validation
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $action = $_POST['action'] ?? '';
    $id = $_POST['id'] ?? null;
    $username = $_POST['username'] ?? '';
    $nama_lengkap = $_POST['nama_lengkap'] ?? '';
    $role = $_POST['role'] ?? '';
    $no_hp = $_POST['no_hp'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($action === 'save') {
        if ($id) {
            // Update
            if ($password) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username=?, password=?, nama_lengkap=?, role=?, no_hp=? WHERE id=?");
                $stmt->execute([$username, $hashed, $nama_lengkap, $role, $no_hp, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username=?, nama_lengkap=?, role=?, no_hp=? WHERE id=?");
                $stmt->execute([$username, $nama_lengkap, $role, $no_hp, $id]);
            }
            $message = "User berhasil diperbarui!";
        } else {
            // Create
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            try {
                $stmt = $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, role, no_hp) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$username, $hashed, $nama_lengkap, $role, $no_hp]);
                $message = "User berhasil ditambahkan!";
            } catch (PDOException $e) {
                $error = "Gagal menambah user: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete' && $id) {
        // Delete
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND id != ?");
        $stmt->execute([$id, $_SESSION['user_id']]); // Cannot delete yourself
        $message = "User berhasil dihapus!";
    }
}

// Fetch all users
$users = $pdo->query("SELECT * FROM users ORDER BY role, nama_lengkap")->fetchAll();
?>

<div x-data="{ 
    showModal: false, 
    editMode: false,
    userData: { id: '', username: '', nama_lengkap: '', role: 'ustadz', no_hp: '', password: '' },
    openAdd() {
        this.editMode = false;
        this.userData = { id: '', username: '', nama_lengkap: '', role: 'ustadz', no_hp: '', password: '' };
        this.showModal = true;
    },
    openEdit(user) {
        this.editMode = true;
        this.userData = { ...user, password: '' };
        this.showModal = true;
    }
}">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-slate-800">Daftar Pengguna</h2>
        <button @click="openAdd()"
            class="bg-blue-600 text-white px-4 py-2 rounded-xl flex items-center hover:bg-blue-700 transition shadow-sm font-semibold">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6">
                </path>
            </svg>
            Tambah User
        </button>
    </div>

    <?php if ($message): ?>
        <div class="bg-emerald-50 text-emerald-600 p-4 rounded-xl mb-6 flex items-center border border-emerald-100 italic">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <table class="w-full text-left">
            <thead
                class="bg-slate-50 border-b border-slate-100 text-slate-500 uppercase text-xs font-bold tracking-wider">
                <tr>
                    <th class="px-6 py-4">Nama Lengkap</th>
                    <th class="px-6 py-4">Username</th>
                    <th class="px-6 py-4">Role</th>
                    <th class="px-6 py-4 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($users as $user): ?>
                    <tr class="hover:bg-slate-50 transition font-medium">
                        <td class="px-6 py-4 text-slate-800 align-middle">
                            <?php echo htmlspecialchars($user['nama_lengkap']); ?>
                        </td>
                        <td class="px-6 py-4 text-slate-500 align-middle">
                            <?php echo htmlspecialchars($user['username']); ?>
                        </td>
                        <td class="px-6 py-4 align-middle">
                            <span class="px-3 py-1 rounded-lg text-[10px] font-bold tracking-wider
                                <?php
                                echo match ($user['role']) {
                                    'admin' => 'bg-red-100 text-red-700',
                                    'pj_tahfidz' => 'bg-purple-100 text-purple-700',
                                    'kepsek' => 'bg-amber-100 text-amber-700',
                                    'ustadz' => 'bg-blue-100 text-blue-700',
                                };
                                ?>">
                                <?php echo strtoupper($user['role']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right align-middle">
                            <div class="flex justify-end items-center gap-2">
                                <button @click="openEdit(<?php echo htmlspecialchars(json_encode($user)); ?>)"
                                    class="text-blue-600 hover:bg-blue-600 hover:text-white font-bold text-[10px] uppercase transition bg-blue-50 px-3 py-1.5 rounded-xl border border-blue-100">
                                    Edit
                                </button>
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <form method="POST" action="" class="inline-flex"
                                        onsubmit="return confirm('Hapus user ini?')">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                        <button type="submit"
                                            class="text-red-600 hover:bg-red-600 hover:text-white font-bold text-[10px] uppercase transition bg-red-50 px-3 py-1.5 rounded-xl border border-red-100">
                                            Hapus
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Modal Form -->
    <div x-show="showModal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm"
        x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100" x-cloak>
        <div class="bg-white rounded-3xl w-full max-w-md p-8 shadow-2xl" @click.away="showModal = false">
            <h3 class="text-xl font-bold text-slate-800 mb-6" x-text="editMode ? 'Edit User' : 'Tambah User Baru'"></h3>

            <form method="POST" action="">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" x-model="userData.id">

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Nama Lengkap</label>
                        <input type="text" name="nama_lengkap" x-model="userData.nama_lengkap" required
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Username</label>
                        <input type="text" name="username" x-model="userData.username" required
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Role</label>
                        <select name="role" x-model="userData.role"
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition">
                            <option value="admin">Admin</option>
                            <option value="pj_tahfidz">PJ Tahfidz</option>
                            <option value="kepsek">Kepala Sekolah</option>
                            <option value="ustadz">Ustadz</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">No. HP / WhatsApp</label>
                        <input type="text" name="no_hp" x-model="userData.no_hp"
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition"
                            placeholder="08xxxx">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">
                            Password <span x-show="editMode" class="text-xs text-slate-400 font-normal ml-2">(Kosongkan
                                jika tidak ganti)</span>
                        </label>
                        <input type="password" name="password" :required="!editMode"
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition">
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
</div>

<?php require_once 'includes/footer.php'; ?>