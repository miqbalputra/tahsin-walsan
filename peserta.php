<?php
$pageTitle = 'Manajemen Peserta (Wali Santri)';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

checkRole(['admin', 'pj_tahfidz']);

$message = $_GET['msg'] ?? '';
$error = $_GET['err'] ?? '';

// Handle Create/Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Validation
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $action = $_POST['action'] ?? '';
    $id = $_POST['id'] ?? null;
    $nama_bapak = $_POST['nama_bapak'] ?? '';
    $no_hp = $_POST['no_hp'] ?? '';
    $alamat = $_POST['alamat'] ?? '';
    $kategori = $_POST['kategori'] ?? 'reguler';
    $tempat_tahsin = $_POST['tempat_tahsin'] ?? '';
    $ustadz_luar = $_POST['ustadz_luar'] ?? '';
    $anak_list = $_POST['anak'] ?? []; // Array of children

    if ($action === 'save') {
        $pdo->beginTransaction();
        try {
            if ($id) {
                // Update Wali
                $stmt = $pdo->prepare("UPDATE wali_santri SET nama_bapak=?, no_hp=?, alamat=?, kategori=?, tempat_tahsin=?, ustadz_luar=? WHERE id=?");
                $stmt->execute([$nama_bapak, $no_hp, $alamat, $kategori, $tempat_tahsin, $ustadz_luar, $id]);

                // Delete old children then re-insert to simplify sync
                $pdo->prepare("DELETE FROM santri_detail WHERE wali_santri_id = ?")->execute([$id]);
                $wali_id = $id;
            } else {
                // Insert Wali
                $stmt = $pdo->prepare("INSERT INTO wali_santri (nama_bapak, no_hp, alamat, kategori, tempat_tahsin, ustadz_luar) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nama_bapak, $no_hp, $alamat, $kategori, $tempat_tahsin, $ustadz_luar]);
                $wali_id = $pdo->lastInsertId();
            }

            // Insert Children
            $stmtAnak = $pdo->prepare("INSERT INTO santri_detail (wali_santri_id, nama_anak, kelas) VALUES (?, ?, ?)");
            foreach ($anak_list as $anak) {
                if (!empty($anak['nama'])) {
                    $stmtAnak->execute([$wali_id, $anak['nama'], $anak['kelas']]);
                }
            }

            // Update halaqoh membership
            $halaqoh_id_input = $_POST['halaqoh_id'] ?? '';
            $pdo->prepare("DELETE FROM halaqoh_members WHERE wali_santri_id = ?")->execute([$wali_id]);
            if (!empty($halaqoh_id_input)) {
                $pdo->prepare("INSERT INTO halaqoh_members (halaqoh_id, wali_santri_id) VALUES (?, ?)")->execute([intval($halaqoh_id_input), $wali_id]);
            }

            $pdo->commit();
            addLog($pdo, 'SAVE_PESERTA', ($id ? "Update" : "Tambah") . " data wali santri: $nama_bapak");
            $message = "Data peserta berhasil disimpan!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Terjadi kesalahan: " . $e->getMessage();
        }
    } elseif ($action === 'delete' && $id) {
        $stmt = $pdo->prepare("DELETE FROM wali_santri WHERE id = ?");
        $stmt->execute([$id]);
        addLog($pdo, 'DELETE_PESERTA', "Menghapus ID Peserta: $id");
        $message = "Peserta berhasil dihapus!";
    }
}

// Filters
$kelas_filter = $_GET['kelas'] ?? '';
$nama_ayah_filter = $_GET['nama_ayah'] ?? '';
$nama_anak_filter = $_GET['nama_anak'] ?? '';
$kategori_filter = $_GET['kategori'] ?? '';
$halaqoh_filter = $_GET['halaqoh'] ?? '';

