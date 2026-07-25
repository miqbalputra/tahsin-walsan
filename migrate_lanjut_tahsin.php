<?php
/**
 * Migration: Tambah kolom `lanjut_tahsin` pada tabel wali_santri.
 *
 * Kolom ini menandai wali santri yang SEMUA anaknya sudah lulus (Mustawa 6 -> "Lulus")
 * tetapi bapak masih mau ikut tahsin. Wali ber-flag ini dikecualikan dari daftar
 * kandidat arsip otomatis di halaman naik-kelas.php.
 *
 * Jalankan sekali: php migrate_lanjut_tahsin.php  (atau buka via browser).
 * Idempoten: aman dijalankan berulang (cek kolom dulu sebelum ALTER).
 */
require_once 'config/database.php';

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
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}