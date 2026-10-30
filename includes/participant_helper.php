<?php
require_once __DIR__ . '/security_helper.php';
require_once __DIR__ . '/database_helper.php';
require_once __DIR__ . '/participant_identity_helper.php';
require_once __DIR__ . '/data_induk_helper.php';
require_once __DIR__ . '/alumni_archive_helper.php';

final class ParticipantManagementException extends RuntimeException {}

function participantActionLabel(string $action): string
{
    return ['merge' => 'Gabung peserta', 'archive' => 'Arsipkan peserta', 'save' => 'Simpan peserta',
        'class_promote' => 'Naikkan kelas', 'class_graduate' => 'Luluskan anak',
        'class_restore' => 'Kembalikan kelas anak', 'achievement' => 'Ubah capaian catatan',
        'baseline' => 'Ubah capaian awal', 'membership_add' => 'Tambahkan keanggotaan',
        'membership_remove' => 'Nonaktifkan keanggotaan'][$action] ?? 'Perubahan peserta';
}

function participantApplyMigration(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new RuntimeException('Jalankan migrasi jurnal peserta di luar transaksi data.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS participant_changes (
        operation_id CHAR(32) PRIMARY KEY, actor_id INT NULL, actor_name VARCHAR(100) NOT NULL,
        action VARCHAR(20) NOT NULL, wali_id INT NULL, request_hash CHAR(64) NOT NULL,
        before_json LONGTEXT NOT NULL, after_json LONGTEXT NOT NULL, result_json TEXT NOT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        KEY idx_participant_changes_wali(wali_id,created_at),
        FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY(wali_id) REFERENCES wali_santri(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!participantSchemaReady($pdo)) throw new RuntimeException('Struktur jurnal peserta belum lengkap.');
    appRequireTransactionalTables($pdo, ['participant_changes']);
}

function participantSchemaReady(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT operation_id,actor_id,actor_name,action,wali_id,request_hash,before_json,after_json,result_json,created_at FROM participant_changes LIMIT 0');
        return true;
    } catch (PDOException $error) {
        if (in_array($error->getCode(), ['42S02', '42S22'], true)) return false;
        throw $error;
    }
}

function participantId($value, bool $optional = false): ?int
{
    if ($optional && ($value === '' || $value === null)) return null;
    if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)
        || !($id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]))) {
        throw new ParticipantManagementException('ID peserta atau anak tidak valid. Buka kembali formulir.');
    }
    return $id;
}

function participantHasPrograms(PDO $pdo): bool
{
    return (bool) $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='halaqoh' AND column_name='program_code'")->fetchColumn();
}

function participantSnapshot(PDO $pdo, int $id, bool $lock = false): array
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    $statement = $pdo->prepare('SELECT * FROM wali_santri WHERE id=?' . $suffix);
    $statement->execute([$id]);
    $parent = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$parent) throw new ParticipantManagementException('Peserta tidak ditemukan. Buka kembali daftar peserta.');
    $statement = $pdo->prepare('SELECT * FROM santri_detail WHERE wali_santri_id=? ORDER BY id' . $suffix);
    $statement->execute([$id]);
    $children = $statement->fetchAll(PDO::FETCH_ASSOC);
    $program = participantHasPrograms($pdo) ? 'h.program_code' : "'bapak'";
    $statement = $pdo->prepare("SELECT hm.*, $program AS participant_program, h.nama_halaqoh AS participant_group_name FROM halaqoh_members hm JOIN halaqoh h ON h.id=hm.halaqoh_id WHERE hm.wali_santri_id=? ORDER BY hm.id" . $suffix);
    $statement->execute([$id]);
    return ['parent' => $parent, 'children' => $children, 'memberships' => $statement->fetchAll(PDO::FETCH_ASSOC),
        'managed' => isset(participantManagedIds($pdo)[$id]), 'central' => dataIndukConfig()['enabled']];
}

