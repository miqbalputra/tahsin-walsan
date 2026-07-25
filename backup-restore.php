<?php
/**
 * Backup & Restore (ZIP CSV, in-app)
 *
 * Export : GET ?export=1  -> download ZIP berisi CSV per-tabel (preserve ID + relasi FK),
 *          manifest.json, schema.sql. NULL -> sentinel "\N".
 * Preview: POST action=preview (upload .zip) -> validasi + ringkasan baris, tanpa eksekusi.
 * Restore: POST action=restore -> safety backup otomatis lalu ganti seluruh data tabel
 *          (DELETE + INSERT preserved-ID) dalam satu transaksi (FK_CHECKS=0 -> 1).
 * Download: GET ?download=<filename> -> whitelist file di temp_excel/ (safety backup).
 *
 * Tabel (urutan dependensi parent-dulu): users, wali_santri, halaqoh, santri_detail,
 * halaqoh_members, presensi.
 *
 * Hanya admin / pj_tahfidz. Semua POST pakai CSRF + addLog.
 */
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

checkRole(['admin', 'pj_tahfidz']);

$pageTitle = 'Backup & Restore';

// ---------------------------------------------------------------------------
// Konfigurasi
// ---------------------------------------------------------------------------
$BACKUP_TABLES = ['users', 'wali_santri', 'halaqoh', 'santri_detail', 'halaqoh_members', 'presensi'];
$TEMP_DIR     = __DIR__ . '/temp_excel';
$NULL_TOKEN   = "\\N"; // penanda NULL di CSV (konvensi mysqldump)
$MAX_UPLOAD   = 100 * 1024 * 1024; // 100MB

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------
function tableColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0); // nama kolom (Field)
}

function safeFilename(string $name): ?string
{
    if ($name === '' || preg_match('/[^A-Za-z0-9_.\-]/', $name)) return null;
    if (str_contains($name, '..')) return null;
    return $name;
}

/**
 * Bangun ZIP backup lengkap di $zipPath (tidak di-stream).
 * Mengembalikan ['ok'=>bool, 'counts'=>[], 'error'=>string].
 */
function createBackupZip(PDO $pdo, string $zipPath, array $tables, string $nullToken): array
{
    if (!is_dir(dirname($zipPath))) {
        mkdir(dirname($zipPath), 0755, true);
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'error' => 'Gagal membuat file ZIP.'];
    }
    $counts = [];
    foreach ($tables as $tbl) {
        $cols    = tableColumns($pdo, $tbl);
        $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $rows    = $pdo->query("SELECT $colList FROM `$tbl` ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);

        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, $cols);
        foreach ($rows as $r) {
            $line = [];
            foreach ($cols as $c) {
                $v = $r[$c] ?? null;
                $line[] = ($v === null) ? $nullToken : $v;
            }
            fputcsv($csv, $line);
        }
        rewind($csv);
        $zip->addFromString($tbl . '.csv', stream_get_contents($csv));
        fclose($csv);
        $counts[$tbl] = count($rows);
    }
    $manifest = [
        'app'          => 'tahsin-walsan',
        'version'      => 1,
        'generated_at' => date('Y-m-d H:i:s'),
        'tables'       => $counts,
    ];
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $schema = '';
    foreach ($tables as $tbl) {
        $row    = $pdo->query("SHOW CREATE TABLE `$tbl`")->fetch(PDO::FETCH_ASSOC);
        $schema .= ($row['Create Table'] ?? '') . ";\n\n";
    }
    $zip->addFromString('schema.sql', $schema);
    if ($zip->close() !== true) {
        return ['ok' => false, 'error' => 'ZipArchive::close gagal menulis (cek writable temp dir & ruang disk).'];
    }
    if (!is_file($zipPath) || filesize($zipPath) === 0) {
        return ['ok' => false, 'error' => 'File ZIP kosong setelah ditulis.'];
    }

    return ['ok' => true, 'counts' => $counts];
}

/**
 * Analisa ZIP restore: validasi manifest + hitung baris tiap CSV + cek kolom.
 * Mengembalikan ['error'=>null|string, 'rows'=>[], 'total_zip', 'total_db', 'summary'].
 */
