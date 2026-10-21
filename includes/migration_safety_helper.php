<?php
/** CLI-only database recovery tooling. Never loads application DB credentials. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);

function safetyProfile(string $version): string
{
    if (str_contains(strtolower($version), 'mariadb')) return 'mariadb';
    if (preg_match('/^8\.(?:0|4)\./', $version)) return 'mysql';
    throw new RuntimeException('Versi mesin ini belum memiliki profil pemulihan terverifikasi.');
}

function safetyDumpStages(string $profile): array
{
    $common = ['--single-transaction', '--quick', '--skip-lock-tables', '--skip-add-locks', '--hex-blob', '--default-character-set=utf8mb4'];
    if ($profile === 'mysql') $common = array_merge($common, ['--no-tablespaces', '--column-statistics=0', '--set-gtid-purged=OFF']);
    $stages = ['schema.sql' => ['--no-data', '--skip-triggers', '--routines', '--skip-events'],
        'data.sql' => ['--no-create-info', '--skip-triggers', '--skip-routines', '--skip-events'],
        'objects.sql' => ['--no-data', '--no-create-info', '--triggers', '--skip-routines', '--events']];
    return array_map(static fn($options) => array_merge($common, $options), $stages);
}

function safetyIdentifier(string $name): string
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D', $name)) throw new RuntimeException('Identitas database/objek tidak didukung.');
    return '`' . $name . '`';
}

function safetyConfig(string $prefix): array
{
    $result = [];
    foreach (['HOST', 'PORT', 'USERNAME', 'PASSWORD'] as $key) {
        $value = getenv($prefix . $key);
        if ($value === false || ($value === '' && $key !== 'PASSWORD')) throw new RuntimeException('Environment wajib belum diisi: ' . $prefix . $key);
        $result[strtolower($key)] = $value;
    }
    if (!ctype_digit($result['port']) || (int)$result['port'] < 1 || (int)$result['port'] > 65535) throw new RuntimeException('Port tidak valid.');
    if (preg_match('/[;\r\n\x00]/', $result['host'])) throw new RuntimeException('Host tidak valid.');
    $result['database'] = (string)getenv($prefix . 'DATABASE');
    if ($result['database'] !== '') safetyIdentifier($result['database']);
    return $result;
}

function safetyConnect(array $config, bool $withDatabase = true): PDO
{
    $dsn = 'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';charset=utf8mb4';
    if ($withDatabase) {
        safetyIdentifier($config['database']);
        $dsn .= ';dbname=' . $config['database'];
    }
    $pdo = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true,
        PDO::ATTR_TIMEOUT => 10]);
    $pdo->exec("SET time_zone='+07:00'");
    $pdo->exec('SET SESSION lock_wait_timeout=10');
    return $pdo;
}

function safetyJson(string $path, array $value): void
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $handle = fopen($path, 'xb');
    if (!$handle) throw new RuntimeException('Berkas bukti sudah ada atau tidak dapat dibuat.');
    chmod($path, 0600);
    try { if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) throw new RuntimeException('Gagal menulis bukti lengkap.'); }
    finally { fclose($handle); }
}

function safetyPrivateDirectory(string $path): string
{
    $parent = realpath(dirname($path));
    if (!$parent || is_link($path) || file_exists($path)) throw new RuntimeException('Pilih direktori baru dengan parent yang sudah ada.');
    $project = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    $resolved = str_replace('\\', '/', $parent . '/' . basename($path));
    if (str_starts_with(strtolower($resolved) . '/', strtolower($project) . '/')) throw new RuntimeException('Backup/bukti wajib disimpan di luar workspace/web root.');
    if (!mkdir($path, 0700)) throw new RuntimeException('Tidak dapat membuat direktori privat.');
    return realpath($path);
}

function safetyIdentity(PDO $pdo): array
{
    return $pdo->query('SELECT @@hostname AS hostname,@@port AS port,@@datadir AS datadir,@@server_id AS server_id,VERSION() AS version')->fetch();
}

function safetyPosition(PDO $pdo): ?array
{
    if (!(int)$pdo->query('SELECT @@log_bin')->fetchColumn()) return null;
    $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $row = $pdo->query(safetyProfile($version) === 'mysql' && preg_match('/^8\.4\./', $version) ? 'SHOW BINARY LOG STATUS' : 'SHOW MASTER STATUS')->fetch();
    if (!$row || (int)$row['Position'] < 4) throw new RuntimeException('Format/posisi binlog belum didukung; jangan mengaktifkan penulis baru.');
    return ['file' => $row['File'], 'position' => (int)$row['Position'], 'format' => $pdo->query('SELECT @@binlog_format')->fetchColumn()];
}

function safetyRowLine(array $row): string
{
    return json_encode(array_map(static fn($v) => $v === null ? null : base64_encode((string)$v), array_values($row)), JSON_THROW_ON_ERROR) . "\n";
}

/** Global read lock is held by the caller for backup/delta; inventory uses a read view. */
function safetyBaseline(PDO $pdo, string $directory): array
{
    $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $stmt = $pdo->prepare('SELECT DEFAULT_CHARACTER_SET_NAME AS charset,DEFAULT_COLLATION_NAME AS collation FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
    $stmt->execute([$database]);
    $baseline = ['database' => $database, 'encoding' => $stmt->fetch(), 'tables' => [], 'objects' => []];
    $tables = $pdo->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll();
    foreach ($tables as $number => $table) {
        $name = $table['TABLE_NAME']; $quoted = safetyIdentifier($name);
        $columns = $pdo->query('SHOW COLUMNS FROM ' . $quoted)->fetchAll();
        $keys = $pdo->query("SHOW INDEX FROM {$quoted} WHERE Key_name='PRIMARY'")->fetchAll();
        usort($keys, static fn($a, $b) => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
        if (!$keys) throw new RuntimeException('Tabel tanpa primary key perlu prosedur rekonsiliasi tersendiri: ' . $name);
        $keyNames = array_column($keys, 'Column_name');
        $order = implode(',', array_map('safetyIdentifier', $keyNames));
        $rowFile = sprintf('rows-%04d.jsonl', $number); $handle = fopen($directory . '/' . $rowFile, 'xb');
        if (!$handle) throw new RuntimeException('Gagal membuat hash baris.');
        chmod($directory . '/' . $rowFile, 0600);
        $hash = hash_init('sha256'); $count = 0;
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $rows = $pdo->query('SELECT * FROM ' . $quoted . ' ORDER BY ' . $order);
        try {
            while ($row = $rows->fetch()) {
                $line = safetyRowLine($row); hash_update($hash, $line); $count++;
                $key = array_map(static fn($k) => $row[$k] === null ? null : base64_encode((string)$row[$k]), $keyNames);
                $evidence = json_encode(['key' => $key, 'sha256' => hash('sha256', $line)], JSON_THROW_ON_ERROR) . "\n";
                if (fwrite($handle, $evidence) !== strlen($evidence)) throw new RuntimeException('Disk tidak dapat menampung hash baris.');
            }
            if (!fflush($handle)) throw new RuntimeException('Gagal menyelesaikan hash baris.');
        } finally { fclose($handle); $rows->closeCursor(); $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); }
        $ddl = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch()['Create Table'];
        preg_match('/\bAUTO_INCREMENT=(\d+)/', $ddl, $auto);
        $baseline['tables'][$name] = ['rows' => $count, 'sha256' => hash_final($hash), 'primary_key' => $keyNames,
            'columns' => array_column($columns, 'Field'), 'engine' => $table['ENGINE'], 'rows_file' => $rowFile,
            'next_id' => $auto[1] ?? null, 'ddl_sha256' => hash('sha256', preg_replace('/\bAUTO_INCREMENT=\d+\s*/', '', $ddl))];
    }
    foreach (['VIEW' => ["SELECT TABLE_NAME AS name FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME", 'Create View'],
        'TRIGGER' => ["SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME", 'SQL Original Statement'],
        'PROCEDURE' => ["SELECT ROUTINE_NAME AS name FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_TYPE='PROCEDURE' ORDER BY ROUTINE_NAME", 'Create Procedure'],
        'FUNCTION' => ["SELECT ROUTINE_NAME AS name FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_TYPE='FUNCTION' ORDER BY ROUTINE_NAME", 'Create Function'],
        'EVENT' => ["SELECT EVENT_NAME AS name FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE() ORDER BY EVENT_NAME", 'Create Event']] as $kind => [$query, $field]) {
        foreach ($pdo->query($query)->fetchAll() as $object) {
            $definition = $pdo->query('SHOW CREATE ' . $kind . ' ' . safetyIdentifier($object['name']))->fetch();
            if (!is_string($definition[$field] ?? null)) throw new RuntimeException('Definisi objek tidak dapat diperiksa.');
            $semantic = [$definition[$field], $definition['sql_mode'] ?? null, $definition['time_zone'] ?? null,
                $definition['character_set_client'] ?? null, $definition['collation_connection'] ?? null];
            $baseline['objects'][$kind . ':' . $object['name']] = hash('sha256', json_encode($semantic, JSON_THROW_ON_ERROR));
        }
    }
    return $baseline;
}

function safetyDifferences(array $expected, array $actual): array
{
    $differences = [];
    foreach (['database', 'encoding', 'objects'] as $field) if ($expected[$field] !== $actual[$field]) $differences[] = $field;
    if (array_keys($expected['tables']) !== array_keys($actual['tables'])) $differences[] = 'table-list';
    foreach ($expected['tables'] as $name => $table) {
        if (!isset($actual['tables'][$name])) continue;
        foreach (['rows', 'sha256', 'primary_key', 'columns', 'engine', 'ddl_sha256'] as $field) {
            if ($table[$field] !== $actual['tables'][$name][$field]) $differences[] = $name . ':' . $field;
        }
        if ($table['next_id'] !== null && (int)$actual['tables'][$name]['next_id'] < (int)$table['next_id']) $differences[] = $name . ':next_id';
    }
    return $differences;
}

function safetyClientFile(array $config, string $directory): string
{
    $path = $directory . '/client-' . bin2hex(random_bytes(6)) . '.cnf';
    $handle = fopen($path, 'xb');
    if (!$handle) throw new RuntimeException('Gagal membuat konfigurasi client sementara.');
    chmod($path, 0600);
    $escape = static fn($v) => '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string)$v) . '"';
    try {
        $text = "[client]\nprotocol=tcp\n";
        foreach (['host', 'port', 'username' => 'user', 'password'] as $key => $name) {
            $field = is_int($key) ? $name : $key; $text .= $name . '=' . $escape($config[$field]) . "\n";
        }
        if (fwrite($handle, $text) !== strlen($text)) throw new RuntimeException('Gagal menulis konfigurasi client.');
    } finally { fclose($handle); }
    return $path;
}

function safetyNative(string $binary, array $args, array $config, string $directory, string $output, ?string $input = null): void
{
    if (!is_file($binary) || !is_executable($binary)) throw new RuntimeException('Path binary native wajib valid.');
    $client = safetyClientFile($config, $directory);
    $error = $directory . '/native-' . bin2hex(random_bytes(5)) . '.stderr';
    $descriptors = [0 => ['file', $input ?? (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), 'r'],
        1 => ['file', $output, 'wb'], 2 => ['file', $error, 'wb']];
    $process = null;
    try {
        $process = proc_open(array_merge([$binary, '--defaults-file=' . $client], $args), $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        chmod($output, 0600); chmod($error, 0600);
        if (!is_resource($process)) throw new RuntimeException('Tidak dapat menjalankan client native.');
        $deadline = microtime(true) + max(10, (int)(getenv('TAHSIN_SAFETY_TIMEOUT') ?: 120));
        do {
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true) > $deadline) { proc_terminate($process); throw new RuntimeException('Client native melewati batas waktu.'); }
            usleep(20000);
        } while (true);
        $exitCode = $status['exitcode']; proc_close($process); $process = null;
        if ($exitCode !== 0) throw new RuntimeException('Client native gagal; lihat berkas stderr privat pada direktori bukti.');
    } finally {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        if (is_file($client)) unlink($client);
    }
}

