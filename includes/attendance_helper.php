<?php
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
function attendanceFetchActiveMembers(PDO $pdo, $halaqohId, $ustadzId)
{
    $stmt = $pdo->prepare("SELECT w.id, w.nama_bapak, w.no_hp,
                                  GROUP_CONCAT(CONCAT(sd.nama_anak, ' [', sd.kelas, ']') SEPARATOR '<br>') AS info_anak
                           FROM halaqoh h
                           JOIN halaqoh_members hm ON hm.halaqoh_id = h.id
                           JOIN wali_santri w ON w.id = hm.wali_santri_id
                           LEFT JOIN santri_detail sd ON sd.wali_santri_id = w.id
                           WHERE h.id = ? AND h.ustadz_id = ?
                             AND hm.archived_at IS NULL
                             AND w.status_aktif = 1
                             AND w.kategori IN ('reguler', 'askar')
                           GROUP BY w.id, w.nama_bapak, w.no_hp
                           ORDER BY w.nama_bapak");
    $stmt->execute([$halaqohId, $ustadzId]);

    return $stmt->fetchAll();
}

function attendanceFetchActiveMemberIds(PDO $pdo, $halaqohId, $ustadzId, $forUpdate = false)
{
    $sql = "SELECT DISTINCT hm.wali_santri_id
            FROM halaqoh h
            JOIN halaqoh_members hm ON hm.halaqoh_id = h.id
            JOIN wali_santri w ON w.id = hm.wali_santri_id
            WHERE h.id = ? AND h.ustadz_id = ?
              AND hm.archived_at IS NULL
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

function attendanceNormalizePayload($data, $allowReset = false)
{
    if (!is_array($data)) {
        throw new AttendanceValidationException('Data peserta presensi tidak valid.');
    }

    $intent = strtolower(trim((string) ($data['intent'] ?? 'save')));
    $status = strtoupper(trim((string) ($data['status'] ?? 'A')));
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

/**
 * Saves one or more attendance payloads atomically.
 *
 * A row lock on the owned halaqoh serializes autosaves and full submissions
 * from this application. Existing legacy duplicates are updated together, but
 * are never removed by this routine.
 */
function attendanceSaveEntries(PDO $pdo, $halaqohId, $ustadzId, $tanggal, $entries, $requireCompleteRoster = false)
{
    if (!is_array($entries) || $entries === []) {
        throw new AttendanceValidationException('Tidak ada data presensi untuk disimpan.');
    }

    $normalizedEntries = [];
    foreach ($entries as $waliId => $data) {
        $waliId = filter_var($waliId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($waliId === false) {
            throw new AttendanceValidationException('Data peserta presensi tidak valid.');
        }
        $normalizedEntries[(int) $waliId] = attendanceNormalizePayload($data, true);
    }

    $pdo->beginTransaction();
    try {
        // The lock makes the read-then-insert path safe against concurrent
        // autosave/finalization requests for the same halaqoh.
        $lock = $pdo->prepare('SELECT id FROM halaqoh WHERE id = ? AND ustadz_id = ? FOR UPDATE');
        $lock->execute([$halaqohId, $ustadzId]);
        if (!$lock->fetch()) {
            throw new AttendanceValidationException('Halaqoh tidak ditemukan atau bukan milik Anda.');
        }

        $activeIds = attendanceFetchActiveMemberIds($pdo, $halaqohId, $ustadzId, true);
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
            WHERE halaqoh_id = :halaqoh_id AND wali_santri_id = :wali_santri_id AND tanggal = :tanggal");
        $insert = $pdo->prepare("INSERT INTO presensi
            (halaqoh_id, wali_santri_id, tanggal, status, alasan, jenis_materi, jilid, nama_surat, halaman, hasil_talaqqi)
            VALUES (:halaqoh_id, :wali_santri_id, :tanggal, :status, :alasan, :jenis_materi, :jilid, :nama_surat, :halaman, :hasil_talaqqi)");
        $delete = $pdo->prepare('DELETE FROM presensi WHERE halaqoh_id = ? AND wali_santri_id = ? AND tanggal = ?');
        $exists = $pdo->prepare('SELECT 1 FROM presensi WHERE halaqoh_id = ? AND wali_santri_id = ? AND tanggal = ? LIMIT 1');

        $savedCount = 0;
        $deletedCount = 0;
        foreach ($normalizedEntries as $waliId => $entry) {
            if ($entry['intent'] === 'reset') {
                $delete->execute([$halaqohId, $waliId, $tanggal]);
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
            $exists->execute([$halaqohId, $waliId, $tanggal]);
            if ($exists->fetchColumn()) {
                $update->execute($params);
            } else {
                $insert->execute($params);
            }
            $savedCount++;
        }

        $pdo->commit();
        return ['saved' => $savedCount, 'deleted' => $deletedCount];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