function analyzeRestoreZip(PDO $pdo, string $zipPath, array $tables, string $nullToken): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['error' => 'Tidak bisa membuka file ZIP.'];
    }
    $manifestRaw = $zip->getFromName('manifest.json');
    if ($manifestRaw === false) {
        $zip->close();
        return ['error' => 'manifest.json tidak ditemukan di dalam ZIP.'];
    }
    $manifest = json_decode($manifestRaw, true);
    if (!is_array($manifest) || ($manifest['app'] ?? '') !== 'tahsin-walsan') {
        $zip->close();
        return ['error' => 'ZIP bukan backup tahsin-walsan yang valid (app tidak cocok).'];
    }

    $rows     = [];
    $totalZip = 0;
    $totalDb  = 0;
    foreach ($tables as $tbl) {
        $dbCols  = tableColumns($pdo, $tbl);
        $dbCount = (int) $pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
        $totalDb += $dbCount;

        $csvRaw = $zip->getFromName($tbl . '.csv');
        if ($csvRaw === false) {
            $rows[] = [
                'table'   => $tbl,
                'zip_rows'  => null,
                'db_rows'   => $dbCount,
                'cols_ok'   => false,
                'missing'   => [],
                'note'      => 'CSV tidak ada di ZIP',
            ];
            continue;
        }

        $h = fopen('php://temp', 'r+');
        fwrite($h, $csvRaw);
        rewind($h);
        $header = fgetcsv($h, 0, ',', '"', '');
        $dataRows = 0;
        while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
            $isEmpty = true;
            foreach ($r as $cell) {
                if ($cell !== null && $cell !== '') { $isEmpty = false; break; }
            }
            if ($isEmpty) continue;
            $dataRows++;
        }
        fclose($h);

        $missing = $header ? array_values(array_diff($header, $dbCols)) : [];
        $rows[] = [
            'table'     => $tbl,
            'zip_rows'  => $dataRows,
            'db_rows'   => $dbCount,
            'cols_ok'   => empty($missing),
            'missing'   => $missing,
            'note'      => $header ? '' : 'CSV kosong / header tidak terbaca',
        ];
        $totalZip += $dataRows;
    }
    $zip->close();

    return [
        'error'     => null,
        'rows'      => $rows,
        'total_zip' => $totalZip,
        'total_db'  => $totalDb,
        'summary'   => "preview $totalZip baris dari " . basename($zipPath),
    ];
}

/**
 * Eksekusi restore: transaksi, FK off, DELETE semua tabel, INSERT per tabel preserve ID.
 * Rollback penuh bila ada baris gagal.
 */