function safetyFinishPackage(string $directory, array $manifest): array
{
    $files = [];
    foreach (new DirectoryIterator($directory) as $file) {
        if ($file->isDot()) continue;
        if (!$file->isFile() || $file->isLink() || str_ends_with($file->getFilename(), '.cnf')) throw new RuntimeException('Isi paket tidak valid.');
        $files[$file->getFilename()] = ['bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getPathname())];
    }
    ksort($files); $manifest['files'] = $files;
    safetyJson($directory . '/manifest.json', $manifest);
    return ['ok' => true, 'directory' => $directory, 'manifest_sha256' => hash_file('sha256', $directory . '/manifest.json'),
        'kind' => $manifest['kind'], 'tables' => count($manifest['baseline']['tables']), 'objects' => count($manifest['baseline']['objects'])];
}

function safetyReadPackage(string $directory, ?string $kind = null): array
{
    $directory = realpath($directory) ?: throw new RuntimeException('Direktori paket tidak ditemukan.');
    $path = $directory . '/manifest.json';
    if (is_link($path)) throw new RuntimeException('Manifest symlink ditolak.');
    $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['format'] ?? '') !== 'tahsin-migration-safety-v1' || ($kind && ($manifest['kind'] ?? '') !== $kind)) throw new RuntimeException('Jenis paket tidak sesuai.');
    if (empty($manifest['files']) || !isset($manifest['baseline'], $manifest['source'])) throw new RuntimeException('Manifest tidak lengkap.');
    foreach ($manifest['files'] as $name => $expected) {
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/D', $name) || $name === 'manifest.json') throw new RuntimeException('Path paket tidak valid.');
        $file = $directory . '/' . $name;
        if (!is_file($file) || is_link($file) || filesize($file) !== $expected['bytes'] || !hash_equals($expected['sha256'], hash_file('sha256', $file))) throw new RuntimeException('Paket tidak utuh: ' . $name);
    }
    foreach ($manifest['baseline']['tables'] as $table) if (!isset($manifest['files'][$table['rows_file'] ?? ''])) throw new RuntimeException('Bukti hash baris hilang.');
    return $manifest;
}