function participantFingerprint(?array $snapshot): string
{
    return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function participantText(array $input, string $key, int $limit, bool $required = false): string
{
    $value = appRequestString($input, $key);
    if (($required && $value === '') || !preg_match('//u', $value)
        || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) || appTextLength($value) > $limit) {
        $label = ['nama_bapak'=>'nama peserta','no_hp'=>'nomor HP','alamat'=>'alamat','kategori'=>'kategori',
            'tempat_tahsin'=>'tempat tahsin','ustadz_luar'=>'pengajar luar','nama'=>'nama anak','kelas'=>'kelas',
            'nama_ayah'=>'nama peserta','nama_anak'=>'nama anak'][$key] ?? 'formulir';
        throw new ParticipantManagementException('Periksa isian ' . $label . '; batasnya ' . $limit . ' karakter' . ($required ? ' dan wajib diisi.' : '.'));
    }
    return $value;
}

function participantInput(array $input, ?array $before): array
{
    $locked = $before && ($before['managed'] || $before['central']);
    $values = [];
    foreach (['kategori' => 20, 'tempat_tahsin' => 255, 'ustadz_luar' => 100] as $key => $limit) {
        $values[$key] = participantText($input, $key, $limit, $key === 'kategori');
        if ($before && $key !== 'kategori' && appRequestString($input, $key, '', false) === (string) $before['parent'][$key]) $values[$key] = $before['parent'][$key];
    }
    if (!in_array($values['kategori'], ['reguler', 'askar', 'tahsin_luar'], true)) throw new ParticipantManagementException('Pilih kategori program dari daftar.');
    if ($values['kategori'] === 'tahsin_luar' && (trim((string)$values['tempat_tahsin']) === '' || trim((string)$values['ustadz_luar']) === '')) throw new ParticipantManagementException('Isi tempat dan pengajar untuk kategori Tahsin Luar.');
    if (!$locked) {
        foreach (['nama_bapak' => 100, 'no_hp' => 20, 'alamat' => 5000] as $key => $limit) {
            if ($before && appRequestString($input, $key, '', false) === (string) $before['parent'][$key]) $values[$key] = $before['parent'][$key];
            else $values[$key] = participantText($input, $key, $limit, $key === 'nama_bapak');
        }
    }
    $children = [];
    $remove = [];
    if (!$locked) {
        $rows = $input['anak'] ?? [];
        $rawRemove = $input['remove_children'] ?? [];
        if (!is_array($rows) || count($rows) > 100 || !is_array($rawRemove) || count($rawRemove) > 100) throw new ParticipantManagementException('Daftar anak tidak valid atau melebihi 100 anak.');
        foreach ($rawRemove as $rawId) {
            $childId = participantId($rawId);
            if (isset($remove[$childId])) throw new ParticipantManagementException('Pilihan penghapusan anak berulang.');
            $remove[$childId] = true;
        }
        $submitted = [];
        $oldChildren = array_column($before['children'] ?? [], null, 'id');
        foreach ($rows as $row) {
            if (!is_array($row)) throw new ParticipantManagementException('Format daftar anak tidak valid.');
            $childId = participantId($row['id'] ?? '', true);
            if ($childId !== null) {
                if (isset($submitted[$childId])) throw new ParticipantManagementException('ID anak berulang pada formulir.');
                $submitted[$childId] = true;
            }
            if ($childId !== null && isset($remove[$childId])) continue;
            $name = participantText($row, 'nama', 100);
            $class = participantText($row, 'kelas', 20);
            if ($childId !== null && isset($oldChildren[$childId]) && appRequestString($row,'nama','',false) === $oldChildren[$childId]['nama_anak']) $name = $oldChildren[$childId]['nama_anak'];
            if ($childId !== null && isset($oldChildren[$childId]) && appRequestString($row,'kelas','',false) === (string)$oldChildren[$childId]['kelas']) $class = $oldChildren[$childId]['kelas'];
            if ($childId === null && $name === '' && $class === '') continue;
            if ($name === '') throw new ParticipantManagementException('Nama anak wajib diisi; centang hapus jika memang ingin menghapus anak tersebut.');
            $children[] = ['id' => $childId, 'nama_anak' => $name, 'kelas' => $class];
        }
        $existingIds = array_fill_keys(array_column($before['children'] ?? [], 'id'), true);
        if (array_diff_key($existingIds, $submitted) || array_diff_key($submitted, $existingIds) || array_diff_key($remove, $existingIds)) {
            throw new ParticipantManagementException('Daftar anak berubah atau belum lengkap. Buka kembali formulir agar anak lama tidak terhapus.');
        }
        if ($remove && appRequestString($input, 'confirm_remove') !== '1') throw new ParticipantManagementException('Centang konfirmasi penghapusan anak setelah memeriksa pilihan.');
    }
    $memberships = $input['memberships'] ?? [];
    if (!is_array($memberships) || array_diff(array_keys($memberships), ['bapak', 'ibu'])) throw new ParticipantManagementException('Pilihan halaqoh tidak valid.');
    foreach (['bapak', 'ibu'] as $program) {
        $target = appRequestString($memberships, $program, 'keep');
        if (!in_array($target, ['keep', 'none'], true)) participantId($target);
        $memberships[$program] = $target;
    }
    $reactivate = appRequestString($input, 'reactivate');
    if (!in_array($reactivate, ['', '1'], true)) throw new ParticipantManagementException('Pilihan aktivasi peserta tidak valid.');
    return ['values' => $values, 'children' => $children, 'remove' => array_keys($remove),
        'memberships' => $memberships, 'reactivate' => $reactivate === '1', 'locked' => (bool) $locked];
}

