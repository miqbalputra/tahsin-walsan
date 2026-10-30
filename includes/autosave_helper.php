<?php
require_once __DIR__ . '/attendance_helper.php';

class AutosaveException extends RuntimeException
{
    public function __construct(string $message, public int $httpStatus = 422, public array $details = []) { parent::__construct($message); }
}

function autosaveSchemaReady(PDO $pdo): bool
{
    try {
        foreach (['drafts', 'operations', 'revisions', 'sessions'] as $name) $pdo->query("SELECT 1 FROM tahsin_autosave_{$name} LIMIT 0");
        $pdo->query('SELECT program_code FROM halaqoh LIMIT 0');
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name IN ('tahsin_autosave_ai','tahsin_autosave_au','tahsin_autosave_ad')");
        return (int) $stmt->fetchColumn() === 3;
    } catch (PDOException $e) { return false; }
}

function autosaveEnabled(PDO $pdo, int $groupId = 0): bool
{
    if (getenv('TAHSIN_AUTOSAVE_ENABLED') === 'false' || getenv('TAHSIN_AUTOSAVE_ENABLED') === '0') return false;
    $pilot = trim((string) getenv('TAHSIN_AUTOSAVE_HALAQOH_IDS'));
    if ($groupId && $pilot !== '' && !in_array($groupId, array_map('intval', explode(',', $pilot)), true)) return false;
    return autosaveSchemaReady($pdo);
}

function autosaveHash(mixed $value): string { return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }

function autosaveFields(): array
{
    return ['status', 'alasan', 'jenis_materi', 'jilid', 'nama_surat_dari', 'nama_surat_sampai', 'halaman_dari', 'halaman_sampai', 'hasil_talaqqi'];
}

function autosavePayload(array $input): array
{
    $payload = [];
    foreach (autosaveFields() as $field) {
        $value = $input[$field] ?? '';
        if (!is_scalar($value) && $value !== null) throw new AutosaveException('Format isian tidak valid.');
        $value = (string) $value;
        $limit = $field === 'alasan' ? 500 : (str_starts_with($field, 'nama_surat') ? 100 : 30);
        if (!preg_match('//u', $value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value) || preg_match_all('/./us', $value) > $limit) throw new AutosaveException('Isian terlalu panjang atau tidak valid.');
        $payload[$field] = $value;
    }
    foreach (['status' => ['', 'H', 'S', 'I', 'A'], 'jenis_materi' => ['', 'Iqro', 'Al Quran'], 'hasil_talaqqi' => ['', 'Lulus', 'Ulang']] as $field => $allowed) {
        if (!in_array($payload[$field], $allowed, true)) throw new AutosaveException('Pilihan isian tidak valid.');
    }
    return $payload;
}

function autosaveMissing(array $p): array
{
    $missing = [];
    if ($p['status'] === '') return ['status kehadiran'];
    if (in_array($p['status'], ['S', 'I'], true)) return trim($p['alasan']) === '' ? ['alasan/keterangan'] : [];
    if ($p['status'] !== 'H') return [];
    if (!in_array($p['jenis_materi'], ['Iqro', 'Al Quran'], true)) $missing[] = 'jenis materi';
    if ($p['jenis_materi'] === 'Iqro' && !preg_match('/^[1-6]$/D', $p['jilid'])) $missing[] = 'jilid 1–6';
    foreach (['halaman_dari', 'halaman_sampai'] as $field) if (!preg_match('/^[1-9][0-9]{0,3}$/D', $p[$field]) && !($p['jenis_materi']==='Iqro' && $p[$field]==='EBTA')) $missing[] = str_replace('_', ' ', $field);
    $rangeStart=$p['halaman_dari']==='EBTA'?10000:(int)$p['halaman_dari']; $rangeEnd=$p['halaman_sampai']==='EBTA'?10000:(int)$p['halaman_sampai'];
    if ($rangeStart > $rangeEnd && ($p['jenis_materi'] === 'Iqro' || $p['nama_surat_dari'] === $p['nama_surat_sampai'])) $missing[] = 'rentang bacaan berurutan';
    if ($p['jenis_materi'] === 'Al Quran') foreach (['nama_surat_dari', 'nama_surat_sampai'] as $field) if (trim($p[$field]) === '') $missing[] = str_replace('_', ' ', $field);
    if ($p['jenis_materi'] === 'Al Quran' && preg_match_all('/./us', attendanceCombineRange($p['nama_surat_dari'], $p['nama_surat_sampai'], 's/d')) > 100) $missing[] = 'nama surat lebih singkat';
    if (!in_array($p['hasil_talaqqi'], ['Lulus', 'Ulang'], true)) $missing[] = 'hasil Lulus/Ulang';
    return $missing;
}

