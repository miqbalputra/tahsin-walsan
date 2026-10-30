<?php
require_once __DIR__ . '/database_helper.php';
require_once __DIR__ . '/participant_identity_helper.php';
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

/** Fail before a roster write if its archive columns or rollback guarantees are missing. */
function dataIndukRequireRosterArchive(PDO $pdo): void
{
    try {
        $pdo->query('SELECT archived_at,archive_reason FROM halaqoh_members LIMIT 0');
    } catch (PDOException $error) {
        if (in_array($error->getCode(), ['42S02', '42S22'], true)) {
            throw new DataIndukException('Migrasi arsip anggota halaqoh belum siap. Sinkronisasi dibatalkan tanpa mengubah roster.', 0);
        }
        throw $error;
    }
    appRequireTransactionalTables($pdo, ['wali_santri', 'santri_detail', 'halaqoh_members', 'data_induk_sync_conflicts']);
}

function dataIndukArchiveMemberships(PDO $pdo, int $waliId, string $reason): int
{
    $statement = $pdo->prepare('SELECT id FROM halaqoh_members WHERE wali_santri_id=? AND archived_at IS NULL ORDER BY id FOR UPDATE');
    $statement->execute([$waliId]);
    if (!$statement->fetchAll(PDO::FETCH_COLUMN)) return 0;
    $statement = $pdo->prepare('UPDATE halaqoh_members SET archived_at=CURRENT_TIMESTAMP,archive_reason=? WHERE wali_santri_id=? AND archived_at IS NULL');
    $statement->execute([$reason, $waliId]);
    return $statement->rowCount();
}

