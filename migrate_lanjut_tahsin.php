<?php
/**
 * Migration: Tambah kolom `lanjut_tahsin` pada tabel wali_santri.
 *
 * Kolom ini menyimpan catatan lama bahwa wali masih ingin ikut tahsin setelah
 * semua anaknya lulus. Catatan ini ditampilkan sebagai informasi dan tidak
 * mengecualikan pengarsipan alumni otomatis.
 *
 * Jalankan melalui CLI: php migrate_lanjut_tahsin.php.
 * Idempoten: aman dijalankan berulang (cek kolom dulu sebelum ALTER).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/config/database.php';

try {
    // Cek apakah kolom sudah ada (idempoten)
    $check = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wali_santri'
          AND COLUMN_NAME = 'lanjut_tahsin'
    ");
    $check->execute();

    if ((int) $check->fetchColumn() > 0) {
        echo "Kolom `lanjut_tahsin` sudah ada pada tabel wali_santri. Tidak ada perubahan dilakukan.\n";
    } else {
        $pdo->exec("ALTER TABLE wali_santri ADD COLUMN lanjut_tahsin TINYINT(1) NOT NULL DEFAULT 0 AFTER status_aktif");
        echo "Berhasil menambahkan kolom `lanjut_tahsin` pada tabel wali_santri.\n";
    }
} catch (Throwable $error) {
    error_log('Lanjut tahsin migration failed: ' . $error->getMessage());
    fwrite(STDERR, "Migrasi lanjut_tahsin gagal. " . $error->getMessage() . "\n");
    exit(1);
}
