<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/includes/attendance_form_helper.php';
if (!in_array('--apply',$argv,true)) {
    echo "DRY-RUN: tambah jurnal pengiriman formulir tanpa mengganti atau mengosongkan tabel existing.\nGunakan --apply setelah backup dan pemeriksaan staging.\n";
    exit;
}
require_once __DIR__ . '/config/database.php';
try { attendanceFormApplyMigration($pdo); echo "Jurnal formulir siap. Tabel existing tidak diganti atau dikosongkan.\n"; }
catch (Throwable $error) { error_log('Attendance form migration: '.$error->getMessage()); fwrite(STDERR,"Migrasi belum selesai. Periksa log; catatan existing dipertahankan.\n"); exit(1); }
