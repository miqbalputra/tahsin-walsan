<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/includes/participant_merge_helper.php';
if (!in_array('--apply',$argv,true)) {
    echo "DRY-RUN: tambah jurnal (jika belum ada) dan pengalihan ID peserta. Data existing tidak diubah.\n";
    echo "Setelah backup dan verifikasi staging: php migrate_participant_merge.php --apply\n";
    exit;
}
require __DIR__.'/config/database.php';
try { participantMergeApplyMigration($pdo); echo "Penggabungan peserta siap.\n"; }
catch (Throwable $error) { error_log('Participant merge migration: '.$error->getMessage()); fwrite(STDERR,"Migrasi gagal. Periksa koneksi dan log aplikasi.\n"); exit(1); }
