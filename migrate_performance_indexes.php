<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_helper.php';

checkRole(['admin']);

$indexes = [
    ['presensi', 'idx_presensi_tanggal', 'CREATE INDEX idx_presensi_tanggal ON presensi (tanggal)'],
    ['presensi', 'idx_presensi_halaqoh_tanggal', 'CREATE INDEX idx_presensi_halaqoh_tanggal ON presensi (halaqoh_id, tanggal)'],
    ['presensi', 'idx_presensi_wali_tanggal', 'CREATE INDEX idx_presensi_wali_tanggal ON presensi (wali_santri_id, tanggal)'],
    ['presensi', 'idx_presensi_status_tanggal', 'CREATE INDEX idx_presensi_status_tanggal ON presensi (status, tanggal)'],
    ['halaqoh', 'idx_halaqoh_ustadz', 'CREATE INDEX idx_halaqoh_ustadz ON halaqoh (ustadz_id)'],
    ['halaqoh_members', 'idx_halaqoh_members_halaqoh', 'CREATE INDEX idx_halaqoh_members_halaqoh ON halaqoh_members (halaqoh_id)'],
    ['halaqoh_members', 'idx_halaqoh_members_wali', 'CREATE INDEX idx_halaqoh_members_wali ON halaqoh_members (wali_santri_id)'],
    ['santri_detail', 'idx_santri_detail_wali', 'CREATE INDEX idx_santri_detail_wali ON santri_detail (wali_santri_id)'],
    ['santri_detail', 'idx_santri_detail_kelas_wali', 'CREATE INDEX idx_santri_detail_kelas_wali ON santri_detail (kelas, wali_santri_id)'],
];

header('Content-Type: text/plain; charset=utf-8');

foreach ($indexes as [$table, $indexName, $sql]) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
        AND table_name = ?
        AND index_name = ?
    ");
    $stmt->execute([$table, $indexName]);

    if ((int) $stmt->fetchColumn() > 0) {
        echo "SKIP {$indexName} already exists\n";
        continue;
    }

    $pdo->exec($sql);
    echo "OK   {$indexName} created\n";
}
