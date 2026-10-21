<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/includes/autosave_migration_helper.php';
if (!in_array('--apply', $argv, true)) {
    echo "DRY-RUN: tambah program_code pada halaqoh, empat tabel autosave dan tiga trigger revisi. Data lama dipertahankan.\n";
    echo "Sesudah backup: php migrate_autosave.php --apply\n";
    exit;
}
require __DIR__ . '/config/database.php';
try { foreach (autosaveApplyMigration($pdo) as $message) echo $message . "\n"; }
catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