/** Check every declared FK, including composite constraints and ON DELETE CASCADE. */
function participantChildHasReferences(PDO $pdo, array $child): bool
{
    $keys = $pdo->query("SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='santri_detail' ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
    $constraints = [];
    foreach ($keys as $key) $constraints[$key['TABLE_NAME'] . '\0' . $key['CONSTRAINT_NAME']][] = $key;
    $quote = static fn($identifier) => '`' . str_replace('`', '``', $identifier) . '`';
    foreach ($constraints as $keys) {
        $where = []; $values = [];
        foreach ($keys as $key) {
            $where[] = $quote($key['COLUMN_NAME']) . '=?';
            $values[] = $child[$key['REFERENCED_COLUMN_NAME']];
        }
        $statement = $pdo->prepare('SELECT 1 FROM ' . $quote($keys[0]['TABLE_NAME']) . ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1 FOR UPDATE');
        $statement->execute($values);
        if ($statement->fetchColumn()) return true;
    }
    return false;
}

/** Read authoritative school rows, including when the optional family view is unavailable. */
function participantSchoolSnapshot(PDO $pdo, int $id, bool $lock = false): array
{
    $suffix=$lock?' FOR UPDATE':''; $guardians=$relations=$students=$legacy=$children=[];
    try {
        $statement=$pdo->prepare('SELECT * FROM family_guardians WHERE wali_santri_id=? ORDER BY id'.$suffix); $statement->execute([$id]); $guardians=$statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $error) { if ($error->getCode()!=='42S02') throw $error; }
    if ($guardians) {
        $ids=array_column($guardians,'id'); $in=implode(',',array_fill(0,count($ids),'?'));
        $statement=$pdo->prepare("SELECT * FROM family_relations WHERE guardian_id IN ($in) ORDER BY guardian_id,student_id".$suffix); $statement->execute($ids); $relations=$statement->fetchAll(PDO::FETCH_ASSOC);
        $studentIds=array_values(array_unique(array_column($relations,'student_id')));
        if ($studentIds) {
            $in=implode(',',array_fill(0,count($studentIds),'?'));
            $statement=$pdo->prepare("SELECT * FROM family_students WHERE id IN ($in) ORDER BY id".$suffix); $statement->execute($studentIds); $students=$statement->fetchAll(PDO::FETCH_ASSOC);
        }
        $current=[]; foreach ($relations as $relation) if ((int)$relation['is_current']===1) $current[(int)$relation['student_id']]=true;
        foreach ($students as $student) if (isset($current[(int)$student['id']])) $children[]=['nama_anak'=>$student['nama_anak'],'kelas'=>$student['kelas'],'school_status'=>$student['school_status']];
    } else {
        $statement=$pdo->prepare('SELECT * FROM santri_detail WHERE wali_santri_id=? ORDER BY id'.$suffix); $statement->execute([$id]); $legacy=$statement->fetchAll(PDO::FETCH_ASSOC);
        $hasDataIndukRoster = familyDataIndukRosterColumnsReady($pdo);
        foreach ($legacy as $child) {
            $archivedByDataInduk = $hasDataIndukRoster && !empty($child['data_induk_student_id']) && ($child['status_roster'] ?? '') === 'archived';
            $status = $archivedByDataInduk ? 'archived' : (isAlumniClass($child['kelas']) ? 'graduated' : 'active');
            $children[]=['nama_anak'=>$child['nama_anak'],'kelas'=>$child['kelas'],'school_status'=>$status];
        }
    }
    $eligible=(bool)array_filter($children,static fn($child)=>$child['school_status']==='active'&&trim((string)$child['kelas'])!=='');
    return compact('guardians','relations','students','legacy','children','eligible');
}

function participantCanJoinHalaqoh(PDO $pdo, int $id): bool
{
    $lock=$pdo->inTransaction();
    $statement=$pdo->prepare('SELECT status_aktif,kategori FROM wali_santri WHERE id=?'.($lock?' FOR UPDATE':'')); $statement->execute([$id]); $parent=$statement->fetch(PDO::FETCH_ASSOC);
    return $parent && (int)$parent['status_aktif']===1 && in_array($parent['kategori'],['reguler','askar'],true) && participantSchoolSnapshot($pdo,$id,$lock)['eligible'];
}

function participantAssignMemberships(PDO $pdo, int $id, array $choices): void
{
    $snapshot = participantSnapshot($pdo, $id, true);
    foreach ($choices as $program => $target) {
        if ($target === 'keep') continue;
        $active = array_filter($snapshot['memberships'], static fn($row) => $row['participant_program'] === $program && $row['archived_at'] === null);
        if ($target !== 'none') {
            $column = participantHasPrograms($pdo) ? 'program_code' : "'bapak'";
            $statement = $pdo->prepare("SELECT $column FROM halaqoh WHERE id=? FOR UPDATE");
            $statement->execute([(int) $target]);
            if ($statement->fetchColumn() !== $program) throw new ParticipantManagementException('Halaqoh tidak ditemukan pada program yang dipilih. Buka kembali formulir.');
            if ((int) $snapshot['parent']['status_aktif'] !== 1) throw new ParticipantManagementException('Peserta arsip belum dapat ditempatkan di halaqoh. Pilih pertahankan/tanpa penempatan, atau aktifkan kembali jika memiliki anak yang masih sekolah.');
            $alreadyActive=(bool)array_filter($active,static fn($row)=>(int)$row['halaqoh_id']===(int)$target);
            if (!$alreadyActive && !participantCanJoinHalaqoh($pdo,$id)) throw new ParticipantManagementException('Penempatan baru memerlukan peserta Reguler/Askar aktif dengan anak yang masih sekolah.');
        }
        foreach ($active as $membership) {
            if ($target !== 'none' && (int) $membership['halaqoh_id'] === (int) $target) continue;
            $pdo->prepare("UPDATE halaqoh_members SET archived_at=CURRENT_TIMESTAMP,archive_reason='MANUAL_REASSIGN' WHERE id=?")->execute([$membership['id']]);
        }
        if ($target === 'none' || array_filter($active, static fn($row) => (int) $row['halaqoh_id'] === (int) $target)) continue;
        $statement = $pdo->prepare('SELECT id FROM halaqoh_members WHERE wali_santri_id=? AND halaqoh_id=? ORDER BY id LIMIT 1 FOR UPDATE');
        $statement->execute([$id, (int) $target]);
        $membershipId = $statement->fetchColumn();
        if ($membershipId!==false) $pdo->prepare('UPDATE halaqoh_members SET archived_at=NULL,archive_reason=NULL WHERE id=?')->execute([$membershipId]);
        else $pdo->prepare('INSERT INTO halaqoh_members(wali_santri_id,halaqoh_id) VALUES(?,?)')->execute([$id, (int) $target]);
    }
}

function participantApply(PDO $pdo, int $actorId, string $action, ?int $id, array $input, string $fingerprint, string $operationId): array
{
    if ($pdo->inTransaction()) throw new RuntimeException('Selesaikan transaksi lain sebelum menyimpan peserta.');
    if (!in_array($action, ['save', 'archive'], true) || ($action === 'archive' && $id === null)
        || !preg_match('/^[a-f0-9]{32}$/D', $operationId) || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) throw new ParticipantManagementException('Formulir tidak valid. Buka kembali halaman peserta.');
    if (!participantSchemaReady($pdo)) throw new ParticipantManagementException('Pengelola perlu menyiapkan jurnal perubahan peserta sebelum penyimpanan.');
    $requestHash = hash('sha256', json_encode([$action, $id, $input, $fingerprint], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $lockName = participantWriteLockName($pdo);
    $statement = $pdo->prepare('SELECT GET_LOCK(?,5)'); $statement->execute([$lockName]);
    if ((int) $statement->fetchColumn() !== 1) throw new ParticipantManagementException('Perubahan peserta lain sedang diproses. Tunggu sebentar lalu coba lagi.');
    $originalMode = null;
    try {
        appRequireTransactionalTables($pdo);
        $originalMode = appUseStrictSqlMode($pdo);
        $pdo->beginTransaction();
        $statement = $pdo->prepare('SELECT role,nama_lengkap FROM users WHERE id=? FOR UPDATE'); $statement->execute([$actorId]);
        $actor = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$actor || !in_array($actor['role'], ['admin', 'pj_tahfidz'], true)) throw new ParticipantManagementException('Akun Anda tidak dapat mengelola peserta.');
        $statement = $pdo->prepare('SELECT * FROM participant_changes WHERE operation_id=? FOR UPDATE'); $statement->execute([$operationId]);
        if ($receipt = $statement->fetch(PDO::FETCH_ASSOC)) {
            if ((int) $receipt['actor_id'] !== $actorId || !hash_equals($receipt['request_hash'], $requestHash)) throw new ParticipantManagementException('Formulir ini sudah dipakai untuk perubahan lain. Buka formulir baru.');
            $pdo->commit();
            return json_decode($receipt['result_json'], true, 32, JSON_THROW_ON_ERROR);
        }
        $before = $id === null ? null : participantSnapshot($pdo, $id, true);
        if (!hash_equals(participantFingerprint($before), $fingerprint)) throw new ParticipantManagementException('Data berubah sejak formulir dibuka. Isian Anda belum disimpan. Buka formulir terbaru dan bandingkan kembali.');
        if ($action === 'archive') {
            if ($before['managed'] || $before['central']) throw new ParticipantManagementException('Arsip identitas pusat dilakukan melalui master keluarga atau Data Induk.');
            if (appRequestString($input, 'confirmed') !== '1') throw new ParticipantManagementException('Centang konfirmasi pengarsipan peserta.');
            $pdo->prepare('UPDATE wali_santri SET status_aktif=0 WHERE id=?')->execute([$id]);
            $pdo->prepare("UPDATE halaqoh_members SET archived_at=CURRENT_TIMESTAMP,archive_reason='MANUAL_ARCHIVE' WHERE wali_santri_id=? AND archived_at IS NULL")->execute([$id]);
            $archived = 0;
        } else {
            if ($id === null && dataIndukConfig()['enabled']) throw new ParticipantManagementException('Biodata baru dibuat melalui Data Induk. Jalankan sinkronisasi dari sumber tersebut.');
            $data = participantInput($input, $before);
            $values = $data['values'];
            if ($id === null) {
                $columns = implode(',', array_keys($values)); $placeholders = implode(',', array_fill(0, count($values), '?'));
                $pdo->prepare("INSERT INTO wali_santri($columns) VALUES($placeholders)")->execute(array_values($values));
                $id = participantCaptureInsertedId($pdo);
            } else {
                $changed = array_filter($values, static fn($value,$column) => $before['parent'][$column] !== $value, ARRAY_FILTER_USE_BOTH);
                if ($changed) {
                    $assignments = implode(',', array_map(static fn($column) => "$column=?", array_keys($changed)));
                    $pdo->prepare("UPDATE wali_santri SET $assignments WHERE id=?")->execute([...array_values($changed), $id]);
                }
            }
            if (!$data['locked']) {
                foreach ($data['children'] as $child) {
                    if ($child['id'] === null) $pdo->prepare('INSERT INTO santri_detail(wali_santri_id,nama_anak,kelas) VALUES(?,?,?)')->execute([$id, $child['nama_anak'], $child['kelas']]);
                    else {
                        $previous = array_column($before['children'],null,'id')[$child['id']];
                        if ($previous['nama_anak'] !== $child['nama_anak'] || $previous['kelas'] !== $child['kelas']) $pdo->prepare('UPDATE santri_detail SET nama_anak=?,kelas=? WHERE id=? AND wali_santri_id=?')->execute([$child['nama_anak'], $child['kelas'], $child['id'], $id]);
                    }
                }
                foreach ($before['children'] ?? [] as $child) {
                    if (!in_array((int) $child['id'], $data['remove'], true)) continue;
                    if (participantChildHasReferences($pdo, $child)) throw new ParticipantManagementException('Anak ' . $child['nama_anak'] . ' masih terhubung ke data lain. Penghapusan dibatalkan agar riwayat tetap utuh.');
                    $pdo->prepare('DELETE FROM santri_detail WHERE id=? AND wali_santri_id=?')->execute([$child['id'], $id]);
                }
            }
            if ($data['reactivate']) {
                if ($data['locked']) throw new ParticipantManagementException('Aktivasi identitas pusat dilakukan melalui sumbernya.');
                if (!waliHasActiveSchoolChild($pdo, $id)) throw new ParticipantManagementException('Untuk aktif kembali, peserta harus memiliki anak dengan kelas sekolah aktif.');
                $pdo->prepare('UPDATE wali_santri SET status_aktif=1 WHERE id=?')->execute([$id]);
            }
            $archived = archiveEligibleAlumni($pdo, [$id], 'AUTO_SAVE_PESERTA')['archived_wali'];
            participantAssignMemberships($pdo, $id, $data['memberships']);
        }
        $after = participantSnapshot($pdo, $id, true);
        $beforeJson = json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $afterJson = json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($beforeJson) + strlen($afterJson) > 8 * 1024 * 1024) throw new ParticipantManagementException('Riwayat peserta terlalu besar untuk formulir ini. Hubungi pengelola; perubahan belum disimpan.');
        $result = ['id' => $id, 'operation_id' => $operationId, 'action' => $action, 'auto_archived' => $archived];
        $pdo->prepare('INSERT INTO participant_changes(operation_id,actor_id,actor_name,action,wali_id,request_hash,before_json,after_json,result_json) VALUES(?,?,?,?,?,?,?,?,?)')->execute([
            $operationId, $actorId, $actor['nama_lengkap'], $action, $id, $requestHash, $beforeJson, $afterJson, json_encode($result, JSON_THROW_ON_ERROR)]);
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally {
        try { if ($originalMode !== null) $pdo->prepare('SET SESSION sql_mode=?')->execute([$originalMode]); }
        catch (Throwable $error) { error_log('Participant SQL mode restore: ' . $error->getMessage()); }
        try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
        catch (Throwable $error) { error_log('Participant lock release: ' . $error->getMessage()); }
    }
}
