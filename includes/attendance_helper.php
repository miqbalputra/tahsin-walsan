<?php
require_once __DIR__ . '/tahsin_report_helper.php';
require_once __DIR__ . '/family_helper.php';
require_once __DIR__ . '/attendance_history_helper.php';
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

function attendancePrepareHistory(PDO $pdo, int $actorId): void
{
    try {
        attendanceHistoryRequireReady($pdo);
        attendanceHistoryActor($pdo, $actorId, ['ustadz']);
    } catch (AttendanceHistoryException $failure) {
        throw new AttendanceValidationException($failure->getMessage());
    }
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
    $childTable = familyChildrenTable($pdo);
    $boundary = attendanceHistoryBoundary($pdo, $tanggal);
    if ($boundary) {
        $stmt = $pdo->prepare("SELECT w.id, w.nama_bapak, w.no_hp,
            GROUP_CONCAT(DISTINCT CONCAT(sd.nama_anak, ' [', sd.kelas, ']') SEPARATOR '<br>') AS info_anak
            FROM tahsin_roster_history rh JOIN wali_santri w ON w.id = rh.wali_santri_id
            LEFT JOIN {$childTable} sd ON sd.wali_santri_id = w.id
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
            LEFT JOIN {$childTable} sd ON sd.wali_santri_id = w.id
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
                           LEFT JOIN {$childTable} sd ON sd.wali_santri_id = w.id
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

function attendanceReadPayload($data): array
{
    if (!is_array($data)) throw new AttendanceValidationException('Data peserta presensi tidak valid.');
    $limits = ['intent'=>10,'status'=>10,'alasan'=>500,'jenis_materi'=>20,'jilid'=>2,'nama_surat_dari'=>100,'nama_surat_sampai'=>100,'halaman_dari'=>10,'halaman_sampai'=>10,'hasil_talaqqi'=>10];
    $raw=[];
    foreach ($limits as $field=>$limit) {
        $value=$data[$field]??($field==='intent'?'save':'');
        if (!is_string($value) && !is_int($value)) throw new AttendanceValidationException('Format isian presensi tidak valid.');
        $value=(string)$value;
        if (!preg_match('//u',$value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value) || appTextLength($value)>$limit) throw new AttendanceValidationException('Isian presensi terlalu panjang atau mengandung karakter tidak valid.');
        $raw[$field]=trim($value);
    }
    return $raw;
}

function attendanceNormalizePayload($data, $allowReset = false)
{
    $data=attendanceReadPayload($data);
    $intent=strtolower($data['intent']); $status=strtoupper($data['status']);
    if ($intent==='reset' || ($allowReset && $status==='RESET')) return ['intent'=>'reset'];
    if ($intent!=='save' || !in_array($status,['H','S','I','A'],true)) throw new AttendanceValidationException('Status presensi tidak valid.');
    $alasan=in_array($status,['S','I'],true)?$data['alasan']:null;
    $jenisMateri=$jilid=$namaSurat=$halaman=$hasilTalaqqi=null;
    if ($status==='H') {
        $jenisMateri=$data['jenis_materi'];
        if (!in_array($jenisMateri,['Iqro','Al Quran'],true)) throw new AttendanceValidationException('Jenis materi wajib dipilih untuk peserta yang hadir.');
        $jilid=$jenisMateri==='Iqro'?$data['jilid']:null;
        $namaSurat=$jenisMateri==='Al Quran'?attendanceCombineRange($data['nama_surat_dari'],$data['nama_surat_sampai'],'s/d'):null;
        if ($jenisMateri==='Iqro' && !preg_match('/^[1-6]$/D',$jilid)) throw new AttendanceValidationException('Pilih jilid Iqro 1 sampai 6.');
        if ($jenisMateri==='Al Quran' && ($data['nama_surat_dari']==='' || $data['nama_surat_sampai']==='' || appTextLength($namaSurat)>100)) throw new AttendanceValidationException('Nama surat dari-sampai wajib diisi, maksimal 100 karakter gabungan.');
        foreach (['halaman_dari','halaman_sampai'] as $field) {
            if (!preg_match('/^[1-9][0-9]{0,3}$/D',$data[$field]) && !($jenisMateri==='Iqro' && $data[$field]==='EBTA')) throw new AttendanceValidationException('Isi halaman/ayat dengan nomor yang valid, atau EBTA untuk Iqro.');
        }
        $start=$data['halaman_dari']==='EBTA'?10000:(int)$data['halaman_dari']; $end=$data['halaman_sampai']==='EBTA'?10000:(int)$data['halaman_sampai'];
        if ($start>$end && ($jenisMateri==='Iqro' || $data['nama_surat_dari']===$data['nama_surat_sampai'])) throw new AttendanceValidationException('Rentang halaman/ayat harus berurutan pada bacaan yang sama.');
        $halaman=attendanceCombineRange($data['halaman_dari'],$data['halaman_sampai']); $hasilTalaqqi=$data['hasil_talaqqi'];
        if (!in_array($hasilTalaqqi,['Lulus','Ulang'],true)) throw new AttendanceValidationException('Hasil talaqqi wajib dipilih untuk peserta Hadir.');
    } elseif (in_array($status,['S','I'],true) && $alasan==='') throw new AttendanceValidationException('Alasan atau keterangan wajib diisi jika status Sakit atau Izin.');
    return ['intent'=>'save','status'=>$status,'alasan'=>$alasan,'jenis_materi'=>$jenisMateri,'jilid'=>$jilid,'nama_surat'=>$namaSurat,'halaman'=>$halaman,'hasil_talaqqi'=>$hasilTalaqqi];
}

/** Preserve raw legacy text and unused fields when their meaning did not change. */
function attendanceRecordChanges(array $target, array $entry): array
{
    unset($entry['intent'],$entry['automatic_alpha']);
    if ($target['status']===$entry['status']) {
        if ($entry['status']==='H') {
            unset($entry['alasan']);
            if ($target['jenis_materi']===$entry['jenis_materi']) unset($entry[$entry['jenis_materi']==='Iqro'?'nama_surat':'jilid']);
        } else {
            foreach (['jenis_materi','jilid','nama_surat','halaman','hasil_talaqqi'] as $field) unset($entry[$field]);
            if ($entry['status']==='A') unset($entry['alasan']);
        }
    }
    foreach ($entry as $field=>$value) {
        if (($target[$field]===null)!==($value===null)) continue;
        $previous=trim((string)$target[$field]); $next=trim((string)$value);
        if (in_array($field,['halaman','nama_surat'],true)) {
            $delimiter=$field==='halaman'?'-':' s/d ';
            $parts=explode($delimiter,$previous,2);
            $previous=attendanceCombineRange($parts[0],$parts[1]??$parts[0],$field==='halaman'?'-':'s/d');
        }
        if ($previous===$next) unset($entry[$field]);
    }
    return $entry;
}

/** Blank draft entries become Alfa only on an explicit full-form finalization. */
function attendanceNormalizeSubmission($data, string $tanggal, bool $finalize, ?DateTimeImmutable $now = null): array
{
    $data = attendanceReadPayload($data);
    if ($finalize && strtolower($data['intent']) === 'save'
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

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
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

        $insert = $pdo->prepare("INSERT INTO presensi
            (halaqoh_id, wali_santri_id, tanggal, status, alasan, jenis_materi, jilid, nama_surat, halaman, hasil_talaqqi)
            VALUES (:halaqoh_id, :wali_santri_id, :tanggal, :status, :alasan, :jenis_materi, :jilid, :nama_surat, :halaman, :hasil_talaqqi)");
        $delete = $pdo->prepare('DELETE FROM presensi WHERE halaqoh_id = ? AND wali_santri_id = ? AND tanggal BETWEEN ? AND ?');
        $exists = $pdo->prepare('SELECT * FROM presensi WHERE wali_santri_id = ? AND tanggal BETWEEN ? AND ? ORDER BY id FOR UPDATE');

        $savedCount = 0;
        $deletedCount = 0;
        $autoAlphaCount = 0;
        $supportsVerification = tahsinSchemaReady($pdo);
        $historyPrepared = false;
        $prepareHistory = static function () use ($pdo, $ustadzId, &$historyPrepared): void {
            if (!$historyPrepared) {
                attendancePrepareHistory($pdo, (int) $ustadzId);
                $historyPrepared = true;
            }
        };
        foreach ($normalizedEntries as $waliId => $entry) {
            if ($entry['intent'] === 'reset') {
                $prepareHistory();
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
                // Update identical copies together, but preserve verified conflicting sources.
                $targets = $verification ? [$resolved['record']] : $source;
                $prepareHistory();
                foreach ($targets as $target) {
                    if ((int) $target['halaqoh_id'] !== (int) $halaqohId) continue;
                    $changes=attendanceRecordChanges($target,$entry);
                    if ($changes) {
                        $columns=implode(',',array_map(static fn($field)=>attendanceHistoryQuote($field).'=?',array_keys($changes)));
                        $pdo->prepare('UPDATE presensi SET '.$columns.' WHERE id=?')->execute([...array_values($changes),(int)$target['id']]);
                    }
                }
                if (function_exists('addLog')) addLog($pdo, 'UPDATE_TAHSIN_ATTENDANCE', json_encode(['wali_id' => $waliId, 'sesi' => $weekStart, 'history' => 'presensi_history'], JSON_UNESCAPED_UNICODE));
            } else {
                $insert->execute($params);
                if (!empty($entry['automatic_alpha'])) $autoAlphaCount++;
            }
            $savedCount++;
        }

        if ($ownsTransaction) $pdo->commit();
        return ['saved' => $savedCount, 'deleted' => $deletedCount, 'auto_alpha' => $autoAlphaCount];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
