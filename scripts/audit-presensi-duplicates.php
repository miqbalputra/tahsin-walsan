<?php
/**
 * Read-only audit for duplicate attendance keys.
 *
 * Run before and after deployment:
 *   php scripts/audit-presensi-duplicates.php
 */
require_once __DIR__ . '/../config/database.php';

$stmt = $pdo->query("SELECT halaqoh_id, wali_santri_id, tanggal, COUNT(*) AS total
                     FROM presensi
                     GROUP BY halaqoh_id, wali_santri_id, tanggal
                     HAVING COUNT(*) > 1
                     ORDER BY tanggal DESC, halaqoh_id, wali_santri_id");
$duplicates = $stmt->fetchAll();

echo json_encode([
    'duplicate_keys' => count($duplicates),
    'duplicate_rows' => array_sum(array_map(static fn($row) => (int) $row['total'], $duplicates)),
    'rows' => $duplicates,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($duplicates === [] ? 0 : 2);
