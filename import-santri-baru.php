<?php
/**
 * Import Santri Baru (Tahun Ajaran Baru) — dengan deteksi kakak-beradik.
 *
 * Alur:
 *   1. Sumber data: file CSV `data_santri_baru.csv` (jika ada di root) ATAU upload via form.
 *      Kolom CSV: nama_bapak, no_hp, nama_anak, kelas, halaqoh
 *   2. Preview: tiap baris dicocokkan ke wali_santri yang sudah ada berdasarkan
 *      nama_bapak (case-insensitive, trim) + no_hp (dinormalisasi: digit saja, 0->62).
 *      - Cocok & anak belum ada  -> "Adik" -> akan ditempel ke wali existing (tidak bikin wali baru).
 *      - Cocok & anak sudah ada   -> "Duplikat" -> di-skip.
 *      - Tidak cocok               -> "Wali baru" -> bikin wali_santri baru.
 *   3. Execute (setelah konfirmasi): transaksi INSERT santri_detail (untuk adik) /
 *      INSERT wali_santri + santri_detail (untuk wali baru). Wali terarsip yang ketemu
 *      adiknya otomatis diaktifkan kembali. Dedup nama_anak per wali. addLog.
 */
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

checkRole(['admin', 'pj_tahfidz']);

$pageTitle = 'Import Santri Baru (Deteksi Kakak-Adik)';

/** Normalisasi no HP: hanya digit, leading 0 -> 62. '' jika kosong/tidak valid. */
function normalizeHp(string $hp): string
{
    $d = preg_replace('/[^0-9]/', '', $hp);
    if ($d === '') {
        return '';
    }
    if (str_starts_with($d, '62')) {
        return $d;
    }
    if (str_starts_with($d, '0')) {
        return '62' . substr($d, 1);
    }
    return $d;
}

/** Normalisasi nama: lower, trim, collapse spasi ganda. */
function normName(string $n): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', $n)));
}

/** Baca CSV (handle BOM, delimiter ',' atau ';'). Kembalikan array asosiatif baris. */
function parseCsvFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $contents = file_get_contents($path);
    // hapus BOM
    $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
    // deteksi delimiter
    $delim = (substr_count($contents, ';') > substr_count($contents, ',')) ? ';' : ',';
    $rows = [];
    $tmp = tmpfile();
    fwrite($tmp, $contents);
    fseek($tmp, 0);
    $header = fgetcsv($tmp, 0, $delim, '"', '');
    if (!$header) {
        fclose($tmp);
        return [];
    }
    $header = array_map(fn($h) => strtolower(trim($h)), $header);
    while (($data = fgetcsv($tmp, 0, $delim, '"', '')) !== false) {
        if (count($data) === 1 && $data[0] === '') {
            continue;
        }
        $assoc = [];
        foreach ($header as $i => $col) {
            $assoc[$col] = trim($data[$i] ?? '');
        }
        $rows[] = $assoc;
    }
    fclose($tmp);
    return $rows;
}

/** Sumber file aktif: upload (session) jika ada, kalau tidak pakai data_santri_baru.csv di root. */
function activeSourceFile(): string
{
    if (!empty($_SESSION['import_baru_file']) && is_file($_SESSION['import_baru_file'])) {
        return $_SESSION['import_baru_file'];
    }
    $default = __DIR__ . '/data_santri_baru.csv';
    return is_file($default) ? $default : '';
}

/**
 * Hitung disposition tiap baris CSV terhadap DB live.
 * Kembalikan: ['rows' => [...], 'counts' => [...], 'new_families' => [...]]
 * Tiap row: [nama_bapak, no_hp, nama_anak, kelas, halaqoh, status, target_wali_id, kakak, catatan]
 *   status: 'attach' | 'new' | 'dup' | 'error'
 */
