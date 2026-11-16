<?php
require_once __DIR__ . '/participant_helper.php';

final class StudentProgressException extends RuntimeException {}

function studentProgressNextClass(string $class): ?string
{
    if (!preg_match('/^Mustawa\s+([1-6])(?:\s+(Ikhwan|Akhwat))?$/iD', trim($class), $match)) return null;
    $level = (int) $match[1];
    return $level === 6 ? 'Lulus' : 'Mustawa ' . ($level + 1)
        . (isset($match[2]) ? ' ' . ucfirst(strtolower($match[2])) : '');
}

function studentProgressClassChoices(PDO $pdo): array
{
    $choices = [];
    for ($level = 1; $level <= 6; $level++) {
        foreach (['', ' Ikhwan', ' Akhwat'] as $suffix) $choices[] = 'Mustawa ' . $level . $suffix;
    }
    foreach ($pdo->query('SELECT DISTINCT kelas FROM santri_detail WHERE kelas IS NOT NULL ORDER BY kelas')->fetchAll(PDO::FETCH_COLUMN) as $class) {
        if (trim($class) !== '' && !isAlumniClass($class) && appTextLength($class) <= 20) $choices[] = $class;
    }
    return array_values(array_unique($choices));
}

function studentProgressFingerprint(array $child, int $parentActive): string
{
    return hash('sha256', serialize([(int) $child['id'], (int) $child['wali_santri_id'],
        $child['nama_anak'], $child['kelas'], $parentActive]));
}

/** Bind all displayed choices to one session; never trust IDs added by a client. */
function studentProgressFormToken(array $rows, int $actorId): string
{
    $forms = is_array($_SESSION['student_progress_forms'] ?? null) ? $_SESSION['student_progress_forms'] : [];
    $forms = array_filter($forms, static fn($form) => is_array($form) && ($form['expires'] ?? 0) > time());
    $expected = [];
    foreach ($rows as $row) $expected[(int) $row['id']] = studentProgressFingerprint($row, (int) $row['parent_active']);
    $token = bin2hex(random_bytes(16));
    $forms[$token] = ['actor_id' => $actorId, 'expires' => time() + 1800, 'expected' => $expected];
    $_SESSION['student_progress_forms'] = array_slice($forms, -8, null, true);
    return $token;
}

function studentProgressIds($values): array
{
    if (!is_array($values) || !$values || count($values) > 200) {
        throw new StudentProgressException('Pilih 1 sampai 200 anak dalam satu proses. Daftar lebih besar dapat diproses bertahap.');
    }
    $ids = [];
    foreach ($values as $value) {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)
            || !($id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]))) {
            throw new StudentProgressException('Daftar anak tidak valid. Buka kembali halaman naik kelas.');
        }
        $ids[$id] = $id;
    }
    sort($ids, SORT_NUMERIC);
    return array_values($ids);
}

function studentProgressSubmit(PDO $pdo, int $actorId, array $input): array
{
    $token = appRequestString($input, 'progress_token');
    $form = $_SESSION['student_progress_forms'][$token] ?? null;
    if (!preg_match('/^[a-f0-9]{32}$/D', $token) || !is_array($form)
        || ($form['actor_id'] ?? 0) !== $actorId || ($form['expires'] ?? 0) <= time()
        || !is_array($form['expected'] ?? null)) {
        throw new StudentProgressException('Formulir naik kelas berakhir atau berasal dari sesi lain. Buka kembali halaman dan periksa pilihan anak.');
    }
    $action = appRequestString($input, 'action');
    $ids = studentProgressIds($action === 'set_kelas' ? [$input['santri_id'] ?? null] : ($input['santri_ids'] ?? null));
    return studentProgressApply($pdo, $actorId, $action, $ids, appRequestString($input, 'kelas'), $form['expected'], $token);
}

