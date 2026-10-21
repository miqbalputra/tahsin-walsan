<?php
require_once __DIR__ . '/tahsin_report_helper.php';
/**
 * Shared attendance persistence rules.
 *
 * Both the full-form submit and the per-member autosave use this file so the
 * roster shown to an ustadz is validated by exactly the same business rules
 * when it is saved.
 */

class AttendanceValidationException extends RuntimeException
{
}

/**
 * Older production databases may not yet have the optional archive columns.
 * Attendance must remain usable during that additive-migration window.
 */
function attendanceSupportsMemberArchiving(PDO $pdo)
{
    static $supportByConnection = [];

    $connectionId = spl_object_id($pdo);
    if (array_key_exists($connectionId, $supportByConnection)) {
        return $supportByConnection[$connectionId];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'halaqoh_members'
               AND column_name = 'archived_at'
             LIMIT 1"
        );
        $stmt->execute();
        $supportByConnection[$connectionId] = (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        // A restricted database account can still use the legacy schema.
        $supportByConnection[$connectionId] = false;
    }

    return $supportByConnection[$connectionId];
}

function attendanceActiveMembershipCondition(PDO $pdo, $alias = 'hm')
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Alias tabel anggota tidak valid.');
    }

    return attendanceSupportsMemberArchiving($pdo) ? " AND {$alias}.archived_at IS NULL" : '';
}

function attendanceCombineRange($start, $end, $delimiter = '-')
{
    $start = trim((string) $start);
    $end = trim((string) $end);

    if ($start === '') {
        return '';
    }

    if ($end === '' || $end === $start) {
        return $start;
    }

    return $delimiter === 's/d' ? "$start s/d $end" : "$start-$end";
}

/**
 * Returns the roster eligible for attendance input. This is intentionally the
 * sole definition of an active member for the attendance feature.
 */
function attendanceHistoryBoundary(PDO $pdo, $tanggal): ?string
{
    if (!$tanggal || !tahsinSchemaReady($pdo)) return null;
    $boundary = tahsinSessionDate($tanggal) . ' 00:00:00.000000';
    $tracked = $pdo->query('SELECT tracked_from FROM tahsin_tracking WHERE id = 1')->fetchColumn();
    return $tracked && $boundary >= $tracked ? $boundary : null;
}