function autosaveFromRecord(?array $r): array
{
    $p = array_fill_keys(autosaveFields(), '');
    if (!$r) return $p;
    foreach (['status', 'alasan', 'jenis_materi', 'jilid', 'hasil_talaqqi'] as $key) $p[$key] = (string) ($r[$key] ?? '');
    foreach (['nama_surat' => ' s/d ', 'halaman' => '-'] as $field => $delimiter) {
        $parts = explode($delimiter, (string) ($r[$field] ?? ''), 2);
        $p[$field . '_dari'] = trim($parts[0]);
        $p[$field . '_sampai'] = trim($parts[1] ?? $parts[0]);
    }
    return $p;
}

/** One group lock serializes autosave/finalization with legacy teacher writes. */
function autosaveContext(PDO $pdo, int $userId, int $groupId, string $date, bool $lock = false): array
{
    if (!tahsinDate($date) || $date > tahsinNow()->format('Y-m-d')) throw new AutosaveException('Tanggal sesi tidak valid.');
    $group = attendanceFetchOwnedHalaqoh($pdo, $groupId, $userId, $date, $lock);
    if (!$group) throw new AutosaveException('Kelompok bukan penugasan Anda.', 403);
    $session = tahsinSessionDate($date);
    $members = attendanceFetchActiveMembers($pdo, $groupId, $userId, $date);
    $holidays = tahsinHolidayMap($pdo);
    $active = tahsinSessionActive($session, $holidays) && !array_key_exists($date, $holidays);
    $reportingReady = tahsinSchemaReady($pdo);
    $program = $group['program_code'] ?? 'bapak';
    $revision = autosaveHash([$program, $groupId, (int) $group['ustadz_id'], $session, $active,
        array_map(static fn($m) => (int) $m['id'], $members)]);
    return compact('group', 'session', 'members', 'holidays', 'active', 'revision', 'program', 'date', 'reportingReady');
}

