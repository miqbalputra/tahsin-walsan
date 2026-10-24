<?php
$pageTitle = 'Manajemen Peserta (Wali Santri)';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_helper.php';
require_once __DIR__ . '/includes/participant_helper.php';
checkRole(['admin', 'pj_tahfidz']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken($_POST['csrf_token'] ?? null);
    appRequestError(422, 'Buka formulir peserta terbaru', 'Formulir peserta sudah diperbarui. Permintaan lama belum diproses. Pilih Edit atau Arsipkan dari daftar untuk melanjutkan.');
}
$message = is_string($_SESSION['participant_flash'] ?? null) ? $_SESSION['participant_flash'] : (is_string($_GET['msg'] ?? null) ? $_GET['msg'] : '');
unset($_SESSION['participant_flash']);
$error = is_string($_GET['err'] ?? null) ? $_GET['err'] : '';
try {
    $kelas_filter = participantText($_GET, 'kelas', 100);
    $nama_ayah_filter = participantText($_GET, 'nama_ayah', 100);
    $nama_anak_filter = participantText($_GET, 'nama_anak', 255);
    $kategori_filter = appRequestString($_GET, 'kategori');
    if ($kategori_filter !== '' && !in_array($kategori_filter,['reguler','askar','tahsin_luar'],true)) throw new ParticipantManagementException('Pilih kategori dari daftar.');
    $halaqoh_filter = appRequestString($_GET, 'halaqoh');
    if ($halaqoh_filter !== '') participantId($halaqoh_filter);
    $page = filter_var(appRequestString($_GET,'page','1'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
    if (!$page) throw new ParticipantManagementException('Nomor halaman tidak valid.');
} catch (ParticipantManagementException|InvalidArgumentException $failure) {
    appRequestError(422, 'Filter belum dapat digunakan', $failure->getMessage());
}

// Build Query with multiple filters
$childTable = familyChildrenTable($pdo);
$sql = 'SELECT w.* FROM wali_santri w';

$conditions = [];
$params = [];

// Filter: Nama Ayah (text search)
if ($nama_ayah_filter !== '') {
    $conditions[] = "w.nama_bapak LIKE :nama_ayah";
    $params[':nama_ayah'] = '%' . $nama_ayah_filter . '%';
}

// Filter: Nama Anak (text search via subquery)
if ($nama_anak_filter !== '') {
    $conditions[] = "w.id IN (SELECT wali_santri_id FROM {$childTable} WHERE nama_anak LIKE :nama_anak)";
    $params[':nama_anak'] = '%' . $nama_anak_filter . '%';
}

// Filter: Kelas Anak
if ($kelas_filter !== '') {
    $conditions[] = "w.id IN (SELECT wali_santri_id FROM {$childTable} WHERE kelas = :kls)";
    $params[':kls'] = $kelas_filter;
}

// Filter: Kategori
if ($kategori_filter !== '') {
    $conditions[] = "w.kategori = :kategori";
    $params[':kategori'] = $kategori_filter;
}

// Filter: Halaqoh
if ($halaqoh_filter !== '') {
    $conditions[] = "w.id IN (SELECT wali_santri_id FROM halaqoh_members WHERE halaqoh_id = :halaqoh_id AND archived_at IS NULL)";
    $params[':halaqoh_id'] = $halaqoh_filter;
}

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$countStatement=$pdo->prepare(str_replace('SELECT w.*','SELECT COUNT(*)',$sql));
$countStatement->execute($params); $totalParticipants=(int)$countStatement->fetchColumn();
$pages=max(1,(int)ceil($totalParticipants/50)); $page=min($page,$pages);
$sql .= ' ORDER BY w.nama_bapak,w.id LIMIT 50 OFFSET ' . (($page-1)*50);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$peserta = $stmt->fetchAll();
$participantChildren=[]; $participantMemberships=[];
if ($peserta) {
    $ids=array_column($peserta,'id'); $placeholders=implode(',',array_fill(0,count($ids),'?'));
    $statement=$pdo->prepare("SELECT wali_santri_id,nama_anak,kelas FROM $childTable WHERE wali_santri_id IN ($placeholders) ORDER BY nama_anak,kelas");
    $statement->execute($ids);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $child) $participantChildren[(int)$child['wali_santri_id']][]=$child;
    $programColumn=participantHasPrograms($pdo)?'h.program_code':"'bapak'";
    $statement=$pdo->prepare("SELECT hm.wali_santri_id,h.nama_halaqoh,u.nama_lengkap,$programColumn AS program_code FROM halaqoh_members hm JOIN halaqoh h ON h.id=hm.halaqoh_id JOIN users u ON u.id=h.ustadz_id WHERE hm.archived_at IS NULL AND hm.wali_santri_id IN ($placeholders) ORDER BY h.nama_halaqoh,hm.id");
    $statement->execute($ids);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $membership) $participantMemberships[(int)$membership['wali_santri_id']][]=$membership;
}
$pageUrl=static fn($target)=>'peserta.php?'.http_build_query(array_filter(['page'=>$target,'kelas'=>$kelas_filter,'nama_ayah'=>$nama_ayah_filter,'nama_anak'=>$nama_anak_filter,'kategori'=>$kategori_filter,'halaqoh'=>$halaqoh_filter],static fn($value)=>$value!==''));

