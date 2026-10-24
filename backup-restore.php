<?php
/**
 * Backup & Restore (ZIP CSV, in-app)
 *
 * Export : GET ?export=1  -> download ZIP berisi CSV per-tabel (preserve ID + relasi FK),
 *          manifest.json, schema.sql. NULL -> sentinel "\N".
 * Preview: POST action=preview (upload .zip) -> validasi + ringkasan baris, tanpa eksekusi.
 * Restore: POST action=restore -> safety backup otomatis lalu merge data baru
 *          (preserve-ID, skip konflik, tanpa DELETE/UPDATE data lama).
 * Download: GET ?download=<filename> -> whitelist file di temp_excel/ (safety backup).
 *
 * Semua base table ditemukan melalui metadata; induk diproses sebelum anak.
 *
 * Hanya admin / pj_tahfidz. Semua POST pakai CSRF + addLog.
 */
require_once 'config/database.php';
require_once 'includes/auth_helper.php';
require_once __DIR__ . '/includes/backup_helper.php';

checkRole(['admin', 'pj_tahfidz']);

$pageTitle = 'Backup & Restore';

// ---------------------------------------------------------------------------
// Konfigurasi
// ---------------------------------------------------------------------------
$BACKUP_TABLES = [];
$NULL_TOKEN   = "\\N"; // penanda NULL di CSV (konvensi mysqldump)
$MAX_UPLOAD_MB = min(512, max(1, (int) (getenv('BACKUP_MAX_UPLOAD_MB') ?: 100)));
$MAX_UPLOAD   = $MAX_UPLOAD_MB * 1024 * 1024;
try {
    $TEMP_DIR = resolveTempDir();
    purgeSafetyBackups($TEMP_DIR);
    $BACKUP_TABLES = discoverBackupTables($pdo);
} catch (Throwable $failure) {
    reportApplicationError($failure, 'backup-initialization');
    appRequestError(503, 'Cadangan belum tersedia', 'Periksa kembali halaman ini. Jika masalah berlanjut, hubungi pengelola untuk memeriksa penyimpanan cadangan.');
}

