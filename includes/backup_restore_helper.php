<?php
require_once __DIR__ . '/csv_helper.php';
require_once __DIR__ . '/database_helper.php';
/** Bounded archive parsing and transactional preserve-ID merge; no extraction. */
// One import batch retains its before/after journal. Its bounded JSON payload
// can exceed a normal attendance row; leave room for CSV quote doubling.
const BACKUP_MAX_ROW_BYTES = 32 * 1024 * 1024;

function backupExpandedLimit(): int
{
    return min(2048, max(1, (int) (getenv('BACKUP_MAX_EXPANDED_MB') ?: 512))) * 1024 * 1024;
}

function backupRequireTransactionalTables(PDO $pdo, array $tables): void
{
    appRequireTransactionalTables($pdo,$tables);
}

function backupAssertParticipantAliases(PDO $pdo): void
{
    $count=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('wali_santri','participant_merge_aliases') AND TABLE_TYPE='BASE TABLE'")->fetchColumn();
    if ($count!==2) return;
    $invalid=$pdo->query('SELECT 1 FROM participant_merge_aliases a LEFT JOIN wali_santri s ON s.id=a.source_id LEFT JOIN wali_santri t ON t.id=a.target_id WHERE s.id IS NOT NULL OR t.id IS NULL OR a.source_id=a.target_id LIMIT 1 FOR UPDATE')->fetchColumn();
    if ($invalid) throw new InvalidArgumentException('Cadangan menghidupkan kembali ID peserta yang sudah digabung atau memiliki pengalihan ID yang tidak valid. Pemulihan dibatalkan. Tinjau pada database terpisah bersama pengelola.');
}

function backupOpenArchive(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) throw new InvalidArgumentException('Berkas ZIP tidak dapat dibaca. Pilih ulang cadangan yang utuh.');
    try {
        if ($zip->numFiles > 1000) throw new InvalidArgumentException('ZIP berisi terlalu banyak berkas.');
        $entries = [];
        $expanded = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            if (!$entry || !preg_match('/^(manifest\.json|schema\.sql|[A-Za-z0-9_]+\.csv)$/D', $entry['name']) || isset($entries[$entry['name']])) {
                throw new InvalidArgumentException('ZIP berisi nama berkas tidak valid atau berkas ganda.');
            }
            if (($entry['encryption_method'] ?? 0) !== 0) throw new InvalidArgumentException('ZIP terenkripsi tidak didukung.');
            $limit = $entry['name'] === 'manifest.json' ? 1024 * 1024 : backupExpandedLimit();
            $expanded += $entry['size'];
            if ($entry['size'] > $limit || $expanded > backupExpandedLimit()) throw new InvalidArgumentException('Isi ZIP setelah dibuka terlalu besar. Minta pengelola memulihkan cadangan ini secara terpisah.');
            $entries[$entry['name']] = $entry['size'];
        }
        if (!isset($entries['manifest.json'])) throw new InvalidArgumentException('manifest.json tidak ditemukan di dalam ZIP.');
        $raw = $zip->getFromName('manifest.json', 1024 * 1024 + 1);
        $manifest = json_decode($raw === false ? '' : $raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['app'] ?? '') !== 'tahsin-walsan'
            || !in_array($manifest['version'] ?? 1, [1, 2, 3], true)
            || !is_array($manifest['tables'] ?? null) || !$manifest['tables']) throw new InvalidArgumentException('Manifest bukan cadangan tahsin-walsan yang didukung.');
        if (($manifest['version'] ?? 1) === 3 && ($manifest['value_encoding'] ?? '') !== 'backslash-v1') throw new InvalidArgumentException('Format nilai cadangan tidak dikenal.');
        if (isset($manifest['checksums']) && !is_array($manifest['checksums'])) throw new InvalidArgumentException('Daftar checksum cadangan tidak valid.');
        $expected = ['manifest.json', 'schema.sql'];
        foreach ($manifest['tables'] as $table => $count) {
            if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/D', $table) || !is_int($count) || $count < 0 || !isset($entries[$table . '.csv'])) {
                throw new InvalidArgumentException('Daftar tabel, jumlah baris, atau CSV dalam manifest tidak lengkap.');
            }
            $expected[] = $table . '.csv';
            $checksum = $manifest['checksums'][$table] ?? null;
            if (($manifest['version'] ?? 1) >= 2 && (!is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/D', $checksum))) throw new InvalidArgumentException('Checksum tabel tidak lengkap. Pilih cadangan yang utuh.');
        }
        if (array_diff(array_keys($entries), $expected)) throw new InvalidArgumentException('ZIP memuat CSV yang tidak tercantum dalam manifest.');
        return [$zip, $manifest];
    } catch (Throwable $error) {
        $zip->close();
        if ($error instanceof JsonException) throw new InvalidArgumentException('manifest.json rusak atau tidak dapat dibaca.');
        throw $error;
    }
}

