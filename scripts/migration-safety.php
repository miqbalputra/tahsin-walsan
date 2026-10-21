<?php
/** Explicit CLI operations; no application environment fallback and no automatic production cutover. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/migration_safety_helper.php';
$command = $argv[1] ?? 'help';
if ($command === 'help') {
    echo "Tahsin migration safety (MariaDB / MySQL 8.0, 8.4 profiles)\n";
    echo "inventory --out=NEW_PRIVATE_DIRECTORY\nbackup --lock-writes --out=NEW_PRIVATE_DIRECTORY\n";
    echo "verify --backup=PACKAGE\ncapture-delta --lock-writes --backup=PACKAGE --out=NEW_PRIVATE_DIRECTORY\n";
    echo "restore-drill --backup=PACKAGE --out=NEW_PRIVATE_DIRECTORY\nreplay-drill --backup=PACKAGE --delta=PACKAGE --out=NEW_PRIVATE_DIRECTORY\n";
    exit;
}
$options = [];
foreach (array_slice($argv, 2) as $option) {
    if (!preg_match('/^--([a-z-]+)(?:=(.+))?$/D', $option, $match) || isset($options[$match[1]])) { fwrite(STDERR, "Parameter tidak valid.\n"); exit(2); }
    $options[$match[1]] = $match[2] ?? true;
}
$allowed = ['inventory' => ['out'], 'backup' => ['out', 'lock-writes'], 'verify' => ['backup'],
    'capture-delta' => ['out', 'backup', 'lock-writes'], 'restore-drill' => ['out', 'backup'], 'replay-drill' => ['out', 'backup', 'delta']];
try {
    if (!isset($allowed[$command]) || array_diff(array_keys($options), $allowed[$command]) || array_diff($allowed[$command], array_keys($options))) throw new RuntimeException('Parameter wajib tidak lengkap atau operasi tidak dikenal.');
    if (isset($options['lock-writes']) && $options['lock-writes'] !== true) throw new RuntimeException('Gunakan flag --lock-writes pada jendela pemeliharaan.');
    foreach ($options as $key => $value) if ($key !== 'lock-writes' && !is_string($value)) throw new RuntimeException('Path wajib diisi.');
    $directory = isset($options['out']) ? safetyPrivateDirectory($options['out']) : null;
    $started = microtime(true);
    if ($command === 'verify') {
        $package = safetyReadPackage($options['backup']);
        $result = ['ok' => true, 'kind' => $package['kind'], 'files' => count($package['files'])];
    } elseif ($command === 'restore-drill') $result = safetyRestore($options['backup'], $directory);
    elseif ($command === 'replay-drill') $result = safetyReplay($options['backup'], $options['delta'], $directory);
    else {
        $config = safetyConfig('TAHSIN_SAFETY_DB_'); $pdo = safetyConnect($config);
        if ($command === 'backup') $result = safetyBackup($pdo, $config, $directory);
        elseif ($command === 'capture-delta') $result = safetyDelta($pdo, $config, $options['backup'], $directory);
        else {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $pdo->beginTransaction();
            try { $baseline = safetyBaseline($pdo, $directory); $pdo->commit(); }
            catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            $variables = $pdo->query("SHOW GLOBAL VARIABLES WHERE Variable_name IN ('log_bin','binlog_format','sync_binlog','innodb_flush_log_at_trx_commit','event_scheduler','time_zone','system_time_zone')")->fetchAll();
            safetyJson($directory . '/inventory.json', ['source' => safetyIdentity($pdo), 'baseline' => $baseline, 'variables' => $variables,
                'position' => safetyPosition($pdo), 'ddl_frozen' => false, 'production_cutover_ready' => false]);
            $result = ['ok' => true, 'inventory' => $directory . '/inventory.json', 'tables' => count($baseline['tables']), 'objects' => count($baseline['objects'])];
        }
    }
    $result['elapsed_seconds'] = round(microtime(true) - $started, 3);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"); exit(1);
}