function safetyBackup(PDO $pdo, array $config, string $directory): array
{
    $source = safetyIdentity($pdo);
    $profile = safetyProfile($source['version']);
    $locked = false;
    try {
        $pdo->exec('FLUSH TABLES WITH READ LOCK'); $locked = true;
        $position = safetyPosition($pdo);
        $baseline = safetyBaseline($pdo, $directory);
        $binary = (string)getenv('TAHSIN_SAFETY_DUMP_BINARY');
        $stages = safetyDumpStages($profile);
        foreach ($stages as $name => $options) safetyNative($binary, array_merge($options, [$config['database']]), $config, $directory, $directory . '/' . $name);
        if (safetyPosition($pdo) !== $position) throw new RuntimeException('Posisi log berubah selama backup terkunci.');
    } finally { if ($locked) $pdo->exec('UNLOCK TABLES'); }
    return safetyFinishPackage($directory, ['format' => 'tahsin-migration-safety-v1', 'kind' => 'backup', 'created_at' => date(DATE_ATOM),
        'source' => $source, 'position' => $position, 'baseline' => $baseline, 'restore_order' => array_keys($stages),
        'scope' => 'application-database-only; server accounts, config, volumes and off-server copies are separate requirements']);
}

function safetyDelta(PDO $pdo, array $config, string $backupDirectory, string $directory): array
{
    $backup = safetyReadPackage($backupDirectory, 'backup');
    if (safetyIdentity($pdo) !== $backup['source'] || $config['database'] !== $backup['baseline']['database']) throw new RuntimeException('Sumber delta berbeda dari backup.');
    $start = $backup['position'];
    if (!$start || $start['format'] !== 'ROW') throw new RuntimeException('Backup harus memiliki posisi binlog ROW.');
    $locked = false;
    try {
        $pdo->exec('FLUSH TABLES WITH READ LOCK'); $locked = true;
        $end = safetyPosition($pdo);
        if (!$end || $end['format'] !== 'ROW') throw new RuntimeException('Binlog ROW wajib untuk delta.');
        $logs = array_column($pdo->query('SHOW BINARY LOGS')->fetchAll(), 'Log_name');
        $first = array_search($start['file'], $logs, true); $last = array_search($end['file'], $logs, true);
        if ($first === false || $last === false || $first > $last || ($first === $last && $start['position'] > $end['position'])) throw new RuntimeException('Rantai binlog tidak lengkap.');
        $baseline = safetyBaseline($pdo, $directory);
        if ($baseline['objects'] !== $backup['baseline']['objects'] || array_keys($baseline['tables']) !== array_keys($backup['baseline']['tables'])) throw new RuntimeException('Perubahan skema selama jendela delta belum didukung.');
        foreach ($baseline['tables'] as $name => $table) if ($table['ddl_sha256'] !== $backup['baseline']['tables'][$name]['ddl_sha256']) throw new RuntimeException('DDL berubah selama jendela delta.');
        $args = ['--read-from-remote-server', '--database=' . $config['database'], '--start-position=' . $start['position'], '--stop-position=' . $end['position']];
        safetyNative((string)getenv('TAHSIN_SAFETY_BINLOG_BINARY'), array_merge($args, array_slice($logs, $first, $last - $first + 1)), $config, $directory, $directory . '/delta.sql');
    } finally { if ($locked) $pdo->exec('UNLOCK TABLES'); }
    return safetyFinishPackage($directory, ['format' => 'tahsin-migration-safety-v1', 'kind' => 'delta', 'created_at' => date(DATE_ATOM),
        'source' => $backup['source'], 'backup_manifest_sha256' => hash_file('sha256', $backupDirectory . '/manifest.json'),
        'start' => $start, 'end' => $end, 'baseline' => $baseline]);
}