function computeDispositions(PDO $pdo, array $csvRows): array
{
    // Map wali existing: key = normName(nama_bapak).'|'.normalizeHp(no_hp)
    $waliAll = $pdo->query("SELECT id, nama_bapak, no_hp, status_aktif FROM wali_santri")->fetchAll(PDO::FETCH_ASSOC);
    $waliMap = [];
    foreach ($waliAll as $w) {
        $key = normName((string) $w['nama_bapak']) . '|' . normalizeHp((string) $w['no_hp']);
        if (!isset($waliMap[$key])) {
            $waliMap[$key] = $w;
        }
    }

    // Anak existing per wali: wali_id => [normName(nama_anak), ...]
    $children = $pdo->query("SELECT wali_santri_id, nama_anak FROM santri_detail")->fetchAll(PDO::FETCH_ASSOC);
    $childMap = [];
    foreach ($children as $c) {
        $childMap[(int) $c['wali_santri_id']][] = normName((string) $c['nama_anak']);
    }

    // Halaqoh by nama (case-insensitive)
    $halaqohRows = $pdo->query("SELECT id, nama_halaqoh FROM halaqoh")->fetchAll(PDO::FETCH_ASSOC);
    $halaqohMap = [];
    foreach ($halaqohRows as $h) {
        $halaqohMap[normName((string) $h['nama_halaqoh'])] = (int) $h['id'];
    }

    $result = [];
    $counts = ['attach' => 0, 'new' => 0, 'dup' => 0, 'error' => 0, 'new_families' => 0];
    $newFamilyGroups = []; // key => ['rows' => [idx...], 'halaqoh_id' => int|null]

    foreach ($csvRows as $idx => $r) {
        $nama_bapak = trim($r['nama_bapak'] ?? '');
        $no_hp = trim($r['no_hp'] ?? '');
        $nama_anak = trim($r['nama_anak'] ?? '');
        $kelas = trim($r['kelas'] ?? '');
        $halaqoh = trim($r['halaqoh'] ?? '');
        if ($kelas === '') {
            $kelas = 'Mustawa 1';
        }

        $rowOut = [
            'nama_bapak' => $nama_bapak,
            'no_hp' => $no_hp,
            'nama_anak' => $nama_anak,
            'kelas' => $kelas,
            'halaqoh' => $halaqoh,
            'status' => 'error',
            'target_wali_id' => null,
            'kakak' => '',
            'catatan' => '',
        ];

        if ($nama_bapak === '' || $nama_anak === '') {
            $rowOut['catatan'] = 'nama_bapak atau nama_anak kosong';
            $counts['error']++;
            $result[] = $rowOut;
            continue;
        }

        $hp = normalizeHp($no_hp);
        if ($hp === '') {
            // no_hp kosong -> tidak bisa deteksi kakak, anggap wali baru (perlu verifikasi manual)
            $key = normName($nama_bapak) . '|';
            $rowOut['status'] = 'new';
            $rowOut['catatan'] = 'no_hp kosong — tidak bisa deteksi kakak, dibuat wali baru (verifikasi manual)';
            $counts['new']++;
            if (!isset($newFamilyGroups[$key])) {
                $newFamilyGroups[$key] = ['rows' => [], 'halaqoh_id' => null];
                $counts['new_families']++;
            }
            $newFamilyGroups[$key]['rows'][] = $idx;
            if ($halaqoh !== '' && isset($halaqohMap[normName($halaqoh)])) {
                $newFamilyGroups[$key]['halaqoh_id'] = $halaqohMap[normName($halaqoh)];
            }
            $result[] = $rowOut;
            continue;
        }

        $key = normName($nama_bapak) . '|' . $hp;
        if (isset($waliMap[$key])) {
            $wali = $waliMap[$key];
            $existingChildren = $childMap[(int) $wali['id']] ?? [];
            if (in_array(normName($nama_anak), $existingChildren, true)) {
                $rowOut['status'] = 'dup';
                $rowOut['target_wali_id'] = (int) $wali['id'];
                $rowOut['catatan'] = 'anak sudah ada di wali ini -> skip';
                $counts['dup']++;
            } else {
                $rowOut['status'] = 'attach';
                $rowOut['target_wali_id'] = (int) $wali['id'];
                // daftar kakak = nama anak existing di wali itu
                $kakakNames = $pdo->prepare("SELECT nama_anak, kelas FROM santri_detail WHERE wali_santri_id = ? ORDER BY id");
                $kakakNames->execute([$wali['id']]);
                $kakakList = array_map(fn($c) => $c['nama_anak'] . ' (' . $c['kelas'] . ')', $kakakNames->fetchAll(PDO::FETCH_ASSOC));
                $rowOut['kakak'] = implode(', ', $kakakList);
                $rowOut['catatan'] = ((int) $wali['status_aktif'] === 0) ? 'wali saat ini terarsip -> akan diaktifkan kembali' : '';
                $counts['attach']++;
            }
            $result[] = $rowOut;
            continue;
        }

        // wali baru
        $rowOut['status'] = 'new';
        $counts['new']++;
        if (!isset($newFamilyGroups[$key])) {
            $newFamilyGroups[$key] = ['rows' => [], 'halaqoh_id' => null];
            $counts['new_families']++;
        }
        $newFamilyGroups[$key]['rows'][] = $idx;
        if ($halaqoh !== '' && isset($halaqohMap[normName($halaqoh)])) {
            $newFamilyGroups[$key]['halaqoh_id'] = $halaqohMap[normName($halaqoh)];
        }
        $result[] = $rowOut;
    }

    return ['rows' => $result, 'counts' => $counts, 'new_families' => $newFamilyGroups, 'halaqoh_map' => $halaqohMap];
}

