<?php
$pageTitle = 'Pengelolaan Pengguna';
require_once 'config/database.php';
require_once 'includes/auth_helper.php';
require_once 'includes/user_helper.php';

checkRole(['admin', 'pj_tahfidz']);

$message = is_string($_SESSION['flash_users'] ?? null) ? $_SESSION['flash_users'] : '';
unset($_SESSION['flash_users']);
$error = '';
$showUserModal = false;
$userForm = ['id' => '', 'username' => '', 'nama_lengkap' => '', 'role' => 'ustadz', 'no_hp' => '', 'password' => ''];

// Handle Create/Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken($_POST['csrf_token'] ?? '');
    foreach ($userForm as $key => $default) {
        if ($key !== 'password' && is_string($_POST[$key] ?? null)) $userForm[$key] = $_POST[$key];
    }
    try {
        $action = appRequestString($_POST, 'action');
        if ($action === 'save') {
            $id = saveManagedUser($pdo, (int) $_SESSION['user_id'], $_POST);
            $message = empty($_POST['id']) ? 'Pengguna berhasil ditambahkan.' : 'Perubahan pengguna berhasil disimpan.';
            addLog($pdo, 'SAVE_USER', 'id=' . $id);
            if ($id === (int) $_SESSION['user_id']) {
                unset($_SESSION['auth_password_fingerprint']);
                $_SESSION['role'] = appRequestString($_POST, 'role');
            }
        } elseif ($action === 'delete') {
            $id = userRequestId($_POST, true);
            $confirmed = appRequestString($_POST, 'confirmed', '0') === '1';
            deleteManagedUser($pdo, (int) $_SESSION['user_id'], $id, $confirmed);
            addLog($pdo, 'DELETE_USER', 'id=' . $id);
            $message = 'Pengguna berhasil dihapus.';
        } else {
            throw new UserManagementException('Tindakan tidak dikenali. Buka kembali daftar pengguna.');
        }
        $_SESSION['flash_users'] = $message;
        redirectTo(in_array($_SESSION['role'], ['admin', 'pj_tahfidz'], true) ? 'users.php' : roleHomePath());
    } catch (UserManagementException | InvalidArgumentException $failure) {
        $error = $failure->getMessage();
        $showUserModal = ($_POST['action'] ?? '') === 'save';
    } catch (Throwable $failure) {
        reportApplicationError($failure, 'manage-user');
        $error = 'Perubahan belum tersimpan. Nama pengguna mungkin sudah dipakai atau akun masih terhubung ke data lain. Periksa isian lalu coba lagi.';
        $showUserModal = ($_POST['action'] ?? '') === 'save';
    }
}