function attendanceFetchOwnedHalaqoh(PDO $pdo, $halaqohId, $ustadzId, $tanggal, bool $lock = false)
{
    $stmt = $pdo->prepare('SELECT * FROM halaqoh WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$halaqohId]);
    $halaqoh = $stmt->fetch();
    if (!$halaqoh) return false;
    $boundary = attendanceHistoryBoundary($pdo, $tanggal);
    if ($boundary) {
        $stmt = $pdo->prepare('SELECT DISTINCT ustadz_id FROM tahsin_roster_history WHERE halaqoh_id = ? AND valid_from <= ? AND (valid_until IS NULL OR valid_until > ?)');
        $stmt->execute([$halaqohId, $boundary, $boundary]);
        $owners = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($owners) return in_array((int) $ustadzId, $owners, true) ? $halaqoh : false;
    }
    return (int) $halaqoh['ustadz_id'] === (int) $ustadzId ? $halaqoh : false;
}

function attendanceLegacyRecordedSession(PDO $pdo, $tanggal): bool
{
    if (!$tanggal || attendanceHistoryBoundary($pdo, $tanggal)) return false;
    $holidays = tahsinHolidayMap($pdo);
    return tahsinSessionDate($tanggal) !== tahsinLatestSession(tahsinNow()->format('Y-m-d'), $holidays);
}

function attendanceFetchActiveMembers(PDO $pdo, $halaqohId, $ustadzId, $tanggal = null)
{
    $boundary = attendanceHistoryBoundary($pdo, $tanggal);
    if ($boundary) {
        $stmt = $pdo->prepare("SELECT w.id, w.nama_bapak, w.no_hp,
            GROUP_CONCAT(DISTINCT CONCAT(sd.nama_anak, ' [', sd.kelas, ']') SEPARATOR '<br>') AS info_anak
            FROM tahsin_roster_history rh JOIN wali_santri w ON w.id = rh.wali_santri_id
            LEFT JOIN santri_detail sd ON sd.wali_santri_id = w.id
            WHERE rh.halaqoh_id = ? AND rh.ustadz_id = ? AND rh.valid_from <= ? AND (rh.valid_until IS NULL OR rh.valid_until > ?)
            GROUP BY w.id, w.nama_bapak, w.no_hp ORDER BY w.nama_bapak");
        $stmt->execute([$halaqohId, $ustadzId, $boundary, $boundary]);
        return $stmt->fetchAll();
    }
    if (attendanceLegacyRecordedSession($pdo, $tanggal)) {
        $session = tahsinSessionDate($tanggal);
        $stmt = $pdo->prepare("SELECT w.id, w.nama_bapak, w.no_hp,
            GROUP_CONCAT(DISTINCT CONCAT(sd.nama_anak, ' [', sd.kelas, ']') SEPARATOR '<br>') AS info_anak
            FROM presensi p JOIN wali_santri w ON w.id = p.wali_santri_id JOIN halaqoh h ON h.id = p.halaqoh_id
            LEFT JOIN santri_detail sd ON sd.wali_santri_id = w.id
            WHERE h.id = ? AND h.ustadz_id = ? AND p.tanggal BETWEEN ? AND ?
            GROUP BY w.id, w.nama_bapak, w.no_hp ORDER BY w.nama_bapak");
        $stmt->execute([$halaqohId, $ustadzId, $session, (new DateTimeImmutable($session))->modify('+6 days')->format('Y-m-d')]);
        return $stmt->fetchAll();
    }
    $archiveCondition = attendanceActiveMembershipCondition($pdo);
    $stmt = $pdo->prepare("SELECT w.id, w.nama_bapak, w.no_hp,
                                  GROUP_CONCAT(CONCAT(sd.nama_anak, ' [', sd.kelas, ']') SEPARATOR '<br>') AS info_anak
                           FROM halaqoh h
                           JOIN halaqoh_members hm ON hm.halaqoh_id = h.id
                           JOIN wali_santri w ON w.id = hm.wali_santri_id
                           LEFT JOIN santri_detail sd ON sd.wali_santri_id = w.id
                           WHERE h.id = ? AND h.ustadz_id = ?{$archiveCondition}
                             AND w.status_aktif = 1
                             AND w.kategori IN ('reguler', 'askar')
                           GROUP BY w.id, w.nama_bapak, w.no_hp
                           ORDER BY w.nama_bapak");
    $stmt->execute([$halaqohId, $ustadzId]);

    return $stmt->fetchAll();
}

function attendanceFetchActiveMemberIds(PDO $pdo, $halaqohId, $ustadzId, $forUpdate = false, $tanggal = null)
{
    $boundary = attendanceHistoryBoundary($pdo, $tanggal);
    if ($boundary) {
        $stmt = $pdo->prepare('SELECT DISTINCT w.id FROM tahsin_roster_history rh JOIN wali_santri w ON w.id = rh.wali_santri_id
            WHERE rh.halaqoh_id = ? AND rh.ustadz_id = ? AND rh.valid_from <= ? AND (rh.valid_until IS NULL OR rh.valid_until > ?)
            ORDER BY w.id' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([$halaqohId, $ustadzId, $boundary, $boundary]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    if (attendanceLegacyRecordedSession($pdo, $tanggal)) {
        $session = tahsinSessionDate($tanggal);
        $stmt = $pdo->prepare('SELECT DISTINCT w.id FROM presensi p JOIN wali_santri w ON w.id = p.wali_santri_id
            JOIN halaqoh h ON h.id = p.halaqoh_id WHERE h.id = ? AND h.ustadz_id = ? AND p.tanggal BETWEEN ? AND ? ORDER BY w.id' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([$halaqohId, $ustadzId, $session, (new DateTimeImmutable($session))->modify('+6 days')->format('Y-m-d')]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $archiveCondition = attendanceActiveMembershipCondition($pdo);
    $sql = "SELECT DISTINCT hm.wali_santri_id
            FROM halaqoh h
            JOIN halaqoh_members hm ON hm.halaqoh_id = h.id
            JOIN wali_santri w ON w.id = hm.wali_santri_id
            WHERE h.id = ? AND h.ustadz_id = ?{$archiveCondition}
              AND w.status_aktif = 1
              AND w.kategori IN ('reguler', 'askar')
            ORDER BY hm.wali_santri_id";

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$halaqohId, $ustadzId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function attendanceFetchSessionAttendance(PDO $pdo, $halaqohId, $ustadzId, string $tanggal): array
{
    $ids = attendanceFetchActiveMemberIds($pdo, $halaqohId, $ustadzId, false, $tanggal);
    if (!$ids) return ['entries' => [], 'conflicts' => 0];
    $session = tahsinSessionDate($tanggal);
    $end = (new DateTimeImmutable($session))->modify('+6 days')->format('Y-m-d');
    $stmt = $pdo->prepare('SELECT * FROM presensi WHERE wali_santri_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND tanggal BETWEEN ? AND ? ORDER BY tanggal, id');
    $stmt->execute(array_merge($ids, [$session, $end]));
    $byWali = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $record) $byWali[(int) $record['wali_santri_id']][] = $record;
    $decisions = [];
    if (tahsinSchemaReady($pdo)) {
        $stmt = $pdo->prepare('SELECT * FROM tahsin_verifications WHERE session_date = ? ORDER BY id');
        $stmt->execute([$session]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $decision) $decisions[(int) $decision['wali_santri_id']] = $decision;
    }
    $entries = []; $conflicts = 0;
    $holidays = tahsinHolidayMap($pdo);
    foreach ($byWali as $waliId => $source) {
        $resolved = tahsinResolveSession($source, $decisions[$waliId] ?? null, tahsinSuspectIds($pdo, $source), $holidays);
        if (in_array($resolved['state'], ['conflict', 'invalid_date'], true) || ($resolved['record'] && (int) $resolved['record']['halaqoh_id'] !== (int) $halaqohId)) $conflicts++;
        elseif ($resolved['record']) $entries[$waliId] = $resolved['record'];
    }
    return ['entries' => $entries, 'conflicts' => $conflicts];
}

function attendanceNormalizePayload($data, $allowReset = false)
{
    if (!is_array($data)) {
        throw new AttendanceValidationException('Data peserta presensi tidak valid.');
    }

    $intent = strtolower(trim((string) ($data['intent'] ?? 'save')));
    $status = strtoupper(trim((string) ($data['status'] ?? '')));
    if ($intent === 'reset' || ($allowReset && $status === 'RESET')) {
        return ['intent' => 'reset'];
    }

    if ($intent !== 'save' || !in_array($status, ['H', 'S', 'I', 'A'], true)) {
        throw new AttendanceValidationException('Status presensi tidak valid.');
    }

    $alasan = ($status === 'S' || $status === 'I') ? trim((string) ($data['alasan'] ?? '')) : null;
    $jenisMateri = null;
    $jilid = null;
    $namaSurat = null;
    $halaman = null;
    $hasilTalaqqi = null;

    if ($status === 'H') {
        $jenisMateri = trim((string) ($data['jenis_materi'] ?? ''));
        if (!in_array($jenisMateri, ['Iqro', 'Al Quran'], true)) {
            throw new AttendanceValidationException('Jenis materi wajib dipilih untuk peserta yang hadir.');
        }

        $jilid = $jenisMateri === 'Iqro' ? trim((string) ($data['jilid'] ?? '')) : null;
        $namaSurat = $jenisMateri === 'Al Quran'
            ? attendanceCombineRange($data['nama_surat_dari'] ?? '', $data['nama_surat_sampai'] ?? '', 's/d')
            : null;
        $halaman = attendanceCombineRange($data['halaman_dari'] ?? '', $data['halaman_sampai'] ?? '', '-');
        $hasilTalaqqi = trim((string) ($data['hasil_talaqqi'] ?? ''));

        if ($halaman === '' || !in_array($hasilTalaqqi, ['Lulus', 'Ulang'], true)) {
            throw new AttendanceValidationException('Data pencapaian materi dan hasil talaqqi wajib diisi untuk yang hadir.');
        }
        if ($jenisMateri === 'Iqro' && ($jilid === '' || empty($data['halaman_dari']) || empty($data['halaman_sampai']))) {
            throw new AttendanceValidationException('Jilid dan halaman Iqro dari-sampai wajib dipilih.');
        }
        if ($jenisMateri === 'Al Quran' && ($namaSurat === '' || empty($data['nama_surat_dari']) || empty($data['nama_surat_sampai']) || empty($data['halaman_dari']) || empty($data['halaman_sampai']))) {
            throw new AttendanceValidationException('Nama surat dan ayat Al Quran dari-sampai wajib dipilih.');
        }
    } elseif (($status === 'S' || $status === 'I') && $alasan === '') {
        throw new AttendanceValidationException('Alasan atau keterangan wajib diisi jika status Sakit atau Izin.');
    }

    return [
        'intent' => 'save',
        'status' => $status,
        'alasan' => $alasan,
        'jenis_materi' => $jenisMateri,
        'jilid' => $jilid === '' ? null : $jilid,
        'nama_surat' => $namaSurat,
        'halaman' => $halaman,
        'hasil_talaqqi' => $hasilTalaqqi === '' ? null : $hasilTalaqqi,
    ];
}

/** Blank draft entries become Alfa only on an explicit full-form finalization. */
function attendanceNormalizeSubmission($data, string $tanggal, bool $finalize, ?DateTimeImmutable $now = null): array
{
    if (is_array($data) && $finalize && strtolower(trim((string) ($data['intent'] ?? 'save'))) === 'save'
        && trim((string) ($data['status'] ?? '')) === '') {
        $now = $now ?? tahsinNow();
        $cutoff = new DateTimeImmutable(tahsinSessionDate($tanggal) . ' 07:30:00', new DateTimeZone('Asia/Jakarta'));
        if ($now < $cutoff) throw new AttendanceValidationException('Sesi belum selesai. Peserta yang belum dipilih baru dapat difinalisasi sebagai Alfa setelah Ahad 07.30 WIB.');
        return attendanceNormalizePayload(['status' => 'A']) + ['automatic_alpha' => true];
    }
    return attendanceNormalizePayload($data, true);
}

/**
 * Saves one or more attendance payloads atomically.
 *
 * A row lock on the owned halaqoh serializes autosaves and full submissions
 * from this application. Existing legacy duplicates are updated together, but
 * are never removed by this routine.
 */
function attendanceSaveEntries(PDO $pdo, $halaqohId, $ustadzId, $tanggal, $entries, $requireCompleteRoster = false)
{
    if (!tahsinDate((string) $tanggal) || $tanggal > tahsinNow()->format('Y-m-d')) {
        throw new AttendanceValidationException('Tanggal presensi tidak valid atau masih di masa depan.');
    }
    if (!is_array($entries) || $entries === []) {
        throw new AttendanceValidationException('Tidak ada data presensi untuk disimpan.');
    }

    $normalizedEntries = [];
    foreach ($entries as $waliId => $data) {
        $waliId = filter_var($waliId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($waliId === false) {
            throw new AttendanceValidationException('Data peserta presensi tidak valid.');
        }
        $normalizedEntries[(int) $waliId] = attendanceNormalizeSubmission($data, $tanggal, $requireCompleteRoster);
    }

    $weekStart = tahsinSessionDate($tanggal);
    $weekEnd = (new DateTimeImmutable($weekStart))->modify('+6 days')->format('Y-m-d');
    $holidays = tahsinHolidayMap($pdo);
    if ((!tahsinSessionActive($weekStart, $holidays) || array_key_exists($tanggal, $holidays)) && array_filter($normalizedEntries, static fn($e) => $e['intent'] !== 'reset')) {
        throw new AttendanceValidationException('Sesi Ahad ini libur. Presensi tidak boleh diinput sebagai Alfa atau pertemuan wajib.');
    }

    $pdo->beginTransaction();
    try {
        // The lock makes the read-then-insert path safe against concurrent
        // autosave/finalization requests for the same halaqoh.
        if (!attendanceFetchOwnedHalaqoh($pdo, $halaqohId, $ustadzId, $tanggal, true)) {
            throw new AttendanceValidationException('Halaqoh tidak ditemukan atau bukan milik Anda.');
        }

        $activeIds = attendanceFetchActiveMemberIds($pdo, $halaqohId, $ustadzId, true, $tanggal);
        $activeIdMap = array_fill_keys($activeIds, true);
        $submittedIds = array_keys($normalizedEntries);
        sort($submittedIds, SORT_NUMERIC);

        if ($requireCompleteRoster && $submittedIds !== $activeIds) {
            throw new AttendanceValidationException('Daftar peserta berubah sejak formulir dibuka. Muat ulang halaman, lalu finalisasi kembali. Tidak ada data yang disimpan.');
        }

        foreach ($submittedIds as $waliId) {
            if (!isset($activeIdMap[$waliId])) {
                throw new AttendanceValidationException('Peserta bukan anggota aktif halaqoh ini. Muat ulang halaman sebelum menyimpan.');
            }
        }

        $update = $pdo->prepare("UPDATE presensi
            SET status = :status, alasan = :alasan, jenis_materi = :jenis_materi,
                jilid = :jilid, nama_surat = :nama_surat, halaman = :halaman,
                hasil_talaqqi = :hasil_talaqqi
            WHERE id = :target_id");
        $insert = $pdo->prepare("INSERT INTO presensi
            (halaqoh_id, wali_santri_id, tanggal, status, alasan, jenis_materi, jilid, nama_surat, halaman, hasil_talaqqi)
            VALUES (:halaqoh_id, :wali_santri_id, :tanggal, :status, :alasan, :jenis_materi, :jilid, :nama_surat, :halaman, :hasil_talaqqi)");
        $delete = $pdo->prepare('DELETE FROM presensi WHERE halaqoh_id = ? AND wali_santri_id = ? AND tanggal BETWEEN ? AND ?');
        $exists = $pdo->prepare('SELECT * FROM presensi WHERE wali_santri_id = ? AND tanggal BETWEEN ? AND ? ORDER BY id FOR UPDATE');

        $savedCount = 0;
        $deletedCount = 0;
        $autoAlphaCount = 0;
        $supportsVerification = tahsinSchemaReady($pdo);
        foreach ($normalizedEntries as $waliId => $entry) {
            if ($entry['intent'] === 'reset') {
                $delete->execute([$halaqohId, $waliId, $weekStart, $weekEnd]);
                $deletedCount += $delete->rowCount();
                continue;
            }

            $params = [
                ':halaqoh_id' => $halaqohId,
                ':wali_santri_id' => $waliId,
                ':tanggal' => $tanggal,
                ':status' => $entry['status'],
                ':alasan' => $entry['alasan'],
                ':jenis_materi' => $entry['jenis_materi'],
                ':jilid' => $entry['jilid'],
                ':nama_surat' => $entry['nama_surat'],
                ':halaman' => $entry['halaman'],
                ':hasil_talaqqi' => $entry['hasil_talaqqi'],
            ];
            // rowCount() cannot distinguish an unchanged row from a missing
            // row on MySQL, so check existence explicitly before inserting.
            $exists->execute([$waliId, $weekStart, $weekEnd]);
            $source = $exists->fetchAll(PDO::FETCH_ASSOC);
            $verification = null;
            if ($source && $supportsVerification) {
                $decision = $pdo->prepare('SELECT * FROM tahsin_verifications WHERE wali_santri_id = ? AND session_date = ? ORDER BY id DESC LIMIT 1');
                $decision->execute([$waliId, $weekStart]);
                $verification = $decision->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $resolved = tahsinResolveSession($source, $verification, tahsinSuspectIds($pdo, $source), $holidays);
            if ($resolved['state'] === 'conflict') {
                throw new AttendanceValidationException('Ada catatan berbeda atau verifikasi kedaluwarsa untuk sesi ini. Admin/PJ perlu memverifikasi melalui Rekap Lengkap.');
            }
            if ($resolved['record']) {
                if ((int) $resolved['record']['halaqoh_id'] !== (int) $halaqohId) throw new AttendanceValidationException('Sesi ini sudah tercatat pada halaqoh lain. Minta admin/PJ meninjau Rekap Lengkap.');
                // A blank in a stale form must never overwrite an autosave
                // that completed before this finalization obtained its lock.
                if (!empty($entry['automatic_alpha'])) {
                    if (!$resolved['complete']) throw new AttendanceValidationException('Peserta yang belum dipilih sudah memiliki catatan belum lengkap. Muat ulang dan lengkapi catatan tersebut.');
                    $savedCount++;
                    continue;
                }
                $updateParams = $params;
                unset($updateParams[':halaqoh_id'], $updateParams[':wali_santri_id'], $updateParams[':tanggal']);
                // Update identical copies together, but preserve verified conflicting sources.
                $targets = $verification ? [$resolved['record']] : $source;
                foreach ($targets as $target) {
                    if ((int) $target['halaqoh_id'] !== (int) $halaqohId) continue;
                    $updateParams[':target_id'] = (int) $target['id'];
                    $update->execute($updateParams);
                }
                if (function_exists('addLog')) addLog($pdo, 'UPDATE_TAHSIN_ATTENDANCE', json_encode(['wali_id' => $waliId, 'sesi' => $weekStart, 'before' => $source, 'after' => $entry], JSON_UNESCAPED_UNICODE));
            } else {
                $insert->execute($params);
                if (!empty($entry['automatic_alpha'])) $autoAlphaCount++;
            }
            $savedCount++;
        }

        $pdo->commit();
        return ['saved' => $savedCount, 'deleted' => $deletedCount, 'auto_alpha' => $autoAlphaCount];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