/** Spool one bounded CSV to private OS temporary storage while hashing it. */
function backupCsvStream(ZipArchive $zip, array $manifest, string $table)
{
    $input = $zip->getStream($table . '.csv');
    $output = tmpfile();
    if (!$input || !$output) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        throw new RuntimeException('Penyimpanan sementara cadangan belum tersedia.');
    }
    try {
        $digest = hash_init('sha256');
        $size = 0;
        $declaredSize = $zip->statName($table . '.csv')['size'] ?? -1;
        while (!feof($input)) {
            $chunk = fread($input, 65536);
            if ($chunk === false) throw new InvalidArgumentException('CSV dalam ZIP tidak dapat dibaca.');
            $size += strlen($chunk);
            if ($size > backupExpandedLimit() || $size > $declaredSize) throw new InvalidArgumentException('Ukuran CSV melampaui batas atau tidak sesuai metadata ZIP.');
            hash_update($digest, $chunk);
            if (fwrite($output, $chunk) !== strlen($chunk)) throw new RuntimeException('Ruang penyimpanan cadangan tidak mencukupi.');
        }
        if ($size !== $declaredSize) throw new InvalidArgumentException('CSV dalam ZIP terpotong.');
        $expected = $manifest['checksums'][$table] ?? null;
        if ($expected !== null && (!is_string($expected) || !hash_equals($expected, hash_final($digest)))) throw new InvalidArgumentException('Checksum CSV ' . $table . ' tidak cocok. Berkas mungkin rusak atau berubah.');
        rewind($output);
        return $output;
    } catch (Throwable $error) {
        fclose($output);
        throw $error;
    } finally {
        fclose($input);
    }
}

function backupCsvRows($stream, int $version): Generator
{
    yield from appCsvRows($stream, ',', $version < 3 ? '\\' : '', BACKUP_MAX_ROW_BYTES);
}
function backupDecodeValue(?string $value, int $version, string $nullToken): ?string
{
    if ($value === $nullToken) return null;
    if ($version >= 3 && str_starts_with($value ?? '', '\\\\')) return substr($value, 1);
    return $value;
}

