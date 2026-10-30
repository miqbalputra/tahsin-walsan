<?php
require_once __DIR__ . '/participant_helper.php';
require_once __DIR__ . '/attendance_history_helper.php';

final class AchievementException extends RuntimeException {}

function achievementFilters(array $query): array
{
    $group = appRequestString($query, 'halaqoh_id');
    if ($group !== '' && !filter_var($group, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) throw new AchievementException('Pilih halaqoh dari daftar yang tersedia.');
    $search = appRequestString($query, 'search');
    if (!preg_match('//u', $search) || preg_match('/[\x00-\x1f\x7f]/', $search) || appTextLength($search) > 100) throw new AchievementException('Pencarian maksimal 100 karakter teks yang valid.');
    $active = appRequestString($query, 'aktif', '1');
    if (!in_array($active, ['1','0',''], true)) throw new AchievementException('Pilih status peserta yang tersedia.');
    $page = filter_var(appRequestString($query, 'page', '1'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
    if (!$page) throw new AchievementException('Nomor halaman tidak valid.');
    return ['halaqoh_id' => $group, 'search' => $search, 'aktif' => $active, 'page' => $page];
}

/** A projection of the same resolved sessions as reports; no global latest-H join. */
function achievementCatalog(PDO $pdo, string $role, int $actorId, array $filters): array
{
    if (!in_array($role, ['admin','pj_tahfidz','ustadz'], true)) throw new AchievementException('Akun Anda tidak dapat membuka capaian.');
    $now = tahsinNow();
    $data = tahsinLoadDataset($pdo, $now->format('Y-m-d'), $now);
    $report = tahsinAssembleReport($data, tahsinFilters(['halaqoh_id' => $filters['halaqoh_id'], 'aktif' => $filters['aktif']], $now), $role, $actorId, $now, null, true);
    $groups = array_column($data['halaqoh'], null, 'id');
    $placements = [];
    foreach ($data['roster'] as $member) {
        if ($role === 'ustadz' && (int) $member['ustadz_id'] !== $actorId) continue;
        if ($filters['halaqoh_id'] !== '' && (int) $member['halaqoh_id'] !== (int) $filters['halaqoh_id']) continue;
        $placements[(int) $member['wali_santri_id']][(int) $member['halaqoh_id']] = $member;
    }
    $rows = [];
    foreach ($report['rows'] as $row) {
        $id = (int) $row['id'];
        if ($role === 'ustadz' && empty($placements[$id])) continue;
        $memberships = array_values($placements[$id] ?? []);
        $displayGroups = array_map(static fn($m) => $m['nama_halaqoh'] . ' · ' . $m['nama_ustadz'], $memberships);
        $source = $row['materi_record'] ?? $row['baseline_record'];
        $candidate = $row['latest_hadir_record'];
        if (!$displayGroups && $candidate && isset($groups[(int) $candidate['halaqoh_id']])) {
            $group = $groups[(int) $candidate['halaqoh_id']];
            $displayGroups[] = 'Riwayat: ' . $group['nama_halaqoh'] . ' · ' . $group['nama_ustadz'];
        }
        $item = ['id' => $id, 'nama_bapak' => $row['nama_bapak'], 'no_hp' => $row['no_hp'] ?? '', 'status_aktif' => (int) $row['status_aktif'],
            'anak' => $row['anak'], 'groups' => $displayGroups, 'current_groups' => array_map(static fn($m) => (int) $m['halaqoh_id'], $memberships),
            'material' => $source ? tahsinMaterial($source) : 'Belum ada capaian lengkap yang terkonfirmasi',
            'date' => $row['materi_record']['tanggal'] ?? null, 'initial' => $row['materi_record'] === null && $row['baseline_record'] !== null,
            'edit_record_id' => $candidate === null ? null : (int) $candidate['id'], 'edit_date' => $candidate['tanggal'] ?? null];
        $haystack = $item['nama_bapak'] . ' ' . $item['anak'] . ' ' . implode(' ', $item['groups']);
        if ($filters['search'] !== '' && !tahsinTextContains($haystack, $filters['search'])) continue;
        $rows[] = $item;
    }
    usort($rows, static fn($a,$b) => strnatcasecmp(implode(' ', $a['groups']), implode(' ', $b['groups'])) ?: strnatcasecmp($a['nama_bapak'], $b['nama_bapak']) ?: ($a['id'] <=> $b['id']));
    $total = count($rows); $pages = max(1, (int) ceil($total / 50)); $page = min($filters['page'], $pages);
    $options = array_values(array_filter($data['halaqoh'], static fn($g) => $role !== 'ustadz' || (int) $g['ustadz_id'] === $actorId));
    return ['rows' => array_slice($rows, ($page - 1) * 50, 50), 'total' => $total, 'pages' => $pages, 'page' => $page, 'groups' => $options];
}

function achievementContext(PDO $pdo, string $mode, int $waliId, ?int $recordId, bool $lock = false): array
{
    if (!in_array($mode, ['attendance','initial'], true) || $waliId < 1 || ($mode === 'attendance' && ($recordId === null || $recordId < 0)) || ($mode === 'initial' && $recordId !== null)) throw new AchievementException('Sumber capaian tidak valid. Buka kembali daftar capaian.');
    if (!tahsinSchemaReady($pdo)) throw new AchievementException('Pengelola perlu menyiapkan struktur pelaporan sebelum mengubah capaian.');
    $suffix = $lock ? ' FOR UPDATE' : '';
    $initial = null;
    if ($recordId !== null) {
        $statement = $pdo->prepare('SELECT * FROM presensi WHERE id=? AND wali_santri_id=?'); $statement->execute([$recordId,$waliId]); $initial = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$initial || $initial['status'] !== 'H') throw new AchievementException('Catatan Hadir tidak ditemukan untuk peserta ini.');
        if ($lock && $initial['halaqoh_id'] !== null) {
            $statement = $pdo->prepare('SELECT id FROM halaqoh WHERE id=? FOR UPDATE'); $statement->execute([$initial['halaqoh_id']]); $statement->fetchColumn();
        }
    }
    $participant = participantSnapshot($pdo, $waliId, $lock);
    $statement = $pdo->prepare('SELECT * FROM tahsin_capaian_awal WHERE wali_santri_id=?' . $suffix); $statement->execute([$waliId]); $baseline = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    $records = []; $verification = null; $selected = null; $resolved = null; $holidays = []; $suspects = [];
    if ($initial) {
        $session = tahsinSessionDate($initial['tanggal']); $end = (new DateTimeImmutable($session))->modify('+6 days')->format('Y-m-d');
        $statement = $pdo->prepare('SELECT * FROM presensi WHERE wali_santri_id=? AND tanggal BETWEEN ? AND ? ORDER BY id' . $suffix); $statement->execute([$waliId,$session,$end]); $records = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($records as $record) if ((int) $record['id'] === $recordId) $selected = $record;
        if (!$selected || $selected['status'] !== 'H' || $selected['halaqoh_id'] !== $initial['halaqoh_id'] || $selected['tanggal'] !== $initial['tanggal']) throw new AchievementException('Catatan berpindah atau berubah sejak editor dibuka. Buka kembali daftar capaian.');
        $statement = $pdo->prepare('SELECT * FROM tahsin_verifications WHERE wali_santri_id=? AND session_date=? ORDER BY id DESC LIMIT 1' . $suffix); $statement->execute([$waliId,$session]); $verification = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        foreach (tahsinHolidayMap($pdo) as $date => $label) if ($date >= $session && $date <= $end) $holidays[$date] = $label;
        $suspects = tahsinSuspectIds($pdo,$records); ksort($suspects);
        $resolved = tahsinResolveSession($records,$verification,$suspects,$holidays);
    }
    $identity = array_intersect_key($participant['parent'], array_flip(['id','nama_bapak','created_at','status_aktif']));
    $fingerprint = hash('sha256',serialize([$mode,$waliId,$recordId,$identity,$mode === 'initial' ? $baseline : [$records,$verification,$holidays,$suspects]]));
    return compact('mode','waliId','recordId','participant','baseline','records','verification','selected','resolved','holidays','suspects','fingerprint');
}

function achievementValidateSource(array $context): array
{
    if ($context['mode'] === 'initial') return [];
    $selected = $context['selected']; $resolved = $context['resolved'];
    $session = tahsinSessionDate($selected['tanggal']);
    if ($selected['tanggal'] > tahsinNow()->format('Y-m-d') || !tahsinSessionActive($session,$context['holidays']) || isset($context['holidays'][$selected['tanggal']])) throw new AchievementException('Tanggal catatan perlu ditinjau melalui Rekap Lengkap sebelum mengubah capaian.');
    if (!$resolved['record'] || in_array($resolved['state'],['conflict','invalid_date','baseline'],true)) throw new AchievementException('Ada catatan berbeda atau verifikasi yang perlu diperiksa. Selesaikan melalui Rekap Lengkap sebelum mengubah capaian.');
    if ($context['verification']) {
        if ((int) $resolved['record']['id'] !== (int) $selected['id']) throw new AchievementException('Catatan ini bukan versi yang dipilih dalam verifikasi sesi.');
        return [(int) $selected['id']];
    }
    $eligible = array_filter($context['records'], static fn($row) => !array_key_exists($row['tanggal'],$context['holidays']));
    if (count(array_unique(array_column($eligible,'halaqoh_id'))) > 1) throw new AchievementException('Ada catatan lintas halaqoh pada sesi ini. Verifikasi melalui Rekap Lengkap terlebih dahulu.');
    if (tahsinRecordPayload($selected) !== tahsinRecordPayload($resolved['record'])) throw new AchievementException('Catatan bukan sumber capaian terkonfirmasi. Tinjau Rekap Lengkap.');
    return array_map(static fn($row) => (int) $row['id'], array_values($eligible));
}

function achievementReadContext(PDO $pdo, string $mode, int $waliId, ?int $recordId): array
{
    if ($pdo->inTransaction()) throw new RuntimeException('Selesaikan transaksi lain sebelum membuka editor capaian.');
    $pdo->beginTransaction();
    try {
        $context=achievementContext($pdo,$mode,$waliId,$recordId);
        achievementValidateSource($context);
        $pdo->commit(); return $context;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack(); throw $error;
    }
}

function achievementInput(array $raw, ?array $previous, string $mode): array
{
    $limits = ['jenis_materi'=>20,'jilid'=>2,'nama_surat'=>100,'halaman'=>$mode === 'initial' ? 30 : 20,'hasil_talaqqi'=>10];
    foreach ($limits as $field => $limit) {
        $value = $raw[$field];
        if (!preg_match('//u',$value) || preg_match('/[\x00-\x1f\x7f]/',$value) || appTextLength($value)>$limit) throw new AchievementException('Periksa isian capaian; batas teks atau formatnya tidak valid.');
    }
    $type = trim($raw['jenis_materi']);
    if (!in_array($type,['Iqro','Al Quran'],true)) throw new AchievementException('Pilih jenis materi Iqro atau Al Quran.');
    $sameType = $previous && $previous['jenis_materi'] === $type;
    $text = static fn($field) => $sameType && $raw[$field] === (string) ($previous[$field] ?? '') ? ($previous[$field] ?? null) : trim($raw[$field]);
    $page = $text('halaman');
    if (trim((string) $page) === '') throw new AchievementException('Halaman atau ayat wajib diisi.');
    $surah = $type === 'Al Quran' ? $text('nama_surat') : ($sameType ? $previous['nama_surat'] : null);
    $volume = $type === 'Iqro' ? trim($raw['jilid']) : ($sameType ? $previous['jilid'] : null);
    if ($type === 'Iqro' && !preg_match('/^[1-6]$/D',(string) $volume)) throw new AchievementException('Pilih jilid Iqro 1 sampai 6.');
    if ($type === 'Al Quran' && trim((string) $surah) === '') throw new AchievementException('Nama surat wajib diisi untuk Al Quran.');
    $unchangedPage = $sameType && $raw['halaman'] === (string) ($previous['halaman'] ?? '');
    if (!$unchangedPage) {
        if (!preg_match('/^([1-9][0-9]{0,3})(?:\s*(?:-|s\/d)\s*([1-9][0-9]{0,3}))?$/iD',(string) $page,$range)) throw new AchievementException('Isi halaman/ayat berupa nomor atau rentang, misalnya 1-10.');
        if (isset($range[2]) && (int) $range[1] > (int) $range[2] && ($type === 'Iqro' || !preg_match('/\s+s\/d\s+/i',(string) $surah))) throw new AchievementException('Awal rentang tidak boleh melebihi akhir pada bacaan yang sama.');
    }
    $result = trim($raw['hasil_talaqqi']);
    if ($mode === 'attendance' && !in_array($result,['Lulus','Ulang'],true)) throw new AchievementException('Pilih hasil talaqqi pada catatan Hadir.');
    if ($mode === 'initial' && $result !== '') throw new AchievementException('Capaian awal disimpan tanpa hasil talaqqi atau status Hadir.');
    return ['jenis_materi'=>$type,'jilid'=>$type === 'Iqro' ? (int) $volume : $volume,'nama_surat'=>$surah,'halaman'=>$page]
        + ($mode === 'attendance' ? ['hasil_talaqqi'=>$result] : []);
}

function achievementPack(array $context): array
{
    return ['mode'=>$context['mode'],'record_id'=>$context['recordId'],'baseline'=>$context['baseline'] === null ? null : attendanceHistoryEncode($context['baseline']),
        'records'=>array_map('attendanceHistoryEncode',$context['records']), 'verification'=>$context['verification'] === null ? null : attendanceHistoryEncode($context['verification'])];
}

function achievementApply(PDO $pdo, int $actorId, string $mode, int $waliId, ?int $recordId, array $input, string $fingerprint, string $operation): array
{
    if ($pdo->inTransaction()) throw new RuntimeException('Selesaikan transaksi lain sebelum mengubah capaian.');
    if (!preg_match('/^[a-f0-9]{32}$/D',$operation) || !preg_match('/^[a-f0-9]{64}$/D',$fingerprint)) throw new AchievementException('Formulir capaian tidak valid.');
    $raw = []; foreach (['jenis_materi','jilid','nama_surat','halaman','hasil_talaqqi'] as $field) $raw[$field]=appRequestString($input,$field,'',false);
    $requestHash = hash('sha256',serialize([$mode,$waliId,$recordId,$raw,$fingerprint]));
    if (!participantSchemaReady($pdo)) throw new AchievementException('Jurnal perubahan peserta belum disiapkan. Capaian lama belum diubah.');
    $lockName=participantWriteLockName($pdo); $statement=$pdo->prepare('SELECT GET_LOCK(?,5)'); $statement->execute([$lockName]);
    if ((int) $statement->fetchColumn()!==1) throw new AchievementException('Perubahan peserta lain sedang diproses. Coba kembali sebentar lagi.');
    $sqlMode=null;
    try {
        appRequireTransactionalTables($pdo); $sqlMode=appUseStrictSqlMode($pdo); $pdo->beginTransaction();
        $actor=attendanceHistoryActor($pdo,$actorId,['admin','pj_tahfidz']);
        $statement=$pdo->prepare('SELECT actor_id,request_hash,result_json FROM participant_changes WHERE operation_id=? FOR UPDATE'); $statement->execute([$operation]);
        if ($receipt=$statement->fetch(PDO::FETCH_ASSOC)) {
            if ((int)$receipt['actor_id']!==$actorId || !hash_equals($receipt['request_hash'],$requestHash)) throw new AchievementException('Formulir ini sudah digunakan untuk isian lain. Buka editor baru.');
            $pdo->commit(); return json_decode($receipt['result_json'],true,32,JSON_THROW_ON_ERROR);
        }
        $context=achievementContext($pdo,$mode,$waliId,$recordId,true);
        if (!hash_equals($context['fingerprint'],$fingerprint)) throw new AchievementException('Capaian berubah sejak formulir dibuka. Data belum disimpan; periksa versi terbaru dan bandingkan isian Anda.');
        $targets=achievementValidateSource($context);
        $previous=$mode==='attendance' ? $context['selected'] : $context['baseline'];
        $values=achievementInput($raw,$previous,$mode);
        $changed=array_filter($values,static fn($value,$column) => !$previous || ($previous[$column]===null)!==($value===null) || (string)$previous[$column] !== (string)$value,ARRAY_FILTER_USE_BOTH);
        $before=$context['participant']; $before['achievement']=achievementPack($context);
        if ($mode==='attendance') {
            attendanceHistoryRequireReady($pdo);
            if ($changed) {
                $columns=implode(',',array_map(static fn($column)=>attendanceHistoryQuote($column).'=?',array_keys($changed)));
                $update=$pdo->prepare('UPDATE presensi SET '.$columns." WHERE id=? AND wali_santri_id=? AND status='H'");
                foreach ($targets as $target) $update->execute([...array_values($changed),$target,$waliId]);
            }
        } elseif ($previous===null) {
            $pdo->prepare('INSERT INTO tahsin_capaian_awal(wali_santri_id,jenis_materi,jilid,nama_surat,halaman,updated_by) VALUES(?,?,?,?,?,?)')->execute([$waliId,$values['jenis_materi'],$values['jilid'],$values['nama_surat'],$values['halaman'],$actorId]);
        } elseif ($changed) {
            $columns=implode(',',array_map(static fn($column)=>attendanceHistoryQuote($column).'=?',array_keys($changed)));
            $pdo->prepare('UPDATE tahsin_capaian_awal SET '.$columns.',updated_by=?,updated_at=NOW(6) WHERE wali_santri_id=?')->execute([...array_values($changed),$actorId,$waliId]);
        }
        $fresh=achievementContext($pdo,$mode,$waliId,$recordId,true);
        $after=$fresh['participant']; $after['achievement']=achievementPack($fresh);
        $beforeJson=json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); $afterJson=json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (strlen($beforeJson)+strlen($afterJson)>8*1024*1024) throw new AchievementException('Riwayat capaian terlalu besar untuk editor ini. Hubungi pengelola; perubahan dibatalkan.');
        $result=['id'=>$waliId,'record_id'=>$recordId,'mode'=>$mode,'operation_id'=>$operation,'requires_review'=>$changed && $context['verification']!==null];
        $pdo->prepare('INSERT INTO participant_changes(operation_id,actor_id,actor_name,action,wali_id,request_hash,before_json,after_json,result_json) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$operation,$actorId,$actor['nama_lengkap'],$mode==='initial'?'baseline':'achievement',$waliId,$requestHash,$beforeJson,$afterJson,json_encode($result,JSON_THROW_ON_ERROR)]);
        $pdo->commit(); return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack(); throw $error;
    } finally {
        try { if ($sqlMode!==null) $pdo->prepare('SET SESSION sql_mode=?')->execute([$sqlMode]); $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
        catch (Throwable $error) { error_log('Achievement connection cleanup: '.$error->getMessage()); }
    }
}
