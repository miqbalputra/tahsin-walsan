<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__ . '/includes/participant_import_helper.php';
if (!in_array('--apply',$argv,true)) {
    echo "DRY-RUN: tambah tabel jurnal impor peserta. Data peserta, anak, presensi dan halaqoh tidak diubah.\n";
    echo "Setelah backup dan verifikasi staging: php migrate_participant_import.php --apply\n";
    exit;
}
require __DIR__ . '/config/database.php';
try { participantImportApplyMigration($pdo); echo "Jurnal impor peserta siap.\n"; }
catch (Throwable $error) { error_log('Participant import migration: ' . $error->getMessage()); fwrite(STDERR,"Migrasi jurnal impor gagal. Periksa koneksi dan log aplikasi.\n"); exit(1); }
