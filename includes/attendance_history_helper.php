<?php
require_once __DIR__ . '/security_helper.php';
require_once __DIR__ . '/database_helper.php';
require_once __DIR__ . '/tahsin_report_helper.php';

final class AttendanceHistoryException extends RuntimeException {}

function attendanceHistoryColumns(PDO $pdo): array
{
    $columns = $pdo->query('SHOW FULL COLUMNS FROM presensi')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as &$column) {
        unset($column['Privileges']);
        foreach ($column as &$value) if ($value !== null) $value = (string) $value;
        unset($value);
    }
    unset($column);
    return $columns;
}

function attendanceHistorySchemaHash(PDO $pdo): string
{
    return hash('sha256', serialize(attendanceHistoryColumns($pdo)));
}

function attendanceHistoryQuote(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/** Base64 preserves NULL, literal text and binary extension columns without coercion. */
function attendanceHistorySnapshotSql(PDO $pdo, string $alias): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias)) throw new InvalidArgumentException('Alias presensi tidak valid.');
    $parts = [];
    foreach (attendanceHistoryColumns($pdo) as $column) {
        $name = $column['Field'];
        $parts[] = $pdo->quote($name);
        $parts[] = 'TO_BASE64(CAST(' . $alias . '.' . attendanceHistoryQuote($name) . ' AS BINARY))';
    }
    return 'JSON_OBJECT(' . implode(',', $parts) . ')';
}

function attendanceHistoryContextSql(string $alias): string
{
    return "JSON_OBJECT('parent_id',TO_BASE64(CAST($alias.wali_santri_id AS BINARY)),
        'parent_name',(SELECT TO_BASE64(CAST(w.nama_bapak AS BINARY)) FROM wali_santri w WHERE w.id=$alias.wali_santri_id),
        'parent_created',(SELECT TO_BASE64(CAST(w.created_at AS BINARY)) FROM wali_santri w WHERE w.id=$alias.wali_santri_id),
        'group_id',TO_BASE64(CAST($alias.halaqoh_id AS BINARY)),
        'group_name',(SELECT TO_BASE64(CAST(h.nama_halaqoh AS BINARY)) FROM halaqoh h WHERE h.id=$alias.halaqoh_id),
        'group_teacher',(SELECT TO_BASE64(CAST(h.ustadz_id AS BINARY)) FROM halaqoh h WHERE h.id=$alias.halaqoh_id))";
}

function attendanceHistoryReady(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id,instance_key,event,presensi_id,participant_ref,before_json,after_json,context_json,offset_minutes,actor_id,actor_name,origin_id,created_at FROM presensi_history LIMIT 0');
        $state = $pdo->query('SELECT id,instance_key,schema_hash,update_name,delete_name,update_sha,delete_sha FROM presensi_history_state WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if (!$state || !hash_equals($state['schema_hash'], attendanceHistorySchemaHash($pdo))) return false;
        if (!preg_match('/^[a-f0-9]{32}$/D', $state['instance_key'])) return false;
        $statement = $pdo->prepare("SELECT TRIGGER_NAME,ACTION_STATEMENT,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name IN (?,?)");
        $statement->execute([$state['update_name'], $state['delete_name']]);
        $triggers = array_column($statement->fetchAll(PDO::FETCH_ASSOC), null, 'TRIGGER_NAME');
        foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $key => $event) {
            if ($state[$key . '_name'] !== 'tahsin_history_' . $key . '_' . substr($state['schema_hash'], 0, 16)) return false;
            $trigger = $triggers[$state[$key . '_name']] ?? null;
            if (!$trigger || $trigger['ACTION_TIMING'] !== 'BEFORE' || $trigger['EVENT_MANIPULATION'] !== $event
                || $trigger['EVENT_OBJECT_TABLE'] !== 'presensi'
                || !is_string($trigger['ACTION_STATEMENT'])
                || !hash_equals($state[$key . '_sha'], hash('sha256', $trigger['ACTION_STATEMENT']))) return false;
        }
        return true;
    } catch (PDOException $error) {
        if (in_array($error->getCode(), ['42S02', '42S22'], true)) return false;
        throw $error;
    }
}

function attendanceHistoryRequireReady(PDO $pdo): void
{
    if (!attendanceHistoryReady($pdo)) throw new AttendanceHistoryException('Riwayat pemulihan presensi belum siap. Hubungi pengelola; catatan lama belum diubah.');
    appRequireTransactionalTables($pdo);
}

