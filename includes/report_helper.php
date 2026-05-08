<?php

function getReportFilters()
{
    return [
        'start_date' => $_GET['start_date'] ?? date('Y-m-01'),
        'end_date' => $_GET['end_date'] ?? date('Y-m-d'),
        'halaqoh_id' => $_GET['halaqoh_id'] ?? '',
        'wali_santri_id' => $_GET['wali_santri_id'] ?? '',
        'kelas' => $_GET['kelas'] ?? '',
        'search' => $_GET['search'] ?? '',
    ];
}

function buildPresensiReportQuery($filters, $role = '', $user_id = null, $options = [])
{
    $includePhone = !empty($options['include_phone']);
    $includeUstadz = !empty($options['include_ustadz']);

    $columns = [
        'p.id',
        'p.tanggal',
        'p.halaqoh_id',
        'p.wali_santri_id',
        'p.status',
        'p.jenis_materi',
        'p.jilid',
        'p.nama_surat',
        'p.halaman',
        'p.hasil_talaqqi',
        'p.alasan',
        'w.nama_bapak',
        'h.nama_halaqoh',
    ];

    if ($includePhone) {
        $columns[] = 'w.no_hp';
    }

    if ($includeUstadz) {
        $columns[] = 'u.nama_lengkap AS nama_ustadz';
    }

    $sql = 'SELECT ' . implode(', ', $columns) . '
        FROM presensi p
        JOIN wali_santri w ON p.wali_santri_id = w.id
        JOIN halaqoh h ON p.halaqoh_id = h.id';

    if ($includeUstadz) {
        $sql .= ' JOIN users u ON h.ustadz_id = u.id';
    }

    $sql .= ' WHERE p.tanggal BETWEEN :start AND :end';
    $params = [
        ':start' => $filters['start_date'],
        ':end' => $filters['end_date'],
    ];

    if ($role === 'ustadz') {
        $sql .= ' AND h.ustadz_id = :u_id';
        $params[':u_id'] = $user_id;
    }

    if (!empty($filters['halaqoh_id'])) {
        $sql .= ' AND p.halaqoh_id = :h_id';
        $params[':h_id'] = $filters['halaqoh_id'];
    }

    if (!empty($filters['wali_santri_id'])) {
        $sql .= ' AND p.wali_santri_id = :w_id';
        $params[':w_id'] = $filters['wali_santri_id'];
    }

    if (!empty($filters['kelas'])) {
        $sql .= ' AND EXISTS (
            SELECT 1
            FROM santri_detail sd_kelas
            WHERE sd_kelas.wali_santri_id = w.id
            AND sd_kelas.kelas = :kls
        )';
        $params[':kls'] = $filters['kelas'];
    }

    if (!empty($filters['search'])) {
        $sql .= ' AND (
            w.nama_bapak LIKE :q1
            OR EXISTS (
                SELECT 1
                FROM santri_detail sd_search
                WHERE sd_search.wali_santri_id = w.id
                AND sd_search.nama_anak LIKE :q2
            )
        )';
        $params[':q1'] = '%' . $filters['search'] . '%';
        $params[':q2'] = '%' . $filters['search'] . '%';
    }

    $sql .= ' ORDER BY p.tanggal DESC, h.nama_halaqoh, w.nama_bapak';

    return [$sql, $params];
}

function fetchPresensiReport($pdo, $filters, $role = '', $user_id = null, $options = [])
{
    [$sql, $params] = buildPresensiReportQuery($filters, $role, $user_id, $options);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