// ---------------------------------------------------------------------------
// POST handler
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'upload' && isset($_FILES['file_csv'])) {
        $filename = $_FILES['file_csv']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            redirectTo('import-santri-baru.php?err=' . rawurlencode('Hanya file CSV yang diizinkan.'));
        }
        if ($_FILES['file_csv']['error'] !== UPLOAD_ERR_OK) {
            redirectTo('import-santri-baru.php?err=' . rawurlencode('Gagal upload file.'));
        }
        // simpan ke temp_excel/
        $tempDir = __DIR__ . '/temp_excel';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }
        $dest = $tempDir . '/import_baru_' . bin2hex(random_bytes(8)) . '.csv';
        if (!move_uploaded_file($_FILES['file_csv']['tmp_name'], $dest)) {
            redirectTo('import-santri-baru.php?err=' . rawurlencode('Gagal menyimpan file upload.'));
        }
        $_SESSION['import_baru_file'] = $dest;
        redirectTo('import-santri-baru.php?msg=' . rawurlencode('File CSV dimuat. Tinjau laporan preview di bawah.'));
    }

    if ($action === 'cancel') {
        if (!empty($_SESSION['import_baru_file']) && is_file($_SESSION['import_baru_file'])) {
            @unlink($_SESSION['import_baru_file']);
        }
        unset($_SESSION['import_baru_file']);
        redirectTo('import-santri-baru.php');
    }

    if ($action === 'execute') {
        $source = activeSourceFile();
        if ($source === '') {
            redirectTo('import-santri-baru.php?err=' . rawurlencode('Tidak ada file sumber. Upload CSV atau letakkan data_santri_baru.csv.'));
        }
        $csvRows = parseCsvFile($source);
        if (!$csvRows) {
            redirectTo('import-santri-baru.php?err=' . rawurlencode('File CSV kosong atau tidak terbaca.'));
        }

        // hitung ulang disposition dari DB live (jangan percaya input POST)
        $disp = computeDispositions($pdo, $csvRows);
        $rows = $disp['rows'];
        $newFamilies = $disp['new_families'];
        $halaqohMap = $disp['halaqoh_map'];

        $attached = 0;
        $newWali = 0;
        $newChildren = 0;
        $skipped = 0;

        try {
            $pdo->beginTransaction();

            $stmtInsertChild = $pdo->prepare("INSERT INTO santri_detail (wali_santri_id, nama_anak, kelas) VALUES (?, ?, ?)");
            $stmtReactivate = $pdo->prepare("UPDATE wali_santri SET status_aktif = 1 WHERE id = ? AND status_aktif = 0");

            // 1. Adik -> tempel ke wali existing
            foreach ($rows as $r) {
                if ($r['status'] === 'attach') {
                    $stmtReactivate->execute([$r['target_wali_id']]);
                    $stmtInsertChild->execute([$r['target_wali_id'], $r['nama_anak'], $r['kelas']]);
                    $attached++;
                }
            }

            // 2. Wali baru (satu wali per keluarga) + anak-anaknya
            $stmtInsertWali = $pdo->prepare("INSERT INTO wali_santri (nama_bapak, no_hp, kategori) VALUES (?, ?, 'reguler')");
            $stmtInsertHm = $pdo->prepare("INSERT IGNORE INTO halaqoh_members (halaqoh_id, wali_santri_id) VALUES (?, ?)");
            foreach ($newFamilies as $key => $fam) {
                // ambil nama_bapak & no_hp dari baris pertama grup
                $firstRow = $rows[$fam['rows'][0]];
                $stmtInsertWali->execute([$firstRow['nama_bapak'], $firstRow['no_hp']]);
                $waliId = (int) $pdo->lastInsertId();
                $newWali++;
                foreach ($fam['rows'] as $idx) {
                    $r = $rows[$idx];
                    $stmtInsertChild->execute([$waliId, $r['nama_anak'], $r['kelas']]);
                    $newChildren++;
                }
                if (!empty($fam['halaqoh_id'])) {
                    $stmtInsertHm->execute([$fam['halaqoh_id'], $waliId]);
                }
            }

            // 3. dup/error -> skip (hanya hitung)
            foreach ($rows as $r) {
                if ($r['status'] === 'dup' || $r['status'] === 'error') {
                    $skipped++;
                }
            }

            addLog($pdo, 'IMPORT_SANTRI_BARU', "Adik ditempel: $attached, Wali baru: $newWali, Anak baru di wali baru: $newChildren, Skip: $skipped");
            $pdo->commit();

            // bersihkan temp upload
            if (!empty($_SESSION['import_baru_file']) && is_file($_SESSION['import_baru_file'])) {
                @unlink($_SESSION['import_baru_file']);
            }
            unset($_SESSION['import_baru_file']);

            $msg = "Import selesai. $attached adik ditempel ke wali existing, $newWali wali baru dibuat ($newChildren anak), $skipped baris di-skip (duplikat/error).";
            redirectTo('import-santri-baru.php?done=' . rawurlencode($msg));
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            redirectTo('import-santri-baru.php?err=' . rawurlencode('Gagal import: ' . $e->getMessage()));
        }
    }

    redirectTo('import-santri-baru.php');
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------
$message = $_GET['msg'] ?? '';
$done = $_GET['done'] ?? '';
$error = $_GET['err'] ?? '';
$source = activeSourceFile();
$csvRows = $source !== '' ? parseCsvFile($source) : [];
$disp = $csvRows ? computeDispositions($pdo, $csvRows) : ['rows' => [], 'counts' => ['attach' => 0, 'new' => 0, 'dup' => 0, 'error' => 0, 'new_families' => 0], 'new_families' => [], 'halaqoh_map' => []];
$previewRows = $disp['rows'];
$counts = $disp['counts'];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="mb-6">
    <a href="peserta.php" class="inline-flex items-center text-slate-400 hover:text-blue-600 transition mb-3">
        <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
        </svg>
        Kembali ke Data Peserta
    </a>
    <h2 class="text-2xl font-bold text-slate-800">Import Santri Baru (Deteksi Kakak-Adik)</h2>
    <p class="text-slate-500 text-sm mt-1">Tahun ajaran baru. Cocokkan santri baru ke wali yang sudah ada berdasarkan
        <strong>nama bapak + no HP</strong>. Yang punya kakak akan ditempel sebagai adik (tanpa bikin wali baru).</p>
