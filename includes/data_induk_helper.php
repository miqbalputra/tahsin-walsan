<?php
/**
 * Read-model integration for Data Induk Yayasan.
 * This file deliberately contains no page-load synchronization: remote calls are
 * only made by the CLI scheduler or an explicitly requested admin sync.
 */

final class DataIndukException extends RuntimeException
{
    public int $statusCode;

    public function __construct(string $message, int $statusCode = 0)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }
}

function dataIndukConfig(): array
{
    return [
        'enabled' => filter_var(getenv('DATA_INDUK_ENABLED'), FILTER_VALIDATE_BOOL),
        'base_url' => rtrim((string) getenv('DATA_INDUK_BASE_URL'), '/'),
        'api_key' => (string) getenv('DATA_INDUK_API_KEY'),
        'unit_id' => (string) getenv('DATA_INDUK_UNIT_ID'),
        'stale_minutes' => max(15, (int) (getenv('DATA_INDUK_SYNC_STALE_MINUTES') ?: 60)),
    ];
}

function dataIndukIsConfigured(): bool
{
    $config = dataIndukConfig();
    return $config['enabled'] && $config['base_url'] !== '' && $config['api_key'] !== '' && $config['unit_id'] !== '';
}

function dataIndukNormalize(string $value): string
{
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    return preg_replace('/[^a-z0-9]/u', '', trim($value)) ?? '';
}

function dataIndukHttpGet(string $path, array $query = []): array
{
    $config = dataIndukConfig();
    if (!dataIndukIsConfigured()) {
        throw new DataIndukException('Integrasi Data Induk belum dikonfigurasi.', 0);
    }

    $url = $config['base_url'] . '/api/v1/integrations/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query(array_filter($query, static fn($value) => $value !== '' && $value !== null));
    }
    $lastStatus = 0;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $headers = ['Accept: application/json', 'X-API-Key: ' . $config['api_key']];
        $body = false;
        $responseHeaders = [];
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FAILONERROR => false,
                CURLOPT_HEADER => true,
            ]);
            $raw = curl_exec($handle);
            $lastStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($raw !== false) {
                $headerLength = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
                $responseHeaders = explode("\r\n", substr($raw, 0, $headerLength));
                $body = substr($raw, $headerLength);
            }
            curl_close($handle);
        } else {
            $context = stream_context_create(['http' => [
                'method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 15, 'ignore_errors' => true,
            ]]);
            $body = @file_get_contents($url, false, $context);
            $responseHeaders = $http_response_header ?? [];
            if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches)) {
                $lastStatus = (int) $matches[1];
            }
        }
        if ($body !== false && $lastStatus >= 200 && $lastStatus < 300) {
            $decoded = json_decode($body, true);
            if (!is_array($decoded) || !array_key_exists('data', $decoded)) {
                throw new DataIndukException('Respons Data Induk tidak sesuai kontrak.', $lastStatus);
            }
            return $decoded['data'];
        }
        if (!in_array($lastStatus, [0, 429, 500, 502, 503, 504], true) || $attempt === 2) {
            throw new DataIndukException('Permintaan Data Induk gagal (HTTP ' . ($lastStatus ?: 'jaringan') . ').', $lastStatus);
        }
        $retryAfter = 1;
        foreach ($responseHeaders as $header) {
            if (stripos($header, 'Retry-After:') === 0) {
                $retryAfter = min(5, max(1, (int) trim(substr($header, 12))));
            }
        }
        sleep($retryAfter);
    }
    throw new DataIndukException('Permintaan Data Induk gagal.', $lastStatus);
}

function dataIndukFetchAll(string $path, array $query = []): array
{
    $items = [];
    $cursor = '';
    do {
        $page = dataIndukHttpGet($path, $query + ['cursor' => $cursor, 'limit' => 200]);
        if (!isset($page['items']) || !is_array($page['items'])) {
            throw new DataIndukException('Respons koleksi Data Induk tidak valid.');
        }
        $items = array_merge($items, $page['items']);
        $next = (string) ($page['nextCursor'] ?? '');
        if ($next !== '' && $next === $cursor) {
            throw new DataIndukException('Cursor Data Induk tidak maju.');
        }
        $cursor = $next;
    } while ($cursor !== '');
    return $items;
}

