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
require_once 'includes/alumni_archive_helper.php';
require_once 'includes/database_helper.php';
require_once 'includes/participant_identity_helper.php';
require_once 'includes/import_storage_helper.php';
require_once 'includes/legacy_student_import_helper.php';

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
    // Keep all matches: an old database may already contain duplicate identities.
    $waliAll = $pdo->query("SELECT id, nama_bapak, no_hp, status_aktif FROM wali_santri ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $waliMap = [];
    $waliByName = [];
    foreach ($waliAll as $w) {
        $nameKey = normName((string) $w['nama_bapak']);
        $phoneKey = normalizeHp((string) $w['no_hp']);
        $waliByName[$nameKey][] = $w;
        if ($phoneKey !== '') $waliMap[$nameKey . '|' . $phoneKey][] = $w;
    }
    $managedWaliIds = participantManagedIds($pdo);

    // Anak existing per wali: wali_id => [normName(nama_anak), ...]
    $children = $pdo->query("SELECT wali_santri_id, nama_anak FROM santri_detail ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $childMap = [];
    foreach ($children as $c) {
        $childMap[(int) $c['wali_santri_id']][normName((string) $c['nama_anak'])] = true;
    }

    // Halaqoh by nama (case-insensitive)
    $halaqohRows = $pdo->query("SELECT id, nama_halaqoh FROM halaqoh ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $halaqohMatches = [];
    foreach ($halaqohRows as $h) {
        $halaqohMatches[normName((string) $h['nama_halaqoh'])][] = (int) $h['id'];
    }
    $halaqohMap = [];
    foreach ($halaqohMatches as $key => $ids) if (count($ids) === 1) $halaqohMap[$key] = $ids[0];

    $result = [];
    $counts = ['attach' => 0, 'new' => 0, 'dup' => 0, 'error' => 0, 'new_families' => 0];
    $newFamilyGroups = []; // key => ['rows' => [idx...], 'halaqoh_id' => int|null]
    $blankPhoneNameCounts = [];
    foreach ($csvRows as $row) {
        $rowPhone = normalizeHp(trim((string) ($row['no_hp'] ?? '')));
        $rowName = normName(trim((string) ($row['nama_bapak'] ?? '')));
        if ($rowName !== '' && $rowPhone === '') $blankPhoneNameCounts[$rowName] = ($blankPhoneNameCounts[$rowName] ?? 0) + 1;
    }

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
            $nameKey = normName($nama_bapak);
            if ($no_hp !== '') {
                $rowOut['catatan'] = 'nomor HP tidak berisi angka yang dapat dibaca; perbaiki CSV sebelum impor';
                $counts['error']++;
                $result[] = $rowOut;
                continue;
            }
            if (!empty($waliByName[$nameKey])) {
                $rowOut['catatan'] = 'nomor HP kosong dan nama wali sudah ada; pemadanan otomatis ditolak agar anak tidak masuk ke wali yang keliru';
                $counts['error']++;
                $result[] = $rowOut;
                continue;
            }
            if (($blankPhoneNameCounts[$nameKey] ?? 0) > 1) {
                $rowOut['catatan'] = 'nama wali tanpa nomor HP muncul beberapa kali; isi nomor HP agar identitas keluarga tidak ditebak';
                $counts['error']++;
                $result[] = $rowOut;
                continue;
            }
            $key = $nameKey . '|';
            $rowOut['status'] = 'new';
            $rowOut['catatan'] = 'no_hp kosong — wali baru perlu verifikasi manual';
            if (!isset($newFamilyGroups[$key])) {
                $newFamilyGroups[$key] = ['rows' => [], 'halaqoh_id' => null];
                $counts['new_families']++;
            }
            $childKey = normName($nama_anak);
            if (isset($newFamilyGroups[$key]['children'][$childKey])) {
                $rowOut['status'] = 'dup';
                $rowOut['catatan'] = 'anak yang sama muncul lebih dari sekali pada berkas; baris berikutnya dilewati';
                $counts['dup']++;
            } else {
                $newFamilyGroups[$key]['children'][$childKey] = true;
                $newFamilyGroups[$key]['rows'][] = $idx;
                $counts['new']++;
            }
            $result[] = $rowOut;
            continue;
        }

        $key = normName($nama_bapak) . '|' . $hp;
        if (isset($waliMap[$key])) {
            if (count($waliMap[$key]) !== 1) {
                $rowOut['catatan'] = 'beberapa wali memiliki nama dan nomor HP yang sama; pemadanan otomatis ditolak';
                $counts['error']++;
                $result[] = $rowOut;
                continue;
            }
            $wali = $waliMap[$key][0];
            $childKey = normName($nama_anak);
            if (isset($childMap[(int) $wali['id']][$childKey])) {
                $rowOut['status'] = 'dup';
                $rowOut['target_wali_id'] = (int) $wali['id'];
                $rowOut['catatan'] = 'anak sudah ada di wali ini -> skip';
                $counts['dup']++;
            } elseif (isset($managedWaliIds[(int) $wali['id']])) {
                $rowOut['catatan'] = 'wali dikelola master keluarga/Data Induk; gunakan alur impor peserta agar hubungan sumber tidak berubah';
                $counts['error']++;
            } else {
                $rowOut['status'] = 'attach';
                $rowOut['target_wali_id'] = (int) $wali['id'];
                // daftar kakak = nama anak existing di wali itu
                $kakakNames = $pdo->prepare("SELECT nama_anak, kelas FROM santri_detail WHERE wali_santri_id = ? ORDER BY id");
                $kakakNames->execute([$wali['id']]);
                $kakakList = array_map(fn($c) => $c['nama_anak'] . ' (' . $c['kelas'] . ')', $kakakNames->fetchAll(PDO::FETCH_ASSOC));
                $rowOut['kakak'] = implode(', ', $kakakList);
                $rowOut['catatan'] = ((int) $wali['status_aktif'] === 0) ? 'wali saat ini terarsip -> akan diaktifkan kembali' : '';
                $childMap[(int) $wali['id']][$childKey] = true;
                $counts['attach']++;
            }
            $result[] = $rowOut;
            continue;
        }

        // wali baru
        $rowOut['status'] = 'new';
        if (!isset($newFamilyGroups[$key])) {
            $newFamilyGroups[$key] = ['rows' => [], 'halaqoh_id' => null];
            $counts['new_families']++;
        }
        $childKey = normName($nama_anak);
        if (isset($newFamilyGroups[$key]['children'][$childKey])) {
            $rowOut['status'] = 'dup';
            $rowOut['catatan'] = 'anak yang sama muncul lebih dari sekali pada berkas; baris berikutnya dilewati';
            $counts['dup']++;
        } else {
            $newFamilyGroups[$key]['children'][$childKey] = true;
            $newFamilyGroups[$key]['rows'][] = $idx;
            $counts['new']++;
        }
        $result[] = $rowOut;
    }

    // A family is assigned only when every requested group name resolves to the
    // same unique halaqoh. Ambiguous or conflicting labels never pick a group.
    foreach ($newFamilyGroups as &$family) {
        $requested = [];
        foreach ($family['rows'] as $rowIndex) {
            $label = trim((string) ($result[$rowIndex]['halaqoh'] ?? ''));
            if ($label !== '') $requested[normName($label)] = true;
        }
        $resolved = [];
        foreach (array_keys($requested) as $name) if (isset($halaqohMap[$name])) $resolved[$halaqohMap[$name]] = true;
        if (!$requested) $family['halaqoh_id'] = null;
        elseif (count($resolved) === 1 && count($resolved) === count($requested)) $family['halaqoh_id'] = (int) array_key_first($resolved);
        else {
            $family['halaqoh_id'] = null;
            foreach ($family['rows'] as $rowIndex) {
                $result[$rowIndex]['catatan'] = trim($result[$rowIndex]['catatan'] . ' Nama halaqoh tidak unik, tidak ditemukan, atau berbeda dalam satu keluarga; penempatan tidak dibuat otomatis.');
            }
        }
    }
    unset($family);

    return ['rows' => $result, 'counts' => $counts, 'new_families' => $newFamilyGroups, 'halaqoh_map' => $halaqohMap];
}

