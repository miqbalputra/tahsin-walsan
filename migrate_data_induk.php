<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/data_induk_migration_helper.php';

$requestedApply = in_array('--apply', $_SERVER['argv'] ?? [], true)
    || (($_GET['apply'] ?? '') === '1')
    || getenv('APPLY_MIGRATIONS') === '1';
$apply = PHP_SAPI === 'cli' && $requestedApply;

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/includes/auth_helper.php';
    checkRole(['admin']);
}

header('Content-Type: text/plain; charset=utf-8');
if ($requestedApply && PHP_SAPI !== 'cli') {
    http_response_code(405);
    echo "Apply migration hanya diizinkan dari CLI setelah backup dan uji clone.\n";
    exit(1);
}

if (!$apply) {
    echo "Mode DRY-RUN. Jalankan php migrate_data_induk.php --apply setelah backup dan verifikasi clone.\n";
    echo "Migrations are additive and resumable; each column and index is checked independently.\n";
    echo "No presensi rows are deleted or rewritten, and no unique attendance index is created.\n";
    foreach ([
        'wali_santri.data_induk_guardian_id',
        'santri_detail.data_induk_student_id',
        'santri_detail.data_induk_enrollment_id',
        'santri_detail.data_induk_class_id',
        'santri_detail.status_roster',
        'users.data_induk_teacher_id',
        'users.data_induk_active',
        'data_induk_references',
        'data_induk_sync_state',
        'data_induk_sync_runs',
        'data_induk_sync_conflicts',
    ] as $item) {
        echo "PLAN {$item}\n";
    }
    exit(0);
}

echo "Mode APPLY Data Induk migration\n";
foreach (dataIndukApplyMigration($pdo) as $message) {
    echo $message . "\n";
}

$duplicateKeys = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT 1 FROM presensi GROUP BY halaqoh_id,wali_santri_id,tanggal HAVING COUNT(*) > 1) duplicate_keys')->fetchColumn();
if ($duplicateKeys > 0) {
    echo "NOTICE presensi contains {$duplicateKeys} duplicate attendance key(s); rows were preserved. Review them in the attendance report; this additive migration does not require deleting them.\n";
}
echo "DONE Data Induk schema is ready. No family identities were imported.\n";
