<?php
require_once __DIR__ . '/config/database.php';
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/includes/auth_helper.php';
    checkRole(['admin']);
}

$apply = (($_GET['apply'] ?? '') === '1') || getenv('APPLY_MIGRATIONS') === '1';

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

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}
echo $apply
    ? "Mode APPLY: hanya index yang belum ada dan tidak redundant yang akan dibuat.\n"
    : "Mode DRY-RUN: tidak ada perubahan. Jalankan APPLY_MIGRATIONS=1 php migrate_performance_indexes.php setelah backup tervalidasi.\n";

foreach ($indexes as [$table, $indexName, $sql]) {
    $tableStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? AND table_type = 'BASE TABLE'");
    $tableStmt->execute([$table]);
    if ((int) $tableStmt->fetchColumn() === 0) {
        echo "SKIP {$indexName} table {$table} tidak ditemukan\n";
        continue;
    }

    preg_match('/ON\s+\w+\s*\(([^)]+)\)/i', $sql, $columnMatch);
    $requestedColumns = array_map(static fn($column) => trim(trim($column), '` '), explode(',', $columnMatch[1] ?? ''));
    $columnStmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?");
    $columnStmt->execute([$table]);
    $availableColumns = $columnStmt->fetchAll(PDO::FETCH_COLUMN);
    if (array_diff($requestedColumns, $availableColumns)) {
        echo "SKIP {$indexName} kolom tidak lengkap di {$table}\n";
        continue;
    }

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

    $equivalentStmt = $pdo->prepare("
        SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS columns_signature
        FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = ? AND index_name <> 'PRIMARY'
        GROUP BY index_name
    ");
    $equivalentStmt->execute([$table]);
    $requestedSignature = implode(',', $requestedColumns);
    $equivalent = null;
    foreach ($equivalentStmt->fetchAll() as $existingIndex) {
        if ((string) $existingIndex['columns_signature'] === $requestedSignature) {
            $equivalent = $existingIndex['index_name'];
            break;
        }
    }
    if ($equivalent !== null) {
        echo "SKIP {$indexName} redundant; equivalent {$equivalent} exists\n";
        continue;
    }

    if (!$apply) {
        echo "PLAN {$indexName} on {$table} ({$requestedSignature})\n";
        continue;
    }

    $pdo->exec($sql);
    echo "OK   {$indexName} created\n";
}