function executeRestore(PDO $pdo, string $zipPath, array $tables, string $nullToken): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['error' => 'Tidak bisa membuka file ZIP.'];
    }
    $counts = [];
    try {
        $pdo->beginTransaction();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        // Hapus semua (DELETE = transaksional, bisa rollback; bukan TRUNCATE).
        foreach ($tables as $tbl) {
            $pdo->exec("DELETE FROM `$tbl`");
        }

        // Insert parent-dulu.
        foreach ($tables as $tbl) {
            $csvRaw = $zip->getFromName($tbl . '.csv');
            if ($csvRaw === false) { $counts[$tbl] = 0; continue; }

            $dbCols = tableColumns($pdo, $tbl);
            $h = fopen('php://temp', 'r+');
            fwrite($h, $csvRaw);
            rewind($h);
            $header = fgetcsv($h, 0, ',', '"', '');
            if ($header === false || $header === null) { fclose($h); $counts[$tbl] = 0; continue; }

            // Intersect kolom CSV ∩ kolom DB -> resilient thd schema drift.
            $cols   = array_values(array_intersect($header, $dbCols));
            $colIdx = array_map(fn($c) => array_search($c, $header, true), $cols);
            $colList     = implode(', ', array_map(fn($c) => "`$c`", $cols));
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $stmt = $pdo->prepare("INSERT INTO `$tbl` ($colList) VALUES ($placeholders)");

            $n = 0;
            while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
                $isEmpty = true;
                foreach ($r as $cell) {
                    if ($cell !== null && $cell !== '') { $isEmpty = false; break; }
                }
                if ($isEmpty) continue;

                $params = [];
                foreach ($colIdx as $i) {
                    $val = $r[$i] ?? '';
                    $params[] = ($val === $nullToken) ? null : $val;
                }
                $stmt->execute($params);
                $n++;
            }
            fclose($h);
            $counts[$tbl] = $n;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $pdo->commit();
        $zip->close();
        return ['error' => null, 'counts' => $counts, 'summary' => 'inserted ' . json_encode($counts)];
    } catch (Throwable $e) {
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $ee) {}
        try { $pdo->rollBack(); } catch (Throwable $ee) {}
        $zip->close();
        return ['error' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// GET handler: export
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === '1') {
    $stamp    = date('Y-m-d_His');
    $zipPath  = $TEMP_DIR . '/backup_' . $stamp . '.zip';
    $result   = createBackupZip($pdo, $zipPath, $BACKUP_TABLES, $NULL_TOKEN);
    if (!$result['ok']) {
        die('Gagal membuat backup: ' . ($result['error'] ?? 'unknown'));
    }
    addLog($pdo, 'EXPORT_BACKUP', json_encode($result['counts']));
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
    $name = safeFilename($dl);
    $valid = $name !== null
        && preg_match('/^(backup_|backup_pre_restore_|restore_)[A-Za-z0-9_\-]+\.zip$/', $name)
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
$msg    = $_GET['msg'] ?? '';
$err    = $_GET['err'] ?? '';
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $err = 'Invalid CSRF token.';
    } elseif ($action === 'preview') {
        if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            $err = 'Upload gagal. Pastikan file ZIP dipilih.';
        } else {
            $f   = $_FILES['backup_file'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if ($ext !== 'zip') {
                $err = 'File harus berformat .zip';
            } elseif ($f['size'] > $MAX_UPLOAD) {
                $err = 'Ukuran file melebihi 100MB.';
            } else {
                if (!is_dir($TEMP_DIR)) mkdir($TEMP_DIR, 0755, true);
                $stamp = date('Y-m-d_His');
                $dest  = $TEMP_DIR . '/restore_' . $stamp . '.zip';
                if (!move_uploaded_file($f['tmp_name'], $dest)) {
                    $err = 'Gagal menyimpan file upload.';
                } else {
                    $r = analyzeRestoreZip($pdo, $dest, $BACKUP_TABLES, $NULL_TOKEN);
                    if (!empty($r['error'])) {
                        $err = $r['error'];
                        @unlink($dest);
                    } else {
                        $_SESSION['restore_zip']   = $dest;
                        $_SESSION['restore_token'] = $stamp;
                        $preview = $r;
                        addLog($pdo, 'RESTORE_PREVIEW', $r['summary']);
                    }
                }
            }
        }
    } elseif ($action === 'restore') {
        $dest  = $_SESSION['restore_zip']   ?? '';
        $token = $_SESSION['restore_token'] ?? '';
        if (!$dest || !is_file($dest) || ($_POST['restore_token'] ?? '') !== $token) {
            $err = 'Sesi restore tidak valid. Upload ulang file backup.';
        } elseif (($_POST['confirm_text'] ?? '') !== 'RESTORE') {
            $err = 'Konfirmasi tidak valid.';
        } else {
            // Pre-restore safety backup ke disk.
            $safetyStamp = date('Y-m-d_His');
            $safetyZip   = $TEMP_DIR . '/backup_pre_restore_' . $safetyStamp . '.zip';
            $safety      = createBackupZip($pdo, $safetyZip, $BACKUP_TABLES, $NULL_TOKEN);
            if (!$safety['ok']) {
                $err = 'Gagal membuat safety backup pre-restore: ' . ($safety['error'] ?? 'unknown');
            } else {
                $r = executeRestore($pdo, $dest, $BACKUP_TABLES, $NULL_TOKEN);
                if (!empty($r['error'])) {
                    $err = 'Restore gagal & dirollback: ' . $r['error'];
                } else {
                    $safetyName = 'backup_pre_restore_' . $safetyStamp . '.zip';
                    addLog($pdo, 'RESTORE_BACKUP', 'safety=' . $safetyName . '; ' . $r['summary']);
                    $msg = 'Restore berhasil. Safety backup: ' . $safetyName
                        . ' (download via menu Backup & Restore jika perlu rollback).';
                    unset($_SESSION['restore_zip'], $_SESSION['restore_token']);
                    @unlink($dest);
                    redirectTo('backup-restore.php?msg=' . urlencode($msg));
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Data untuk tampilan: jumlah baris tiap tabel saat ini + daftar safety backup
// ---------------------------------------------------------------------------
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
<div class="space-y-6" x-data="{ confirmOpen: false, confirmText: '' }">

    <?php if ($msg): ?>
        <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 text-sm text-emerald-800">
            ✅ <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="bg-red-50 border border-red-200 rounded-2xl p-4 text-sm text-red-800">
            ⚠️ <?php echo htmlspecialchars($err); ?>
        </div>
    <?php endif; ?>

    <!-- Kartu 1: Download Backup -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="p-5 border-b border-slate-100">
            <h3 class="font-bold text-slate-800">Download Backup (ZIP CSV)</h3>
            <p class="text-xs text-slate-500 mt-0.5">
                Export seluruh data 6 tabel (preserve ID + relasi) ke satu file ZIP. Bisa di-restore kapan saja.
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
                Download Backup (ZIP)
            </a>
            <p class="text-xs text-amber-700 mt-3 bg-amber-50 border border-amber-100 rounded-lg p-2">
                ⚠️ File backup berisi <strong>password hash</strong> akun (tabel users). Simpan di tempat aman & jangan dibagikan.
            </p>
        </div>
    </div>

    <!-- Kartu 2: Restore dari Backup -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="p-5 border-b border-slate-100">
            <h3 class="font-bold text-slate-800">Restore dari Backup</h3>
            <p class="text-xs text-slate-500 mt-0.5">
                Upload ZIP backup → pratinjau → konfirmasi. <strong>Seluruh data 6 tabel akan DIGANTI</strong>.
            </p>
        </div>
        <div class="p-5">
            <?php if ($preview): ?>
                <!-- Hasil preview -->
                <div class="bg-red-50 border border-red-200 rounded-xl p-3 text-sm text-red-800 mb-4">
                    ⚠️ Pratinjau berikut akan <strong>mengganti seluruh data</strong> tabel di bawah.
                    Backup terbaru otomatis dibuat sebelum eksekusi (lihat "Safety Backup" setelah selesai).
                </div>
                <div class="overflow-x-auto mb-4">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
                            <tr>
                                <th class="text-left px-4 py-2">Tabel</th>
                                <th class="text-right px-4 py-2">Baris di ZIP</th>
                                <th class="text-right px-4 py-2">Baris DB saat ini</th>
                                <th class="text-left px-4 py-2">Status Kolom</th>
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
                                            <span class="text-amber-600" title="Kolom di CSV tidak ada di DB — akan di-skip, kolom DB pakai default">
                                                skema beda (skip: <?php echo htmlspecialchars(implode(', ', $row['missing'])); ?>)
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

                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" @click="confirmOpen = true"
                        class="inline-flex items-center gap-2 bg-red-600 text-white px-5 py-2.5 rounded-xl font-semibold text-sm hover:bg-red-700 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                        </svg>
                        Lanjutkan Restore
                    </button>
                    <a href="backup-restore.php" class="text-slate-500 hover:text-slate-700 px-4 py-2.5 rounded-xl font-semibold text-sm hover:bg-slate-100 transition">Batal</a>
                </div>

                <!-- Modal konfirmasi -->
                <div x-show="confirmOpen" x-cloak
                    class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm"
                    @click.away="confirmOpen = false">
                    <div class="bg-white rounded-2xl w-full max-w-md p-6 shadow-2xl">
                        <h3 class="text-lg font-bold text-red-600 mb-2">Konfirmasi Restore</h3>
                        <p class="text-sm text-slate-600 mb-4">
                            Tindakan ini akan <strong>mengganti seluruh data</strong> 6 tabel dengan isi ZIP.
                            Safety backup otomatis dibuat sebelum eksekusi.
                        </p>
                        <p class="text-xs text-slate-500 mb-2">Ketik <strong class="font-mono">RESTORE</strong> untuk mengaktifkan tombol:</p>
                        <input type="text" x-model="confirmText" placeholder="RESTORE"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg font-mono mb-4 focus:ring-2 focus:ring-red-400 focus:border-red-400 outline-none">
                        <form method="post" action="backup-restore.php">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="restore_token" value="<?php echo htmlspecialchars($_SESSION['restore_token'] ?? ''); ?>">
                            <input type="hidden" name="confirm_text" x-bind:value="confirmText">
                            <div class="flex gap-3 justify-end">
                                <button type="button" @click="confirmOpen = false"
                                    class="px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100 text-sm font-semibold">Batal</button>
                                <button type="submit" :disabled="confirmText !== 'RESTORE'"
                                    class="px-5 py-2 rounded-xl bg-red-600 text-white text-sm font-bold hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed transition">
                                    Jalankan Restore
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- Form upload -->
                <form method="post" action="backup-restore.php" enctype="multipart/form-data" class="space-y-3">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="preview">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">File Backup (.zip)</label>
                        <input type="file" name="backup_file" accept=".zip" required
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
                <h3 class="font-bold text-slate-800">Safety Backup (Pre-Restore)</h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Backup otomatis yang dibuat sebelum tiap eksekusi restore. Download jika perlu rollback.
                </p>
            </div>
            <div class="p-5">
                <ul class="divide-y divide-slate-100">
                    <?php foreach ($safetyFiles as $fn => $mtime): ?>
                        <li class="flex items-center justify-between py-2.5">
                            <div>
                                <p class="font-mono text-sm text-slate-700"><?php echo htmlspecialchars($fn); ?></p>
                                <p class="text-xs text-slate-400"><?php echo date('Y-m-d H:i:s', $mtime); ?></p>
                            </div>
                            <a href="backup-restore.php?download=<?php echo urlencode($fn); ?>"
                                class="text-blue-600 hover:text-blue-700 text-sm font-semibold">Download</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="bg-amber-50 border border-amber-100 rounded-2xl p-4 text-sm text-amber-800">
        <strong>ℹ️ Catatan:</strong> Restore bersifat <strong>destruktif</strong> (mengganti seluruh data tabel).
        Safety backup otomatis dibuat sebelum eksekusi. Hanya admin / pj_tahfidz.
        Riwayat export/restore tercatat di <strong>Log Aktivitas</strong>.
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>