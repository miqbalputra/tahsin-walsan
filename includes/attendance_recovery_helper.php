<?php
require_once __DIR__ . '/attendance_history_helper.php';

function attendanceRecoveryEntry(PDO $pdo, int $id, bool $lock = false): array
{
    $statement = $pdo->prepare('SELECT * FROM presensi_history WHERE id=?' . ($lock ? ' FOR UPDATE' : '')); $statement->execute([$id]);
    $entry = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$entry) throw new AttendanceHistoryException('Riwayat presensi tidak ditemukan.');
    return $entry;
}

function attendanceRecoveryTimezone(int $minutes): string
{
    if (abs($minutes) > 840) throw new AttendanceHistoryException('Zona waktu snapshot tidak valid.');
    return sprintf('%s%02d:%02d', $minutes < 0 ? '-' : '+', intdiv(abs($minutes), 60), abs($minutes) % 60);
}

function attendanceRecoveryContext(PDO $pdo, array $target, bool $lock): array
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    $group = false;
    if ($target['halaqoh_id'] !== null) {
        $statement = $pdo->prepare('SELECT id,nama_halaqoh,ustadz_id FROM halaqoh WHERE id=?' . $suffix); $statement->execute([$target['halaqoh_id']]); $group = $statement->fetch(PDO::FETCH_ASSOC);
    }
    $statement = $pdo->prepare('SELECT id,nama_bapak,created_at FROM wali_santri WHERE id=?' . $suffix); $statement->execute([$target['wali_santri_id']]); $parent = $statement->fetch(PDO::FETCH_ASSOC);
    return ['parent_id' => $parent ? (string) $parent['id'] : null, 'parent_name' => $parent['nama_bapak'] ?? null,
        'parent_created' => $parent['created_at'] ?? null, 'group_id' => $group ? (string) $group['id'] : null,
        'group_name' => $group['nama_halaqoh'] ?? null, 'group_teacher' => $group ? (string) $group['ustadz_id'] : null];
}

/** Run with the snapshot's fixed UTC offset so TIMESTAMP values retain their instant. */
function attendanceRecoveryInspect(PDO $pdo, array $entry, bool $lock = false): array
{
    $target = attendanceHistoryDecode($entry['before_json']);
    $restored = $pdo->prepare('SELECT id FROM presensi_history WHERE origin_id=?'); $restored->execute([$entry['id']]);
    $restoredId = $restored->fetchColumn();
    $reason = '';
    $context = []; $current = null; $peers = [];
    if (!$target || !in_array($entry['event'], ['update', 'delete'], true)) {
        $reason = 'Entri ini tidak memiliki versi lama yang dapat dipulihkan.';
        $current = attendanceHistorySelect($pdo, $entry['presensi_id'], $lock);
    }
    else {
        foreach (['id', 'wali_santri_id', 'halaqoh_id', 'tanggal', 'created_at'] as $column) {
            if (!array_key_exists($column, $target)) throw new AttendanceHistoryException('Identitas snapshot tidak lengkap.');
        }
        if ($target['id'] !== $entry['presensi_id'] || $target['wali_santri_id'] !== $entry['participant_ref'] || !tahsinDate((string) $target['tanggal'])) throw new AttendanceHistoryException('Identitas snapshot tidak konsisten.');
        $context = attendanceRecoveryContext($pdo, $target, $lock);
        $current = attendanceHistorySelect($pdo, $target['id'], $lock);
        $start = tahsinSessionDate($target['tanggal']);
        $end = (new DateTimeImmutable($start))->modify('+6 days')->format('Y-m-d');
        $snapshotSql = attendanceHistorySnapshotSql($pdo, 'p');
        $statement = $pdo->prepare("SELECT $snapshotSql FROM presensi p WHERE p.wali_santri_id=? AND p.tanggal BETWEEN ? AND ? AND p.id<>? ORDER BY p.id" . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$target['wali_santri_id'], $start, $end, $target['id']]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $json) $peers[] = attendanceHistoryDecode($json);
        $instance = $pdo->query('SELECT instance_key FROM presensi_history_state WHERE id=1')->fetchColumn();
        if (!hash_equals((string) $instance, $entry['instance_key'])) $reason = 'Riwayat ini berasal dari database lain. Salinannya tetap tersedia untuk diperiksa pengelola.';
        elseif (attendanceHistoryHash($context) !== attendanceHistoryHash(attendanceHistoryDecode($entry['context_json']))) $reason = 'Identitas peserta atau halaqoh berubah sejak versi ini disimpan. Pemulihan otomatis ditolak agar tidak terhubung ke identitas lain.';
        elseif (array_diff(array_keys($target), array_column(attendanceHistoryColumns($pdo), 'Field'))) $reason = 'Ada kolom lama yang belum tersedia pada struktur database saat ini.';
        elseif ($entry['event'] === 'delete' && $current !== null) $reason = 'ID catatan ini sudah digunakan kembali. Data saat ini tidak akan ditimpa.';
        elseif ($entry['event'] === 'update' && $current === null) $reason = 'Catatan sudah dihapus setelah perubahan ini. Pilih riwayat penghapusan terbaru untuk memulihkannya.';
        elseif ($peers) $reason = 'Sesi ini sudah memiliki catatan lain. Tinjau Rekap Lengkap agar pemulihan tidak membuat duplikasi atau konflik.';
        elseif ($current !== null) {
            foreach (['id', 'wali_santri_id', 'halaqoh_id', 'tanggal', 'created_at'] as $column) {
                if ($current['row'][$column] !== $target[$column]) $reason = 'Identitas atau tanggal catatan saat ini berbeda dari versi lama. Data saat ini tidak ditimpa.';
            }
        }
    }
    if ($restoredId !== false) $reason = 'Versi ini sudah dipulihkan. Gunakan riwayat perubahan yang lebih baru jika diperlukan.';
    $fingerprint = hash('sha256', serialize([$entry['id'], $entry['instance_key'], $entry['event'], $entry['before_json'], $entry['context_json'], $context, $current, $peers, attendanceHistorySchemaHash($pdo)]));
    return ['entry' => $entry, 'target' => $target, 'current' => $current['row'] ?? null, 'reason' => $reason,
        'can_restore' => $reason === '', 'fingerprint' => $fingerprint, 'restored_id' => $restoredId === false ? null : (int) $restoredId];
}

