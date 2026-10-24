<?php
require_once __DIR__ . '/security_helper.php';
function tableColumns(PDO $pdo, string $table): array
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        throw new InvalidArgumentException('Nama tabel tidak valid.');
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0); // nama kolom (Field)
}

function tablePrimaryKeyColumns(PDO $pdo, string $table): array
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        throw new InvalidArgumentException('Nama tabel tidak valid.');
    }

    // MySQL and MariaDB do not accept ORDER BY on SHOW KEYS. Query the
    // information schema instead so composite keys keep their column order.
    $stmt = $pdo->prepare("SELECT COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND CONSTRAINT_NAME = 'PRIMARY'
        ORDER BY ORDINAL_POSITION");
    $stmt->execute([$table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Discover every physical table in the current database. This prevents
 * operational tables added by later migrations from being silently omitted
 * from a backup.
 */
function discoverBackupTables(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT TABLE_NAME
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
        ORDER BY TABLE_NAME");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($tables)) {
        throw new RuntimeException('Tidak ada tabel database yang dapat dibackup.');
    }

    // Parent tables must be restored before child tables. A cycle is kept in
    // stable alphabetical order and will be rejected by enabled FK checks.
    $dependencies = array_fill_keys($tables, []);
    $fkStmt = $pdo->query("SELECT TABLE_NAME, REFERENCED_TABLE_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND REFERENCED_TABLE_NAME IS NOT NULL");
    foreach ($fkStmt->fetchAll(PDO::FETCH_ASSOC) as $fk) {
        if (isset($dependencies[$fk['TABLE_NAME']], $dependencies[$fk['REFERENCED_TABLE_NAME']])) {
            $dependencies[$fk['TABLE_NAME']][] = $fk['REFERENCED_TABLE_NAME'];
        }
    }

    $ordered = [];
    $remaining = array_fill_keys($tables, true);
    while (!empty($remaining)) {
        $progress = false;
        foreach (array_keys($remaining) as $table) {
            $parentsReady = true;
            foreach (array_unique($dependencies[$table]) as $parent) {
                if (isset($remaining[$parent])) {
                    $parentsReady = false;
                    break;
                }
            }
            if ($parentsReady) {
                $ordered[] = $table;
                unset($remaining[$table]);
                $progress = true;
            }
        }
        if (!$progress) {
            foreach (array_keys($remaining) as $table) {
                $ordered[] = $table;
            }
            break;
        }
    }

    return $ordered;
}

function tableOrderClause(PDO $pdo, string $table, array $columns): string
{
    $keys = tablePrimaryKeyColumns($pdo, $table);
    if (empty($keys)) {
        return '';
    }

    $keys = array_values(array_intersect($keys, $columns));
    return empty($keys)
        ? ''
        : ' ORDER BY ' . implode(', ', array_map(static fn($column) => "`$column`", $keys));
}

/**
 * An explicit storage path must work; never silently substitute ephemeral
 * storage for a configured persistent volume. Legacy installations retain
 * their existing temp_excel location, with a private temp fallback.
 */
function resolveTempDir(): string
{
    $configured = trim(getenv('BACKUP_STORAGE_PATH') ?: '');
    if ($configured !== '') {
        $normalized = rtrim(str_replace('\\', '/', $configured), '/');
        if ($normalized === '' || preg_match('/^[A-Za-z]:$/D', $normalized)
            || !(str_starts_with($normalized, '/') || preg_match('~^[A-Za-z]:/~', $normalized))) {
            throw new RuntimeException('BACKUP_STORAGE_PATH harus menunjuk folder cadangan tersendiri dengan alamat absolut.');
        }
        if (!is_dir($normalized)) @mkdir($normalized, 0700, true);
        if (is_dir($normalized) && is_writable($normalized)) return $normalized;
        throw new RuntimeException('Folder cadangan yang dikonfigurasi belum dapat ditulis.');
    }
    $candidates = [
        dirname(__DIR__) . '/temp_excel',
        rtrim(sys_get_temp_dir(), '/\\') . '/tahsin_backup_' . substr(hash('sha256', dirname(__DIR__)), 0, 16),
    ];
    foreach ($candidates as $c) {
        if (!is_dir($c)) { @mkdir($c, 0700, true); }
        @chmod($c, 0700);
        if (is_dir($c) && is_writable($c)) { return $c; }
    }
    throw new RuntimeException('Penyimpanan cadangan belum tersedia. Hubungi pengelola untuk memeriksa ruang disk dan izin folder.');
}

function safeFilename($name): ?string
{
    if (!is_string($name) || $name === '' || preg_match('/[^A-Za-z0-9_.\-]/', $name)) return null;
    if (str_contains($name, '..')) return null;
    return $name;
}

/**
 * Cadangan pre-restore tidak boleh menumpuk di server: simpan paling banyak
 * $keep arsip terbaru dan hapus sisanya setelah berumur lebih dari $maxAgeDays.
 */
function purgeSafetyBackups(string $dir, int $maxAgeDays = 7, int $keep = 3): void
{
    if (!is_dir($dir)) return;
    $files = [];
    foreach (scandir($dir) ?: [] as $name) {
        if (in_array($name, ['.', '..'], true)) continue;
        if (preg_match('/^backup_pre_restore_[A-Za-z0-9_\-]+\.zip$/', $name)) {
            $mtime = @filemtime($dir . '/' . $name);
            if ($mtime !== false) $files[$name] = $mtime;
        }
    }
    arsort($files);
    $cutoff = time() - $maxAgeDays * 86400;
    $position = 0;
    foreach ($files as $name => $mtime) {
        $position++;
        if ($position <= $keep && $mtime > $cutoff) continue;
        @unlink($dir . '/' . $name);
    }
}

/**
 * Bangun ZIP backup lengkap di $zipPath dengan snapshot konsisten dan
 * checksum per tabel. CSV ditulis ke file sementara agar tidak memuat satu
 * tabel penuh ke memory PHP.
 * $maskPasswords=true mengosongkan kolom users.password untuk unduhan
 * non-admin; arsip pemulihan (safety backup) harus selalu memakai hash asli.
 * Mengembalikan ['ok'=>bool, 'counts'=>[], 'error'=>string].
 */
function createBackupZip(PDO $pdo, string $zipPath, array $tables, string $nullToken, bool $maskPasswords = false): array
{
    if (!is_dir(dirname($zipPath))) {
        mkdir(dirname($zipPath), 0755, true);
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        return ['ok' => false, 'error' => 'Berkas cadangan baru belum dapat dibuat. Gunakan nama baru dan periksa ruang penyimpanan. Cadangan yang sudah ada tidak ditimpa.'];
    }
    $counts = [];
    $checksums = [];
    $tempFiles = [];
    $inTransaction = false;
    $csv = null;

    try {
        backupRequireTransactionalTables($pdo, $tables);
        // InnoDB snapshot: concurrent writes remain available and every table
        // is exported from the same read view.
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        $inTransaction = true;

        foreach ($tables as $tbl) {
            $cols = tableColumns($pdo, $tbl);
            $colList = implode(', ', array_map(static fn($column) => "`$column`", $cols));
            if ($maskPasswords && $tbl === 'users' && in_array('password', $cols, true)) {
                $colList = implode(', ', array_map(static fn($column) => $column === 'password' ? "'' AS `password`" : "`$column`", $cols));
            }
            $orderClause = tableOrderClause($pdo, $tbl, $cols);
            $tempPath = tempnam(sys_get_temp_dir(), 'tahsin_backup_');
            if ($tempPath === false) {
                throw new RuntimeException('Tidak dapat membuat file sementara backup.');
            }
            $tempFiles[] = $tempPath;
            $csv = fopen($tempPath, 'wb');
            if ($csv === false) {
                throw new RuntimeException('Tidak dapat menulis CSV sementara.');
            }

            if (fputcsv($csv, $cols, ',', '"', '') === false) throw new RuntimeException('Gagal menulis header CSV.');
            $count = 0;
            $previousBuffering = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            $stmt = null;
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            try {
                $stmt = $pdo->query("SELECT $colList FROM `$tbl`$orderClause");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $line = [];
                    foreach ($cols as $column) {
                        $value = $row[$column] ?? null;
                        $line[] = $value === null ? $nullToken : (str_starts_with((string) $value, '\\') ? '\\' . $value : $value);
                    }
                    $bytes = fputcsv($csv, $line, ',', '"', '');
                    if ($bytes === false) throw new RuntimeException('Gagal menulis baris CSV.');
                    if ($bytes > BACKUP_MAX_ROW_BYTES) throw new InvalidArgumentException('Ada catatan yang melebihi batas pemulihan aplikasi. Minta pengelola membuat cadangan database secara terpisah.');
                    $count++;
                }
            } finally {
                if ($stmt) $stmt->closeCursor();
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $previousBuffering);
            }
            fclose($csv);
            $csv = null;

            $counts[$tbl] = $count;
            $checksums[$tbl] = hash_file('sha256', $tempPath);
            if (!$zip->addFile($tempPath, $tbl . '.csv')) {
                throw new RuntimeException("Gagal menambahkan {$tbl}.csv ke ZIP.");
            }
        }

        $schema = '';
        foreach ($tables as $tbl) {
            $row = $pdo->query("SHOW CREATE TABLE `$tbl`")->fetch(PDO::FETCH_ASSOC);
            $schema .= ($row['Create Table'] ?? '') . ";\n\n";
        }
        if (!$zip->addFromString('schema.sql', $schema)) throw new RuntimeException('Gagal menyimpan skema.');
        $manifest = [
            'app' => 'tahsin-walsan',
            'version' => 3,
            'value_encoding' => 'backslash-v1',
            'generated_at' => date('c'),
            'database' => $pdo->query('SELECT DATABASE()')->fetchColumn(),
            'tables' => $counts,
            'checksums' => $checksums,
        ];
        if (!$zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))) throw new RuntimeException('Gagal menyimpan manifest.');

        if ($zip->close() !== true) {
            throw new RuntimeException('ZipArchive::close gagal menulis (cek writable temp dir & ruang disk).');
        }
        $pdo->commit();
        $inTransaction = false;

        if (!is_file($zipPath) || filesize($zipPath) === 0) {
            throw new RuntimeException('File ZIP kosong setelah ditulis.');
        }
        @chmod($zipPath, 0600);

        return ['ok' => true, 'counts' => $counts, 'checksums' => $checksums];
    } catch (Throwable $e) {
        if ($inTransaction) {
            try { $pdo->rollBack(); } catch (Throwable $ignored) {}
        }
        try { $zip->close(); } catch (Throwable $ignored) {}
        if (is_file($zipPath)) @unlink($zipPath);
        if (function_exists('reportApplicationError')) reportApplicationError($e, 'backup-export');
        return ['ok' => false, 'error' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'Backup gagal dibuat. Periksa log aplikasi dan ruang disk.'];
    } finally {
        if (is_resource($csv)) fclose($csv);
        foreach ($tempFiles as $tempFile) {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }
}

require_once __DIR__ . '/backup_restore_helper.php';
