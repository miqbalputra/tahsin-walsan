<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/includes/participant_merge_helper.php';
require_once __DIR__.'/includes/data_quality_migration_helper.php';
if (!in_array('--apply',$argv,true)) {
    echo "DRY-RUN: tambah jurnal pemeriksaan, receipt undo, metadata verifikasi per program dan penghitung revisi. Data domain lama tidak diubah.\n";
    echo "Setelah backup dan uji pemulihan: php migrate_data_quality.php --apply\n"; exit;
}
require __DIR__.'/config/database.php';
try { participantMergeApplyMigration($pdo); dataQualityApplyMigration($pdo); echo "Pemeriksaan data dan undo merge siap.\n"; }
catch (Throwable $e) { error_log('Data quality migration: '.$e->getMessage()); fwrite(STDERR,"Migrasi belum selesai. Periksa log; jangan jalankan pembersihan data.\n"); exit(1); }
