<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/includes/participant_helper.php';
if (!in_array('--apply', $argv, true)) {
    echo "DRY-RUN: tambah jurnal perubahan peserta. Data peserta, anak dan presensi tidak diubah.\n";
    echo "Setelah backup dan verifikasi staging: php migrate_participant_management.php --apply\n";
    exit;
}
require __DIR__ . '/config/database.php';
try { participantApplyMigration($pdo); echo "Jurnal perubahan peserta siap.\n"; }
catch (Throwable $error) { error_log('Participant migration: ' . $error->getMessage()); fwrite(STDERR, "Migrasi gagal. Periksa koneksi dan log aplikasi.\n"); exit(1); }