function autosaveEntry(PDO $pdo, int $userId, array $ctx, int $waliId, bool $lock = false): array
{
    $end = (new DateTimeImmutable($ctx['session']))->modify('+6 days')->format('Y-m-d');
    $stmt = $pdo->prepare('SELECT * FROM presensi WHERE wali_santri_id=? AND tanggal BETWEEN ? AND ? ORDER BY id' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$waliId, $ctx['session'], $end]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $verification = null;
    if ($ctx['reportingReady']) {
        $stmt = $pdo->prepare('SELECT * FROM tahsin_verifications WHERE wali_santri_id=? AND session_date=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$waliId, $ctx['session']]);
        $verification = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $resolved = tahsinResolveSession($records, $verification, tahsinSuspectIds($pdo, $records), $ctx['holidays']);
    $stmt = $pdo->prepare('SELECT revision FROM tahsin_autosave_revisions WHERE wali_id=? AND session_date=?');
    $stmt->execute([$waliId, $ctx['session']]);
    $officialRevision = autosaveHash([$resolved['fingerprint'], $verification, (int) $stmt->fetchColumn()]);
    $stmt = $pdo->prepare('SELECT * FROM tahsin_autosave_drafts WHERE user_id=? AND halaqoh_id=? AND session_date=? AND wali_id=?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$userId, $ctx['group']['id'], $ctx['session'], $waliId]);
    $draft = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $stale = $draft && ($draft['official_revision'] !== $officialRevision || $draft['context_revision'] !== $ctx['revision']);
    $wrongGroup = $resolved['record'] && (int) $resolved['record']['halaqoh_id'] !== (int) $ctx['group']['id'];
    $conflict = in_array($resolved['state'], ['conflict', 'invalid_date', 'baseline'], true) || $wrongGroup;
    $payload = $draft ? json_decode($draft['payload_json'], true, 32, JSON_THROW_ON_ERROR) : autosaveFromRecord($resolved['record']);
    return [
        'wali_id' => $waliId, 'payload' => $payload, 'official_payload' => autosaveFromRecord($resolved['record']),
        'official_revision' => $officialRevision, 'draft_revision' => (int) ($draft['revision'] ?? 0),
        'context_revision' => $ctx['revision'], 'state' => ($stale || $conflict) ? 'conflict' : ($draft['state'] ?? ($resolved['complete'] ? 'official' : 'empty')),
        'official_complete' => $resolved['complete'] && !$wrongGroup && !$conflict,
        'missing_fields' => autosaveMissing($payload), 'updated_at' => $draft['updated_at'] ?? null,
        'blocked' => $conflict, 'stale_draft' => (bool) $stale,
    ];
}

function autosaveSessionState(PDO $pdo, int $userId, int $groupId, string $date): array
{
    $pdo->beginTransaction();
    try {
        $ctx = autosaveContext($pdo, $userId, $groupId, $date, true);
        $entries = [];
        foreach ($ctx['members'] as $member) $entries[] = autosaveEntry($pdo, $userId, $ctx, (int) $member['id']);
        $stmt = $pdo->prepare('SELECT * FROM tahsin_autosave_sessions WHERE halaqoh_id=? AND session_date=?');
        $stmt->execute([$groupId, $ctx['session']]);
        $closed = $stmt->fetch(PDO::FETCH_ASSOC);
        $fingerprint = autosaveHash([$ctx['revision'], array_column($entries, 'official_revision')]);
        $completed = $closed && $closed['fingerprint'] === $fingerprint && !array_filter($entries, static fn($e) => $e['state'] !== 'official');
        $result = ['program' => $ctx['program'], 'session_date' => $ctx['session'], 'context_revision' => $ctx['revision'],
            'active' => $ctx['active'], 'entries' => $entries, 'completed_at' => $completed ? $closed['completed_at'] : null];
        $pdo->commit();
        return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function autosaveWrite(PDO $pdo, int $userId, int $groupId, string $date, array $request): array
{
    $operation = (string) ($request['operation_id'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D', $operation)) throw new AutosaveException('ID operasi tidak valid.');
    $waliId = filter_var($request['wali_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$waliId || !is_array($request['payload'] ?? null)) throw new AutosaveException('Data peserta tidak valid.');
    $payload = autosavePayload($request['payload']);
    $canonicalRequest = $request;
    unset($canonicalRequest['csrf_token']); // Auth tokens rotate; an operation's data does not.
    $requestHash = autosaveHash([$groupId, $date, $canonicalRequest]);
    $pdo->beginTransaction();
    try {
        $ctx = autosaveContext($pdo, $userId, $groupId, $date, true);
        if (!in_array($waliId, array_map('intval', array_column($ctx['members'], 'id')), true)) throw new AutosaveException('Peserta atau roster berubah. Draft tetap tersedia.', 409);
        $stmt = $pdo->prepare('SELECT * FROM tahsin_autosave_operations WHERE user_id=? AND operation_id=?');
        $stmt->execute([$userId, $operation]);
        if ($receipt = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!hash_equals($receipt['request_hash'], $requestHash)) throw new AutosaveException('ID operasi sudah dipakai untuk data lain.', 409);
            $pdo->commit();
            return json_decode($receipt['response_json'], true, 32, JSON_THROW_ON_ERROR);
        }
        if (!$ctx['active']) throw new AutosaveException('Sesi ini libur. Draft di HP dipertahankan.', 409);
        if (($request['program'] ?? '') !== $ctx['program'] || ($request['context_revision'] ?? '') !== $ctx['revision']) throw new AutosaveException('Jadwal, program, atau roster berubah. Tinjau sesi kembali.', 409);
        $entry = autosaveEntry($pdo, $userId, $ctx, $waliId, true);
        if ($entry['blocked']) throw new AutosaveException('Catatan resmi memerlukan verifikasi admin.', 409, ['entry' => $entry]);
        if (($request['official_revision'] ?? '') !== $entry['official_revision'] || (int) ($request['draft_revision'] ?? -1) !== $entry['draft_revision']) {
            throw new AutosaveException('Data sudah berubah di perangkat lain. Tinjau kedua versi.', 409, ['entry' => $entry]);
        }
        $missing = autosaveMissing($payload);
        $state = $missing ? 'draft' : 'official';
        if (!$missing) attendanceSaveEntries($pdo, $groupId, $userId, $date, [$waliId => $payload]);
        $fresh = autosaveEntry($pdo, $userId, $ctx, $waliId);
        $stmt = $pdo->prepare("INSERT INTO tahsin_autosave_drafts
            (user_id,halaqoh_id,session_date,wali_id,revision,payload_json,official_revision,context_revision,state,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,NOW(6)) ON DUPLICATE KEY UPDATE revision=VALUES(revision),payload_json=VALUES(payload_json),
            official_revision=VALUES(official_revision),context_revision=VALUES(context_revision),state=VALUES(state),updated_at=NOW(6)");
        $stmt->execute([$userId, $groupId, $ctx['session'], $waliId, $entry['draft_revision'] + 1,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $fresh['official_revision'], $ctx['revision'], $state]);
        $result = autosaveEntry($pdo, $userId, $ctx, $waliId);
        $stmt = $pdo->prepare('INSERT INTO tahsin_autosave_operations (user_id,operation_id,request_hash,response_json) VALUES (?,?,?,?)');
        $stmt->execute([$userId, $operation, $requestHash, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function autosaveFinalize(PDO $pdo, int $userId, int $groupId, string $date, array $request): array
{
    $pdo->beginTransaction();
    try {
        $ctx = autosaveContext($pdo, $userId, $groupId, $date, true);
        if (!$ctx['active'] || ($request['context_revision'] ?? '') !== $ctx['revision']) throw new AutosaveException('Sesi atau roster berubah.', 409);
        $entries = [];
        foreach ($ctx['members'] as $member) {
            $entry = autosaveEntry($pdo, $userId, $ctx, (int) $member['id'], true);
            if ($entry['state'] !== 'official' || !$entry['official_complete'] || ($request['revisions'][(string) $member['id']] ?? '') !== $entry['official_revision']) {
                throw new AutosaveException('Masih ada peserta belum lengkap, perubahan belum terkirim, atau konflik.', 409);
            }
            $entries[] = $entry;
        }
        if (!$entries) throw new AutosaveException('Belum ada peserta dalam sesi ini.');
        $stmt = $pdo->prepare('INSERT INTO tahsin_autosave_sessions (halaqoh_id,session_date,completed_by,completed_at,fingerprint) VALUES (?,?,?,NOW(6),?) ON DUPLICATE KEY UPDATE completed_by=VALUES(completed_by),completed_at=NOW(6),fingerprint=VALUES(fingerprint)');
        $stmt->execute([$groupId, $ctx['session'], $userId, autosaveHash([$ctx['revision'], array_column($entries, 'official_revision')])]);
        $pdo->commit();
        return ['completed' => true, 'message' => 'Sesi selesai. Semua presensi dan capaian tersimpan lengkap.'];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
