<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/halaqoh_fk_migration_helper.php';

$apply = in_array('--apply', $argv, true);
try {
    $plan = halaqohFkMigrationPlan($pdo);
    if ($plan['orphan_count'] > 0) {
        fwrite(STDERR, 'BLOCK: ' . $plan['orphan_count'] . " presensi merujuk halaqoh yang tidak tersedia. Tidak ada perubahan dilakukan.\n");
        exit(2);
    }
    if (!$apply) {
        echo "DRY-RUN: isi presensi tidak diubah.\n";
        echo $plan['needs_change']
            ? "PLAN: jadikan halaqoh_id nullable dan gunakan ON DELETE SET NULL. Jalankan php migrate_halaqoh_fk.php --apply setelah backup dan pemeriksaan staging.\n"
            : "OK: foreign key sudah sesuai; tidak ada perubahan diperlukan.\n";
        exit(0);
    }

    $result = halaqohFkMigrationApply($pdo);
    echo $result['changed']
        ? 'APPLIED: foreign key presensi memakai ON DELETE SET NULL (' . $result['constraint_name'] . "). Isi baris presensi dipertahankan.\n"
        : 'OK: foreign key sudah sesuai (' . $result['constraint_name'] . "); tidak ada perubahan diperlukan.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Migrasi dihentikan: ' . $error->getMessage() . "\n");
    exit(1);
}
