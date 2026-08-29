<?php
/**
 * Additive migration for safe alumni archiving.
 * No existing row is deleted or rewritten.
 */
require_once __DIR__ . '/config/database.php';

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/includes/auth_helper.php';
    checkRole(['admin']);
    header('Content-Type: text/plain; charset=utf-8');
}
$table = 'halaqoh_members';
$columns = [
    'archived_at' => 'ALTER TABLE halaqoh_members ADD COLUMN archived_at DATETIME NULL AFTER wali_santri_id',
    'archive_reason' => 'ALTER TABLE halaqoh_members ADD COLUMN archive_reason VARCHAR(50) NULL AFTER archived_at',
];

try {
    foreach ($columns as $column => $sql) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec($sql);
            echo "OK   kolom {$column} ditambahkan\n";
        } else {
            echo "SKIP kolom {$column} sudah ada\n";
        }
    }

    $indexName = 'idx_hm_wali_archived';
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
    );
    $stmt->execute([$table, $indexName]);
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec('CREATE INDEX idx_hm_wali_archived ON halaqoh_members (wali_santri_id, archived_at)');
        echo "OK   index {$indexName} dibuat\n";
    } else {
        echo "SKIP index {$indexName} sudah ada\n";
    }

    echo "Migrasi alumni archive selesai tanpa menghapus data.\n";
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Alumni archive migration failed: ' . $e->getMessage());
    echo "GAGAL migrasi alumni archive: " . $e->getMessage() . "\n";
    exit(1);
}