function safetyRecovery(array $source): array
{
    $config = safetyConfig('TAHSIN_RECOVERY_DB_');
    if (!in_array($config['host'], ['127.0.0.1', '::1'], true) || (int)$config['port'] === 3306) throw new RuntimeException('Recovery hanya untuk instance loopback terpisah pada port nondefault.');
    $pdo = safetyConnect($config, false); $identity = safetyIdentity($pdo);
    $normalize = static fn($v) => strtolower(rtrim(str_replace('\\', '/', $v), '/'));
    if ($normalize($identity['datadir']) === $normalize($source['datadir'])) throw new RuntimeException('Recovery menolak datadir sumber.');
    $guardPath = (string)getenv('TAHSIN_RECOVERY_GUARD_FILE'); $guardFile = realpath($guardPath);
    if (!$guardFile || is_link($guardPath) || $normalize(dirname($guardFile)) !== $normalize($identity['datadir'])) throw new RuntimeException('Guard wajib berada di datadir instance recovery yang benar.');
    $guard = json_decode(file_get_contents($guardFile), true, 512, JSON_THROW_ON_ERROR);
    if (($guard['purpose'] ?? '') !== 'tahsin-recovery-only' || (int)($guard['port'] ?? 0) !== (int)$config['port']) throw new RuntimeException('Guard instance recovery tidak cocok.');
    if (strtoupper((string)$pdo->query('SELECT @@event_scheduler')->fetchColumn()) === 'ON') throw new RuntimeException('Event scheduler recovery wajib OFF/DISABLED.');
    if (preg_replace('/-log$/', '', $identity['version']) !== preg_replace('/-log$/', '', $source['version'])) throw new RuntimeException('Versi mesin recovery harus sama dengan sumber.');
    return [$pdo, $config];
}

