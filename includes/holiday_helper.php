<?php
require_once __DIR__ . '/attendance_history_helper.php';

function holidayDate(array $input, string $key): string
{
    $value = appRequestString($input, $key);
    if (!tahsinDate($value)) throw new AttendanceHistoryException('Pilih tanggal yang valid pada formulir libur.');
    return $value;
}

/** Calendar change, required audit and optional attendance cleanup commit together. */
function holidayApply(PDO $pdo, int $actorId, array $input): string
{
    if ($pdo->inTransaction()) throw new RuntimeException('Selesaikan transaksi lain sebelum mengubah libur.');
    $action = appRequestString($input, 'action');
    if (!in_array($action, ['add', 'delete', 'clean_bulk'], true)) throw new AttendanceHistoryException('Pilih tindakan libur yang tersedia.');
    $values = [];
    if ($action === 'add') {
        $values['tanggal'] = holidayDate($input, 'tanggal');
        $values['keterangan'] = appRequestString($input, 'keterangan');
        if (!preg_match('//u', $values['keterangan']) || appTextLength($values['keterangan']) > 255) throw new AttendanceHistoryException('Keterangan libur maksimal 255 karakter teks yang valid.');
        $clean = appRequestString($input, 'clean_alfa', '0');
        if (!in_array($clean, ['0', '1'], true)) throw new AttendanceHistoryException('Pilihan pembersihan Alfa tidak valid.');
        $values['clean_alfa'] = $clean === '1';
    } elseif ($action === 'clean_bulk') {
        $values = ['start_date' => holidayDate($input, 'start_date'), 'end_date' => holidayDate($input, 'end_date')];
        if ($values['start_date'] > $values['end_date']) throw new AttendanceHistoryException('Tanggal akhir harus sama atau setelah tanggal awal.');
        if (appRequestString($input, 'confirmed', '0') !== '1') throw new AttendanceHistoryException('Konfirmasikan pembersihan setelah memeriksa rentang tanggal.');
    } else {
        $values['id'] = filter_var(appRequestString($input, 'id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$values['id']) throw new AttendanceHistoryException('ID libur tidak valid.');
    }
    appRequireTransactionalTables($pdo);
    if ($action === 'clean_bulk' || !empty($values['clean_alfa'])) attendanceHistoryRequireReady($pdo);
    $mode = appUseStrictSqlMode($pdo);
    try {
        $pdo->beginTransaction();
        $actor = attendanceHistoryActor($pdo, $actorId, ['admin', 'pj_tahfidz']);
        $before = $after = null; $count = 0;
        if ($action === 'add') {
            $statement = $pdo->prepare('SELECT * FROM holidays WHERE tanggal=? FOR UPDATE'); $statement->execute([$values['tanggal']]); $before = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
            $pdo->prepare('INSERT INTO holidays(tanggal,keterangan) VALUES(?,?) ON DUPLICATE KEY UPDATE keterangan=VALUES(keterangan)')->execute([$values['tanggal'], $values['keterangan']]);
            $statement->execute([$values['tanggal']]); $after = $statement->fetch(PDO::FETCH_ASSOC);
            if ($values['clean_alfa']) {
                $statement = $pdo->prepare("DELETE FROM presensi WHERE tanggal=? AND status='A'"); $statement->execute([$values['tanggal']]); $count = $statement->rowCount();
            }
            $message = 'Hari libur berhasil disimpan.';
        } elseif ($action === 'delete') {
            $statement = $pdo->prepare('SELECT * FROM holidays WHERE id=? FOR UPDATE'); $statement->execute([$values['id']]); $before = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$before) throw new AttendanceHistoryException('Hari libur tidak ditemukan. Buka kembali daftar libur.');
            $pdo->prepare('DELETE FROM holidays WHERE id=?')->execute([$values['id']]);
            $message = 'Hari libur berhasil dihapus. Catatan presensi tetap dipertahankan.';
        } else {
            $statement = $pdo->prepare("DELETE FROM presensi WHERE status='A' AND tanggal BETWEEN ? AND ? AND tanggal IN (SELECT tanggal FROM holidays)");
            $statement->execute([$values['start_date'], $values['end_date']]); $count = $statement->rowCount();
            $message = 'Pembersihan Alfa selesai.';
        }
        $pdo->prepare('INSERT INTO activity_logs(user_id,username,action,description,ip_address) VALUES(?,?,?,?,?)')->execute([
            $actorId, $actor['username'], 'HOLIDAY_' . strtoupper($action), json_encode(['before' => $before, 'after' => $after,
                'criteria' => $values, 'attendance_removed' => $count], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $_SERVER['REMOTE_ADDR'] ?? '']);
        $pdo->commit();
        if ($count > 0) $message .= " $count catatan Alfa dibatalkan; salinan lengkap tersedia pada Riwayat Pemulihan Presensi.";
        return $message;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally {
        try { $pdo->prepare('SET SESSION sql_mode=?')->execute([$mode]); }
        catch (Throwable $error) { error_log('Holiday SQL mode restore: ' . $error->getMessage()); }
    }
}
