<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/data_induk_migration_helper.php';

$apply = in_array('--apply', $argv, true) || getenv('APPLY_MIGRATIONS') === '1';
header('Content-Type: text/plain; charset=utf-8');

try {
    if (!$apply) {
        $operations = dataIndukMigrationPlan($pdo);
        echo "Mode DRY-RUN. Jalankan php migrate_data_induk.php --apply setelah backup dan staging berhasil diverifikasi.\n";
        if (!$operations) echo "OK Database sudah sesuai skema Data Induk.\n";
        foreach ($operations as $operation) echo 'PLAN ' . $operation['label'] . "\n";
        exit(0);
    }

    echo "Mode APPLY Data Induk migration. Semua pemeriksaan dijalankan sebelum perubahan pertama.\n";
    $operations = dataIndukMigrationApply($pdo, static function (string $label): void { echo "OK {$label}\n"; });
    if (!$operations) echo "OK Database sudah sesuai skema Data Induk.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'BLOCK/ERROR ' . $error->getMessage() . "\n");
    exit(2);
}