/** Validate all source tables before any insertion; do not silently drop data. */
function backupInspect(PDO $pdo, ZipArchive $zip, array $manifest, array $tables, string $nullToken): array
{
    backupRequireTransactionalTables($pdo, $tables);
    if (array_diff(array_keys($manifest['tables']), $tables)) throw new InvalidArgumentException('Cadangan memuat tabel yang belum tersedia pada aplikasi ini. Minta pengelola menyesuaikan versi database terlebih dahulu.');
    $foreignKeys = backupForeignKeys($pdo);
    $rows = [];
    $totalZip = $totalDb = 0;
    foreach ($tables as $table) {
        $dbCount = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        $totalDb += $dbCount;
        if (!array_key_exists($table, $manifest['tables'])) {
            $rows[] = ['table'=>$table,'zip_rows'=>null,'db_rows'=>$dbCount,'cols_ok'=>false,'missing'=>[],'note'=>'Tidak ada di cadangan ini; data tabel tetap dipertahankan.'];
            continue;
        }
        $stream = backupCsvStream($zip, $manifest, $table);
        try {
            $header = null;
            $generated = [];
            $count = 0;
            $keys = tablePrimaryKeyColumns($pdo, $table);
            foreach (backupCsvRows($stream, $manifest['version'] ?? 1) as $record) {
                if ($header === null) {
                    $header = $record;
                    if (count(array_unique($header)) !== count($header) || array_diff($header, tableColumns($pdo, $table)) || array_diff($keys, $header)) throw new InvalidArgumentException('Kolom atau primary key CSV ' . $table . ' tidak cocok. Pemulihan tidak dijalankan.');
                    $info = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($info as $column) {
                        if (str_contains($column['Extra'],'GENERATED') && !str_contains($column['Extra'],'DEFAULT_GENERATED')) $generated[] = $column['Field'];
                        if ($column['Null'] === 'NO' && $column['Default'] === null && $column['Extra'] === '' && !in_array($column['Field'], $header, true)) throw new InvalidArgumentException('CSV ' . $table . ' tidak memiliki kolom wajib ' . $column['Field'] . '.');
                    }
                    continue;
                }
                if (count($record) !== count($header)) throw new InvalidArgumentException('Jumlah kolom CSV ' . $table . ' tidak konsisten pada baris ' . ($count + 2) . '.');
                foreach ($keys as $key) {
                    if (backupDecodeValue($record[array_search($key, $header, true)], $manifest['version'] ?? 1, $nullToken) === null) throw new InvalidArgumentException('Primary key kosong pada CSV ' . $table . '.');
                }
                $count++;
            }
            if ($header === null || $count !== $manifest['tables'][$table]) throw new InvalidArgumentException('Jumlah baris CSV ' . $table . ' tidak sesuai manifest atau header tidak terbaca.');
            $totalZip += $count;
            $notes = [];
            if (!$keys) {
                if ($generated && $count > 0) $notes[] = 'Tanpa primary key dan memiliki kolom otomatis; pemulihan memerlukan tinjauan pengelola.';
                elseif ($dbCount === 0) $notes[] = 'Tanpa primary key: tabel tujuan kosong dan baris dapat dipulihkan tanpa menggabungkan data yang tidak beridentitas.';
                else $notes[] = 'Tanpa primary key: tabel tujuan sudah berisi data, sehingga tabel dilewati agar tidak membuat duplikasi.';
            }
            $isParentTable = array_filter($foreignKeys, static fn($reference) => $reference['parent'] === $table);
            $missingColumns = array_values(array_diff(tableColumns($pdo, $table), $header));
            if ($keys && $isParentTable && $missingColumns) {
                $notes[] = 'Arsip tidak memuat semua kolom tabel induk; anak baru ditolak bila ID induk yang sama tidak dapat diverifikasi penuh.';
            }
            $rows[] = ['table'=>$table,'zip_rows'=>$count,'db_rows'=>$dbCount,'cols_ok'=>true,'checksum_ok'=>true,'missing'=>[],
                'note'=>implode(' ', $notes)];
        } finally { fclose($stream); }
    }
    return ['error'=>null,'rows'=>$rows,'total_zip'=>$totalZip,'total_db'=>$totalDb,
        'legacy'=>($manifest['version'] ?? 1) < 3,
        'summary'=>'preview ' . $totalZip . ' baris; format=' . ($manifest['version'] ?? 1)];
}

function analyzeRestoreZip(PDO $pdo, string $zipPath, array $tables, string $nullToken): array
{
    $zip = null;
    try {
        [$zip, $manifest] = backupOpenArchive($zipPath);
        return backupInspect($pdo, $zip, $manifest, $tables, $nullToken);
    } catch (InvalidArgumentException $error) {
        return ['error'=>$error->getMessage()];
    } catch (Throwable $error) {
        if (function_exists('reportApplicationError')) reportApplicationError($error, 'backup-preview');
        return ['error'=>'Cadangan belum dapat diperiksa. Periksa ruang disk dan koneksi database.'];
    } finally { if ($zip) $zip->close(); }
}