$users = listManagedUsers($pdo);
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div x-data="{ 
    showModal: <?= $showUserModal ? 'true' : 'false' ?>,
    editMode: <?= $userForm['id'] !== '' ? 'true' : 'false' ?>,
    userData: <?= appJsonAttribute($userForm) ?>,
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
            Tambah Pengguna
        </button>
    </div>

    <?php if ($message): ?>
        <div role="status" class="bg-emerald-50 text-emerald-600 p-4 rounded-xl mb-6 flex items-center border border-emerald-100 italic">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <?= appEscape($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error && !$showUserModal): ?>
        <p role="alert" class="bg-red-50 text-red-800 p-4 rounded-xl mb-6"><?= appEscape($error) ?></p>
    <?php endif; ?>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-x-auto" role="region" aria-label="Daftar pengguna" tabindex="0">
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
                                    default => 'bg-slate-100 text-slate-700',
                                };
                                ?>">
                                <?= appEscape(userRoleLabel($user['role'])) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right align-middle">
                            <div class="flex justify-end items-center gap-2">
                                <button @click="openEdit(<?= appJsonAttribute($user) ?>)"
                                    class="text-blue-600 hover:bg-blue-600 hover:text-white font-bold text-[10px] uppercase transition bg-blue-50 px-3 py-1.5 rounded-xl border border-blue-100">
                                    Edit
                                </button>
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <form method="POST" action="" class="inline-flex items-center gap-2">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                        <label class="inline-flex items-center gap-1 text-[10px] text-slate-500">
                                            <input type="checkbox" name="confirmed" value="1" required class="h-4 w-4 rounded border-slate-300 text-red-600 focus:ring-red-500">
                                            <span>Setuju hapus permanen</span>
                                        </label>
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
    <div x-show="showModal" role="dialog" aria-modal="true" aria-labelledby="user-dialog-title" @keydown.escape.window="showModal = false"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm"
        x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100" x-cloak>
        <div class="bg-white rounded-3xl w-full max-w-md max-h-[90vh] overflow-y-auto p-6 sm:p-8 shadow-2xl" @click.away="showModal = false">
            <h3 id="user-dialog-title" class="text-xl font-bold text-slate-800 mb-6" x-text="editMode ? 'Edit Pengguna' : 'Tambah Pengguna'"></h3>
            <?php if ($error && $showUserModal): ?>
                <p role="alert" class="bg-red-50 text-red-800 p-4 rounded-xl mb-4"><?= appEscape($error) ?></p>
            <?php endif; ?>

            <form method="POST" action="">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" x-model="userData.id">

                <div class="space-y-4">
                    <div>
                        <label for="user-full-name" class="block text-sm font-semibold text-slate-700 mb-1">Nama Lengkap</label>
                        <p x-show="userData.data_induk_teacher_id" class="mb-3 rounded-xl bg-blue-50 p-3 text-xs text-blue-700">Nama dan kontak ustadz mengikuti Data Induk; akun login, password, dan role tetap dikelola di sini.</p>
                        <input id="user-full-name" type="text" name="nama_lengkap" maxlength="100" autocomplete="name" x-model="userData.nama_lengkap" required :readonly="!!userData.data_induk_teacher_id"
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition">
                    </div>
                    <div>
                        <label for="user-username" class="block text-sm font-semibold text-slate-700 mb-1">Nama Pengguna</label>
                        <input id="user-username" type="text" name="username" maxlength="50" autocomplete="off" x-model="userData.username" required
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition">
                    </div>
                    <div>
                        <label for="user-role" class="block text-sm font-semibold text-slate-700 mb-1">Peran dan Hak Akses</label>
                        <select id="user-role" name="role" x-model="userData.role" required
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition">
                            <?php if ($_SESSION['role'] === 'admin'): ?>
                            <option value="admin">Admin</option>
                            <?php endif; ?>
                            <option value="pj_tahfidz">PJ Tahfidz</option>
                            <option value="kepsek">Kepala Sekolah</option>
                            <option value="ustadz">Ustadz / Ustadzah</option>
                        </select>
                    </div>
                    <div>
                        <label for="user-phone" class="block text-sm font-semibold text-slate-700 mb-1">No. HP / WhatsApp</label>
                        <input id="user-phone" type="tel" name="no_hp" maxlength="20" autocomplete="tel" x-model="userData.no_hp" :readonly="!!userData.data_induk_teacher_id"
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition"
                            placeholder="08xxxx">
                    </div>
                    <div>
                        <label for="user-password" class="block text-sm font-semibold text-slate-700 mb-1">
                            Kata Sandi <span x-show="editMode" class="text-xs text-slate-400 font-normal ml-2">(Kosongkan
                                jika tidak ganti)</span>
                        </label>
                        <input id="user-password" type="password" name="password" x-model="userData.password" minlength="8" maxlength="72" autocomplete="new-password" aria-describedby="user-password-help" :required="!editMode"
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition">
                        <p id="user-password-help" class="mt-2 text-sm text-slate-600">Minimal 8 karakter. Perubahan kata sandi mengakhiri sesi akun ini di perangkat lain.</p>
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
