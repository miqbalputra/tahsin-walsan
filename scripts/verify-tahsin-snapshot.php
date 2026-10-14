<?php
/** Restore a local CSV snapshot into a NEW isolated staging database; never connects to the app DB. */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/tahsin_migration_helper.php';
require_once __DIR__ . '/../includes/tahsin_report_helper.php';
$directory = realpath($argv[1] ?? __DIR__ . '/../backup');
$database = $argv[2] ?? 'tahsin_test_snapshot_' . getmypid();
if (!$directory || !preg_match('/^tahsin_test_[a-z0-9_]+$/', $database)) throw new RuntimeException('Provide a local snapshot directory and a NEW tahsin_test_* database.');
$schema = file_get_contents($directory . '/schema.sql');
if (!$schema || preg_match('/\b(DROP|TRUNCATE|USE|GRANT|REVOKE|OUTFILE|LOAD\s+DATA)\b/i', $schema)) throw new RuntimeException('Snapshot schema must contain only additive table definitions.');
$pdo = new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4', 'root', 'tahsin_local_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$database}`");
$pdo->exec("SET time_zone = '+07:00'");
$pdo->exec(str_replace('utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci', $schema));
$tables = ['users', 'wali_santri', 'halaqoh', 'santri_detail', 'halaqoh_members', 'presensi'];
$columnsByTable = $before = [];
$pdo->beginTransaction();
try {
    foreach ($tables as $table) {
        $file = fopen($directory . '/' . $table . '.csv', 'rb');
        if (!$file) throw new RuntimeException("Missing snapshot table: {$table}");
        $headers = fgetcsv($file, 0, ',', '"', '');
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        $columns = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll();
        $available = array_column($columns, 'Field');
        if (array_diff($headers, $available)) throw new RuntimeException("Snapshot columns mismatch: {$table}");
        $nullable = [];
        foreach ($columns as $column) $nullable[$column['Field']] = $column['Null'] === 'YES';
        $insert = $pdo->prepare("INSERT INTO `{$table}` (`" . implode('`,`', $headers) . '`) VALUES (' . implode(',', array_fill(0, count($headers), '?')) . ')');
        while (($values = fgetcsv($file, 0, ',', '"', '')) !== false) {
            if (count($values) !== count($headers)) throw new RuntimeException("Malformed snapshot row: {$table}");
            foreach ($values as $index => &$value) if ($nullable[$headers[$index]] && ($value === '' || $value === '\\N')) $value = null;
            unset($value);
            $insert->execute($values);
        }
        fclose($file);
        $columnsByTable[$table] = $headers;
    }
    $pdo->commit();
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
foreach ($tables as $table) {
    $source = $pdo->query("SELECT `" . implode('`,`', $columnsByTable[$table]) . "` FROM `{$table}` ORDER BY id")->fetchAll();
    $before[$table] = ['rows' => count($source), 'hash' => hash('sha256', json_encode($source))];
}
$range = $pdo->query('SELECT MIN(tanggal) AS first_date, MAX(tanggal) AS last_date FROM presensi')->fetch();
$filters = tahsinFilters(['start_date' => tahsinSessionDate($range['first_date']), 'end_date' => $range['last_date']]);
$beforeReport = tahsinFetchReport($pdo, $filters);
tahsinApplyMigration($pdo);
$integrity = [];
foreach ($tables as $table) {
    $source = $pdo->query("SELECT `" . implode('`,`', $columnsByTable[$table]) . "` FROM `{$table}` ORDER BY id")->fetchAll();
    $same = count($source) === $before[$table]['rows'] && hash_equals($before[$table]['hash'], hash('sha256', json_encode($source)));
    if (!$same) throw new RuntimeException("Existing data changed during migration: {$table}");
    $integrity[$table] = ['rows' => count($source), 'preserved' => true];
}
$afterReport = tahsinFetchReport($pdo, $filters);
foreach (['hadir', 'sakit', 'izin', 'alpha', 'total_presensi', 'conflicts'] as $key) {
    if ($beforeReport['summary'][$key] !== $afterReport['summary'][$key]) throw new RuntimeException("Historical canonical report changed during schema migration: {$key}");
}
if ($afterReport['summary']['coverage_percent'] !== null) throw new RuntimeException('Historical completeness must remain unknown.');
echo json_encode(['result' => 'PASS', 'staging_database' => $database, 'tables' => $integrity,
    'historical_report' => array_intersect_key($afterReport['summary'], array_flip(['peserta', 'hadir', 'sakit', 'izin', 'alpha', 'conflicts', 'coverage_percent'])),
    'historical_totals_preserved' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
