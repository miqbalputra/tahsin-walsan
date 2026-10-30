<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/includes/attendance_history_helper.php';
if (!in_array('--apply', $argv, true)) {
    echo "DRY-RUN: tambah jurnal versi presensi dan trigger salinan sebelum update/hapus. Data existing tidak diubah.\n";
    echo "Setelah backup dan verifikasi staging: php migrate_attendance_history.php --apply\n";
    exit;
}
require __DIR__ . '/config/database.php';
try { attendanceHistoryApplyMigration($pdo); echo "Jurnal pemulihan presensi siap.\n"; }
catch (Throwable $error) { error_log('Attendance history migration: ' . $error->getMessage()); fwrite(STDERR, "Migrasi belum selesai. Periksa log; data presensi existing dipertahankan.\n"); exit(1); }
