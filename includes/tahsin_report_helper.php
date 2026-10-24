<?php
require_once __DIR__ . '/family_helper.php';
/**
 * Weekly attendance is calculated here, never by treating an absent row as A.
 * The pure assembler is shared by HTML, exports, dashboards and integrations.
 */
function tahsinDate(string $value): ?string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Jakarta'));
    $errors = DateTimeImmutable::getLastErrors();
    return $date && (!$errors || (!$errors['warning_count'] && !$errors['error_count'])) && $date->format('Y-m-d') === $value ? $value : null;
}

function tahsinNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
}

// Production's PHP 8.2 image does not require mbstring.
function tahsinTextLimit(string $value, int $length): string
{
    if (function_exists('mb_substr')) return mb_substr($value, 0, $length, 'UTF-8');
    if (preg_match_all('/./us', $value, $characters) === false) throw new InvalidArgumentException('Teks harus menggunakan UTF-8 yang valid.');
    return implode('', array_slice($characters[0], 0, $length));
}

function tahsinTextContains(string $value, string $search): bool
{
    return function_exists('mb_stripos') ? mb_stripos($value, $search, 0, 'UTF-8') !== false : preg_match('/' . preg_quote($search, '/') . '/iu', $value) === 1;
}

function tahsinSessionDate(string $date): string
{
    if (!tahsinDate($date)) {
        throw new InvalidArgumentException('Tanggal sesi tidak valid.');
    }
    $day = new DateTimeImmutable($date, new DateTimeZone('Asia/Jakarta'));
    return $day->modify('-' . $day->format('w') . ' days')->format('Y-m-d');
}

function tahsinSessionActive(string $session, array $holidays = []): bool
{
    return tahsinDate($session) !== null && tahsinSessionDate($session) === $session
        && (int) substr($session, 8, 2) > 7 && !array_key_exists($session, $holidays);
}

function tahsinSessions(string $start, string $end, array $holidays = []): array
{
    $day = new DateTimeImmutable(tahsinSessionDate($start), new DateTimeZone('Asia/Jakarta'));
    if ($day->format('Y-m-d') < $start) {
        $day = $day->modify('+7 days');
    }
    $sessions = [];
    while ($day->format('Y-m-d') <= $end) {
        $key = $day->format('Y-m-d');
        if (tahsinSessionActive($key, $holidays)) {
            $sessions[] = $key;
        }
        $day = $day->modify('+7 days');
    }
    return $sessions;
}

function tahsinDeadline(string $session): string
{
    return (new DateTimeImmutable($session, new DateTimeZone('Asia/Jakarta')))->modify('+1 day')->format('Y-m-d') . ' 10:00:00';
}

function tahsinLatestSession(string $today, array $holidays = []): ?string
{
    $key = tahsinSessionDate($today);
    for ($i = 0; $i < 520; $i++) {
        if (tahsinSessionActive($key, $holidays)) {
            return $key;
        }
        $key = (new DateTimeImmutable($key))->modify('-7 days')->format('Y-m-d');
    }
    return null;
}