function safetyRestore(string $backupDirectory, string $directory): array
{
    $backup = safetyReadPackage($backupDirectory, 'backup');
    foreach (['schema.sql', 'data.sql', 'objects.sql'] as $required) if (!isset($backup['files'][$required])) throw new RuntimeException('Tahap backup hilang.');
    if ($backup['restore_order'] !== ['schema.sql', 'data.sql', 'objects.sql']) throw new RuntimeException('Urutan pemulihan tidak valid.');
    [$pdo, $config] = safetyRecovery($backup['source']);
    $database = $backup['baseline']['database']; safetyIdentifier($database);
    $schemas = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('information_schema','performance_schema','mysql','sys')")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($schemas as $schema) {
        if ($schema !== 'test') throw new RuntimeException('Instance recovery harus kosong; database yang sudah ada tidak diubah.');
        if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='test'")->fetchColumn()) throw new RuntimeException('Instance recovery memiliki tabel pengguna.');
    }
    $encoding = $backup['baseline']['encoding'];
    $pdo->exec('CREATE DATABASE ' . safetyIdentifier($database) . ' CHARACTER SET ' . safetyIdentifier($encoding['charset']) . ' COLLATE ' . safetyIdentifier($encoding['collation']));
    $config['database'] = $database;
    foreach ($backup['restore_order'] as $stage) safetyNative((string)getenv('TAHSIN_SAFETY_CLIENT_BINARY'), ['--binary-mode', '--default-character-set=utf8mb4', $database], $config, $directory, $directory . '/' . $stage . '.stdout', $backupDirectory . '/' . $stage);
    $pdo->exec('USE ' . safetyIdentifier($database));
    $actual = safetyBaseline($pdo, $directory); $differences = safetyDifferences($backup['baseline'], $actual);
    safetyJson($directory . '/verification.json', ['ok' => !$differences, 'differences' => $differences, 'baseline' => $actual,
        'backup_manifest_sha256' => hash_file('sha256', $backupDirectory . '/manifest.json'), 'target' => safetyIdentity($pdo)]);
    if ($differences) throw new RuntimeException('Hasil restore berbeda; lihat verification.json privat. Database pemulihan dipertahankan untuk pemeriksaan.');
    return ['ok' => true, 'database' => $database, 'tables' => count($actual['tables']), 'objects' => count($actual['objects']), 'verification' => $directory . '/verification.json'];
}