function backupForeignKeys(PDO $pdo): array
{
    $rows = $pdo->query("SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
    $keys = [];
    foreach ($rows as $row) {
        $id = $row['TABLE_NAME'] . ':' . $row['CONSTRAINT_NAME'];
        if (!isset($keys[$id])) $keys[$id] = ['table'=>$row['TABLE_NAME'],'parent'=>$row['REFERENCED_TABLE_NAME'],'columns'=>[],'parent_columns'=>[]];
        $keys[$id]['columns'][] = $row['COLUMN_NAME'];
        $keys[$id]['parent_columns'][] = $row['REFERENCED_COLUMN_NAME'];
    }
    return array_values($keys);
}

function backupReferenceFingerprint(string $table, array $columns, array $values): string
{
    return hash('sha256', serialize([$table,$columns,array_map(static fn($value)=>$value === null ? null : (string) $value,$values)]));
}

/** Lock existing keys and identify changed parents before restoring children.
 * A new child must never silently attach to a different existing identity
 * merely because the two databases happen to share a numeric primary key.
 */
function backupChangedParentKeys(PDO $pdo, ZipArchive $zip, array $manifest, array $foreignKeys, string $nullToken): array
{
    $changed = [];
    foreach (array_keys($manifest['tables']) as $table) {
        $keys = tablePrimaryKeyColumns($pdo,$table);
        if (!$keys) continue;
        $references = array_values(array_filter($foreignKeys,static fn($key)=>$key['parent'] === $table));
        $stream = backupCsvStream($zip,$manifest,$table);
        try {
            $header = null;
            foreach (backupCsvRows($stream,$manifest['version'] ?? 1) as $row) {
                if ($header === null) {
                    $header = $row;
                    $missingColumns = array_values(array_diff(tableColumns($pdo,$table),$header));
                    $where = implode(' AND ',array_map(static fn($key)=>"`$key`=?",$keys));
                    $lookup = $pdo->prepare("SELECT * FROM `$table` WHERE $where LIMIT 1 FOR UPDATE");
                    continue;
                }
                $values = array_map(static fn($value)=>backupDecodeValue($value,$manifest['version'] ?? 1,$nullToken),$row);
                $record = array_combine($header,$values);
                $lookup->execute(array_map(static fn($key)=>$record[$key],$keys));
                $existing = $lookup->fetch(PDO::FETCH_ASSOC);
                if (!$existing || !$references) continue;
                // An older archive may omit nullable columns introduced later.
                // If this row's numeric ID already exists, missing source values
                // make it unsafe to assume that a new child belongs to that same
                // person. Treat the parent as uncertain and block such children.
                $differs = $references && $missingColumns;
                foreach ($record as $column=>$value) {
                    if ($existing[$column] === null ? $value !== null : ($value === null || (string) $existing[$column] !== (string) $value)) { $differs = true; break; }
                }
                if (!$differs) continue;
                foreach ($references as $reference) {
                    $columns = $reference['parent_columns'];
                    foreach ([$record,$existing] as $parent) {
                        // Referenced unique columns need not be part of an old
                        // CSV header; use the existing parent for that case.
                        $tuple = array_map(static fn($column)=>$parent[$column] ?? $existing[$column],$columns);
                        $changed[backupReferenceFingerprint($table,$columns,$tuple)] = true;
                    }
                }
                if (count($changed) > 10000) throw new InvalidArgumentException('Terlalu banyak perbedaan ID induk. Minta pengelola membandingkan cadangan pada database terpisah terlebih dahulu.');
            }
        } finally { fclose($stream); }
    }
    return $changed;
}

function executeMergeRestore(PDO $pdo, string $zipPath, array $tables, string $nullToken): array
{
    if ($pdo->inTransaction()) return ['error'=>'Selesaikan transaksi lain sebelum memulihkan cadangan.'];
    $zip = null;
    $stream = null;
    $originalSqlMode = null;
    try {
        [$zip, $manifest] = backupOpenArchive($zipPath);
        backupInspect($pdo, $zip, $manifest, $tables, $nullToken);
        $originalSqlMode = appUseStrictSqlMode($pdo,true);
        // Keep InnoDB gap locks for empty keyless tables so concurrent inserts
        // cannot race a restore and create rows without a safe merge identity.
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        $foreignKeys = backupForeignKeys($pdo);
        $changedParents = backupChangedParentKeys($pdo,$zip,$manifest,$foreignKeys,$nullToken);
        $counts = $conflicts = $skipped = [];
        $parentLookups = [];
        foreach ($tables as $table) {
            $counts[$table] = $conflicts[$table] = 0;
            if (!isset($manifest['tables'][$table])) continue;
            $keys = tablePrimaryKeyColumns($pdo, $table);
            $restoreKeylessTable = false;
            if (!$keys) {
                // Without a primary key, merge cannot identify individual
                // rows. Restore only when the target table is empty.
                $existingRows = $pdo->query("SELECT 1 FROM `$table` LIMIT 1 FOR UPDATE");
                $hasExistingRows = $existingRows->fetchColumn() !== false;
                $existingRows->closeCursor();
                if ($hasExistingRows) {
                    $skipped[$table] = 'Tanpa primary key dan tabel tujuan sudah berisi data; dilewati agar tidak membuat duplikasi.';
                    continue;
                }
                $restoreKeylessTable = true;
            }
            $stream = backupCsvStream($zip, $manifest, $table);
            $header = null;
            foreach (backupCsvRows($stream, $manifest['version'] ?? 1) as $row) {
                if ($header === null) {
                    $header = $row;
                    $generated = [];
                    foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $column) {
                        if (str_contains($column['Extra'],'GENERATED') && !str_contains($column['Extra'],'DEFAULT_GENERATED')) $generated[] = $column['Field'];
                    }
                    $writeHeader = array_values(array_diff($header,$generated));
                    $columns = implode(',', array_map(static fn($column)=>"`$column`", $writeHeader));
                    $where = implode(' AND ', array_map(static fn($key)=>"`$key`=?", $keys));
                    $exists = $restoreKeylessTable ? null : $pdo->prepare("SELECT 1 FROM `$table` WHERE $where LIMIT 1 FOR UPDATE");
                    $insert = $pdo->prepare("INSERT INTO `$table` ($columns) VALUES (" . implode(',',array_fill(0,count($writeHeader),'?')) . ')');
                    if ($restoreKeylessTable && $generated && ($manifest['tables'][$table] ?? 0) > 0) throw new InvalidArgumentException('Tabel tanpa primary key memiliki kolom otomatis; pemulihan aman memerlukan tinjauan pengelola.');
                    $verifyGenerated = $generated && !$restoreKeylessTable ? $pdo->prepare("SELECT * FROM `$table` WHERE $where LIMIT 1") : null;
                    continue;
                }
                $values = array_map(static fn($value)=>backupDecodeValue($value,$manifest['version'] ?? 1,$nullToken), $row);
                $byName = array_combine($header,$values);
                if ($exists) {
                    $exists->execute(array_map(static fn($key)=>$byName[$key], $keys));
                    if ($exists->fetchColumn() !== false) { $conflicts[$table]++; continue; }
                }
                foreach ($foreignKeys as $reference) {
                    if ($reference['table'] !== $table) continue;
                    $tuple = array_map(static fn($column)=>$byName[$column] ?? null,$reference['columns']);
                    if (in_array(null,$tuple,true)) continue;
                    $lookupId = backupReferenceFingerprint($reference['parent'],$reference['parent_columns'],[]);
                    if (!isset($parentLookups[$lookupId])) {
                        $parentWhere = implode(' AND ',array_map(static fn($column)=>"`$column`=?",$reference['parent_columns']));
                        $parentLookups[$lookupId] = $pdo->prepare('SELECT * FROM `' . $reference['parent'] . "` WHERE $parentWhere LIMIT 1 FOR UPDATE");
                    }
                    $parentLookups[$lookupId]->execute($tuple);
                    $actualParent = $parentLookups[$lookupId]->fetch(PDO::FETCH_ASSOC);
                    // Compare database-normalized keys: '0001' and 1 may refer
                    // to the same parent under numeric/collation coercion.
                    if ($actualParent) $tuple = array_map(static fn($column)=>$actualParent[$column],$reference['parent_columns']);
                    if (isset($changedParents[backupReferenceFingerprint($reference['parent'],$reference['parent_columns'],$tuple)])) {
                        throw new InvalidArgumentException('ID induk berbeda atau arsip tidak lengkap antara cadangan dan data saat ini (' . $reference['parent'] . '). Pemulihan dibatalkan agar data baru tidak terhubung ke identitas yang keliru. Minta pengelola meninjau cadangan pada database terpisah.');
                    }
                }
                $insert->execute(array_map(static fn($column)=>$byName[$column],$writeHeader));
                if ($verifyGenerated) {
                    $verifyGenerated->execute(array_map(static fn($key)=>$byName[$key],$keys));
                    $computed = $verifyGenerated->fetch(PDO::FETCH_ASSOC);
                    foreach (array_intersect($generated,$header) as $column) {
                        $value = $byName[$column];
                        if ($computed[$column] === null ? $value !== null : ($value === null || (string) $computed[$column] !== (string) $value)) throw new InvalidArgumentException('Hasil kolom otomatis ' . $table . '.' . $column . ' berbeda dari cadangan. Sesuaikan versi database terlebih dahulu.');
                    }
                }
                $counts[$table]++;
            }
            fclose($stream);
            $stream = null;
        }
        backupAssertParticipantAliases($pdo);
        $pdo->commit();
        return ['error'=>null,'counts'=>$counts,'conflicts'=>$conflicts,'skipped'=>$skipped,
            'summary'=>'inserted=' . json_encode($counts) . '; skipped_existing=' . json_encode($conflicts) . '; skipped_tables=' . json_encode($skipped)];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (function_exists('reportApplicationError')) reportApplicationError($error, 'backup-merge-restore');
        return ['error'=>$error instanceof InvalidArgumentException ? $error->getMessage() : 'Pemulihan gagal. Seluruh perubahan parsial dibatalkan; data sebelumnya tetap tersimpan.'];
    } finally {
        if (is_resource($stream)) fclose($stream);
        if ($zip) $zip->close();
        if ($originalSqlMode !== null) {
            try { $pdo->prepare('SET SESSION sql_mode=?')->execute([$originalSqlMode]); }
            catch (Throwable $modeError) { if (function_exists('reportApplicationError')) reportApplicationError($modeError,'backup-sql-mode'); }
        }
    }
}
