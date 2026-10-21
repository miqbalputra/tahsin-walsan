<?php
require_once __DIR__ . '/tahsin_report_helper.php';

/** Current-week operational check, independent of historical-report readiness. */
function tahsinReminderOverview(array $dataset, DateTimeImmutable $now, bool $allPending = false): array
{
    $session = tahsinSessionDate($now->format('Y-m-d'));
    if (!tahsinSessionActive($session, $dataset['holidays'])) {
        return ['status' => 'skip', 'reason' => (int) substr($session, 8, 2) <= 7
            ? 'Ahad pekan pertama libur; tidak ada kewajiban presensi pekan ini.'
            : 'Sesi Ahad pekan ini tercatat libur.', 'tanggal_tahsin' => $session];
    }
    if ($now < new DateTimeImmutable($session . ' 07:30:00', new DateTimeZone('Asia/Jakarta'))) {
        return ['status' => 'skip', 'reason' => 'Sesi tahsin masih berlangsung sampai sekitar 07.30 WIB.', 'tanggal_tahsin' => $session];
    }
    $start = $session;
    if ($allPending && !empty($dataset['tracked_from'])) $start = min(substr($dataset['tracked_from'], 0, 10), $session);
    $filters = tahsinFilters(['start_date' => $start, 'end_date' => $now->format('Y-m-d')], $now);
    $report = tahsinAssembleReport($dataset, $filters, 'admin', 0, $now, $session);
    $groups = tahsinPendingFromReport($report, $now, $session);
    $pending = array_values(array_filter($groups, static fn($g) => $g['belum_diisi'] > 0));
    $current = array_values(array_filter($groups, static fn($g) => $g['tanggal_tahsin'] === $session));
    $currentRosterGroups = count(array_filter($current, static fn($g) => $g['roster_basis'] === 'current_roster'));
    $statusCounts = ['H' => 0, 'S' => 0, 'I' => 0, 'A' => 0];
    $rawStatusCounts = $statusCounts;
    foreach ($current as $group) foreach ($statusCounts as $status => $_) $statusCounts[$status] += $group['attendance_counts'][$status];
    foreach ($dataset['records'] as $record) {
        if ($record['tanggal'] >= $session && $record['tanggal'] <= $now->format('Y-m-d') && isset($rawStatusCounts[$record['status']])) $rawStatusCounts[$record['status']]++;
    }
    $checked = array_sum(array_column($current, 'total_anggota'));
    return ['status' => 'success', 'tanggal_tahsin' => $session, 'count' => count($pending), 'data' => $pending,
        'inspection_complete' => $checked > 0,
        'summary' => [
            'checked_group_sessions' => count($groups), 'current_group_count' => count($current),
            'checked_participant_sessions' => $checked,
            'complete_participant_sessions' => array_sum(array_column($current, 'complete_input')),
            'pending_teachers' => count(array_unique(array_filter(array_column($pending, 'ustadz_id')))),
            'pending_participant_sessions' => array_sum(array_column($pending, 'belum_diisi')),
            'missing_presensi' => array_sum(array_column($pending, 'missing_presensi')),
            'missing_capaian' => array_sum(array_column($pending, 'missing_capaian')),
            'missing_keterangan' => array_sum(array_column($pending, 'missing_keterangan')),
            'conflicts' => array_sum(array_column($pending, 'conflicts')),
            'attendance_counts' => $statusCounts,
            'raw_status_counts' => $rawStatusCounts,
            'roster_basis' => $currentRosterGroups ? 'current_roster' : ($current ? 'session_history' : 'unavailable'),
            'history_ready' => !empty($dataset['schema_ready']), 'tracked_from' => $dataset['tracked_from'],
            'backlog_requested' => $allPending,
        ],
        'warnings' => $currentRosterGroups ? ['Pekan berjalan diperiksa memakai roster aktif yang juga dipakai formulir input. Histori sesi belum tersedia; kekurangan ini tidak digunakan untuk menebak kewajiban pekan lama.'] : [],
    ];
}
