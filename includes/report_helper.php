<?php

function getReportFilters()
{
    $defaultStart = date('Y-m-01');
    $defaultEnd = date('Y-m-d');

    return [
        'start_date' => normalizeDateInput($_GET['start_date'] ?? $defaultStart, $defaultStart),
        'end_date' => normalizeDateInput($_GET['end_date'] ?? $defaultEnd, $defaultEnd),
        'halaqoh_id' => filterPositiveInt($_GET['halaqoh_id'] ?? ''),
        'wali_santri_id' => filterPositiveInt($_GET['wali_santri_id'] ?? ''),
        'kelas' => trim(substr((string) ($_GET['kelas'] ?? ''), 0, 50)),
        'search' => trim(substr((string) ($_GET['search'] ?? ''), 0, 100)),
    ];
}

function filterPositiveInt($value)
{
    $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $value === false ? '' : (int) $value;
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

    if (!empty($options['paginate'])) {
        $page = max(1, (int) ($options['page'] ?? 1));
        $limit = min(100, max(1, (int) ($options['limit'] ?? 50)));
        $params[':report_limit'] = $limit;
        $params[':report_offset'] = ($page - 1) * $limit;
        $sql .= ' LIMIT :report_limit OFFSET :report_offset';
    }

    return [$sql, $params];
}

function fetchPresensiReport($pdo, $filters, $role = '', $user_id = null, $options = [])
{
    [$sql, $params] = buildPresensiReportQuery($filters, $role, $user_id, $options);
    $stmt = $pdo->prepare($sql);
    foreach ($params as $name => $value) {
        $type = in_array($name, [':report_limit', ':report_offset'], true) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($name, $value, $type);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

function countPresensiReport($pdo, $filters, $role = '', $user_id = null)
{
    [$sql, $params] = buildPresensiReportQuery($filters, $role, $user_id);
    $sql = preg_replace('/\s+ORDER BY\s+.*$/si', '', $sql);
    $countSql = 'SELECT COUNT(*) FROM (' . $sql . ') AS report_count';
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}