function tahsinFilters(array $query = [], ?DateTimeImmutable $now = null): array
{
    $now = $now ?? tahsinNow();
    $start = tahsinDate((string) ($query['start_date'] ?? $query['start'] ?? '')) ?? $now->format('Y-m-01');
    $end = tahsinDate((string) ($query['end_date'] ?? $query['end'] ?? '')) ?? $now->format('Y-m-d');
    if ($start > $end) {
        throw new InvalidArgumentException('Tanggal awal harus sebelum atau sama dengan tanggal akhir.');
    }
    if ((new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days > 3660) {
        throw new InvalidArgumentException('Pilih periode maksimal sepuluh tahun.');
    }
    $filters = ['start_date' => $start, 'end_date' => min($end, $now->format('Y-m-d'))];
    foreach (['halaqoh_id', 'ustadz_id', 'wali_santri_id'] as $key) {
        $filters[$key] = max(0, (int) ($query[$key] ?? 0));
    }
    foreach (['kelas', 'search', 'kategori', 'aktif', 'kelengkapan', 'risk'] as $key) {
        $filters[$key] = tahsinTextLimit(trim((string) ($query[$key] ?? '')), 100);
    }
    return $filters;
}

function tahsinSchemaReady(PDO $pdo): bool
{
    try {
        foreach (['tahsin_tracking', 'tahsin_roster_history', 'tahsin_verifications', 'tahsin_capaian_awal'] as $table) {
            $pdo->query("SELECT 1 FROM {$table} WHERE 1 = 0");
        }
        if (!$pdo->query('SELECT tracked_from FROM tahsin_tracking WHERE id = 1 AND schema_version = 1')->fetchColumn()) return false;
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $names = ['tahsin_hm_ai', 'tahsin_hm_au', 'tahsin_hm_ad', 'tahsin_wali_au', 'tahsin_wali_bd', 'tahsin_halaqoh_au', 'tahsin_halaqoh_bd'];
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name IN (' . implode(',', array_fill(0, count($names), '?')) . ')');
            $stmt->execute($names);
            if ((int) $stmt->fetchColumn() !== count($names)) return false;
        }
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function tahsinHolidayMap(PDO $pdo): array
{
    try {
        $map = [];
        foreach ($pdo->query('SELECT tanggal, keterangan FROM holidays')->fetchAll(PDO::FETCH_ASSOC) as $row) $map[$row['tanggal']] = (string) ($row['keterangan'] ?? 'Libur Kajian');
        return $map;
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') return [];
        throw $e;
    }
}

function tahsinSuspectIds(PDO $pdo, array $records): array
{
    if (!$records) return [];
    try {
        $dates = array_column($records, 'tanggal');
        $stmt = $pdo->prepare("SELECT description, created_at FROM activity_logs WHERE action = 'INIT_CAPAIAN' AND created_at BETWEEN ? AND ?");
        $stmt->execute([min($dates) . ' 00:00:00', max($dates) . ' 23:59:59']);
        $suspects = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $log) {
            if (!preg_match('/Wali ID:\s*(\d+)/', $log['description'], $match)) continue;
            foreach ($records as $record) {
                if ((int) $record['wali_santri_id'] === (int) $match[1] && $record['status'] === 'H'
                    && $record['tanggal'] === substr($log['created_at'], 0, 10) && !empty($record['created_at'])
                    && abs(strtotime($record['created_at']) - strtotime($log['created_at'])) <= 30) $suspects[(int) $record['id']] = true;
            }
        }
        return $suspects;
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') return [];
        throw $e;
    }
}

function tahsinLoadDataset(PDO $pdo, string $endDate, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? tahsinNow();
    $end = min((new DateTimeImmutable(tahsinSessionDate($endDate)))->modify('+6 days')->format('Y-m-d'), $now->format('Y-m-d'));
    $data = ['wali' => $pdo->query('SELECT * FROM wali_santri ORDER BY nama_bapak, id')->fetchAll(PDO::FETCH_ASSOC),
        'children' => $pdo->query('SELECT wali_santri_id, nama_anak, kelas FROM ' . familyChildrenTable($pdo) . ' ORDER BY nama_anak')->fetchAll(PDO::FETCH_ASSOC),
        'halaqoh' => $pdo->query('SELECT h.*, u.nama_lengkap AS nama_ustadz, u.no_hp AS ustadz_phone FROM halaqoh h LEFT JOIN users u ON u.id = h.ustadz_id ORDER BY h.nama_halaqoh')->fetchAll(PDO::FETCH_ASSOC),
        'records' => [], 'roster' => [], 'history' => [], 'verifications' => [], 'baselines' => [],
        'holidays' => [], 'suspect_ids' => [], 'tracked_from' => null, 'schema_ready' => tahsinSchemaReady($pdo)];
    $stmt = $pdo->prepare('SELECT * FROM presensi WHERE tanggal <= ? ORDER BY tanggal, id');
    $stmt->execute([$end]);
    $data['records'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Compatibility for installations awaiting the alumni migration.
    try {
        $pdo->query('SELECT archived_at FROM halaqoh_members WHERE 1 = 0');
        $archive = ' AND hm.archived_at IS NULL';
    } catch (PDOException $e) {
        $archive = '';
    }
    $data['roster'] = $pdo->query("SELECT DISTINCT hm.wali_santri_id, hm.halaqoh_id, h.ustadz_id, w.kategori,
        h.nama_halaqoh, u.nama_lengkap AS nama_ustadz, u.no_hp AS ustadz_phone
        FROM halaqoh_members hm JOIN wali_santri w ON w.id = hm.wali_santri_id
        JOIN halaqoh h ON h.id = hm.halaqoh_id LEFT JOIN users u ON u.id = h.ustadz_id
        WHERE w.status_aktif = 1 AND w.kategori IN ('reguler', 'askar'){$archive}")->fetchAll(PDO::FETCH_ASSOC);
    $data['holidays'] = tahsinHolidayMap($pdo);
    if ($data['schema_ready']) {
        $data['tracked_from'] = $pdo->query('SELECT tracked_from FROM tahsin_tracking WHERE id = 1')->fetchColumn() ?: null;
        $data['history'] = $pdo->query("SELECT rh.*, COALESCE(u.no_hp, '') AS ustadz_phone FROM tahsin_roster_history rh LEFT JOIN users u ON u.id = rh.ustadz_id ORDER BY rh.id")->fetchAll(PDO::FETCH_ASSOC);
        $data['verifications'] = $pdo->query('SELECT * FROM tahsin_verifications ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $data['baselines'] = $pdo->query('SELECT * FROM tahsin_capaian_awal')->fetchAll(PDO::FETCH_ASSOC);
    }
    $data['suspect_ids'] = tahsinSuspectIds($pdo, $data['records']);
    return $data;
}

function tahsinRecordPayload(array $record): array
{
    $status = (string) ($record['status'] ?? '');
    $payload = ['status' => $status, 'alasan' => in_array($status, ['S', 'I'], true) ? trim((string) ($record['alasan'] ?? '')) : ''];
    foreach (['jenis_materi', 'jilid', 'nama_surat', 'halaman', 'hasil_talaqqi'] as $key) {
        $payload[$key] = $status === 'H' ? trim((string) ($record[$key] ?? '')) : '';
    }
    if ($payload['jenis_materi'] === 'Iqro') {
        $payload['nama_surat'] = '';
        $payload['jilid'] = (string) (int) $payload['jilid'];
    } elseif ($payload['jenis_materi'] === 'Al Quran') {
        $payload['jilid'] = '';
    }
    return $payload;
}

function tahsinMissingFields(array $record): array
{
    $p = tahsinRecordPayload($record);
    if (!in_array($p['status'], ['H', 'S', 'I', 'A'], true)) {
        return ['status kehadiran'];
    }
    if (in_array($p['status'], ['S', 'I'], true)) {
        return $p['alasan'] === '' ? ['alasan/keterangan'] : [];
    }
    if ($p['status'] !== 'H') {
        return [];
    }
    $missing = [];
    if (!in_array($p['jenis_materi'], ['Iqro', 'Al Quran'], true)) {
        $missing[] = 'jenis materi';
    }
    if ($p['jenis_materi'] === 'Iqro' && ((int) $p['jilid'] < 1 || (int) $p['jilid'] > 6)) {
        $missing[] = 'jilid Iqro';
    }
    if ($p['jenis_materi'] === 'Al Quran' && $p['nama_surat'] === '') {
        $missing[] = 'surat';
    }
    if ($p['halaman'] === '') {
        $missing[] = 'halaman/ayat';
    }
    if (!in_array($p['hasil_talaqqi'], ['Lulus', 'Ulang'], true)) {
        $missing[] = 'hasil talaqqi';
    }
    return $missing;
}

function tahsinFingerprint(array $records): string
{
    usort($records, static fn($a, $b) => (int) $a['id'] <=> (int) $b['id']);
    return hash('sha256', json_encode(array_map(static fn($r) => [
        'id' => (int) $r['id'], 'wali' => (int) $r['wali_santri_id'], 'halaqoh' => $r['halaqoh_id'],
        'tanggal' => $r['tanggal'], 'payload' => tahsinRecordPayload($r), 'created_at' => $r['created_at'] ?? null,
    ], $records), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function tahsinResolveSession(array $records, ?array $verification = null, array $suspectIds = [], array $holidays = []): array
{
    $fingerprint = tahsinFingerprint($records);
    $result = ['records' => $records, 'fingerprint' => $fingerprint, 'record' => null,
        'state' => 'missing', 'complete' => false, 'missing_fields' => ['presensi dan capaian'], 'verification_stale' => false];
    if (!$records) {
        return $result;
    }
    $eligible = array_values(array_filter($records, static fn($r) => !array_key_exists($r['tanggal'], $holidays)));
    if ($verification && hash_equals($fingerprint, (string) $verification['fingerprint'])) {
        if (empty($verification['selected_presensi_id'])) {
            return array_replace($result, ['state' => 'baseline', 'missing_fields' => ['catatan ini hanya capaian awal']]);
        }
        foreach ($records as $record) {
            if ((int) $record['id'] === (int) $verification['selected_presensi_id']) {
                if (array_key_exists($record['tanggal'], $holidays)) return array_replace($result, ['state' => 'invalid_date', 'missing_fields' => ['tanggal pelaksanaan jatuh pada libur kajian; isi catatan pada tanggal pelaksanaan yang benar']]);
                $result['record'] = $record;
                break;
            }
        }
        if (!$result['record']) {
            $result['verification_stale'] = true;
        }
    } else {
        $result['verification_stale'] = $verification !== null;
    }
    if (!$result['record']) {
        if (!$eligible) return array_replace($result, ['state' => 'invalid_date', 'missing_fields' => ['tanggal pelaksanaan jatuh pada libur kajian; isi catatan pada tanggal pelaksanaan yang benar']]);
        $payloads = [];
        $suspect = false;
        foreach ($eligible as $record) {
            $payloads[json_encode(tahsinRecordPayload($record), JSON_UNESCAPED_UNICODE)] = true;
            $suspect = $suspect || isset($suspectIds[(int) $record['id']]);
        }
        if (count($payloads) > 1 || $suspect || $result['verification_stale']) {
            return array_replace($result, ['state' => 'conflict', 'missing_fields' => [$suspect ? 'verifikasi dugaan capaian awal' : 'verifikasi catatan berbeda/keputusan kedaluwarsa']]);
        }
        usort($eligible, static fn($a, $b) => [$b['tanggal'], (int) $b['id']] <=> [$a['tanggal'], (int) $a['id']]);
        $result['record'] = $eligible[0];
    }
    $result['missing_fields'] = tahsinMissingFields($result['record']);
    $result['complete'] = $result['missing_fields'] === [];
    $result['state'] = $result['complete'] ? 'complete' : ($result['record']['status'] === 'H' ? 'capaian' : 'presensi_incomplete');
    return $result;
}

function tahsinMaterial(?array $record): string
{
    if (!$record || empty($record['jenis_materi'])) {
        return '—';
    }
    return trim($record['jenis_materi'] . ' ' . ($record['jenis_materi'] === 'Iqro' ? 'Jilid ' . ($record['jilid'] ?? '') : ($record['nama_surat'] ?? ''))
        . ' ' . ($record['jenis_materi'] === 'Iqro' ? 'Hal. ' : 'Ayat ') . ($record['halaman'] ?? ''));
}

function tahsinOwnerMatches(array $owner, array $filters, string $role, int $userId): bool
{
    return ($role !== 'ustadz' || (int) ($owner['ustadz_id'] ?? 0) === $userId)
        && (!$filters['halaqoh_id'] || (int) ($owner['halaqoh_id'] ?? 0) === $filters['halaqoh_id'])
        && (!$filters['ustadz_id'] || (int) ($owner['ustadz_id'] ?? 0) === $filters['ustadz_id']);
}

function tahsinEmptyTotals(): array
{
    return ['hadir' => 0, 'sakit' => 0, 'izin' => 0, 'alpha' => 0, 'lulus' => 0, 'ulang' => 0,
        'total_presensi' => 0, 'expected' => 0, 'complete_input' => 0, 'missing_presensi' => 0,
        'missing_capaian' => 0, 'missing_keterangan' => 0, 'conflicts' => 0, 'unverified' => 0];
}

function tahsinAddCell(array &$totals, array $cell): void
{
    $statusMap = ['H' => 'hadir', 'S' => 'sakit', 'I' => 'izin', 'A' => 'alpha'];
    if ($cell['in_schedule'] && $cell['record'] && isset($statusMap[$cell['record']['status']])) {
        $totals[$statusMap[$cell['record']['status']]]++;
        $totals['total_presensi']++;
        if ($cell['record']['status'] === 'H' && $cell['complete']) {
            $totals['lulus'] += ($cell['record']['hasil_talaqqi'] ?? '') === 'Lulus' ? 1 : 0;
            $totals['ulang'] += ($cell['record']['hasil_talaqqi'] ?? '') === 'Ulang' ? 1 : 0;
        }
    }
    if ($cell['expected']) {
        $totals['expected']++;
        if ($cell['complete'] && $cell['in_schedule']) {
            $totals['complete_input']++;
        } elseif (in_array($cell['state'], ['missing', 'baseline'], true)) {
            $totals['missing_presensi']++;
        }
    }
    // A recorded H or S/I proves that these fields are missing, even when
    // the historical roster is unknown. It does not prove anyone else absent.
    if ($cell['in_schedule'] && $cell['state'] === 'capaian') $totals['missing_capaian']++;
    if ($cell['in_schedule'] && $cell['state'] === 'presensi_incomplete') $totals['missing_keterangan']++;
    $totals['conflicts'] += $cell['in_schedule'] && in_array($cell['state'], ['conflict', 'invalid_date'], true) ? 1 : 0;
}

function tahsinFinalizeTotals(array $totals): array
{
    foreach (['hadir', 'sakit', 'izin', 'alpha'] as $status) {
        $totals[$status . '_percent'] = $totals['total_presensi'] ? round(100 * $totals[$status] / $totals['total_presensi'], 1) : null;
    }
    $totals['coverage_percent'] = !$totals['unverified'] && $totals['expected'] ? round(100 * $totals['complete_input'] / $totals['expected'], 1) : null;
    $totals['provisional'] = $totals['unverified'] > 0 || $totals['conflicts'] > 0 || $totals['missing_capaian'] > 0 || $totals['missing_keterangan'] > 0 || $totals['complete_input'] < $totals['expected'];
    return $totals;
}

function tahsinQuality(array $row): array
{
    if ($row['conflicts']) return ['conflict', 'Perlu Verifikasi'];
    if ($row['missing_capaian']) return ['capaian', 'Capaian Belum Lengkap'];
    if ($row['missing_presensi'] || $row['missing_keterangan']) return [$row['total_presensi'] ? 'partial' : 'missing', $row['total_presensi'] ? 'Sebagian Diinput' : 'Belum Diinput'];
    if ($row['unverified']) return ['unverified', 'Belum Terverifikasi'];
    if ($row['total_presensi'] || $row['expected']) return ['complete', 'Lengkap'];
    if (empty($row['status_aktif'])) return ['archived', 'Arsip; Tidak Ada Data dalam Periode'];
    if ($row['kategori'] === 'tahsin_luar') return ['external', 'Data Tahsin Luar Tidak Tersedia'];
    if (empty($row['halaqoh_id'])) return ['unassigned', 'Belum Punya Halaqoh'];
    return ['no_data', 'Tidak Ada Sesi dalam Periode'];
}

/** No database writes. Future obligations are never generated. */
function tahsinAssembleReport(array $data, array $filters, string $role = 'admin', int $userId = 0, ?DateTimeImmutable $now = null, ?string $liveSession = null): array
{
    $now = $now ?? tahsinNow();
    $end = min($filters['end_date'], $now->format('Y-m-d'));
    $sessions = $filters['start_date'] <= $end ? tahsinSessions($filters['start_date'], $end, $data['holidays']) : [];
    $groups = [];
    foreach ($data['halaqoh'] as $group) {
        $group['halaqoh_id'] = (int) $group['id'];
        $groups[(int) $group['id']] = $group;
    }
    $children = $current = $history = $records = $decisions = $baselines = [];
    foreach ($data['children'] as $child) $children[(int) $child['wali_santri_id']][] = $child;
    foreach ($data['roster'] as $member) $current[(int) $member['wali_santri_id']][] = $member;
    foreach ($data['history'] as $member) $history[(int) $member['wali_santri_id']][] = $member;
    foreach ($data['records'] as $record) {
        $key = tahsinSessionDate($record['tanggal']);
        if ($key <= $end && $record['tanggal'] <= $now->format('Y-m-d')) $records[(int) $record['wali_santri_id']][$key][] = $record;
    }
    foreach ($data['verifications'] as $v) $decisions[(int) $v['wali_santri_id']][$v['session_date']] = $v;
    foreach ($data['baselines'] as $b) $baselines[(int) $b['wali_santri_id']] = $b;
    $rows = [];
    foreach ($data['wali'] as $wali) {
        $id = (int) $wali['id'];
        if ($filters['wali_santri_id'] && $id !== $filters['wali_santri_id']) continue;
        if ($filters['kategori'] && $wali['kategori'] !== $filters['kategori']) continue;
        if ($filters['aktif'] !== '' && (string) (int) $wali['status_aktif'] !== $filters['aktif']) continue;
        $kids = $children[$id] ?? [];
        if ($filters['kelas'] && !in_array($filters['kelas'], array_column($kids, 'kelas'), true)) continue;
        $matchingCurrent = array_values(array_filter($current[$id] ?? [], static fn($o) => tahsinOwnerMatches($o, $filters, $role, $userId)));
        $currentOwner = $matchingCurrent[0] ?? $current[$id][0] ?? [];
        $childText = implode(', ', array_map(static fn($c) => $c['nama_anak'] . ($c['kelas'] ? ' [' . $c['kelas'] . ']' : ''), $kids));
        $haystack = $wali['nama_bapak'] . ' ' . $childText . ' ' . implode(' ', array_column($current[$id] ?? [], 'nama_halaqoh')) . ' ' . implode(' ', array_column($history[$id] ?? [], 'nama_halaqoh'));
        if ($filters['search'] && !tahsinTextContains($haystack, $filters['search'])) continue;
        $row = array_merge($wali, tahsinEmptyTotals(), ['id' => $id, 'anak' => $childText, 'children' => $kids,
            'halaqoh_id' => $currentOwner['halaqoh_id'] ?? null, 'ustadz_id' => $currentOwner['ustadz_id'] ?? null,
            'nama_halaqoh' => $currentOwner['nama_halaqoh'] ?? '—', 'nama_ustadz' => $currentOwner['nama_ustadz'] ?? '—',
            'current_halaqoh_id' => $currentOwner['halaqoh_id'] ?? null,
            'details' => [], 'terakhir_hadir' => null, 'materi_terakhir' => '—', 'materi_record' => null, 'baseline_record' => null]);
        $relevant = tahsinOwnerMatches($currentOwner, $filters, $role, $userId);
        $allKeys = array_unique(array_merge($sessions, array_keys($records[$id] ?? [])));
        sort($allKeys);
        foreach ($allKeys as $session) {
            $inPeriod = $session >= $filters['start_date'] && $session <= $end;
            $inSchedule = tahsinSessionActive($session, $data['holidays']);
            $boundary = $session . ' 00:00:00.000000';
            $known = $data['tracked_from'] && $boundary >= $data['tracked_from'];
            $owners = [];
            if ($known) {
                foreach ($history[$id] ?? [] as $member) {
                    if ($member['valid_from'] <= $boundary && (!$member['valid_until'] || $member['valid_until'] > $boundary)) {
                        $owners[$member['halaqoh_id'] . ':' . $member['ustadz_id']] = $member;
                    }
                }
            } elseif ($session === $liveSession) {
                foreach ($current[$id] ?? [] as $member) $owners[$member['halaqoh_id'] . ':' . $member['ustadz_id']] = $member;
            }
            $raw = $records[$id][$session] ?? [];
            if (!$owners && !$raw) continue;
            $cell = tahsinResolveSession($raw, $decisions[$id][$session] ?? null, $data['suspect_ids'], $data['holidays']);
            foreach ($cell['records'] as &$sourceRow) $sourceRow['on_holiday'] = array_key_exists($sourceRow['tanggal'], $data['holidays']);
            unset($sourceRow);
            $owner = $owners ? array_values($owners)[0] : [];
            if (count($owners) > 1) {
                $matchingOwners = array_values(array_filter($owners, static fn($o) => tahsinOwnerMatches($o, $filters, $role, $userId)));
                if ($matchingOwners) $owner = $matchingOwners[0];
            }
            if (count($owners) > 1 && $cell['record'] && isset($decisions[$id][$session]) && !$cell['verification_stale']) {
                $selectedOwners = array_filter($owners, static fn($o) => (int) $o['halaqoh_id'] === (int) $cell['record']['halaqoh_id']);
                if (count($selectedOwners) === 1) { $owners = $selectedOwners; $owner = array_values($owners)[0]; }
            }
            if (!$owner && $raw) {
                $recordGroup = $groups[(int) (($cell['record'] ?? $raw[0])['halaqoh_id'] ?? 0)] ?? [];
                $owner = $recordGroup;
            }
            $ownerMatches = tahsinOwnerMatches($owner, $filters, $role, $userId);
            if (count($owners) > 1) {
                $cell['state'] = 'conflict';
                $cell['record'] = null;
                $cell['complete'] = false;
                $cell['missing_fields'] = ['verifikasi penempatan ganda pada sesi ini'];
            }
            // Other teachers' source records are never exposed in their colleague's report.
            if ($role === 'ustadz') {
                $cell['records'] = array_values(array_filter($raw, static function ($r) use ($groups, $owners, $userId) {
                    if ((int) ($groups[(int) ($r['halaqoh_id'] ?? 0)]['ustadz_id'] ?? 0) === $userId) return true;
                    foreach ($owners as $o) if ((int) $o['ustadz_id'] === $userId && (int) $o['halaqoh_id'] === (int) $r['halaqoh_id']) return true;
                    return false;
                }));
                // Resolving the complete dataset may select a source from a
                // different group, even when this session's roster belongs to
                // the teacher. Do not expose that source through `record` or
                // the latest-material summary after filtering `records`.
                if ($cell['record'] && !in_array((int) $cell['record']['id'], array_map(static fn($r) => (int) $r['id'], $cell['records']), true)) {
                    $cell['record'] = null;
                    $cell['complete'] = false;
                    $cell['state'] = 'conflict';
                    $cell['missing_fields'] = ['verifikasi penempatan catatan di luar kelompok'];
                }
            }
            if ($inPeriod && $ownerMatches && ($owners || $raw)) $relevant = true;
            if (!$ownerMatches) continue;
            // A wali with multiple simultaneously recorded placements is still
            // one required participant-session; the group attribution remains
            // unresolved until the placement conflict is reviewed.
            $cell += ['session_date' => $session, 'owner' => $owner, 'expected' => $inSchedule && count($owners) >= 1,
                'in_schedule' => $inSchedule, 'verified_roster' => (bool) $known, 'deadline_at' => tahsinDeadline($session),
                'roster_source' => $known ? 'Histori roster pada sesi' : ($session === $liveSession ? 'Roster aktif saat ini; histori sesi belum terverifikasi' : 'Histori belum tersedia')];
            if ($inSchedule && $cell['record'] && $cell['record']['status'] === 'H') {
                if (!$row['terakhir_hadir'] || $cell['record']['tanggal'] > $row['terakhir_hadir']) $row['terakhir_hadir'] = $cell['record']['tanggal'];
                if ($cell['complete']) {
                    $row['materi_terakhir'] = tahsinMaterial($cell['record']);
                    $row['materi_record'] = $cell['record'];
                }
            }
            if ($inPeriod) {
                if (!empty($owner['halaqoh_id']) && ($cell['expected'] || $cell['records'])) {
                    foreach (['halaqoh_id', 'ustadz_id', 'nama_halaqoh', 'nama_ustadz'] as $field) $row[$field] = $owner[$field] ?? $row[$field];
                }
                tahsinAddCell($row, $cell);
                $row['details'][] = $cell;
            }
        }
        // Never invent historical missing attendance from today's roster.
        foreach ($sessions as $session) {
            if ((!$data['tracked_from'] || $session . ' 00:00:00.000000' < $data['tracked_from'])
                && ((!empty($current[$id]) && tahsinOwnerMatches($currentOwner, $filters, $role, $userId)) || $row['total_presensi'] || $row['conflicts'])) {
                $row['unverified'] = 1;
                break;
            }
        }
        if (!$relevant) continue;
        if ($row['materi_record'] === null && isset($baselines[$id]) && substr($baselines[$id]['updated_at'], 0, 10) <= $end) {
            $row['materi_terakhir'] = 'Capaian awal: ' . tahsinMaterial($baselines[$id]);
            $row['baseline_record'] = $baselines[$id];
        }
        $row = tahsinFinalizeTotals($row);
        [$row['quality'], $row['quality_label']] = tahsinQuality($row);
        $row['risk_level'] = $row['alpha'] >= 3 ? 'rawan' : ($row['alpha'] ? 'pantau' : ($row['total_presensi'] ? 'stabil' : 'belum_data'));
        $row['risk_label'] = ['rawan' => 'Butuh Follow-up (Alfa tercatat)', 'pantau' => 'Pantau (Alfa tercatat)', 'stabil' => 'Tanpa Alfa Tercatat', 'belum_data' => 'Belum Ada Data Kehadiran'][$row['risk_level']];
        if ($filters['kelengkapan'] && $row['quality'] !== $filters['kelengkapan']) continue;
        if ($filters['risk'] && $row['risk_level'] !== $filters['risk']) continue;
        $rows[] = $row;
    }
    $summary = tahsinEmptyTotals();
    $perHalaqoh = $perGuru = $trend = [];
    foreach ($sessions as $session) {
        $rosterVerified = $data['tracked_from'] && $session . ' 00:00:00.000000' >= $data['tracked_from'];
        $trend[$session] = ['tanggal' => $session, 'roster_verified' => (bool) $rosterVerified] + tahsinEmptyTotals();
    }
    $periodParticipantIds = [];
    foreach ($rows as $row) {
        foreach (array_keys($summary) as $key) $summary[$key] += $row[$key];
        foreach ($row['details'] as $cell) {
            if (!$cell['in_schedule']) continue;
            if ($cell['expected'] || !empty($cell['records'])) $periodParticipantIds[$row['id']] = true;
            $owner = $cell['owner'];
            $placementConflict = in_array('verifikasi penempatan ganda pada sesi ini', $cell['missing_fields'] ?? [], true);
            foreach (['halaqoh' => 'halaqoh_id', 'guru' => 'ustadz_id'] as $type => $key) {
                $target =& ${$type === 'halaqoh' ? 'perHalaqoh' : 'perGuru'};
                $groupOwner = $owner;
                if ($placementConflict) {
                    $groupOwner = $type === 'halaqoh'
                        ? ['halaqoh_id' => 0, 'ustadz_id' => 0, 'nama_halaqoh' => 'Perlu Verifikasi Penempatan', 'nama_ustadz' => 'Belum ditentukan']
                        : ['halaqoh_id' => 0, 'ustadz_id' => 0, 'nama_halaqoh' => 'Penempatan belum terverifikasi', 'nama_ustadz' => 'Perlu Verifikasi Penempatan'];
                }
                $ownerId = $placementConflict ? 0 : (int) ($groupOwner[$key] ?? 0);
                if (!isset($target[$ownerId])) $target[$ownerId] = $groupOwner + tahsinEmptyTotals() + ['member_ids' => []];
                $target[$ownerId]['member_ids'][$row['id']] = true;
                $target[$ownerId]['unverified'] = max($target[$ownerId]['unverified'], $row['unverified']);
                tahsinAddCell($target[$ownerId], $cell);
                unset($target);
            }
            if (isset($trend[$cell['session_date']])) tahsinAddCell($trend[$cell['session_date']], $cell);
        }
    }
    foreach ([$perHalaqoh, $perGuru] as $i => $list) {
        foreach ($list as &$group) {
            $group['total_anggota'] = count($group['member_ids']);
            unset($group['member_ids']);
            $group = tahsinFinalizeTotals($group);
        }
        unset($group);
        if ($i === 0) $perHalaqoh = $list; else $perGuru = $list;
    }
    $summary = tahsinFinalizeTotals($summary);
    $summary['peserta'] = count($rows);
    $summary['peserta_periode'] = count($periodParticipantIds);
    // One wali is counted once per Ahad session. `expected` only comes from
    // the historical roster (or the explicitly labeled live reminder roster).
    // Older sessions stay visibly unverified instead of guessing from today's roster.
    foreach ($trend as &$sessionRow) {
        $sessionRow['peserta_roster'] = $sessionRow['roster_verified'] ? $sessionRow['expected'] : null;
        $sessionRow['peserta_sesi'] = $sessionRow['expected'];
        if (!$sessionRow['roster_verified']) $sessionRow['unverified'] = 1;
        $sessionRow['provisional'] = !$sessionRow['roster_verified'] || $sessionRow['conflicts'] > 0
            || $sessionRow['missing_capaian'] > 0 || $sessionRow['missing_keterangan'] > 0
            || $sessionRow['complete_input'] < $sessionRow['expected'];
        $sessionRow = tahsinFinalizeTotals($sessionRow);
    }
    unset($sessionRow);
    $summary['peserta_sesi'] = $summary['expected'];
    $summary['total_halaqoh'] = count(array_filter(array_keys($perHalaqoh)));
    $summary['rawan'] = count(array_filter($rows, static fn($r) => $r['risk_level'] === 'rawan'));
    return ['rows' => $rows, 'summary' => $summary, 'halaqoh' => array_values($perHalaqoh), 'guru' => array_values($perGuru),
        'trend' => array_values($trend), 'sessions' => $sessions, 'filters' => $filters,
        'schema_ready' => $data['schema_ready'], 'tracked_from' => $data['tracked_from']];
}

function tahsinFetchReport(PDO $pdo, array $filters, string $role = 'admin', int $userId = 0, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? tahsinNow();
    return tahsinAssembleReport(tahsinLoadDataset($pdo, $filters['end_date'], $now), $filters, $role, $userId, $now);
}

function tahsinDashboardData(PDO $pdo, string $role, int $userId): array
{
    $now = tahsinNow();
    $data = tahsinLoadDataset($pdo, $now->format('Y-m-d'), $now);
    $filters = tahsinFilters(['start_date' => $now->modify('-30 days')->format('Y-m-d')], $now);
    $recent = tahsinAssembleReport($data, $filters, $role, $userId, $now);
    $filters['start_date'] = $data['records'] ? tahsinSessionDate($data['records'][0]['tanggal']) : $now->format('Y-m-01');
    $all = tahsinAssembleReport($data, $filters, $role, $userId, $now);
    $warnings = array_values(array_map(static function ($r) { $r['total_alpha'] = $r['alpha']; return $r; }, array_filter($all['rows'], static fn($r) => $r['alpha'] >= 3)));
    usort($warnings, static fn($a, $b) => $b['total_alpha'] <=> $a['total_alpha']);
    return ['recent' => $recent, 'warnings' => $warnings];
}

function tahsinPendingFromReport(array $report, DateTimeImmutable $now, ?string $currentReminderSession = null): array
{
    $groups = [];
    foreach ($report['rows'] as $row) {
        foreach ($row['details'] as $cell) {
            // Teacher reminders must be grounded in a roster captured for this
            // session. The latest active roster is useful context, but cannot
            // prove historical obligations before tracking began.
            // The weekly reminder can check the same current roster used by
            // the input form while history is unavailable. Never extend that
            // fallback to past sessions or change historical report totals.
            $currentRosterReminder = $currentReminderSession === tahsinSessionDate($now->format('Y-m-d'))
                && $cell['session_date'] === $currentReminderSession;
            if (!$cell['in_schedule'] || !$cell['expected'] || (empty($cell['verified_roster']) && !$currentRosterReminder)) continue;
            $owner = $cell['owner'];
            if (empty($owner['halaqoh_id'])) continue;
            $key = $cell['session_date'] . ':' . $owner['halaqoh_id'] . ':' . ($owner['ustadz_id'] ?? 0);
            if (!isset($groups[$key])) $groups[$key] = [
                'halaqoh_id' => (int) $owner['halaqoh_id'], 'nama_halaqoh' => $owner['nama_halaqoh'] ?? 'Halaqoh lama',
                'ustadz_id' => (int) ($owner['ustadz_id'] ?? 0), 'ustadz_name' => $owner['nama_ustadz'] ?? 'Guru belum ditentukan',
                'ustadz_phone' => $owner['ustadz_phone'] ?? '', 'tanggal_tahsin' => $cell['session_date'],
                'tanggal_tahsin_formatted' => 'Ahad, ' . date('d/m/Y', strtotime($cell['session_date'])),
                'total_anggota' => 0, 'sudah_diisi' => 0, 'complete_input' => 0, 'missing_presensi' => 0,
                'missing_capaian' => 0, 'missing_keterangan' => 0, 'conflicts' => 0, 'pending_participants' => [], 'deadline_at' => $cell['deadline_at'],
                'has_expected_roster' => false,
                'expected_count' => 0, 'expected_complete' => 0, 'expected_recorded' => 0,
                'roster_source' => $cell['roster_source'], 'data_verified' => $cell['verified_roster'],
                'roster_basis' => $cell['verified_roster'] ? 'session_history' : 'current_roster',
                'attendance_counts' => ['H' => 0, 'S' => 0, 'I' => 0, 'A' => 0]];
            $g =& $groups[$key];
            $g['has_expected_roster'] = $g['has_expected_roster'] || $cell['expected'];
            if ($cell['expected']) {
                $g['expected_count']++;
                if ($cell['record']) $g['expected_recorded']++;
                if ($cell['complete']) $g['expected_complete']++;
            }
            $g['total_anggota']++;
            if ($cell['record']) $g['sudah_diisi']++;
            if ($cell['record'] && isset($g['attendance_counts'][$cell['record']['status']])) $g['attendance_counts'][$cell['record']['status']]++;
            if ($cell['complete']) $g['complete_input']++;
            else {
                $counter = in_array($cell['state'], ['conflict', 'invalid_date'], true) ? 'conflicts' : ($cell['state'] === 'capaian' ? 'missing_capaian' : ($cell['state'] === 'presensi_incomplete' ? 'missing_keterangan' : 'missing_presensi'));
                $g[$counter]++;
                $g['pending_participants'][] = ['wali_santri_id' => $row['id'], 'nama_bapak' => $row['nama_bapak'],
                    'state' => $cell['state'], 'missing_fields' => $cell['missing_fields'], 'session_date' => $cell['session_date']];
            }
            unset($g);
        }
    }
    $result = [];
    foreach ($groups as $g) {
        $g['belum_diisi'] = count($g['pending_participants']);
        $g['recorded_participants'] = $g['total_anggota'];
        if ($g['has_expected_roster']) {
            $g['total_anggota'] = $g['expected_count'];
            $g['complete_input'] = $g['expected_complete'];
            $g['sudah_diisi'] = $g['expected_recorded'];
        }
        $g['coverage_percent'] = $g['expected_count'] ? round(100 * $g['expected_complete'] / $g['expected_count'], 1) : null;
        $g['status_isi'] = $g['conflicts'] ? 'perlu_verifikasi' : ($g['missing_capaian'] ? 'capaian_belum_lengkap' : (!$g['belum_diisi'] ? 'lengkap' : ($g['sudah_diisi'] ? 'belum_lengkap' : 'belum_sama_sekali')));
        $g['status_label'] = ['perlu_verifikasi' => 'Perlu Verifikasi', 'capaian_belum_lengkap' => 'Capaian Belum Lengkap', 'lengkap' => 'Lengkap', 'belum_lengkap' => 'Sebagian Diinput', 'belum_sama_sekali' => 'Belum Diinput'][$g['status_isi']];
        $g['keterangan'] = $g['status_label'] . ': lengkap ' . $g['complete_input'] . ' dari ' . $g['total_anggota'] . ' peserta; kurang ' . $g['belum_diisi'];
        if (!$g['has_expected_roster']) $g['keterangan'] = $g['status_label'] . ': ' . $g['belum_diisi'] . ' catatan peserta perlu perbaikan; jumlah peserta sesi belum terverifikasi';
        $g['timeliness'] = !$g['belum_diisi'] ? 'Lengkap' : (!$g['has_expected_roster'] ? 'Perlu Konfirmasi Sesi' : ($now->format('Y-m-d H:i:s') >= $g['deadline_at'] ? 'Terlambat' : 'Menunggu Input'));
        $phone = preg_replace('/\D/', '', (string) $g['ustadz_phone']);
        if (str_starts_with($phone, '0')) $phone = '62' . substr($phone, 1);
        $g['ustadz_phone'] = preg_match('/^62\d{8,13}$/', $phone) ? $phone : '';
        $result[] = $g;
    }
    usort($result, static fn($a, $b) => [$a['ustadz_name'], $a['tanggal_tahsin'], $a['nama_halaqoh']] <=> [$b['ustadz_name'], $b['tanggal_tahsin'], $b['nama_halaqoh']]);
    return $result;
}

/**
 * Surface saved historical records that need review without assigning them to
 * today's teacher. Presensi stores a halaqoh id, but not the responsible teacher
 * at the time; pre-tracking teacher attribution therefore cannot be inferred.
 */
function tahsinUnverifiedRecordIssuesFromReport(array $report, string $role = 'admin'): array
{
    if (!in_array($role, ['admin', 'pj_tahfidz', 'kepsek'], true)) return [];
    $issues = [];
    $reviewStates = ['conflict', 'invalid_date', 'baseline', 'capaian', 'presensi_incomplete'];
    foreach ($report['rows'] as $row) {
        foreach ($row['details'] as $cell) {
            if (!$cell['in_schedule'] || !empty($cell['verified_roster'])
                || !in_array($cell['state'], $reviewStates, true) || empty($cell['records'])) continue;

            $halaqohIds = array_values(array_unique(array_filter(array_map(
                static fn($record) => (int) ($record['halaqoh_id'] ?? 0), $cell['records']
            ))));
            sort($halaqohIds);
            $placementKey = count($halaqohIds) === 1 ? (string) $halaqohIds[0] : (count($halaqohIds) ? 'multiple' : 'unknown');
            $key = $cell['session_date'] . ':' . $placementKey;
            if (!isset($issues[$key])) {
                $placementLabel = count($halaqohIds) === 1
                    ? 'Halaqoh #' . $halaqohIds[0] . ' tercatat'
                    : (count($halaqohIds) ? 'Beberapa halaqoh tercatat' : 'Halaqoh tidak tercatat');
                $issues[$key] = [
                    'session_date' => $cell['session_date'],
                    'placement_key' => $placementKey,
                    'placement_label' => $placementLabel,
                    'teacher_label' => 'Guru pada sesi ini belum terverifikasi',
                    'participants' => [],
                ];
            }
            $issues[$key]['participants'][$row['id']] = [
                'wali_santri_id' => (int) $row['id'],
                'nama_bapak' => $row['nama_bapak'],
                'missing_fields' => $cell['missing_fields'],
                'state' => $cell['state'],
            ];
        }
    }
    foreach ($issues as &$issue) $issue['participants'] = array_values($issue['participants']);
    unset($issue);
    return array_values($issues);
}

/** Build the date-first monitoring view while preserving the legacy groups list for integrations. */
function tahsinPendingOverviewFromReport(array $report, DateTimeImmutable $now, string $role = 'admin', ?string $currentReminderSession = null): array
{
    $groups = tahsinPendingFromReport($report, $now, $currentReminderSession);
    $issues = tahsinUnverifiedRecordIssuesFromReport($report, $role);
    $sessions = [];
    $verifiedCount = 0;
    $unverifiedCount = 0;
    foreach ($report['trend'] as $trend) {
        $date = $trend['tanggal'];
        $verified = !empty($trend['roster_verified']);
        if ($verified) $verifiedCount++; else $unverifiedCount++;
        $sessionGroups = array_values(array_filter($groups, static fn($g) => $g['tanggal_tahsin'] === $date));
        $currentRosterAvailable = !$verified && $date === $currentReminderSession && count($sessionGroups) > 0;
        $sessionIssues = array_values(array_filter($issues, static fn($issue) => $issue['session_date'] === $date));
        $sessions[] = [
            'tanggal' => $date,
            'roster_verified' => $verified,
            'current_roster_available' => $currentRosterAvailable,
            'roster_count' => $verified ? (int) $trend['peserta_roster'] : ($currentRosterAvailable ? array_sum(array_column($sessionGroups, 'total_anggota')) : null),
            'complete_input' => $verified ? (int) $trend['complete_input'] : ($currentRosterAvailable ? array_sum(array_column($sessionGroups, 'complete_input')) : null),
            'missing_presensi' => $verified ? (int) $trend['missing_presensi'] : ($currentRosterAvailable ? array_sum(array_column($sessionGroups, 'missing_presensi')) : null),
            'missing_keterangan' => (int) $trend['missing_keterangan'],
            'missing_capaian' => (int) $trend['missing_capaian'],
            'conflicts' => (int) $trend['conflicts'],
            'groups' => $sessionGroups,
            'issues' => $sessionIssues,
        ];
    }
    $pending = array_values(array_filter($groups, static fn($g) => $g['belum_diisi'] > 0));
    $teacherIds = array_unique(array_filter(array_map(static fn($g) => (int) $g['ustadz_id'], $pending)));
    return [
        'sessions' => $sessions,
        'groups' => $groups,
        'summary' => [
            'scheduled_sessions' => count($report['trend']),
            'verified_sessions' => $verifiedCount,
            'unverified_sessions' => $unverifiedCount,
            'pending_teachers' => count($teacherIds),
            'missing_presensi' => array_sum(array_column($pending, 'missing_presensi')),
            'missing_keterangan' => array_sum(array_column($pending, 'missing_keterangan')),
            'missing_capaian' => array_sum(array_column($pending, 'missing_capaian')),
            'conflicts' => array_sum(array_column($pending, 'conflicts')),
            'pending_participants' => array_sum(array_column($pending, 'belum_diisi')),
            'unverified_record_issues' => array_sum(array_map(static fn($issue) => count($issue['participants']), $issues)),
        ],
        'filters' => $report['filters'],
        'tracked_from' => $report['tracked_from'],
    ];
}

function tahsinFetchPendingOverview(PDO $pdo, string $role = 'admin', int $userId = 0, array $query = [], ?DateTimeImmutable $now = null): array
{
    $now = $now ?? tahsinNow();
    $data = tahsinLoadDataset($pdo, $now->format('Y-m-d'), $now);
    $latest = tahsinLatestSession($now->format('Y-m-d'), $data['holidays']);
    if (!$latest) return ['sessions' => [], 'groups' => [], 'summary' => [
        'scheduled_sessions' => 0, 'verified_sessions' => 0, 'unverified_sessions' => 0,
        'pending_teachers' => 0, 'missing_presensi' => 0, 'missing_keterangan' => 0,
        'missing_capaian' => 0, 'conflicts' => 0, 'pending_participants' => 0,
        'unverified_record_issues' => 0,
    ], 'filters' => [], 'tracked_from' => $data['tracked_from']];
    $start = $data['tracked_from'] ? min(substr($data['tracked_from'], 0, 10), $latest) : $latest;
    // Include positively identified historical conflicts/incomplete records.
    // The assembler still generates no missing historical roster entries.
    if ($data['records']) $start = min($start, tahsinSessionDate($data['records'][0]['tanggal']));
    foreach (['start_date', 'end_date'] as $key) if (isset($query[$key]) && trim((string) $query[$key]) === '') unset($query[$key]);
    $filters = tahsinFilters(array_merge(['start_date' => $start, 'end_date' => $now->format('Y-m-d')], $query), $now);
    $report = tahsinAssembleReport($data, $filters, $role, $userId, $now, $latest);
    $currentSession = tahsinSessionDate($now->format('Y-m-d'));
    $currentReminderSession = $latest === $currentSession
        && $now >= new DateTimeImmutable($currentSession . ' 07:30:00', new DateTimeZone('Asia/Jakarta')) ? $currentSession : null;
    return tahsinPendingOverviewFromReport($report, $now, $role, $currentReminderSession);
}

/** Kept as the flat array consumed by the existing dashboard and n8n reminder API. */
function tahsinFetchPending(PDO $pdo, string $role = 'admin', int $userId = 0, array $query = [], ?DateTimeImmutable $now = null): array
{
    return tahsinFetchPendingOverview($pdo, $role, $userId, $query, $now)['groups'];
}

function tahsinSaveVerification(PDO $pdo, int $waliId, string $session, string $fingerprint, ?int $selectedId, int $userId, string $note): void
{
    if (!tahsinSchemaReady($pdo)) throw new RuntimeException('Migrasi pemantauan Tahsin belum diterapkan.');
    if (!tahsinDate($session) || tahsinSessionDate($session) !== $session || $waliId < 1 || $userId < 1 || trim($note) === '') throw new InvalidArgumentException('Data verifikasi dan alasan wajib diisi.');
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM wali_santri WHERE id = ? FOR UPDATE');
        $lock->execute([$waliId]);
        if (!$lock->fetchColumn()) throw new RuntimeException('Wali tidak ditemukan.');
        $stmt = $pdo->prepare('SELECT * FROM presensi WHERE wali_santri_id = ? AND tanggal BETWEEN ? AND ? ORDER BY id FOR UPDATE');
        $stmt->execute([$waliId, $session, min((new DateTimeImmutable($session))->modify('+6 days')->format('Y-m-d'), tahsinNow()->format('Y-m-d'))]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$records || !hash_equals(tahsinFingerprint($records), $fingerprint)) throw new RuntimeException('Data sumber berubah. Muat ulang dan verifikasi kembali.');
        if ($selectedId && !in_array($selectedId, array_map('intval', array_column($records, 'id')), true)) throw new InvalidArgumentException('Catatan terpilih bukan bagian dari sesi ini.');
        $stmt = $pdo->prepare('INSERT INTO tahsin_verifications (wali_santri_id, session_date, fingerprint, selected_presensi_id, decided_by, note) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$waliId, $session, $fingerprint, $selectedId, $userId, tahsinTextLimit(trim($note), 2000)]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function tahsinPercent($value): string
{
    return $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1, ',', '.'), '0'), ',') . '%';
}
