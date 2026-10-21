<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/includes/family_migration_helper.php';
if (!in_array('--apply', $argv, true)) {
    echo "DRY-RUN: tambah 5 tabel keluarga dan 1 view baca. Tidak mengubah tabel/data Bapak, presensi, atau halaqoh.\n";
    echo "Setelah backup: php migrate_family.php --apply\n";
    exit;
}
require __DIR__ . '/config/database.php';
try { familyApplyMigration($pdo); echo "Migrasi keluarga selesai.\n"; }
catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