// ---------------------------------------------------------------------------
// GET handler: export
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === '1') {
    // Hash password hanya ikut diunduh oleh admin; PJ menerima salinan yang
    // kolom passwordnya dikosongkan agar tidak bisa di-crack offline.
    $maskPasswords = ($_SESSION['role'] ?? '') !== 'admin';
    $stamp    = date('Y-m-d_His') . '_' . bin2hex(random_bytes(16));
    $zipPath  = $TEMP_DIR . '/backup_' . $stamp . '.zip';
    $result   = createBackupZip($pdo, $zipPath, $BACKUP_TABLES, $NULL_TOKEN, $maskPasswords);
    if (!$result['ok']) {
        appRequestError(503, 'Cadangan belum dibuat', $result['error'] ?? 'Coba lagi atau hubungi pengelola.');
    }
    addLog($pdo, 'EXPORT_BACKUP', json_encode($result['counts']) . ($maskPasswords ? '; password-masked' : ''));
    // Bersihkan output buffer (ob_start dari auth_helper) & matikan kompresi
    // supaya stream ZIP tidak ter-truncate/korup (penyebab download 0 B).
    if (function_exists('gzclose')) { @ini_set('zlib.output_compression', '0'); }
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="backup_' . $stamp . '.zip"');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    header('Content-Transfer-Encoding: binary');
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

// ---------------------------------------------------------------------------
// GET handler: download safety backup dari temp_excel/
// ---------------------------------------------------------------------------
$dl = $_GET['download'] ?? '';
if ($dl !== '') {
    // Arsip di temp_excel memuat hash password asli (peran pemulihan data),
    // sehingga unduhannya dibatasi hanya untuk admin.
    if (($_SESSION['role'] ?? '') !== 'admin') {
        appRequestError(403, 'Unduhan arsip khusus admin',
            'Arsip cadangan penuh memuat kredensial akun sehingga hanya admin yang dapat mengunduhnya. Hubungi admin untuk mendapatkan salinan.');
    }
    $name = safeFilename($dl);
    $valid = $name !== null
        && preg_match('/^(backup_|backup_pre_restore_|restore_)[A-Za-z0-9_\-]+\.zip$/', $name)
        && !is_link($TEMP_DIR . '/' . $name)
        && is_file($TEMP_DIR . '/' . $name);
    if (!$valid) {
        http_response_code(404);
        exit('File tidak ditemukan / tidak valid.');
    }
    $path = $TEMP_DIR . '/' . $name;
    if (function_exists('gzclose')) { @ini_set('zlib.output_compression', '0'); }
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    header('Content-Transfer-Encoding: binary');
    readfile($path);
    exit;
}

// ---------------------------------------------------------------------------
// POST handler: preview & restore
// ---------------------------------------------------------------------------
$msg = is_string($_SESSION['backup_message'] ?? null) ? $_SESSION['backup_message'] : '';
unset($_SESSION['backup_message']);
$err = '';
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? '') ? ($_POST['action'] ?? '') : '';
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        $err = 'Sesi keamanan formulir berubah. Buka kembali halaman dan pilih ulang berkas cadangan. Permintaan ini belum diproses.';
    } elseif ($action === 'preview') {
        $previousUpload = $_SESSION['restore_zip'] ?? '';
        if (is_string($previousUpload) && dirname($previousUpload) === $TEMP_DIR && str_starts_with(basename($previousUpload), 'restore_') && !is_link($previousUpload) && is_file($previousUpload)) @unlink($previousUpload);
        unset($_SESSION['restore_zip'], $_SESSION['restore_token'], $_SESSION['restore_sha256']);
        if (!isset($_FILES['backup_file']) || !is_array($_FILES['backup_file']) || ($_FILES['backup_file']['error'] ?? null) !== UPLOAD_ERR_OK
            || !is_string($_FILES['backup_file']['name'] ?? null) || !is_string($_FILES['backup_file']['tmp_name'] ?? null)
            || !is_int($_FILES['backup_file']['size'] ?? null) || $_FILES['backup_file']['size'] < 1) {
            $err = 'Upload gagal. Pastikan file ZIP dipilih.';
        } else {
            $f   = $_FILES['backup_file'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if ($ext !== 'zip') {
                $err = 'File harus berformat .zip';
            } elseif ($f['size'] > $MAX_UPLOAD) {
                $err = 'Ukuran file melebihi ' . $MAX_UPLOAD_MB . 'MB.';
            } else {
                $stamp = date('Y-m-d_His') . '_' . bin2hex(random_bytes(16));
                $dest  = $TEMP_DIR . '/restore_' . $stamp . '.zip';
                if (!move_uploaded_file($f['tmp_name'], $dest)) {
                    $err = 'Gagal menyimpan file upload.';
                } else {
                    @chmod($dest, 0600);
                    $r = analyzeRestoreZip($pdo, $dest, $BACKUP_TABLES, $NULL_TOKEN);
                    if (!empty($r['error'])) {
                        $err = $r['error'];
                        @unlink($dest);
                    } else {
                        $_SESSION['restore_zip']   = $dest;
                        $_SESSION['restore_token'] = bin2hex(random_bytes(32));
                        $_SESSION['restore_sha256'] = hash_file('sha256', $dest);
                        $preview = $r;
                        addLog($pdo, 'RESTORE_PREVIEW', $r['summary']);
                    }
                }
            }
        }
    } elseif ($action === 'restore') {
        $dest  = $_SESSION['restore_zip']   ?? '';
        $token = $_SESSION['restore_token'] ?? '';
        $digest = $_SESSION['restore_sha256'] ?? '';
        $actualDigest = is_string($dest) && is_file($dest) && is_readable($dest) ? @hash_file('sha256', $dest) : false;
        if (!is_string($dest) || dirname($dest) !== $TEMP_DIR || is_link($dest) || !is_file($dest)
            || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)
            || !is_string($_POST['restore_token'] ?? null) || !hash_equals($token, $_POST['restore_token'])
            || !is_string($digest) || !is_string($actualDigest) || !hash_equals($digest, $actualDigest)) {
            $err = 'Sesi restore tidak valid. Upload ulang file backup.';
        } elseif (!is_string($_POST['confirm_text'] ?? null) || trim($_POST['confirm_text']) !== 'RESTORE') {
            $err = 'Konfirmasi tidak valid.';
            $rechecked = analyzeRestoreZip($pdo, $dest, $BACKUP_TABLES, $NULL_TOKEN);
            if ($rechecked['error'] === null) $preview = $rechecked;
        } else {
            // Pre-restore safety backup ke disk.
            $safetyStamp = date('Y-m-d_His') . '_' . bin2hex(random_bytes(16));
            $safetyZip   = $TEMP_DIR . '/backup_pre_restore_' . $safetyStamp . '.zip';
            $safety      = createBackupZip($pdo, $safetyZip, $BACKUP_TABLES, $NULL_TOKEN);
            if (!$safety['ok']) {
                $err = 'Gagal membuat safety backup pre-restore: ' . ($safety['error'] ?? 'unknown');
            } else {
                $r = executeMergeRestore($pdo, $dest, $BACKUP_TABLES, $NULL_TOKEN);
                if (!empty($r['error'])) {
                $err = 'Pemulihan belum selesai: ' . $r['error'];
                } else {
                    $safetyName = 'backup_pre_restore_' . $safetyStamp . '.zip';
                    addLog($pdo, 'RESTORE_BACKUP', 'safety=' . $safetyName . '; ' . $r['summary']);
                    $msg = 'Pemulihan selesai: ' . array_sum($r['counts']) . ' baris ditambahkan, ' . array_sum($r['conflicts']) . ' baris dengan ID yang sudah ada dilewati.'
                        . (!empty($r['skipped']) ? ' Tabel yang dilewati: ' . implode(', ', array_keys($r['skipped'])) . '.' : '') . ' Cadangan sebelum pemulihan: ' . $safetyName
                        . ' Unduh salinannya melalui daftar cadangan di halaman ini.';
                    unset($_SESSION['restore_zip'], $_SESSION['restore_token'], $_SESSION['restore_sha256']);
                    @unlink($dest);
                    $_SESSION['backup_message'] = $msg;
                    redirectTo('backup-restore.php');
                }
            }
        }
    } elseif ($action === 'cancel') {
        $pending = $_SESSION['restore_zip'] ?? '';
        if (is_string($pending) && dirname($pending) === $TEMP_DIR && str_starts_with(basename($pending), 'restore_') && !is_link($pending) && is_file($pending)) @unlink($pending);
        unset($_SESSION['restore_zip'], $_SESSION['restore_token'], $_SESSION['restore_sha256']);
        redirectTo('backup-restore.php');
    } else {
        http_response_code(422);
        $err = 'Pilih berkas untuk pratinjau atau buka kembali halaman sebelum memulihkan cadangan.';
    }
}