/** Same journal/lock as participant forms and CSV; every selected child changes atomically. */
function studentProgressApply(PDO $pdo, int $actorId, string $action, array $ids, string $class, array $expected, string $operation): array
{
    if ($pdo->inTransaction()) throw new RuntimeException('Selesaikan transaksi lain sebelum mengubah kelas.');
    if (!in_array($action, ['bulk_naik', 'bulk_lulus', 'set_kelas'], true)
        || !preg_match('/^[a-f0-9]{32}$/D', $operation) || !$ids || count($ids) > 200
        || ($action === 'set_kelas' && count($ids) !== 1)) throw new StudentProgressException('Permintaan perubahan kelas tidak valid.');
    foreach ($ids as $id) {
        if (!is_int($id) || $id < 1 || !is_string($expected[$id] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $expected[$id])) throw new StudentProgressException('Pilihan anak tidak sesuai formulir yang dibuka.');
    }
    $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC);
    $fingerprints = array_intersect_key($expected, array_flip($ids)); ksort($fingerprints, SORT_NUMERIC);
    $requestHash = hash('sha256', serialize([$action, $ids, $class, $fingerprints]));
    if (!participantSchemaReady($pdo)) throw new StudentProgressException('Jurnal perubahan peserta perlu disiapkan pengelola sebelum mengubah kelas.');
    $lockName = participantWriteLockName($pdo);
    $statement = $pdo->prepare('SELECT GET_LOCK(?,5)'); $statement->execute([$lockName]);
    if ((int) $statement->fetchColumn() !== 1) throw new StudentProgressException('Perubahan peserta lain sedang diproses. Tunggu sebentar lalu coba lagi.');
    $originalMode = null;
    try {
        appRequireTransactionalTables($pdo);
        $originalMode = appUseStrictSqlMode($pdo);
        $pdo->beginTransaction();
        $statement = $pdo->prepare('SELECT role,nama_lengkap FROM users WHERE id=? FOR UPDATE'); $statement->execute([$actorId]);
        $actor = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$actor || !in_array($actor['role'], ['admin', 'pj_tahfidz'], true)) throw new StudentProgressException('Akun Anda tidak dapat mengubah kelas.');
        $statement = $pdo->prepare('SELECT actor_id,request_hash,result_json FROM participant_changes WHERE operation_id=? FOR UPDATE'); $statement->execute([$operation]);
        if ($receipt = $statement->fetch(PDO::FETCH_ASSOC)) {
            if ((int) $receipt['actor_id'] !== $actorId || !hash_equals($receipt['request_hash'], $requestHash)) throw new StudentProgressException('Formulir ini sudah digunakan untuk pilihan lain. Buka formulir baru.');
            $pdo->commit();
            return json_decode($receipt['result_json'], true, 32, JSON_THROW_ON_ERROR);
        }
        if (dataIndukConfig()['enabled']) throw new StudentProgressException('Kelas dikelola Data Induk. Perbarui melalui sumber tersebut.');
        $statement = $pdo->prepare('SELECT DISTINCT wali_santri_id FROM santri_detail WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY wali_santri_id');
        $statement->execute($ids); $parentIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $before = []; $selected = [];
        foreach ($parentIds as $parentId) {
            $snapshot = participantSnapshot($pdo, $parentId, true);
            if ($snapshot['managed'] || $snapshot['central']) throw new StudentProgressException('Anak yang terhubung master keluarga atau Data Induk harus diperbarui melalui sumbernya.');
            $before[$parentId] = $snapshot;
            foreach ($snapshot['children'] as $child) {
                if (!in_array((int) $child['id'], $ids, true)) continue;
                $id = (int) $child['id'];
                if (!hash_equals($expected[$id], studentProgressFingerprint($child, (int) $snapshot['parent']['status_aktif']))) throw new StudentProgressException('Data anak berubah sejak formulir dibuka. Perubahan dibatalkan. Buka kembali halaman dan periksa kelas terbaru.');
                if ($action !== 'set_kelas' && (int) $snapshot['parent']['status_aktif'] !== 1) throw new StudentProgressException('Anak dari peserta arsip tidak dapat dinaikkan atau diluluskan melalui formulir ini.');
                $selected[$id] = $child;
            }
        }
        if (count($selected) !== count($ids)) throw new StudentProgressException('Sebagian anak tidak ditemukan atau berpindah identitas. Buka kembali halaman.');
        if ($action === 'set_kelas' && (!in_array($class, studentProgressClassChoices($pdo), true) || appTextLength($class) > 20)) throw new StudentProgressException('Pilih kelas pengganti dari daftar yang tersedia.');
        $update = $pdo->prepare('UPDATE santri_detail SET kelas=? WHERE id=? AND wali_santri_id=?');
        foreach ($selected as $child) {
            if ($action === 'set_kelas') {
                if (!isAlumniClass($child['kelas'])) throw new StudentProgressException('Kelas hanya dapat dikembalikan melalui fitur ini jika anak masih berstatus Lulus.');
                $next = $class;
            } else {
                $next = studentProgressNextClass((string) $child['kelas']);
                if ($next === null) throw new StudentProgressException('Kelas anak tidak sesuai jenjang Mustawa 1 sampai 6. Periksa data sebelum memproses.');
                if ($action === 'bulk_lulus') $next = 'Lulus';
            }
            $update->execute([$next, $child['id'], $child['wali_santri_id']]);
        }
        $archive = archiveEligibleAlumni($pdo, $parentIds, $action === 'bulk_naik' ? 'AUTO_NAIK_KELAS' : 'AUTO_LULUS_MANUAL');
        $journalIds = [];
        foreach ($parentIds as $index => $parentId) $journalIds[$parentId] = $index === 0 ? $operation : substr(hash('sha256', $operation . ':' . $parentId), 0, 32);
        $result = ['done' => count($selected), 'archived_wali' => $archive['archived_wali'],
            'archived_memberships' => $archive['archived_memberships'], 'operation_id' => $operation, 'journal_ids' => $journalIds];
        $journalAction = ['bulk_naik' => 'class_promote', 'bulk_lulus' => 'class_graduate', 'set_kelas' => 'class_restore'][$action];
        $insert = $pdo->prepare('INSERT INTO participant_changes(operation_id,actor_id,actor_name,action,wali_id,request_hash,before_json,after_json,result_json) VALUES(?,?,?,?,?,?,?,?,?)');
        $journalBytes = 0;
        foreach ($parentIds as $parentId) {
            $beforeJson = json_encode($before[$parentId], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $afterJson = json_encode(participantSnapshot($pdo, $parentId, true), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $journalBytes += strlen($beforeJson) + strlen($afterJson);
            if ($journalBytes > 8 * 1024 * 1024) throw new StudentProgressException('Riwayat perubahan terlalu besar. Kurangi jumlah anak yang diproses dalam satu kali penyimpanan.');
            $insert->execute([$journalIds[$parentId], $actorId, $actor['nama_lengkap'], $journalAction, $parentId, $requestHash,
                $beforeJson, $afterJson, json_encode($result, JSON_THROW_ON_ERROR)]);
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally {
        try { if ($originalMode !== null) $pdo->prepare('SET SESSION sql_mode=?')->execute([$originalMode]); }
        catch (Throwable $error) { error_log('Class SQL mode restore: ' . $error->getMessage()); }
        try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
        catch (Throwable $error) { error_log('Class lock release: ' . $error->getMessage()); }
    }
}