</div>

<?php if ($done): ?>
    <div class="bg-emerald-50 text-emerald-700 p-4 rounded-xl mb-6 flex items-center border border-emerald-100 font-semibold">
        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
        </svg>
        <?php echo htmlspecialchars($done); ?>
    </div>
<?php endif; ?>
<?php if ($message): ?>
    <div class="bg-blue-50 text-blue-700 p-4 rounded-xl mb-6 flex items-center border border-blue-100 font-semibold">
        ℹ️ <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="bg-red-50 text-red-600 p-4 rounded-xl mb-6 border border-red-100 font-semibold">
        ⚠️ <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<!-- Sumber data info -->
<div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5 mb-6">
    <h3 class="font-bold text-slate-800 mb-2">Sumber Data CSV</h3>
    <?php if ($source !== ''): ?>
        <div class="flex items-center gap-3 text-sm">
            <span class="bg-emerald-100 text-emerald-700 px-3 py-1 rounded-lg font-bold text-xs">
                ✓ Sumber aktif
            </span>
            <span class="text-slate-600 font-mono text-xs break-all">
                <?php echo htmlspecialchars(basename($source) === 'data_santri_baru.csv' ? 'data_santri_baru.csv (di root project)' : basename($source) . ' (hasil upload)'); ?>
            </span>
            <span class="text-slate-400 text-xs"><?php echo count($csvRows); ?> baris</span>
        </div>
        <?php if (!empty($_SESSION['import_baru_file'])): ?>
            <form method="POST" class="inline mt-3">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="text-slate-500 hover:text-red-600 text-xs font-semibold underline">hapus file upload &amp; ganti</button>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <p class="text-sm text-slate-500 mb-3">Belum ada sumber data. Upload file CSV, atau letakkan file
            <code class="bg-slate-100 px-1.5 py-0.5 rounded text-xs">data_santri_baru.csv</code> di root project.</p>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="flex items-end gap-3 mt-4">
        <?php csrfField(); ?>
        <input type="hidden" name="action" value="upload">
        <div>
            <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Upload CSV Santri Baru</label>
            <input type="file" name="file_csv" accept=".csv" required
                class="text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 border border-slate-200 rounded-xl p-2">
        </div>
        <button type="submit" class="bg-blue-600 text-white px-5 py-2.5 rounded-xl font-bold text-sm hover:bg-blue-700 transition">Muat &amp; Preview</button>
        <a href="template_santri_baru.csv" download class="ml-auto text-slate-500 hover:text-blue-600 text-sm font-semibold underline">Download template CSV</a>
    </form>
    <p class="text-xs text-slate-400 mt-3">Kolom CSV: <code>nama_bapak, no_hp, nama_anak, kelas, halaqoh</code>.
        <code>kelas</code> boleh kosong (default "Mustawa 1"). <code>halaqoh</code> opsional (isi nama halaqoh).</p>