// ---------------------------------------------------------------------------
// Data untuk tampilan: jumlah baris tiap tabel saat ini + daftar safety backup
// ---------------------------------------------------------------------------
if ($err !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && http_response_code() < 400) http_response_code(422);
$currentCounts = [];
foreach ($BACKUP_TABLES as $tbl) {
    $currentCounts[$tbl] = (int) $pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
}

$safetyFiles = [];
if (is_dir($TEMP_DIR)) {
    foreach (scandir($TEMP_DIR) as $fn) {
        if (in_array($fn, ['.', '..'], true)) continue;
        if (preg_match('/^backup_pre_restore_[A-Za-z0-9_\-]+\.zip$/', $fn)) {
            $safetyFiles[$fn] = filemtime($TEMP_DIR . '/' . $fn);
        }
    }
    arsort($safetyFiles);
}

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>
<div class="space-y-6">

    <?php if ($msg): ?>
        <div role="status" class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 text-sm text-emerald-800">
            ✅ <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div role="alert" class="bg-red-50 border border-red-200 rounded-2xl p-4 text-sm text-red-800">
            ⚠️ <?php echo htmlspecialchars($err); ?>
        </div>
    <?php endif; ?>

    <!-- Kartu 1: Download Backup -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="p-5 border-b border-slate-100">
            <h3 class="font-bold text-slate-800">Unduh cadangan (ZIP)</h3>
            <p class="text-xs text-slate-500 mt-0.5">
                Unduh seluruh tabel data beserta ID dan relasinya ke satu file ZIP. Simpan salinannya di tempat aman.
            </p>
        </div>
        <div class="p-5">
            <div class="overflow-x-auto mb-4">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
                        <tr>
                            <th class="text-left px-4 py-2">Tabel</th>
                            <th class="text-right px-4 py-2">Baris saat ini</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($BACKUP_TABLES as $tbl): ?>
                            <tr>
                                <td class="px-4 py-2 font-mono text-slate-700"><?php echo htmlspecialchars($tbl); ?></td>
                                <td class="px-4 py-2 text-right text-slate-600"><?php echo number_format($currentCounts[$tbl]); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <a href="backup-restore.php?export=1"
                class="inline-flex items-center gap-2 bg-emerald-600 text-white px-5 py-2.5 rounded-xl font-semibold text-sm hover:bg-emerald-700 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                </svg>
                Unduh cadangan (ZIP)
            </a>
            <p class="text-xs text-amber-700 mt-3 bg-amber-50 border border-amber-100 rounded-lg p-2">
                ⚠️ Cadangan berisi <strong>data peserta</strong><?php if (($_SESSION['role'] ?? '') === 'admin'): ?> dan <strong>hash kata sandi akun</strong><?php endif; ?>. Simpan di tempat pribadi dan jangan dibagikan.
                <?php if (($_SESSION['role'] ?? '') !== 'admin'): ?>Salinan unduhan Anda <strong>tidak memuat kata sandi akun</strong> (kolom dikosongkan).<?php endif; ?>
            </p>
        </div>
    </div>

    <!-- Kartu 2: Restore dari Backup -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="p-5 border-b border-slate-100">
            <h3 class="font-bold text-slate-800">Pulihkan cadangan</h3>
            <p class="text-xs text-slate-500 mt-0.5">
                Upload ZIP backup → pratinjau → konfirmasi. Restore aman hanya menambahkan data yang belum ada.
            </p>
        </div>
        <div class="p-5">
            <?php if ($preview): ?>
                <!-- Hasil preview -->
                <div class="bg-red-50 border border-red-200 rounded-xl p-3 text-sm text-red-800 mb-4">
                    ✅ Pratinjau selesai. Data belum dipulihkan. Pemulihan hanya menambahkan baris yang belum ada.
                    Baris dengan ID yang sudah ada akan dilewati. Cadangan kondisi saat ini dibuat sebelum pemulihan.
                </div>
                <?php if ($preview['legacy']): ?><p class="mb-4 p-3 bg-amber-50 text-amber-900 rounded-xl">Berkas memakai format cadangan lama. Periksa kembali catatan dengan tanda garis miring atau kutip setelah pemulihan.</p><?php endif; ?>
                <div class="overflow-x-auto mb-4">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
                            <tr>
                                <th class="text-left px-4 py-2">Tabel</th>
                                <th class="text-right px-4 py-2">Baris di ZIP</th>
                                <th class="text-right px-4 py-2">Baris saat ini</th>
                                <th class="text-left px-4 py-2">Hasil pemeriksaan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($preview['rows'] as $row): ?>
                                <tr>
                                    <td class="px-4 py-2 font-mono text-slate-700"><?php echo htmlspecialchars($row['table']); ?></td>
                                    <td class="px-4 py-2 text-right text-slate-600">
                                        <?php echo $row['zip_rows'] === null ? '<span class="text-red-500">tidak ada</span>' : number_format($row['zip_rows']); ?>
                                    </td>
                                    <td class="px-4 py-2 text-right text-slate-400"><?php echo number_format($row['db_rows']); ?></td>
                                    <td class="px-4 py-2 text-xs">
                                        <?php if ($row['note']): ?>
                                            <span class="text-red-600"><?php echo htmlspecialchars($row['note']); ?></span>
                                        <?php elseif ($row['cols_ok']): ?>
                                            <span class="text-emerald-600">cocok</span>
                                        <?php else: ?>
                                            <span class="text-amber-600">
                                                Kolom tidak cocok; pemulihan belum dapat dijalankan.
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-slate-500 mb-4">
                    Total: <?php echo number_format($preview['total_zip']); ?> baris di ZIP /
                    <?php echo number_format($preview['total_db']); ?> baris di DB saat ini.
                </p>

                <section class="border border-red-200 rounded-xl p-4" aria-labelledby="restore-confirm-title">
                    <h4 id="restore-confirm-title" class="font-bold mb-3">Konfirmasi pemulihan</h4>
                    <p id="restore-confirm-help" class="text-sm text-slate-600 mb-3">Cadangan kondisi saat ini dibuat otomatis sebelum pemulihan. Ketik RESTORE untuk menambahkan data dari berkas ini. Baris dengan ID yang sudah ada tetap dipertahankan.</p>
                    <form method="post" action="backup-restore.php" class="space-y-3">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="restore">
                        <input type="hidden" name="restore_token" value="<?= appEscape($_SESSION['restore_token'] ?? '') ?>">
                        <label for="restore-confirm" class="block font-bold">Ketik RESTORE</label>
                        <input id="restore-confirm" name="confirm_text" required pattern="RESTORE" autocomplete="off" spellcheck="false" aria-describedby="restore-confirm-help" class="w-full px-3 py-3 border border-slate-300 rounded-xl">
                        <button type="submit" class="bg-red-600 text-white font-bold rounded-xl px-5 py-3">Jalankan pemulihan</button>
                    </form>
                    <form method="post" action="backup-restore.php" class="mt-3">
                        <?php csrfField(); ?>
                        <button type="submit" name="action" value="cancel" class="border border-slate-300 rounded-xl px-5 py-3">Batalkan pemulihan</button>
                    </form>
                </section>
            <?php else: ?>
                <!-- Form upload -->
                <form method="post" action="backup-restore.php" enctype="multipart/form-data" class="space-y-3">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="preview">
                    <div>
                        <label for="restore-upload" class="block text-sm font-semibold text-slate-700 mb-1">File Backup (.zip)</label>
                        <p id="restore-upload-help" class="text-sm text-slate-600 mb-2">Maksimal <?= (int) $MAX_UPLOAD_MB ?> MB. Berkas diperiksa sebelum Anda mengonfirmasi pemulihan.</p>
                        <input id="restore-upload" type="file" name="backup_file" accept=".zip" required aria-describedby="restore-upload-help"
                            class="block w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:bg-slate-100 file:text-slate-700 file:font-semibold hover:file:bg-slate-200">
                    </div>
                    <button type="submit"
                        class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-xl font-semibold text-sm hover:bg-blue-700 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                        </svg>
                        Pratinjau Restore
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Kartu 3: Safety Backup tersedia -->
    <?php if (!empty($safetyFiles)): ?>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
            <div class="p-5 border-b border-slate-100">
                <h3 class="font-bold text-slate-800">Cadangan sebelum pemulihan</h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Arsip kondisi sebelum pemulihan. Unduh dan simpan salinannya di perangkat Anda.
                </p>
            </div>
            <div class="p-5">
                <ul class="divide-y divide-slate-100">
                    <?php foreach ($safetyFiles as $fn => $mtime): ?>
                        <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <p class="font-bold text-sm text-slate-700"><?php echo date('d/m/Y H:i:s', $mtime); ?></p>
                                <p class="font-mono text-xs text-slate-500 break-all"><?php echo htmlspecialchars($fn); ?></p>
                            </div>
                            <a href="backup-restore.php?download=<?php echo urlencode($fn); ?>"
                                aria-label="Unduh cadangan <?= appEscape(date('d/m/Y H:i:s', $mtime)) ?>"
                                class="text-blue-600 hover:text-blue-700 text-sm font-semibold py-3">Unduh</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="bg-amber-50 border border-amber-100 rounded-2xl p-4 text-sm text-amber-800">
        <strong>ℹ️ Catatan:</strong> Menu ini menambahkan baris yang belum ada; ID yang sudah ada dilewati.
        Cadangan kondisi awal dibuat sebelum pemulihan. Untuk mengembalikan seluruh database ke kondisi awal, hubungi pengelola.
        Unduh salinan cadangan sebelum server diganti atau dideploy ulang. Hanya admin / pj_tahfidz.
        Riwayat export/restore tercatat di <strong>Log Aktivitas</strong>.
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