// Build Query with multiple filters
$sql = "SELECT w.*, GROUP_CONCAT(CONCAT(s.nama_anak, ' (', s.kelas, ')') SEPARATOR ', ') as daftar_anak,
        (SELECT hm.halaqoh_id FROM halaqoh_members hm WHERE hm.wali_santri_id = w.id LIMIT 1) as current_halaqoh_id,
        (SELECT h.nama_halaqoh FROM halaqoh_members hm JOIN halaqoh h ON hm.halaqoh_id = h.id WHERE hm.wali_santri_id = w.id LIMIT 1) as nama_halaqoh,
        (SELECT u.nama_lengkap FROM halaqoh_members hm JOIN halaqoh h ON hm.halaqoh_id = h.id JOIN users u ON h.ustadz_id = u.id WHERE hm.wali_santri_id = w.id LIMIT 1) as nama_ustadz_halaqoh
        FROM wali_santri w 
        LEFT JOIN santri_detail s ON w.id = s.wali_santri_id ";

$conditions = [];
$params = [];

// Filter: Nama Ayah (text search)
if (!empty($nama_ayah_filter)) {
    $conditions[] = "w.nama_bapak LIKE :nama_ayah";
    $params[':nama_ayah'] = '%' . $nama_ayah_filter . '%';
}

// Filter: Nama Anak (text search via subquery)
if (!empty($nama_anak_filter)) {
    $conditions[] = "w.id IN (SELECT wali_santri_id FROM santri_detail WHERE nama_anak LIKE :nama_anak)";
    $params[':nama_anak'] = '%' . $nama_anak_filter . '%';
}

// Filter: Kelas Anak
if (!empty($kelas_filter)) {
    $conditions[] = "w.id IN (SELECT wali_santri_id FROM santri_detail WHERE kelas = :kls)";
    $params[':kls'] = $kelas_filter;
}

// Filter: Kategori
if (!empty($kategori_filter)) {
    $conditions[] = "w.kategori = :kategori";
    $params[':kategori'] = $kategori_filter;
}

// Filter: Halaqoh
if (!empty($halaqoh_filter)) {
    $conditions[] = "w.id IN (SELECT wali_santri_id FROM halaqoh_members WHERE halaqoh_id = :halaqoh_id)";
    $params[':halaqoh_id'] = $halaqoh_filter;
}

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " GROUP BY w.id ORDER BY w.nama_bapak";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$peserta = $stmt->fetchAll();

// Fetch all unique classes for the filter dropdown
$daftar_kelas = $pdo->query("SELECT DISTINCT kelas FROM santri_detail WHERE kelas IS NOT NULL AND kelas != '' ORDER BY kelas")->fetchAll(PDO::FETCH_COLUMN);

// Fetch all halaqoh for filter dropdown
$daftar_halaqoh = $pdo->query("SELECT h.id, h.nama_halaqoh, u.nama_lengkap as nama_ustadz FROM halaqoh h JOIN users u ON h.ustadz_id = u.id ORDER BY h.nama_halaqoh")->fetchAll();

// Optimization: Fetch all children at once to avoid N+1 query problem (Fix N+1)
$childrenData = $pdo->query("SELECT wali_santri_id, nama_anak as nama, kelas FROM santri_detail")->fetchAll(PDO::FETCH_GROUP);

// Convert grouped data to a format easy for Alpine.js
$jsonChildren = json_encode($childrenData);

// Check if any filter is active
$hasActiveFilter = !empty($nama_ayah_filter) || !empty($nama_anak_filter) || !empty($kelas_filter) || !empty($kategori_filter) || !empty($halaqoh_filter);
?>