/** Apply a guardian archive without deleting the guardian, children, attendance, or membership rows. */
function dataIndukArchiveGuardian(PDO $pdo, string $masterId): array
{
    if ($masterId === '' || strlen($masterId) > 36) throw new DataIndukException('ID wali dari Data Induk tidak valid.');
    dataIndukRequireRosterArchive($pdo);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT id FROM wali_santri WHERE data_induk_guardian_id=? LIMIT 1 FOR UPDATE');
        $statement->execute([$masterId]);
        $waliId = $statement->fetchColumn();
        if ($waliId === false) {
            if ($ownsTransaction) $pdo->commit();
            return ['wali_id'=>null,'archived_memberships'=>0];
        }
        $waliId = (int)$waliId;
        $pdo->prepare('UPDATE wali_santri SET status_aktif=0 WHERE id=? AND status_aktif<>0')->execute([$waliId]);
        $archived = dataIndukArchiveMemberships($pdo, $waliId, 'DATA_INDUK_GUARDIAN_ARCHIVE');
        if ($ownsTransaction) $pdo->commit();
        return ['wali_id'=>$waliId,'archived_memberships'=>$archived];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Archived children stop qualifying a guardian for new/current roster placement; history remains intact. */
function dataIndukArchiveStudent(PDO $pdo, string $masterId): array
{
    if ($masterId === '' || strlen($masterId) > 36) throw new DataIndukException('ID anak dari Data Induk tidak valid.');
    dataIndukRequireRosterArchive($pdo);
    $lookup = $pdo->prepare('SELECT wali_santri_id FROM santri_detail WHERE data_induk_student_id=? LIMIT 1');
    $lookup->execute([$masterId]);
    $initialWali = $lookup->fetchColumn();
    if ($initialWali === false) return ['wali_id'=>null,'archived_memberships'=>0];
    $initialWali = (int)$initialWali;
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT id FROM wali_santri WHERE id=? FOR UPDATE');
        $statement->execute([$initialWali]);
        if ($statement->fetchColumn() === false) throw new DataIndukException('Wali anak tidak ditemukan; perubahan dibatalkan.');
        $statement = $pdo->prepare('SELECT wali_santri_id FROM santri_detail WHERE data_induk_student_id=? FOR UPDATE');
        $statement->execute([$masterId]);
        $currentWali = $statement->fetchColumn();
        if ($currentWali === false || (int)$currentWali !== $initialWali) throw new DataIndukException('Relasi wali berubah saat sinkronisasi; ulangi sinkronisasi.');
        $waliId = (int)$currentWali;
        $pdo->prepare("UPDATE santri_detail SET status_roster='archived' WHERE data_induk_student_id=? AND status_roster<>'archived'")->execute([$masterId]);
        $statement = $pdo->prepare('SELECT id FROM santri_detail WHERE wali_santri_id=? ORDER BY id FOR UPDATE');
        $statement->execute([$waliId]);
        $statement->fetchAll(PDO::FETCH_COLUMN);
        $statement = $pdo->prepare("SELECT 1 FROM santri_detail WHERE wali_santri_id=? AND LOWER(TRIM(COALESCE(kelas,'')))<>'lulus' AND (data_induk_student_id IS NULL OR status_roster='active') LIMIT 1");
        $statement->execute([$waliId]);
        $archived = $statement->fetchColumn() ? 0 : dataIndukArchiveMemberships($pdo, $waliId, 'DATA_INDUK_STUDENT_ARCHIVE');
        if ($ownsTransaction) $pdo->commit();
        return ['wali_id'=>$waliId,'archived_memberships'=>$archived];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
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

/** Return false on older databases until the additive migration is applied. */
function dataIndukSchemaReady(PDO $pdo): bool
{
    static $ready;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $required = [
            ['wali_santri', 'data_induk_guardian_id'],
            ['santri_detail', 'data_induk_student_id'],
            ['users', 'data_induk_teacher_id'],
            ['users', 'data_induk_active'],
            ['data_induk_sync_state', null],
        ];
        foreach ($required as [$table, $column]) {
            if ($column === null) {
                $tableStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
                $tableStmt->execute([$table]);
                if ((int) $tableStmt->fetchColumn() !== 1) {
                    return $ready = false;
                }
                continue;
            }
            $columnStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
            $columnStmt->execute([$table, $column]);
            if ((int) $columnStmt->fetchColumn() !== 1) {
                return $ready = false;
            }
        }
        return $ready = true;
    } catch (Throwable $error) {
        return $ready = false;
    }
}

function dataIndukIdentityMode(PDO $pdo): bool
{
    return dataIndukIsConfigured() && dataIndukSchemaReady($pdo);
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
    if (!dataIndukSchemaReady($pdo)) {
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
    require_once __DIR__ . '/family_helper.php';
    if (familySchemaReady($pdo) && (int) $pdo->query('SELECT COUNT(*) FROM family_guardians')->fetchColumn() > 0) {
        throw new DataIndukException('Master keluarga sudah terhubung. Pemetaan ID ekspor sekolah ke UUID Data Induk diperlukan sebelum sinkronisasi roster diaktifkan.');
    }
    dataIndukRequireRosterArchive($pdo);
    $unitId = dataIndukConfig()['unit_id'];
    $students = dataIndukFetchAll('students', ['unitId' => $unitId]);
    $guardians = dataIndukFetchAll('guardians', ['unitId' => $unitId]);
    $links = dataIndukFetchAll('family-links', ['unitId' => $unitId]);
    $classes = dataIndukClassNames($pdo);
    $guardianIds = [];
    $stats = ['students' => count($students), 'guardians' => 0, 'children' => 0, 'conflicts' => 0, 'archived_memberships' => 0];
    $affectedWaliIds = [];
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
            $mappedWaliId = dataIndukMappedId($pdo, 'wali_santri', 'data_induk_guardian_id', $masterId);
            if (!isset($fatherGuardianIds[$masterId]) && $mappedWaliId === null) {
                continue;
            }
            $guardianIds[$masterId] = $mappedWaliId;
            if ($guardianIds[$masterId] === null) {
                if (empty($fatherGuardianIds[$masterId])) continue;
                $candidate = $pdo->prepare('SELECT id FROM wali_santri WHERE LOWER(REPLACE(REPLACE(no_hp,\' \',\'\'),\'-\',\'\'))=? AND LOWER(nama_bapak)=? LIMIT 2');
                $candidate->execute([dataIndukNormalize((string) ($guardian['phone'] ?? '')), dataIndukNormalize((string) ($guardian['fullName'] ?? ''))]);
                $matches = $candidate->fetchAll(PDO::FETCH_COLUMN);
                if (count($matches) === 1 && dataIndukNormalize((string) ($guardian['phone'] ?? '')) !== '') {
                    dataIndukAddConflict($pdo, 'guardian', $masterId, (int) $matches[0], 'Kandidat nama dan nomor HP sama; persetujuan admin diperlukan.', $guardian);
                    $stats['conflicts']++;
                    continue;
                }
                $isArchived = empty($guardian['archivedAt']) ? 0 : 1;
                $insert = $pdo->prepare('INSERT INTO wali_santri (nama_bapak,no_hp,alamat,kategori,status_aktif,data_induk_guardian_id) VALUES (?,?,?,?,?,?)');
                $insert->execute([$guardian['fullName'] ?? 'Tanpa Nama', $guardian['phone'] ?? null, $guardian['address'] ?? null, 'reguler', $isArchived ? 0 : 1, $masterId]);
                $guardianIds[$masterId] = participantCaptureInsertedId($pdo);
                $affectedWaliIds[$guardianIds[$masterId]] = true;
            } else {
                // A current Data Induk snapshot must not silently reactivate a local manual archive.
                $isArchived = empty($guardian['archivedAt']) ? 0 : 1;
                $update = $pdo->prepare('UPDATE wali_santri SET nama_bapak=?, no_hp=?, alamat=?, status_aktif=IF(?,0,status_aktif) WHERE id=?');
                $update->execute([$guardian['fullName'] ?? '', $guardian['phone'] ?? null, $guardian['address'] ?? null, $isArchived, $guardianIds[$masterId]]);
            }
            $affectedWaliIds[$guardianIds[$masterId]] = true;
            if (!empty($guardian['archivedAt'])) {
                $archive = dataIndukArchiveGuardian($pdo, $masterId);
                $stats['archived_memberships'] += $archive['archived_memberships'];
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
                $affectedWaliIds[(int)$waliId] = true;
            } else {
                $oldParent = $pdo->prepare('SELECT wali_santri_id FROM santri_detail WHERE id=? FOR UPDATE');
                $oldParent->execute([$childId]);
                $oldWaliId = $oldParent->fetchColumn();
                if ($oldWaliId !== false) $affectedWaliIds[(int)$oldWaliId] = true;
                $update = $pdo->prepare('UPDATE santri_detail SET wali_santri_id=?, nama_anak=?, kelas=?, data_induk_enrollment_id=?, data_induk_class_id=?, status_roster=? WHERE id=?');
                $update->execute([$waliId, $student['fullName'] ?? '', $className, $student['enrollmentId'] ?? null, $classId ?: null, ($student['status'] ?? '') === 'active' ? 'active' : 'archived', $childId]);
                $affectedWaliIds[(int)$waliId] = true;
            }
            $stats['children']++;
        }
        $affectedWaliIds = array_keys($affectedWaliIds);
        sort($affectedWaliIds, SORT_NUMERIC);
        foreach ($affectedWaliIds as $waliId) {
            $activeChild = $pdo->prepare("SELECT 1 FROM santri_detail WHERE wali_santri_id=? AND LOWER(TRIM(COALESCE(kelas,'')))<>'lulus' AND (data_induk_student_id IS NULL OR status_roster='active') LIMIT 1");
            $activeChild->execute([$waliId]);
            if (!$activeChild->fetchColumn()) $stats['archived_memberships'] += dataIndukArchiveMemberships($pdo, (int)$waliId, 'DATA_INDUK_STUDENT_ARCHIVE');
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
                        $archive = dataIndukArchiveGuardian($pdo, $entityId);
                        $stats['archived_memberships'] = ($stats['archived_memberships'] ?? 0) + $archive['archived_memberships'];
                        break;
                    case 'student':
                        $archive = dataIndukArchiveStudent($pdo, $entityId);
                        $stats['archived_memberships'] = ($stats['archived_memberships'] ?? 0) + $archive['archived_memberships'];
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