// Fetch all unique classes for the filter dropdown
$daftar_kelas = $pdo->query("SELECT DISTINCT kelas FROM {$childTable} WHERE kelas IS NOT NULL AND kelas != '' AND kelas != 'Lulus' ORDER BY kelas")->fetchAll(PDO::FETCH_COLUMN);

// Fetch all halaqoh for filter dropdown
$daftar_halaqoh = $pdo->query("SELECT h.id, h.nama_halaqoh, u.nama_lengkap as nama_ustadz FROM halaqoh h JOIN users u ON h.ustadz_id = u.id ORDER BY h.nama_halaqoh")->fetchAll();

// Check if any filter is active
$hasActiveFilter = $nama_ayah_filter !== '' || $nama_anak_filter !== '' || $kelas_filter !== '' || $kategori_filter !== '' || $halaqoh_filter !== '';
?>

<?php require __DIR__ . '/includes/header.php'; require __DIR__ . '/includes/sidebar.php'; ?>
<div>
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-800">Daftar Wali Santri</h2>
            <p class="text-slate-500 text-sm">Manajemen data orang tua dan santri</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">


            <?php if (($_SESSION['role']??'')==='admin'): ?>
            <a href="merge-peserta.php"
                class="bg-amber-100 text-amber-700 px-4 py-2 rounded-xl flex items-center hover:bg-amber-200 transition shadow-sm font-semibold text-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                </svg>
                Gabung Duplikat
            </a>
            <?php endif; ?>

            <a href="import-peserta.php"
                class="bg-emerald-100 text-emerald-700 px-4 py-2 rounded-xl flex items-center hover:bg-emerald-200 transition shadow-sm font-semibold text-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                    </path>
                </svg>
                Impor CSV
            </a>
            <a href="peserta-riwayat.php" class="border border-slate-300 rounded-xl px-4 py-2 text-blue-700">Riwayat perubahan</a>
            <a href="peserta-form.php"
                class="bg-blue-600 text-white px-4 py-2 rounded-xl flex items-center hover:bg-blue-700 transition shadow-sm font-semibold text-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 6v6m0 0v6m0-6h6m-6 0H6">
                    </path>
                </svg>
                Tambah Peserta
            </a>
        </div>
    </div>

    <details class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5 mb-6" <?= $hasActiveFilter?'open':'' ?>>
        <summary class="font-bold cursor-pointer py-2">Cari dan filter peserta</summary>
        <form method="GET" action="" id="filterForm">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <!-- Filter: Nama Ayah -->
                <div>
                    <label for="filter-nama_ayah" class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Nama
                        Wali</label>
                    <input type="text" id="filter-nama_ayah" name="nama_ayah" value="<?php echo appEscape($nama_ayah_filter); ?>"
                        placeholder="Cari nama wali..."
                        class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-xl px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none transition">
                </div>
                <!-- Filter: Nama Anak -->
                <div>
                    <label for="filter-nama_anak" class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Nama
                        Anak</label>
                    <input type="text" id="filter-nama_anak" name="nama_anak" value="<?php echo appEscape($nama_anak_filter); ?>"
                        placeholder="Cari nama anak..."
                        class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-xl px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none transition">
                </div>
                <!-- Filter: Kelas -->
                <div>
                    <label for="filter-kelas" class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Kelas
                        Anak</label>
                    <select id="filter-kelas" name="kelas"
                        class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-xl px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none transition">
                        <option value="">Semua Kelas</option>
                        <?php foreach ($daftar_kelas as $k): ?>
                            <option value="<?php echo appEscape($k); ?>" <?php echo $kelas_filter === $k ? 'selected' : ''; ?>>
                                <?php echo appEscape($k); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Filter: Kategori -->
                <div>
                    <label for="filter-kategori"
                        class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Kategori</label>
                    <select id="filter-kategori" name="kategori"
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
                    <label for="filter-halaqoh"
                        class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Halaqoh</label>
                    <select id="filter-halaqoh" name="halaqoh"
                        class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-xl px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none transition">
                        <option value="">Semua Halaqoh</option>
                        <?php foreach ($daftar_halaqoh as $hq): ?>
                            <option value="<?php echo $hq['id']; ?>" <?php echo $halaqoh_filter == $hq['id'] ? 'selected' : ''; ?>>
                                <?php echo appEscape($hq['nama_halaqoh']); ?>
                                (<?php echo appEscape($hq['nama_ustadz']); ?>)
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
                    <span class="text-xs text-slate-400 italic ml-auto">Ditemukan <?php echo $totalParticipants; ?>
                        hasil</span>
                <?php endif; ?>
            </div>
        </form>
    </details>

    <?php if ($message): ?>
        <div class="bg-emerald-50 text-emerald-600 p-4 rounded-xl mb-6 flex items-center border border-emerald-100 italic">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <?php echo appEscape($message); ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="bg-red-50 text-red-700 p-4 rounded-xl mb-6 border border-red-200" role="alert">
            <?php echo appEscape($error); ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead
                    class="bg-slate-50 border-b border-slate-100 text-slate-500 uppercase text-xs font-bold tracking-wider">
                    <tr>
                        <th class="px-6 py-4 text-nowrap">Nama Wali (Bapak / Ibu)</th>
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
                        <tr data-participant-id="<?= (int)$p['id'] ?>" class="hover:bg-slate-50 transition">
                            <td class="px-6 py-4 font-bold text-slate-800 align-middle">
                                <?php echo appEscape($p['nama_bapak']); ?>
                                <?php if (empty($p['status_aktif'])): ?>
                                    <span class="ml-1 px-2 py-0.5 rounded-lg bg-slate-200 text-slate-500 text-[9px] font-bold uppercase tracking-wider align-middle">Arsip</span>
                                <?php endif; ?>
                                <?php if (!empty($p['lanjut_tahsin'])): ?>
                                    <span class="ml-1 px-2 py-0.5 rounded-lg bg-emerald-100 text-emerald-700 text-[9px] font-bold uppercase tracking-wider align-middle">Lanjut Tahsin</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 align-middle">
                                <span
                                    class="px-3 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider <?php echo $p['kategori'] === 'reguler' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'; ?>">
                                    <?php echo appEscape(str_replace('_', ' ', (string)$p['kategori'])); ?>
                                </span>
                                <?php if ($p['kategori'] === 'tahsin_luar'): ?>
                                    <div class="mt-1 text-[9px] text-slate-400 italic leading-tight">
                                        Di: <?php echo appEscape($p['tempat_tahsin']); ?><br>
                                        Ustadz: <?php echo appEscape($p['ustadz_luar']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 align-middle max-w-xs break-words">
                                <?php $children=$participantChildren[(int)$p['id']]??[]; if (!$children): ?>Belum ada anak.
                                <?php else: if (count($children)>2): ?><details><summary class="cursor-pointer font-bold py-2"><?= count($children) ?> anak — lihat semua</summary><?php endif; ?>
                                    <ul class="space-y-2"><?php foreach ($children as $child): ?><li><?= appEscape($child['nama_anak']) ?> (<?= appEscape(($child['kelas']??'')!==''?$child['kelas']:'kelas belum diisi') ?>)</li><?php endforeach; ?></ul>
                                <?php if (count($children)>2): ?></details><?php endif; endif; ?>
                            </td>
                            <td class="px-6 py-4 align-middle text-nowrap">
                                <?php if (!empty($participantMemberships[(int)$p['id']])): ?>
                                    <ul class="space-y-3"><?php foreach ($participantMemberships[(int)$p['id']] as $membership): ?><li><div class="text-sm font-semibold text-slate-700"><?= appEscape($membership['nama_halaqoh']) ?></div><p class="text-xs text-slate-500"><?= appEscape(ucfirst($membership['program_code']).' — '.$membership['nama_lengkap']) ?></p></li><?php endforeach; ?></ul>
                                <?php else: ?>
                                    <span class="text-xs text-slate-300 italic">Belum ada</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-slate-500 text-sm align-middle">
                                <div class="flex items-center gap-2">
                                    <div class="flex flex-col">
                                        <span
                                            class="font-bold text-slate-700"><?php echo appEscape($p['no_hp']); ?></span>
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
                                    <a href="peserta-form.php?id=<?= (int)$p['id'] ?>" class="text-blue-700 border border-blue-200 rounded-xl px-3 py-2">Edit</a>
                                    <a href="peserta-form.php?id=<?= (int)$p['id'] ?>&amp;mode=archive" class="text-red-700 border border-red-200 rounded-xl px-3 py-2">Arsipkan</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <nav aria-label="Halaman daftar peserta" class="flex flex-wrap items-center gap-4 py-5"><p>Menampilkan <?= count($peserta) ?> dari <?= $totalParticipants ?> peserta · halaman <?= $page ?> dari <?= $pages ?></p><?php if ($page>1): ?><a class="underline text-blue-700 py-3" href="<?= appEscape($pageUrl($page-1)) ?>">Sebelumnya</a><?php endif; ?><?php if ($page<$pages): ?><a class="underline text-blue-700 py-3" href="<?= appEscape($pageUrl($page+1)) ?>">Berikutnya</a><?php endif; ?></nav>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