function attendanceRecoveryPreview(PDO $pdo, int $id): array
{
    if ($pdo->inTransaction()) throw new RuntimeException('Selesaikan transaksi lain sebelum pratinjau pemulihan.');
    attendanceHistoryRequireReady($pdo);
    $entry = attendanceRecoveryEntry($pdo, $id);
    $timezone = $pdo->query('SELECT @@SESSION.time_zone')->fetchColumn();
    try {
        $pdo->prepare('SET SESSION time_zone=?')->execute([attendanceRecoveryTimezone((int) $entry['offset_minutes'])]);
        $pdo->beginTransaction();
        $result = attendanceRecoveryInspect($pdo, $entry);
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally { $pdo->prepare('SET SESSION time_zone=?')->execute([$timezone]); }
}

function attendanceRecoveryApply(PDO $pdo, int $actorId, int $id, string $fingerprint): array
{
    if ($pdo->inTransaction()) throw new RuntimeException('Selesaikan transaksi lain sebelum pemulihan presensi.');
    if ($id < 1 || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) throw new AttendanceHistoryException('Pratinjau pemulihan tidak valid. Buka kembali riwayat.');
    attendanceHistoryRequireReady($pdo);
    $timezone = $pdo->query('SELECT @@SESSION.time_zone')->fetchColumn();
    $sqlMode = appUseStrictSqlMode($pdo, true);
    try {
        $pdo->beginTransaction();
        attendanceHistoryActor($pdo, $actorId, ['admin', 'pj_tahfidz']);
        $entry = attendanceRecoveryEntry($pdo, $id, true);
        $pdo->prepare('SET SESSION time_zone=?')->execute([attendanceRecoveryTimezone((int) $entry['offset_minutes'])]);
        $preview = attendanceRecoveryInspect($pdo, $entry, true);
        if ($preview['restored_id'] !== null) { $pdo->commit(); return ['history_id' => $preview['restored_id'], 'replayed' => true]; }
        if (!$preview['can_restore']) throw new AttendanceHistoryException($preview['reason']);
        if (!hash_equals($preview['fingerprint'], $fingerprint)) throw new AttendanceHistoryException('Data berubah setelah pratinjau. Pemulihan belum diproses; periksa versi terbaru.');
        $target = $preview['target'];
        $writable = [];
        foreach (attendanceHistoryColumns($pdo) as $column) {
            if (array_key_exists($column['Field'], $target) && !str_contains(strtoupper($column['Extra']), 'GENERATED')) $writable[$column['Field']] = $target[$column['Field']];
        }
        $pdo->prepare('SET @tahsin_restore_origin=?')->execute([$id]);
        if ($preview['current'] === null) {
            $columns = implode(',', array_map('attendanceHistoryQuote', array_keys($writable)));
            $pdo->prepare('INSERT INTO presensi (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($writable), '?')) . ')')->execute(array_values($writable));
        } else {
            unset($writable['id']);
            $columns = implode(',', array_map(static fn($column) => attendanceHistoryQuote($column) . '=?', array_keys($writable)));
            $pdo->prepare('UPDATE presensi SET ' . $columns . ' WHERE id=?')->execute([...array_values($writable), $target['id']]);
        }
        $after = attendanceHistorySelect($pdo, $target['id'], true);
        if (!$after) throw new AttendanceHistoryException('Catatan hasil pemulihan tidak dapat diverifikasi.');
        foreach ($target as $column => $value) if (!array_key_exists($column, $after['row']) || $after['row'][$column] !== $value) throw new AttendanceHistoryException('Hasil pemulihan tidak sama dengan snapshot lama. Perubahan dibatalkan.');
        $statement = $pdo->prepare('SELECT id FROM presensi_history WHERE origin_id=?'); $statement->execute([$id]);
        $historyId = $statement->fetchColumn();
        if ($historyId === false) {
            $pdo->prepare("INSERT INTO presensi_history(instance_key,event,presensi_id,participant_ref,before_json,after_json,context_json,offset_minutes,actor_id,actor_name,origin_id)
                VALUES(?,'restore',?,?,?,?,?,?,?,?,?)")->execute([$entry['instance_key'], $target['id'], $target['wali_santri_id'], $preview['current'] === null ? null : attendanceHistoryEncode($preview['current']),
                attendanceHistoryEncode($after['row']), attendanceHistoryEncode($after['context']), $entry['offset_minutes'], $actorId,
                $pdo->query('SELECT @tahsin_actor_name')->fetchColumn(), $id]);
            $historyId = $pdo->lastInsertId();
        }
        $pdo->commit();
        return ['history_id' => (int) $historyId, 'replayed' => false];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally {
        try { $pdo->exec('SET @tahsin_restore_origin=NULL'); $pdo->prepare('SET SESSION time_zone=?')->execute([$timezone]); $pdo->prepare('SET SESSION sql_mode=?')->execute([$sqlMode]); }
        catch (Throwable $error) { error_log('Attendance recovery connection cleanup: ' . $error->getMessage()); }
    }
}