function dataIndukRequireSchema(PDO $pdo): void
{
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='data_induk_sync_state'");
    if ((int) $stmt->fetchColumn() === 0) {
        throw new RuntimeException('Migrasi Data Induk belum dijalankan. Jalankan: php migrate_data_induk.php --apply');
    }
}

function dataIndukRecordRun(PDO $pdo, string $scope, string $status, array $stats = [], ?string $error = null, ?int $runId = null): int
{
    if ($runId === null) {
        $stmt = $pdo->prepare('INSERT INTO data_induk_sync_runs (scope,status,started_at,stats_json) VALUES (?, ?, NOW(), ?)');
        $stmt->execute([$scope, 'running', json_encode($stats, JSON_UNESCAPED_UNICODE)]);
        return (int) $pdo->lastInsertId();
    }
    $stmt = $pdo->prepare('UPDATE data_induk_sync_runs SET status=?, finished_at=NOW(), stats_json=?, error_message=? WHERE id=?');
    $stmt->execute([$status, json_encode($stats, JSON_UNESCAPED_UNICODE), $error, $runId]);
    return $runId;
}

function dataIndukAddConflict(PDO $pdo, string $type, string $masterId, ?int $localId, string $reason, array $payload): void
{
    $check = $pdo->prepare("SELECT id FROM data_induk_sync_conflicts WHERE entity_type=? AND master_id=? AND status='pending' LIMIT 1");
    $check->execute([$type, $masterId]);
    if ($check->fetchColumn()) {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO data_induk_sync_conflicts (entity_type,master_id,local_id,reason,payload_json,status,created_at) VALUES (?,?,?,?,?,\'pending\',NOW())');
    $stmt->execute([$type, $masterId, $localId, $reason, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
}

function dataIndukUpsertReference(PDO $pdo, string $type, array $row): void
{
    $stmt = $pdo->prepare('INSERT INTO data_induk_references (master_id,reference_type,reference_code,reference_name,payload_json,archived_at,updated_at) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE reference_code=VALUES(reference_code),reference_name=VALUES(reference_name),payload_json=VALUES(payload_json),archived_at=VALUES(archived_at),updated_at=NOW()');
    $stmt->execute([(string) $row['id'], $type, $row['code'] ?? null, $row['name'] ?? null, json_encode($row, JSON_UNESCAPED_UNICODE), empty($row['archivedAt']) ? null : $row['archivedAt']]);
}

function dataIndukSyncReferences(PDO $pdo): array
{
    $result = [];
    foreach (['references/units' => 'unit', 'references/academic-years' => 'academic_year', 'references/grade-levels' => 'grade_level', 'references/classes' => 'class'] as $endpoint => $type) {
        $rows = dataIndukFetchAll($endpoint, $endpoint === 'references/classes' ? ['unitId' => dataIndukConfig()['unit_id']] : []);
        foreach ($rows as $row) {
            dataIndukUpsertReference($pdo, $type, $row);
        }
        $result[$type] = count($rows);
    }
    return $result;
}

function dataIndukMappedId(PDO $pdo, string $table, string $column, string $masterId): ?int
{
    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE {$column}=? LIMIT 1");
    $stmt->execute([$masterId]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (int) $value;
}

function dataIndukClassNames(PDO $pdo): array
{
    $rows = $pdo->query("SELECT master_id, reference_name FROM data_induk_references WHERE reference_type='class'")->fetchAll();
    return array_column($rows, 'reference_name', 'master_id');
}

function dataIndukSyncRoster(PDO $pdo): array
{
    $unitId = dataIndukConfig()['unit_id'];
    $students = dataIndukFetchAll('students', ['unitId' => $unitId]);
    $guardians = dataIndukFetchAll('guardians', ['unitId' => $unitId]);
    $links = dataIndukFetchAll('family-links', ['unitId' => $unitId]);
    $classes = dataIndukClassNames($pdo);
    $guardianIds = [];
    $stats = ['students' => count($students), 'guardians' => 0, 'children' => 0, 'conflicts' => 0];
    // Presensi Tahsin Bapak has a single wali-bapak model. Other family links
    // are fetched for validation but are not forced into this local table.
    $fatherByStudent = [];
    $fatherGuardianIds = [];
    foreach ($links as $link) {
        if (($link['relationship'] ?? '') === 'father' && empty($link['archivedAt'])) {
            $fatherByStudent[(string) $link['studentId']] = (string) $link['guardianId'];
            $fatherGuardianIds[(string) $link['guardianId']] = true;
        }
    }

    $pdo->beginTransaction();
    try {
        foreach ($guardians as $guardian) {
            $masterId = (string) $guardian['id'];
            if (!isset($fatherGuardianIds[$masterId])) {
                continue;
            }
            $guardianIds[$masterId] = dataIndukMappedId($pdo, 'wali_santri', 'data_induk_guardian_id', $masterId);
            if ($guardianIds[$masterId] === null) {
                $candidate = $pdo->prepare('SELECT id FROM wali_santri WHERE LOWER(REPLACE(REPLACE(no_hp,\' \',\'\'),\'-\',\'\'))=? AND LOWER(nama_bapak)=? LIMIT 2');
                $candidate->execute([dataIndukNormalize((string) ($guardian['phone'] ?? '')), dataIndukNormalize((string) ($guardian['fullName'] ?? ''))]);
                $matches = $candidate->fetchAll(PDO::FETCH_COLUMN);
                if (count($matches) === 1 && dataIndukNormalize((string) ($guardian['phone'] ?? '')) !== '') {
                    dataIndukAddConflict($pdo, 'guardian', $masterId, (int) $matches[0], 'Kandidat nama dan nomor HP sama; persetujuan admin diperlukan.', $guardian);
                    $stats['conflicts']++;
                    continue;
                }
                $insert = $pdo->prepare('INSERT INTO wali_santri (nama_bapak,no_hp,alamat,kategori,status_aktif,data_induk_guardian_id) VALUES (?,?,?,?,1,?)');
                $insert->execute([$guardian['fullName'] ?? 'Tanpa Nama', $guardian['phone'] ?? null, $guardian['address'] ?? null, 'reguler', $masterId]);
                $guardianIds[$masterId] = (int) $pdo->lastInsertId();
            } else {
                $update = $pdo->prepare('UPDATE wali_santri SET nama_bapak=?, no_hp=?, alamat=?, status_aktif=? WHERE id=?');
                $update->execute([$guardian['fullName'] ?? '', $guardian['phone'] ?? null, $guardian['address'] ?? null, empty($guardian['archivedAt']) ? 1 : 0, $guardianIds[$masterId]]);
            }
            $stats['guardians']++;
        }
        foreach ($students as $student) {
            $masterId = (string) $student['id'];
            $guardianId = $fatherByStudent[$masterId] ?? '';
            $waliId = $guardianIds[$guardianId] ?? null;
            if (!$waliId) {
                continue;
            }
            $childId = dataIndukMappedId($pdo, 'santri_detail', 'data_induk_student_id', $masterId);
            $classId = (string) ($student['classId'] ?? '');
            $className = $classes[$classId] ?? null;
            if ($childId === null) {
                $candidate = $pdo->prepare('SELECT id FROM santri_detail WHERE wali_santri_id=? AND LOWER(nama_anak)=? LIMIT 2');
                $candidate->execute([$waliId, dataIndukNormalize((string) ($student['fullName'] ?? ''))]);
                $matches = $candidate->fetchAll(PDO::FETCH_COLUMN);
                if (count($matches) === 1) {
                    dataIndukAddConflict($pdo, 'student', $masterId, (int) $matches[0], 'Kandidat anak dengan wali dan nama sama; persetujuan admin diperlukan.', $student);
                    $stats['conflicts']++;
                    continue;
                }
                $insert = $pdo->prepare('INSERT INTO santri_detail (wali_santri_id,nama_anak,kelas,data_induk_student_id,data_induk_enrollment_id,data_induk_class_id,status_roster) VALUES (?,?,?,?,?,?,?)');
                $insert->execute([$waliId, $student['fullName'] ?? 'Tanpa Nama', $className, $masterId, $student['enrollmentId'] ?? null, $classId ?: null, ($student['status'] ?? '') === 'active' ? 'active' : 'archived']);
            } else {
                $update = $pdo->prepare('UPDATE santri_detail SET wali_santri_id=?, nama_anak=?, kelas=?, data_induk_enrollment_id=?, data_induk_class_id=?, status_roster=? WHERE id=?');
                $update->execute([$waliId, $student['fullName'] ?? '', $className, $student['enrollmentId'] ?? null, $classId ?: null, ($student['status'] ?? '') === 'active' ? 'active' : 'archived', $childId]);
            }
            $stats['children']++;
        }
        $pdo->commit();
        return $stats;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function dataIndukSyncTeachers(PDO $pdo): array
{
    $teachers = dataIndukFetchAll('teachers', ['unitId' => dataIndukConfig()['unit_id']]);
    $stats = ['teachers' => 0, 'conflicts' => 0];
    $pdo->beginTransaction();
    try {
        foreach ($teachers as $teacher) {
            $masterId = (string) $teacher['id'];
            $localId = dataIndukMappedId($pdo, 'users', 'data_induk_teacher_id', $masterId);
            if ($localId === null) {
                $find = $pdo->prepare("SELECT id FROM users WHERE role='ustadz' AND LOWER(nama_lengkap)=? LIMIT 2");
                $find->execute([dataIndukNormalize((string) ($teacher['fullName'] ?? ''))]);
                $matches = $find->fetchAll(PDO::FETCH_COLUMN);
                if (count($matches) === 1) {
                    dataIndukAddConflict($pdo, 'teacher', $masterId, (int) $matches[0], 'Kandidat ustadz dengan nama sama; persetujuan admin diperlukan.', $teacher);
                    $stats['conflicts']++;
                }
                continue; // akun lokal hanya dibuat oleh admin agar password/role tetap terkontrol.
            }
            $update = $pdo->prepare('UPDATE users SET nama_lengkap=?, no_hp=?, data_induk_active=1 WHERE id=?');
            $update->execute([$teacher['fullName'] ?? '', $teacher['phone'] ?? null, $localId]);
            $stats['teachers']++;
        }
        $pdo->commit();
        return $stats;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function dataIndukSync(PDO $pdo, string $scope = 'all'): array
{
    dataIndukRequireSchema($pdo);
    if (!dataIndukIsConfigured()) {
        throw new DataIndukException('DATA_INDUK_ENABLED atau secret Data Induk belum lengkap.');
    }
    $scope = in_array($scope, ['all', 'references', 'roster', 'teachers', 'changes'], true) ? $scope : 'all';
    // MariaDB advisory lock is connection-bound and prevents scheduler/manual
    // jobs from applying the same snapshot concurrently.
    $lockName = 'presensi_tahsin_data_induk_sync';
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lockStmt->execute([$lockName]);
    if ((int) $lockStmt->fetchColumn() !== 1) {
        throw new RuntimeException('Sinkronisasi Data Induk lain masih berjalan.');
    }
    $runId = dataIndukRecordRun($pdo, $scope, 'running');
    $started = microtime(true);
    try {
        $stats = [];
        if ($scope === 'changes') {
            $state = $pdo->query('SELECT change_cursor FROM data_induk_sync_state WHERE id=1')->fetch();
            $feed = dataIndukHttpGet('changes', ['unitId' => dataIndukConfig()['unit_id'], 'cursor' => $state['change_cursor'] ?? 0, 'limit' => 200]);
            $stats = ['events' => count($feed['items'] ?? [])];
            foreach (($feed['items'] ?? []) as $event) {
                if (($event['operation'] ?? '') !== 'archive') {
                    continue;
                }
                $entityId = (string) ($event['entityId'] ?? '');
                switch (strtolower((string) ($event['entityType'] ?? ''))) {
                    case 'guardian':
                        $pdo->prepare('UPDATE wali_santri SET status_aktif=0 WHERE data_induk_guardian_id=?')->execute([$entityId]);
                        break;
                    case 'student':
                        $pdo->prepare("UPDATE santri_detail SET status_roster='archived' WHERE data_induk_student_id=?")->execute([$entityId]);
                        break;
                    case 'teacher':
                        $pdo->prepare('UPDATE users SET data_induk_active=0 WHERE data_induk_teacher_id=?')->execute([$entityId]);
                        break;
                }
            }
            if ($stats['events'] > 0) {
                $stats += dataIndukSyncReferences($pdo);
                $stats += dataIndukSyncRoster($pdo);
                $stats += dataIndukSyncTeachers($pdo);
            }
            $pdo->prepare('UPDATE data_induk_sync_state SET change_cursor=?, last_success_at=NOW(), last_error=NULL WHERE id=1')->execute([(string) ($feed['nextCursor'] ?? $state['change_cursor'] ?? '0')]);
        } else {
            if ($scope === 'all' || $scope === 'references') {
                $stats += dataIndukSyncReferences($pdo);
            }
            if ($scope === 'all' || $scope === 'roster') {
                $stats += dataIndukSyncRoster($pdo);
            }
            if ($scope === 'all' || $scope === 'teachers') {
                $stats += dataIndukSyncTeachers($pdo);
            }
            $pdo->exec('UPDATE data_induk_sync_state SET last_success_at=NOW(), last_error=NULL WHERE id=1');
        }
        $stats['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
        dataIndukRecordRun($pdo, $scope, 'success', $stats, null, $runId);
        return $stats;
    } catch (Throwable $error) {
        $safeError = $error instanceof DataIndukException ? $error->getMessage() : 'Sinkronisasi gagal; periksa log server.';
        $pdo->prepare('UPDATE data_induk_sync_state SET last_error=?, last_error_at=NOW() WHERE id=1')->execute([$safeError]);
        dataIndukRecordRun($pdo, $scope, 'failed', [], $safeError, $runId);
        error_log('[data-induk-sync] ' . $safeError);
        throw $error;
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}

function dataIndukResolveConflict(PDO $pdo, int $conflictId, string $decision): void
{
    $stmt = $pdo->prepare("SELECT * FROM data_induk_sync_conflicts WHERE id=? AND status='pending' FOR UPDATE");
    $pdo->beginTransaction();
    try {
        $stmt->execute([$conflictId]);
        $conflict = $stmt->fetch();
        if (!$conflict) {
            throw new RuntimeException('Konflik tidak ditemukan atau sudah diproses.');
        }
        if ($decision === 'approve' && !empty($conflict['local_id'])) {
            $mapping = ['guardian' => ['wali_santri', 'data_induk_guardian_id'], 'student' => ['santri_detail', 'data_induk_student_id'], 'teacher' => ['users', 'data_induk_teacher_id']][$conflict['entity_type']] ?? null;
            if (!$mapping) {
                throw new RuntimeException('Jenis konflik tidak didukung.');
            }
            [$table, $column] = $mapping;
            $update = $pdo->prepare("UPDATE {$table} SET {$column}=? WHERE id=? AND ({$column} IS NULL OR {$column}=?)");
            $update->execute([$conflict['master_id'], $conflict['local_id'], $conflict['master_id']]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('UUID pusat sudah dipetakan ke record lain.');
            }
        }
        $pdo->prepare('UPDATE data_induk_sync_conflicts SET status=?, resolved_at=NOW(), resolved_by=? WHERE id=?')->execute([$decision === 'approve' ? 'approved' : 'ignored', $_SESSION['user_id'] ?? null, $conflictId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
