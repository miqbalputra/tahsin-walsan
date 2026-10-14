<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/tahsin_migration_helper.php';
if (getenv('APPLY_MIGRATIONS') !== '1') {
    echo "DRY-RUN: tidak ada perubahan database.\n";
    echo 'Tabel additive: ' . implode(', ', array_keys(tahsinMigrationTables())) . "\n";
    echo 'Trigger histori transaksi: ' . implode(', ', array_keys(tahsinMigrationTriggers())) . "\n";
    echo "Setelah backup dan restore staging tervalidasi, jalankan APPLY_MIGRATIONS=1 php migrate_tahsin_reporting.php saat pemeliharaan.\n";
    exit;
}
try {
    foreach (tahsinApplyMigration($pdo) as $message) echo $message . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Migrasi gagal: " . $e->getMessage() . "\n");
    exit(1);
}