/** Bind the explicit confirmation to both the CSV and its current DB preview. */
function importSantriPreviewFingerprint(array $csvRows, array $disposition): string
{
    return hash('sha256', serialize([$csvRows, $disposition['rows'], $disposition['counts'], $disposition['new_families']]));
}

// ---------------------------------------------------------------------------
// POST handler
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken($_POST['csrf_token'] ?? '');
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

        $uploadTmp = (string) ($_FILES['file_csv']['tmp_name'] ?? '');
        if ($uploadTmp === '' || !is_uploaded_file($uploadTmp)) {
            error_log('Import santri baru: temporary upload tidak valid.');
            redirectTo('import-santri-baru.php?err=' . rawurlencode('File upload tidak valid atau sudah kedaluwarsa.'));
        }
        if ((int) ($_FILES['file_csv']['size'] ?? 0) > IMPORT_BARU_MAX_BYTES) {
            redirectTo('import-santri-baru.php?err=' . rawurlencode('Ukuran file melebihi 5 MB. Pecah berkas atau gunakan alur Impor Peserta.'));
        }

        $tempDir = resolveImportTempDir();
        if ($tempDir === '') {
            error_log('Import santri baru: tidak ada direktori upload yang writable.');
            redirectTo('import-santri-baru.php?err=' . rawurlencode('Penyimpanan upload belum siap. Hubungi administrator untuk memperbaiki izin folder temporary.'));
        }

        $dest = tempnam($tempDir, 'import_baru_');
        if ($dest === false || !move_uploaded_file($uploadTmp, $dest)) {
            if ($dest !== false && is_file($dest)) {
                @unlink($dest);
            }
            error_log('Import santri baru: gagal memindahkan upload ke direktori temporary: ' . $tempDir);
            redirectTo('import-santri-baru.php?err=' . rawurlencode('Gagal menyimpan file upload. Penyimpanan temporary tidak dapat ditulis.'));
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
        try { $csvRows = parseCsvFile($source); }
        catch (ImportSantriCsvException $failure) {
            redirectTo('import-santri-baru.php?err=' . rawurlencode($failure->getMessage()));
        }
        if (!$csvRows) {
            redirectTo('import-santri-baru.php?err=' . rawurlencode('File CSV kosong atau tidak terbaca.'));
        }
        $previewToken = appRequestString($_POST, 'preview_token');
        $previews = is_array($_SESSION['import_baru_previews'] ?? null) ? $_SESSION['import_baru_previews'] : [];
        $confirmedPreview = $previews[$previewToken] ?? null;
        $sourceHash = hash_file('sha256', $source);
        if (!preg_match('/^[a-f0-9]{32}$/D', $previewToken) || !is_array($confirmedPreview)
            || ($confirmedPreview['actor'] ?? null) !== (int) $_SESSION['user_id']
            || ($confirmedPreview['expires'] ?? 0) <= time()
            || !hash_equals((string) ($confirmedPreview['source'] ?? ''), $sourceHash)) {
            redirectTo('import-santri-baru.php?err=' . rawurlencode('Konfirmasi pratinjau berakhir atau sumber berubah. Periksa ulang seluruh baris sebelum menyimpan.'));
        }

        $attached = 0;
        $newWali = 0;
        $newChildren = 0;
        $skipped = 0;
        $archiveResult = ['archived_wali' => 0, 'archived_memberships' => 0];
        $lockName = participantWriteLockName($pdo);
        $lockAcquired = false;
        $originalSqlMode = null;
        $failure = null;
        try {
            $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
            $lock->execute([$lockName]);
            if ((int) $lock->fetchColumn() !== 1) {
                throw new RuntimeException('Perubahan peserta lain sedang berlangsung. Tunggu sebentar, lalu periksa ulang pratinjau.');
            }
            $lockAcquired = true;
            $originalSqlMode = appUseStrictSqlMode($pdo);
            $pdo->beginTransaction();
            $transactionalTables = ['wali_santri', 'santri_detail', 'halaqoh', 'halaqoh_members'];
            $historyEngine = $pdo->prepare("SELECT ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tahsin_roster_history' AND TABLE_TYPE='BASE TABLE'");
            $historyEngine->execute();
            if ($historyEngine->fetchColumn() !== false) $transactionalTables[] = 'tahsin_roster_history';
            appRequireTransactionalTables($pdo, $transactionalTables);

            $actor = $pdo->prepare('SELECT role FROM users WHERE id = ? FOR UPDATE');
            $actor->execute([(int) $_SESSION['user_id']]);
            if (!in_array($actor->fetchColumn(), ['admin', 'pj_tahfidz'], true)) {
                throw new RuntimeException('Hak impor peserta sudah berubah. Masuk kembali sebelum mencoba lagi.');
            }

            // Use the same mutex as participant forms and CSV imports, then lock
            // source rows before rebuilding the preview used for this write.
            $pdo->query('SELECT id FROM wali_santri ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            $pdo->query('SELECT id FROM santri_detail ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            $pdo->query('SELECT id FROM halaqoh ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);

            $disp = computeDispositions($pdo, $csvRows);
            if (!hash_equals($confirmedPreview['fingerprint'], importSantriPreviewFingerprint($csvRows, $disp))) {
                throw new RuntimeException('Data wali, anak, atau halaqoh berubah sejak pratinjau.');
            }
            $rows = $disp['rows'];
            $newFamilies = $disp['new_families'];

            $stmtInsertChild = $pdo->prepare("INSERT INTO santri_detail (wali_santri_id, nama_anak, kelas) VALUES (?, ?, ?)");
            $affectedWaliIds = [];

            // 1. Adik -> tempel ke wali existing
            foreach ($rows as $r) {
                if ($r['status'] === 'attach') {
                    $stmtInsertChild->execute([$r['target_wali_id'], $r['nama_anak'], $r['kelas']]);
                    $affectedWaliIds[] = (int) $r['target_wali_id'];
                    // Hanya anak aktif yang boleh mengaktifkan kembali wali arsip.
                    reactivateWaliIfHasActiveChild($pdo, (int) $r['target_wali_id']);
                    $attached++;
                }
            }

            // 2. Wali baru (satu wali per keluarga) + anak-anaknya
            $stmtInsertWali = $pdo->prepare("INSERT INTO wali_santri (nama_bapak, no_hp, kategori) VALUES (?, ?, 'reguler')");
            $stmtInsertHm = $pdo->prepare("INSERT INTO halaqoh_members (halaqoh_id, wali_santri_id) VALUES (?, ?)");
            foreach ($newFamilies as $key => $fam) {
                // ambil nama_bapak & no_hp dari baris pertama grup
                $firstRow = $rows[$fam['rows'][0]];
                $stmtInsertWali->execute([$firstRow['nama_bapak'], $firstRow['no_hp']]);
                $waliId = participantCaptureInsertedId($pdo);
                $affectedWaliIds[] = $waliId;
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

            $archiveResult = archiveEligibleAlumni($pdo, $affectedWaliIds, 'AUTO_IMPORT_SANTRI');

            // 3. dup/error -> skip (hanya hitung)
            foreach ($rows as $r) {
                if ($r['status'] === 'dup' || $r['status'] === 'error') {
                    $skipped++;
                }
            }

            addLog($pdo, 'IMPORT_SANTRI_BARU', json_encode([
                'attached' => $attached,
                'new_wali' => $newWali,
                'new_children' => $newChildren,
                'skipped' => $skipped,
                'auto_archived_wali' => $archiveResult['archived_wali'],
                'auto_archived_memberships' => $archiveResult['archived_memberships'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            reportApplicationError($e, 'import-santri-baru');
            $failure = $e;
        } finally {
            if ($originalSqlMode !== null) {
                try {
                    $pdo->prepare('SET SESSION sql_mode = ?')->execute([$originalSqlMode]);
                } catch (Throwable $restoreError) {
                    reportApplicationError($restoreError, 'import-santri-baru-sql-mode');
                }
            }
            if ($lockAcquired) {
                try {
                    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $release->execute([$lockName]);
                } catch (Throwable $releaseError) {
                    reportApplicationError($releaseError, 'import-santri-baru-lock');
                }
            }
        }

        if ($failure !== null) {
            redirectTo('import-santri-baru.php?err=' . rawurlencode('Impor belum selesai. Tidak ada perubahan parsial yang disimpan; periksa pratinjau lalu coba lagi.'));
        }

        // Only clear an uploaded source after the complete transaction commits.
        if (!empty($_SESSION['import_baru_file']) && is_file($_SESSION['import_baru_file'])) {
            @unlink($_SESSION['import_baru_file']);
        }
        unset($_SESSION['import_baru_file']);
        unset($_SESSION['import_baru_previews'][$previewToken]);

        $msg = "Import selesai. $attached adik ditempel ke wali existing, $newWali wali baru dibuat ($newChildren anak), $skipped baris di-skip (duplikat/error).";
        if ($archiveResult['archived_wali'] > 0) {
            $msg .= " {$archiveResult['archived_wali']} wali alumni otomatis diarsipkan.";
        }
        redirectTo('import-santri-baru.php?done=' . rawurlencode($msg));
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
$csvRows = [];
if ($source !== '') {
    try { $csvRows = parseCsvFile($source); }
    catch (ImportSantriCsvException $failure) { $error = $failure->getMessage(); }
}
$disp = $csvRows ? computeDispositions($pdo, $csvRows) : ['rows' => [], 'counts' => ['attach' => 0, 'new' => 0, 'dup' => 0, 'error' => 0, 'new_families' => 0], 'new_families' => [], 'halaqoh_map' => []];
$previewRows = $disp['rows'];
$counts = $disp['counts'];
$previewToken = '';
if ($csvRows && $source !== '') {
    $previewToken = bin2hex(random_bytes(16));
    $previews = is_array($_SESSION['import_baru_previews'] ?? null) ? $_SESSION['import_baru_previews'] : [];
    $previews = array_filter($previews, static fn($item) => is_array($item) && ($item['expires'] ?? 0) > time());
    $previews[$previewToken] = [
        'actor' => (int) $_SESSION['user_id'],
        'source' => hash_file('sha256', $source),
        'fingerprint' => importSantriPreviewFingerprint($csvRows, $disp),
        'expires' => time() + 1800,
    ];
    $_SESSION['import_baru_previews'] = array_slice($previews, -8, null, true);
}

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
                <input type="hidden" name="preview_token" value="<?php echo htmlspecialchars($previewToken, ENT_QUOTES, 'UTF-8'); ?>">
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
        Baris <strong>Wali Baru</strong> dengan catatan "no_hp kosong" tidak bisa dicocokkan otomatis — pastikan identitasnya sebelum menyimpan, atau tambahkan no_hp di CSV lalu upload ulang.
    </div>
<?php else: ?>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-8 text-center text-slate-400 italic">
        Belum ada data untuk dipreview. Upload CSV santri baru atau letakkan file <code>data_santri_baru.csv</code> di root project.
    </div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