function safetyReplay(string $backupDirectory, string $deltaDirectory, string $directory): array
{
    $backup = safetyReadPackage($backupDirectory, 'backup'); $delta = safetyReadPackage($deltaDirectory, 'delta');
    if ($delta['backup_manifest_sha256'] !== hash_file('sha256', $backupDirectory . '/manifest.json') || $delta['source'] !== $backup['source'] || $delta['start'] !== $backup['position']) throw new RuntimeException('Delta tidak terikat pada backup ini.');
    if (!isset($delta['files']['delta.sql'])) throw new RuntimeException('Berkas delta hilang.');
    [$pdo, $config] = safetyRecovery($backup['source']); $database = $backup['baseline']['database'];
    $pdo->exec('USE ' . safetyIdentifier($database)); $before = safetyBaseline($pdo, $directory);
    if (safetyDifferences($backup['baseline'], $before)) throw new RuntimeException('Replay hanya menerima hasil restore tepat dari backup; replay kedua ditolak.');
    $config['database'] = $database;
    safetyNative((string)getenv('TAHSIN_SAFETY_CLIENT_BINARY'), ['--binary-mode', '--default-character-set=utf8mb4', $database], $config, $directory, $directory . '/replay.stdout', $deltaDirectory . '/delta.sql');
    $afterDirectory = $directory . '/after'; mkdir($afterDirectory, 0700);
    $actual = safetyBaseline($pdo, $afterDirectory); $differences = safetyDifferences($delta['baseline'], $actual);
    safetyJson($directory . '/verification.json', ['ok' => !$differences, 'differences' => $differences, 'baseline' => $actual,
        'target' => safetyIdentity($pdo), 'delta_manifest_sha256' => hash_file('sha256', $deltaDirectory . '/manifest.json')]);
    if ($differences) throw new RuntimeException('Replay berbeda; jangan alihkan aplikasi ke hasil pemulihan ini.');
    return ['ok' => true, 'database' => $database, 'verification' => $directory . '/verification.json'];
}