/** Additive, retryable; old trigger versions stay active until both new versions exist. */
function attendanceHistoryApplyMigration(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new RuntimeException('Jalankan migrasi riwayat di luar transaksi data.');
    appRequireTransactionalTables($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS presensi_history (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, instance_key CHAR(32) NOT NULL,
        event VARCHAR(20) NOT NULL, presensi_id VARCHAR(64) NOT NULL, participant_ref VARCHAR(64) NOT NULL,
        before_json LONGTEXT NULL, after_json LONGTEXT NULL, context_json LONGTEXT NOT NULL,
        offset_minutes SMALLINT NOT NULL, actor_id INT NULL, actor_name VARCHAR(100) NULL,
        origin_id BIGINT UNSIGNED NULL, created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        UNIQUE KEY unique_presensi_restore(origin_id), KEY idx_presensi_history_record(presensi_id,id),
        KEY idx_presensi_history_participant(participant_ref,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS presensi_history_state (
        id TINYINT PRIMARY KEY, instance_key CHAR(32) NOT NULL, schema_hash CHAR(64) NOT NULL,
        update_name VARCHAR(64) NOT NULL, delete_name VARCHAR(64) NOT NULL,
        update_sha CHAR(64) NOT NULL, delete_sha CHAR(64) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (attendanceHistoryReady($pdo)) return;
    $hash = attendanceHistorySchemaHash($pdo);
    $state = $pdo->query('SELECT * FROM presensi_history_state WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if (!$state) {
        $pdo->prepare("INSERT INTO presensi_history_state VALUES(1,?,'','','','','')")->execute([bin2hex(random_bytes(16))]);
    }
    $old = attendanceHistorySnapshotSql($pdo, 'OLD');
    $new = attendanceHistorySnapshotSql($pdo, 'NEW');
    $context = attendanceHistoryContextSql('OLD');
    $different = implode(' OR ', array_map(static fn($column) => 'NOT (CAST(OLD.' . attendanceHistoryQuote($column['Field']) . ' AS BINARY) <=> CAST(NEW.' . attendanceHistoryQuote($column['Field']) . ' AS BINARY))', attendanceHistoryColumns($pdo)));
    $metadata = [];
    foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $key => $event) {
        $name = 'tahsin_history_' . $key . '_' . substr($hash, 0, 16);
        $condition = $key === 'update' ? " AND ($different)" : '';
        $after = $key === 'update' ? $new : 'NULL';
        $body = "BEGIN
            IF (SELECT schema_hash FROM presensi_history_state WHERE id=1)='$hash'$condition THEN
                INSERT INTO presensi_history(instance_key,event,presensi_id,participant_ref,before_json,after_json,context_json,offset_minutes,actor_id,actor_name,origin_id)
                SELECT instance_key,'$key',CAST(OLD.id AS CHAR),CAST(OLD.wali_santri_id AS CHAR),$old,$after,$context,
                    TIMESTAMPDIFF(MINUTE,UTC_TIMESTAMP(),CURRENT_TIMESTAMP()),@tahsin_actor_id,@tahsin_actor_name,@tahsin_restore_origin
                FROM presensi_history_state WHERE id=1;
            END IF;
        END";
        $statement = $pdo->prepare('SELECT ACTION_STATEMENT FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name=?');
        $statement->execute([$name]); $existing = $statement->fetchColumn();
        if ($existing === false) $pdo->exec('CREATE TRIGGER ' . attendanceHistoryQuote($name) . " BEFORE $event ON presensi FOR EACH ROW $body");
        else if (preg_replace('/\s+/', '', $existing) !== preg_replace('/\s+/', '', $body)) throw new RuntimeException('Nama trigger riwayat sudah dipakai definisi lain. Periksa sebelum migrasi.');
        $statement->execute([$name]);
        $metadata[$key] = [$name, hash('sha256', (string) $statement->fetchColumn())];
    }
    $pdo->prepare('UPDATE presensi_history_state SET schema_hash=?,update_name=?,delete_name=?,update_sha=?,delete_sha=? WHERE id=1')->execute([
        $hash, $metadata['update'][0], $metadata['delete'][0], $metadata['update'][1], $metadata['delete'][1]]);
    attendanceHistoryRequireReady($pdo);
}

function attendanceHistoryDecode(?string $json): ?array
{
    if ($json === null) return null;
    if (strlen($json) > 16 * 1024 * 1024) throw new AttendanceHistoryException('Snapshot terlalu besar untuk pemulihan melalui halaman ini. Hubungi pengelola untuk pemeriksaan terpisah.');
    $row = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($row) || !$row) throw new AttendanceHistoryException('Snapshot riwayat tidak valid.');
    foreach ($row as $column => &$value) {
        if (!is_string($column) || ($value !== null && !is_string($value))) throw new AttendanceHistoryException('Nilai snapshot tidak valid.');
        if ($value !== null) {
            $value = base64_decode($value, true);
            if ($value === false) throw new AttendanceHistoryException('Encoding snapshot tidak valid.');
        }
    }
    unset($value);
    return $row;
}

function attendanceHistoryHash(array $row): string
{
    ksort($row);
    return hash('sha256', serialize($row));
}

function attendanceHistoryEncode(array $row): string
{
    return json_encode(array_map(static fn($value) => $value === null ? null : base64_encode((string) $value), $row), JSON_THROW_ON_ERROR);
}

function attendanceHistoryActor(PDO $pdo, int $actorId, array $roles): array
{
    $statement = $pdo->prepare('SELECT id,username,nama_lengkap,role FROM users WHERE id=?' . ($pdo->inTransaction() ? ' FOR UPDATE' : '')); $statement->execute([$actorId]);
    $actor = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$actor || !in_array($actor['role'], $roles, true)) throw new AttendanceHistoryException('Akun Anda tidak memiliki izin untuk perubahan presensi ini.');
    $pdo->prepare('SET @tahsin_actor_id=?,@tahsin_actor_name=?')->execute([$actorId, $actor['nama_lengkap']]);
    return $actor;
}

function attendanceHistorySelect(PDO $pdo, string $id, bool $lock = false): ?array
{
    $snapshot = attendanceHistorySnapshotSql($pdo, 'p');
    $context = attendanceHistoryContextSql('p');
    $statement = $pdo->prepare("SELECT $snapshot AS snapshot,$context AS identity_context FROM presensi p WHERE p.id=?" . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute([$id]);
    $record = $statement->fetch(PDO::FETCH_ASSOC);
    return $record ? ['row' => attendanceHistoryDecode($record['snapshot']), 'context' => attendanceHistoryDecode($record['identity_context'])] : null;
}

function attendanceHistoryDeleteOne(PDO $pdo, int $actorId, string $id, string $fingerprint): bool
{
    require_once __DIR__ . '/attendance_helper.php';
    if (!preg_match('/^[1-9][0-9]*$/D', $id) || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) throw new AttendanceHistoryException('Formulir hapus tidak valid. Buka kembali laporan sebelum menghapus.');
    if ($pdo->inTransaction()) throw new RuntimeException('Selesaikan transaksi lain sebelum menghapus presensi.');
    attendanceHistoryRequireReady($pdo);
    $pdo->beginTransaction();
    try {
        $actor = attendanceHistoryActor($pdo, $actorId, ['admin', 'pj_tahfidz', 'ustadz']);
        $initial = attendanceHistorySelect($pdo, $id);
        if (!$initial) { $pdo->commit(); return false; }
        $row = $initial['row'];
        if ($row['halaqoh_id'] !== null) {
            $statement = $pdo->prepare('SELECT id FROM halaqoh WHERE id=? FOR UPDATE'); $statement->execute([$row['halaqoh_id']]); $statement->fetchColumn();
        }
        $current = attendanceHistorySelect($pdo, $id, true);
        if (!$current || $current['row']['halaqoh_id'] !== $row['halaqoh_id'] || !hash_equals($fingerprint, attendanceHistoryHash($current['row']))) throw new AttendanceHistoryException('Catatan berubah sejak laporan dibuka. Data belum dihapus; periksa laporan terbaru.');
        if ($actor['role'] === 'ustadz' && (!$row['halaqoh_id'] || !attendanceFetchOwnedHalaqoh($pdo, (int) $row['halaqoh_id'], $actorId, $row['tanggal'], true))) throw new AttendanceHistoryException('Catatan bukan penugasan Anda pada sesi tersebut.');
        $pdo->prepare('DELETE FROM presensi WHERE id=?')->execute([$id]);
        $pdo->commit();
        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
