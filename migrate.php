<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/config/database.php';

try {
    $table = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? AND table_type=\'BASE TABLE\'');
    $table->execute(['wali_santri']);
    if ($table->fetchColumn() === false) throw new RuntimeException('Tabel wali_santri tidak ditemukan. Tidak ada perubahan dilakukan.');

    $columns = $pdo->prepare('SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name IN (?,?)');
    $columns->execute(['wali_santri', 'access_code', 'no_hp']);
    $found = array_column($columns->fetchAll(PDO::FETCH_ASSOC), null, 'COLUMN_NAME');
    if (!isset($found['no_hp'])) throw new RuntimeException('Kolom no_hp tidak ditemukan. Tidak ada perubahan dilakukan.');

    if (isset($found['access_code'])) {
        $existing = $found['access_code'];
        if (strtolower((string) $existing['DATA_TYPE']) !== 'varchar' || (int) $existing['CHARACTER_MAXIMUM_LENGTH'] !== 10) {
            throw new RuntimeException('Kolom access_code sudah ada dengan definisi berbeda. Tidak ada perubahan dilakukan.');
        }
        echo "Kolom access_code VARCHAR(10) sudah tersedia; tidak ada perubahan dilakukan.\n";
        exit(0);
    }

    $pdo->exec('ALTER TABLE `wali_santri` ADD COLUMN `access_code` VARCHAR(10) AFTER `no_hp`');
    echo "Kolom access_code VARCHAR(10) ditambahkan tanpa mengubah baris data lama.\n";
} catch (Throwable $error) {
    error_log('Access code migration failed: ' . $error->getMessage());
    fwrite(STDERR, 'Migrasi access_code gagal. ' . $error->getMessage() . "\n");
    exit(1);
}