<div x-data="{ 
    showModal: false, 
    showImportModal: false,
    showFilterPanel: <?php echo $hasActiveFilter ? 'true' : 'false'; ?>,
    editMode: false,
    formData: { id: '', nama_bapak: '', no_hp: '', alamat: '', kategori: 'reguler', tempat_tahsin: '', ustadz_luar: '', halaqoh_id: '' },
    anak: [{ nama: '', kelas: '' }],
    openAdd() {
        this.editMode = false;
        this.formData = { id: '', nama_bapak: '', no_hp: '', alamat: '', kategori: 'reguler', tempat_tahsin: '', ustadz_luar: '', halaqoh_id: '' };
        this.anak = [{ nama: '', kelas: '' }];
        this.showModal = true;
    },
    openEdit(wali) {
        this.editMode = true;
        this.formData = { ...wali, halaqoh_id: wali.current_halaqoh_id || '' };
        const childrenData = JSON.parse(document.getElementById('children-data').textContent);
        this.anak = childrenData[wali.id] || [{ nama: '', kelas: '' }];
        this.showModal = true;
    },
    addAnak() {
        this.anak.push({ nama: '', kelas: '' });
    },
    removeAnak(index) {
        if (this.anak.length > 1) this.anak.splice(index, 1);
        else this.anak = [{ nama: '', kelas: '' }];
    }
}">
    <script id="children-data" type="application/json"><?php echo $jsonChildren; ?></script>

    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-800">Daftar Wali Santri</h2>
            <p class="text-slate-500 text-sm">Manajemen data orang tua dan santri</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
            <!-- Toggle Filter Button -->
            <button @click="showFilterPanel = !showFilterPanel"
                class="<?php echo $hasActiveFilter ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-700'; ?> px-4 py-2 rounded-xl flex items-center hover:bg-blue-600 hover:text-white transition shadow-sm font-semibold text-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z">
                    </path>
                </svg>
                Filter
                <?php if ($hasActiveFilter): ?>
                    <span class="ml-1.5 bg-white text-blue-600 text-[10px] font-bold px-1.5 py-0.5 rounded-full"><?php
                    $count = 0;
                    if (!empty($nama_ayah_filter))
                        $count++;
                    if (!empty($nama_anak_filter))
                        $count++;
                    if (!empty($kelas_filter))
                        $count++;
                    if (!empty($kategori_filter))
                        $count++;
                    if (!empty($halaqoh_filter))
                        $count++;
                    echo $count;
                    ?></span>
                <?php endif; ?>
            </button>

            <a href="merge-peserta.php"
                class="bg-amber-100 text-amber-700 px-4 py-2 rounded-xl flex items-center hover:bg-amber-200 transition shadow-sm font-semibold text-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                </svg>
                Gabung Duplikat
            </a>

            <button @click="showImportModal = true"
                class="bg-emerald-100 text-emerald-700 px-4 py-2 rounded-xl flex items-center hover:bg-emerald-200 transition shadow-sm font-semibold text-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                    </path>
                </svg>
                Import
            </button>
            <button @click="openAdd()"
                class="bg-blue-600 text-white px-4 py-2 rounded-xl flex items-center hover:bg-blue-700 transition shadow-sm font-semibold text-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 6v6m0 0v6m0-6h6m-6 0H6">
                    </path>
                </svg>
                Tambah Peserta
            </button>
        </div>
    </div>

    <!-- Filter Panel -->
    <div x-show="showFilterPanel" x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-2"
        class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5 mb-6" x-cloak>
        <form method="GET" action="" id="filterForm">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <!-- Filter: Nama Ayah -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Nama
                        Ayah</label>
                    <input type="text" name="nama_ayah" value="<?php echo htmlspecialchars($nama_ayah_filter); ?>"
                        placeholder="Cari nama ayah..."
                        class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-xl px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none transition">
                </div>
                <!-- Filter: Nama Anak -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Nama
                        Anak</label>
                    <input type="text" name="nama_anak" value="<?php echo htmlspecialchars($nama_anak_filter); ?>"
                        placeholder="Cari nama anak..."
                        class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-xl px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none transition">
                </div>
                <!-- Filter: Kelas -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Kelas
                        Anak</label>
                    <select name="kelas"
                        class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-xl px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none transition">
                        <option value="">Semua Kelas</option>
                        <?php foreach ($daftar_kelas as $k): ?>
                            <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $kelas_filter === $k ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($k); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Filter: Kategori -->
                <div>
                    <label
                        class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Kategori</label>
                    <select name="kategori"
                        class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-xl px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none transition">
                        <option value="">Semua Kategori</option>
                        <option value="reguler" <?php echo $kategori_filter === 'reguler' ? 'selected' : ''; ?>>Reguler
                        </option>
                        <option value="tahsin_luar" <?php echo $kategori_filter === 'tahsin_luar' ? 'selected' : ''; ?>>
                            Tahsin Luar</option>
                        <option value="askar" <?php echo $kategori_filter === 'askar' ? 'selected' : ''; ?>>Askar</option>
                    </select>
                </div>
                <!-- Filter: Halaqoh -->
                <div>
                    <label
                        class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Halaqoh</label>
                    <select name="halaqoh"
                        class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-xl px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none transition">
                        <option value="">Semua Halaqoh</option>
                        <?php foreach ($daftar_halaqoh as $hq): ?>
                            <option value="<?php echo $hq['id']; ?>" <?php echo $halaqoh_filter == $hq['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($hq['nama_halaqoh']); ?>
                                (<?php echo htmlspecialchars($hq['nama_ustadz']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="flex items-center gap-3 mt-4 pt-4 border-t border-slate-100">
                <button type="submit"
                    class="bg-blue-600 text-white px-5 py-2 rounded-xl font-semibold text-sm hover:bg-blue-700 transition shadow-sm flex items-center">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    Terapkan Filter
                </button>
                <a href="peserta.php"
                    class="text-slate-500 hover:text-slate-700 px-4 py-2 rounded-xl font-semibold text-sm transition hover:bg-slate-100 flex items-center">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                        </path>
                    </svg>
                    Reset
                </a>
                <?php if ($hasActiveFilter): ?>
                    <span class="text-xs text-slate-400 italic ml-auto">Menampilkan <?php echo count($peserta); ?>
                        hasil</span>
                <?php endif; ?>
            </div>
        </form>
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
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead
                    class="bg-slate-50 border-b border-slate-100 text-slate-500 uppercase text-xs font-bold tracking-wider">
                    <tr>
                        <th class="px-6 py-4 text-nowrap">Nama Wali (Bapak)</th>
                        <th class="px-6 py-4">Kategori</th>
                        <th class="px-6 py-4">Anak & Kelas</th>
                        <th class="px-6 py-4 text-nowrap">Halaqoh</th>
                        <th class="px-6 py-4">No. HP</th>
                        <th class="px-6 py-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($peserta)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-slate-400 italic">Belum ada data peserta.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($peserta as $p): ?>
                        <tr class="hover:bg-slate-50 transition">
                            <td class="px-6 py-4 font-bold text-slate-800 align-middle">
                                <?php echo htmlspecialchars($p['nama_bapak']); ?>
                            </td>
                            <td class="px-6 py-4 align-middle">
                                <span
                                    class="px-3 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider <?php echo $p['kategori'] === 'reguler' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'; ?>">
                                    <?php echo str_replace('_', ' ', $p['kategori']); ?>
                                </span>
                                <?php if ($p['kategori'] === 'tahsin_luar'): ?>
                                    <div class="mt-1 text-[9px] text-slate-400 italic leading-tight">
                                        Di: <?php echo htmlspecialchars($p['tempat_tahsin']); ?><br>
                                        Ustadz: <?php echo htmlspecialchars($p['ustadz_luar']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 align-middle max-w-xs truncate">
                                <?php echo htmlspecialchars($p['daftar_anak'] ?: '-'); ?>
                            </td>
                            <td class="px-6 py-4 align-middle text-nowrap">
                                <?php if (!empty($p['nama_halaqoh'])): ?>
                                    <div class="text-sm font-semibold text-slate-700"><?php echo htmlspecialchars($p['nama_halaqoh']); ?></div>
                                    <div class="text-[11px] text-slate-400">Ust. <?php echo htmlspecialchars($p['nama_ustadz_halaqoh']); ?></div>
                                <?php else: ?>
                                    <span class="text-xs text-slate-300 italic">Belum ada</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-slate-500 text-sm align-middle">
                                <div class="flex items-center gap-2">
                                    <div class="flex flex-col">
                                        <span
                                            class="font-bold text-slate-700"><?php echo htmlspecialchars($p['no_hp']); ?></span>
                                    </div>
                                    <?php if (!empty($p['no_hp'])):
                                        $wa_no = preg_replace('/[^0-9]/', '', $p['no_hp']);
                                        if (str_starts_with($wa_no, '0')) {
                                            $wa_no = '62' . substr($wa_no, 1);
                                        }

                                        $share_msg = urlencode("Assalamualaikum Pak " . $p['nama_bapak'] . ",\n\nSyukran Jazakumullah Khairan.");
                                        ?>
                                        <a href="https://wa.me/<?php echo $wa_no; ?>?text=<?php echo $share_msg; ?>"
                                            target="_blank"
                                            class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-emerald-50 text-emerald-600 hover:bg-emerald-600 hover:text-white transition-all shadow-sm border border-emerald-100"
                                            title="Kirim Pesan via WA">
                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 448 512">
                                                <path
                                                    d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.7 17.8 69.4 27.2 106.2 27.2 122.4 0 222-99.6 222-222 0-59.3-23-115.1-65-157.1zM223.9 446.3c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3 18.7-68.1-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-5.5-2.8-23.4-8.6-44.6-27.5-16.4-14.7-27.5-32.8-30.7-38.4-3.2-5.6-.3-8.6 2.5-11.4 2.6-2.5 5.5-6.5 8.3-9.8 2.8-3.3 3.7-5.6 5.6-9.3 1.9-3.7.9-6.9-.5-9.8-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 13.2 5.7 23.5 9.2 31.5 11.8 13.3 4.2 25.4 3.6 35 2.2 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z" />
                                            </svg>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-right align-middle">
                                <div class="flex justify-end items-center gap-2">
                                    <button @click="openEdit(<?php echo htmlspecialchars(json_encode($p)); ?>)"
                                        class="text-blue-600 hover:bg-blue-600 hover:text-white font-bold text-[10px] uppercase transition bg-blue-50 px-3 py-1.5 rounded-xl border border-blue-100">
                                        Edit
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Hapus data bapak ini?')"
                                        class="inline-flex">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                        <button type="submit"
                                            class="text-red-600 hover:bg-red-600 hover:text-white font-bold text-[10px] uppercase transition bg-red-50 px-3 py-1.5 rounded-xl border border-red-100">
                                            Hapus
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Form (Large) -->
    <div x-show="showModal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm overflow-y-auto"
        x-cloak>
        <div class="bg-white rounded-3xl w-full max-w-2xl p-8 my-8 shadow-2xl relative" @click.away="showModal = false">
            <h3 class="text-2xl font-bold text-slate-800 mb-6"
                x-text="editMode ? 'Edit Data Wali Santri' : 'Input Peserta Baru'"></h3>

            <form method="POST" action="" class="space-y-6">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" x-model="formData.id">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Nama Bapak (Wali)</label>
                        <input type="text" name="nama_bapak" x-model="formData.nama_bapak" required
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">No. HP / WhatsApp</label>
                        <input type="text" name="no_hp" x-model="formData.no_hp" required
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition"
                            placeholder="08xxxx">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Kategori Program</label>
                        <select name="kategori" x-model="formData.kategori"
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition">
                            <option value="reguler">Reguler</option>
                            <option value="tahsin_luar">Tahsin Luar</option>
                            <option value="askar">Askar</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Alamat</label>
                        <input type="text" name="alamat" x-model="formData.alamat"
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition">
                    </div>
                </div>

                <!-- Halaqoh Assignment -->
                <div class="bg-blue-50 p-4 rounded-2xl border border-blue-100">
                    <label class="block text-sm font-semibold text-blue-700 mb-1">Halaqoh</label>
                    <select name="halaqoh_id" x-model="formData.halaqoh_id"
                        class="w-full px-4 py-2 rounded-xl border border-blue-200 focus:ring-2 focus:ring-blue-500 outline-none transition bg-white">
                        <option value="">-- Belum Ditentukan --</option>
                        <?php foreach ($daftar_halaqoh as $hq): ?>
                            <option value="<?php echo $hq['id']; ?>">
                                <?php echo htmlspecialchars($hq['nama_halaqoh']); ?> — Ust. <?php echo htmlspecialchars($hq['nama_ustadz']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-blue-500 text-[10px] mt-1 font-semibold">Pilih halaqoh untuk peserta ini</p>
                </div>

                <!-- Tahsin Luar Specific Fields -->
                <div x-show="formData.kategori === 'tahsin_luar'" x-transition
                    class="grid grid-cols-1 md:grid-cols-2 gap-6 p-4 bg-purple-50 rounded-2xl border border-purple-100">
                    <div>
                        <label class="block text-sm font-semibold text-purple-700 mb-1">Tempat Tahsin <span
                                class="text-red-500">*</span></label>
                        <input type="text" name="tempat_tahsin" x-model="formData.tempat_tahsin"
                            :required="formData.kategori === 'tahsin_luar'"
                            class="w-full px-4 py-2 rounded-xl border border-purple-200 focus:ring-2 focus:ring-purple-500 outline-none transition bg-white"
                            placeholder="Contoh: Masjid Al-Ikhlas">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-purple-700 mb-1">Ustadz Pengajar <span
                                class="text-red-500">*</span></label>
                        <input type="text" name="ustadz_luar" x-model="formData.ustadz_luar"
                            :required="formData.kategori === 'tahsin_luar'"
                            class="w-full px-4 py-2 rounded-xl border border-purple-200 focus:ring-2 focus:ring-purple-500 outline-none transition bg-white"
                            placeholder="Nama Ustadz">
                    </div>
                </div>

                <!-- Dynamic Children Fields -->
                <div class="bg-slate-50 p-6 rounded-2xl">
                    <div class="flex justify-between items-center mb-4">
                        <label class="text-sm font-bold text-slate-800">Daftar Anak</label>
                        <button type="button" @click="addAnak()"
                            class="text-xs bg-blue-100 text-blue-600 px-3 py-1 rounded-lg font-bold hover:bg-blue-200 transition">
                            + Tambah Anak
                        </button>
                    </div>
                    <div class="space-y-3">
                        <template x-for="(item, index) in anak" :key="index">
                            <div class="flex gap-3">
                                <div class="flex-1">
                                    <input type="text" :name="'anak['+index+'][nama]'" x-model="item.nama"
                                        placeholder="Nama Anak" required
                                        class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition bg-white text-sm">
                                </div>
                                <div class="w-32">
                                    <input type="text" :name="'anak['+index+'][kelas]'" x-model="item.kelas"
                                        placeholder="Kelas"
                                        class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition bg-white text-sm">
                                </div>
                                <button type="button" @click="removeAnak(index)"
                                    class="text-red-400 hover:text-red-600 p-2">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                                        </path>
                                    </svg>
                                </button>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="flex gap-4 pt-4">
                    <button type="button" @click="showModal = false"
                        class="flex-1 px-4 py-3 rounded-2xl border border-slate-200 font-bold text-slate-600 hover:bg-slate-50 transition">Batal</button>
                    <button type="submit"
                        class="flex-1 px-4 py-3 rounded-2xl bg-blue-600 text-white font-bold hover:bg-blue-700 transition shadow-lg shadow-blue-200">Simpan
                        Data</button>
                </div>
            </form>
        </div>
        <!-- Import Modal -->
        <div x-show="showImportModal"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm" x-cloak>
            <div class="bg-white rounded-3xl w-full max-w-md p-8 shadow-2xl" @click.away="showImportModal = false">
                <h3 class="text-xl font-bold text-slate-800 mb-4">Import Peserta dari Excel</h3>
                <p class="text-sm text-slate-500 mb-6">Silakan unggah file format <strong>.csv</strong>. Jika belum
                    punya
                    formatnya, silakan download template di bawah.</p>

                <form action="import-peserta.php" method="POST" enctype="multipart/form-data">
                    <?php csrfField(); ?>
                    <div class="mb-6">
                        <label class="block text-sm font-semibold text-slate-700 mb-2">Pilih File CSV</label>
                        <input type="file" name="file_csv" accept=".csv" required
                            class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 border border-slate-200 rounded-xl p-2">
                    </div>

                    <div class="flex flex-col gap-3">
                        <button type="submit"
                            class="w-full bg-blue-600 text-white font-bold py-3 rounded-xl hover:bg-blue-700 shadow-lg shadow-blue-100 transition">
                            Mulai Import Data
                        </button>
                        <a href="template_import_peserta.csv" download
                            class="text-center text-sm font-semibold text-slate-500 hover:text-blue-600 py-2">
                            Download Template CSV
                        </a>
                        <button type="button" @click="showImportModal = false"
                            class="w-full py-2 text-sm text-slate-400 hover:text-slate-600">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php require_once 'includes/footer.php'; ?>