</div>

<?php if ($csvRows): ?>
    <!-- Ringkasan -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
        <div class="bg-white rounded-2xl border border-slate-100 p-4 text-center shadow-sm">
            <div class="text-2xl font-bold text-blue-600"><?php echo $counts['attach']; ?></div>
            <div class="text-[10px] text-slate-500 font-semibold uppercase">Adik ditempel</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-100 p-4 text-center shadow-sm">
            <div class="text-2xl font-bold text-emerald-600"><?php echo $counts['new_families']; ?></div>
            <div class="text-[10px] text-slate-500 font-semibold uppercase">Wali baru</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-100 p-4 text-center shadow-sm">
            <div class="text-2xl font-bold text-emerald-600"><?php echo $counts['new']; ?></div>
            <div class="text-[10px] text-slate-500 font-semibold uppercase">Anak di wali baru</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-100 p-4 text-center shadow-sm">
            <div class="text-2xl font-bold text-slate-400"><?php echo $counts['dup']; ?></div>
            <div class="text-[10px] text-slate-500 font-semibold uppercase">Duplikat (skip)</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-100 p-4 text-center shadow-sm">
            <div class="text-2xl font-bold text-red-500"><?php echo $counts['error']; ?></div>
            <div class="text-[10px] text-slate-500 font-semibold uppercase">Error</div>
        </div>
    </div>

    <?php if ($counts['error'] > 0): ?>
        <div class="bg-red-50 border border-red-100 rounded-xl p-4 mb-6 text-sm text-red-700">
            Ada <strong><?php echo $counts['error']; ?> baris error</strong> (nama_bapak/nama_anak kosong). Baris ini tidak akan diproses. Perbaiki CSV lalu upload ulang.
        </div>
    <?php endif; ?>

    <!-- Tabel preview -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden mb-6">
        <div class="p-5 border-b border-slate-100 flex items-center justify-between">
            <h3 class="font-bold text-slate-800">Laporan Preview (verifikasi sebelum eksekusi)</h3>
            <form method="POST" onsubmit="return confirm('Eksekusi import? Adik akan ditempel ke wali existing, wali baru akan dibuat. Proses tidak bisa dibatalkan.')">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="execute">
                <button type="submit"
                    class="bg-blue-600 text-white px-5 py-2.5 rounded-xl font-bold text-sm hover:bg-blue-700 transition shadow-sm flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    Eksekusi Import
                </button>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="bg-slate-50 border-b border-slate-100 text-slate-500 uppercase text-xs font-bold tracking-wider">
                    <tr>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Nama Bapak</th>
                        <th class="px-4 py-3">No HP</th>
                        <th class="px-4 py-3">Nama Anak (baru)</th>
                        <th class="px-4 py-3">Kelas</th>
                        <th class="px-4 py-3">Kakak (existing)</th>
                        <th class="px-4 py-3">Catatan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($previewRows as $r): ?>
                        <tr class="hover:bg-slate-50 transition">
                            <td class="px-4 py-3 align-middle">
                                <?php if ($r['status'] === 'attach'): ?>
                                    <span class="px-2.5 py-1 rounded-lg bg-blue-100 text-blue-700 font-bold text-[10px] uppercase tracking-wider">Adik</span>
                                <?php elseif ($r['status'] === 'new'): ?>
                                    <span class="px-2.5 py-1 rounded-lg bg-emerald-100 text-emerald-700 font-bold text-[10px] uppercase tracking-wider">Wali Baru</span>
                                <?php elseif ($r['status'] === 'dup'): ?>
                                    <span class="px-2.5 py-1 rounded-lg bg-slate-200 text-slate-500 font-bold text-[10px] uppercase tracking-wider">Duplikat</span>
                                <?php else: ?>
                                    <span class="px-2.5 py-1 rounded-lg bg-red-100 text-red-700 font-bold text-[10px] uppercase tracking-wider">Error</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 font-semibold text-slate-800 align-middle"><?php echo htmlspecialchars($r['nama_bapak']); ?></td>
                            <td class="px-4 py-3 text-sm text-slate-500 align-middle"><?php echo htmlspecialchars($r['no_hp'] ?: '-'); ?></td>
                            <td class="px-4 py-3 text-sm text-slate-700 align-middle"><?php echo htmlspecialchars($r['nama_anak']); ?></td>
                            <td class="px-4 py-3 text-sm text-slate-600 align-middle"><?php echo htmlspecialchars($r['kelas']); ?></td>
                            <td class="px-4 py-3 text-sm text-slate-500 align-middle max-w-xs"><?php echo htmlspecialchars($r['kakak'] ?: '-'); ?></td>
                            <td class="px-4 py-3 text-xs text-slate-400 align-middle"><?php echo htmlspecialchars($r['catatan']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-amber-50 border border-amber-100 rounded-2xl p-4 text-sm text-amber-800">
        <strong>Sebelum eksekusi:</strong> periksa baris berstatus <strong>Adik</strong> — pastikan kolom "Kakak" benar-benar saudara dari anak baru.
        Baris <strong>Wali Baru</strong> dengan catatan "no_hp kosong" tidak bisa dideteksi kakaknya — pastikan memang keluarga baru, atau tambahkan no_hp di CSV lalu upload ulang.
    </div>
<?php else: ?>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-8 text-center text-slate-400 italic">
        Belum ada data untuk dipreview. Upload CSV santri baru atau letakkan file <code>data_santri_baru.csv</code> di root project.
    </div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>