<?php
/**
 * Backup database terjadwal (CLI-only).
 *
 * Dipanggil cron/scheduled task (Coolify, cPanel, systemd timer):
 *   php /path/ke/scripts/backup-database.php
 *
 * Perilaku:
 * - Menulis ZIP backup penuh (CSV per tabel + schema.sql + manifest checksum)
 *   memakai createBackupZip() yang sama dengan fitur Backup & Restore.
 * - Arsip diberi nama backup_auto_<timestamp>_<hex>.zip dan disimpan di
 *   BACKUP_STORAGE_PATH (wajib di luar webroot) atau fallback resolveTempDir().
 * - Retensi: simpan maksimal 14 arsip terbaru (berdasarkan jumlah; arsip
 *   terbaru tetap dipertahankan walau cron lama tidak berjalan).
 * - Status eksekusi terakhir ditulis ke backup_auto_last.json di folder yang
 *   sama, agar operasional dapat memeriksa tanpa membuka aplikasi.
 * - Kegagalan -> exit(1) dengan pesan di STDERR; backup lama tidak disentuh.
 *
 * Arsip ini memuat hash password (butuh untuk pemulihan penuh) sehingga
 * folder penyimpanannya tidak boleh dapat diakses web.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/security_helper.php';
require_once __DIR__ . '/../includes/backup_helper.php';

const BACKUP_AUTO_PREFIX = 'backup_auto';
const BACKUP_AUTO_KEEP = 14;

$startedAt = hrtime(true);
$dir = null;
$zipPath = null;

/**
 * Probe koneksi sendiri sebelum memuat config/database.php (yang exit sendiri
 * saat gagal) agar kegagalan database tetap tercatat di backup_auto_last.json.
 */
function backupAutoRecordFailure(array $status, ?string $dir): void
{
    if ($dir !== null && is_dir($dir)) {
        @file_put_contents($dir . '/backup_auto_last.json', json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    fwrite(STDERR, 'Backup GAGAL: ' . ($status['error'] ?? 'tidak diketahui') . "\n");
}

try {
    $dir = resolveTempDir();
    $probe = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
            getenv('DB_HOST') ?: 'localhost',
            getenv('DB_PORT') ?: '3306',
            getenv('DB_DATABASE') ?: 'presensi_tahsin',
            getenv('DB_CHARSET') ?: 'utf8mb4'),
        getenv('DB_USERNAME') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
    unset($probe);
    // Koneksi sudah terbukti bisa; config/database.php menyediakan $pdo
    // (termasuk timezone WIB) tanpa risiko exit di tengah proses.
    require_once __DIR__ . '/../config/database.php';
} catch (Throwable $probeError) {
    backupAutoRecordFailure([
        'ok' => false,
        'error' => $probeError->getMessage(),
        'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1e6),
        'finished_at' => date('c'),
    ], $dir);
    if (isset($zipPath) && is_file($zipPath)) {
        @unlink($zipPath);
    }
    exit(1);
}

try {
    if (trim(getenv('BACKUP_STORAGE_PATH') ?: '') === '') {
        fwrite(STDERR, "Peringatan: BACKUP_STORAGE_PATH tidak di-set; memakai folder fallback di dalam webroot. Set path DI LUAR webroot agar arsip (memuat hash password) tidak dapat diakses publik.\n");
    }
    $tables = discoverBackupTables($pdo);
    if (!$tables) {
        throw new RuntimeException('Tidak ada tabel untuk dibackup.');
    }

    $stamp = date('Y-m-d_His') . '_' . bin2hex(random_bytes(8));
    $zipPath = $dir . '/' . BACKUP_AUTO_PREFIX . '_' . $stamp . '.zip';
    $result = createBackupZip($pdo, $zipPath, $tables, "\\N");
    if (empty($result['ok'])) {
        throw new RuntimeException($result['error'] ?? 'Backup gagal dibuat tanpa pesan error.');
    }

    // Retensi murni jumlah: arsip terbaru dipertahankan walau cron lama berhenti.
    $removed = purgeBackupArchives($dir, BACKUP_AUTO_PREFIX, 0, BACKUP_AUTO_KEEP);

    $status = [
        'ok' => true,
        'file' => basename($zipPath),
        'path' => $zipPath,
        'size_bytes' => filesize($zipPath),
        'rows' => $result['counts'],
        'old_archives_removed' => $removed,
        'retention' => ['keep' => BACKUP_AUTO_KEEP],
        'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1e6),
        'finished_at' => date('c'),
    ];
    @file_put_contents($dir . '/backup_auto_last.json', json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    echo 'Backup selesai: ' . basename($zipPath)
        . ' (' . number_format((float) $status['size_bytes']) . ' byte, '
        . array_sum($result['counts']) . ' baris, '
        . $status['duration_ms'] . " ms)\n"
        . 'Arsip lama dihapus: ' . $removed . "\n"
        . 'Folder penyimpanan: ' . $dir . "\n";
    exit(0);
} catch (Throwable $error) {
    backupAutoRecordFailure([
        'ok' => false,
        'error' => $error->getMessage(),
        'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1e6),
        'finished_at' => date('c'),
    ], $dir);
    if (isset($zipPath) && is_file($zipPath)) {
        @unlink($zipPath);
    }
    exit(1);
}
