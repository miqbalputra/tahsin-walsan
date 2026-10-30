<?php
/** Stable family identities, separate from attendance and future Data Induk UUIDs. */
function familySchemaReady(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT wali_santri_id FROM family_children LIMIT 0');
        return true;
    } catch (PDOException $e) {
        if (in_array($e->getCode(), ['42S02', '42S22'], true)) return false;
        throw $e;
    }
}

/** Data Induk roster fields are optional on databases that have not applied its additive migration. */
function familyDataIndukRosterColumnsReady(PDO $pdo): bool
{
    static $readyByConnection = [];
    $key = spl_object_id($pdo);
    if (array_key_exists($key, $readyByConnection)) return $readyByConnection[$key];
    try {
        $pdo->query('SELECT data_induk_student_id,status_roster FROM santri_detail LIMIT 0');
        return $readyByConnection[$key] = true;
    } catch (PDOException $error) {
        if (in_array($error->getCode(), ['42S02', '42S22'], true)) return $readyByConnection[$key] = false;
        throw $error;
    }
}

/** Only these fixed identifiers may be interpolated into read queries. */
function familyChildrenTable(PDO $pdo): string
{
    return familySchemaReady($pdo) ? 'family_children' : 'santri_detail';
}

function familyIsManaged(PDO $pdo, int $waliId): bool
{
    if (!familySchemaReady($pdo)) return false;
    $stmt = $pdo->prepare('SELECT 1 FROM family_guardians WHERE wali_santri_id=?');
    $stmt->execute([$waliId]);
    return (bool) $stmt->fetchColumn();
}

function familyAssertLocalChildrenWritable(PDO $pdo, array $childIds): void
{
    if (!$childIds || !familySchemaReady($pdo)) return;
    $ids = array_values(array_unique(array_map('intval', $childIds)));
    $stmt = $pdo->prepare('SELECT 1 FROM santri_detail s JOIN family_guardians g ON g.wali_santri_id=s.wali_santri_id WHERE s.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') LIMIT 1');
    $stmt->execute($ids);
    if ($stmt->fetchColumn()) throw new RuntimeException('Kelas anak ini dikelola master keluarga. Perbarui master dan impor snapshot terbaru melalui menu Keluarga.');
}

function familyNormalize(string $value): string
{
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    return preg_replace('/[^\pL\pN]/u', '', trim($value)) ?? '';
}

function familyPhone(string $value): string
{
    $value = preg_replace('/\D/', '', $value) ?? '';
    return str_starts_with($value, '62') ? '0' . substr($value, 2) : $value;
}

/** One row per child; each parent has a separate attendance identity. */
function familyFetchStudents(PDO $pdo, int $limit = 50, int $offset = 0, string $search = ''): array
{
    if (!familySchemaReady($pdo)) return ['students' => [], 'total' => 0];
    $limit = max(1, min(100, $limit)); $offset = max(0, $offset);
    $where = $search === '' ? '' : ' WHERE (s.nama_anak LIKE ? OR s.nis LIKE ? OR EXISTS(SELECT 1 FROM family_relations r JOIN family_guardians g ON g.id=r.guardian_id WHERE r.student_id=s.id AND r.is_current=1 AND g.source_name LIKE ?))';
    $params = $search === '' ? [] : array_fill(0, 3, '%' . $search . '%');
    $count = $pdo->prepare('SELECT COUNT(*) FROM family_students s' . $where); $count->execute($params);
    $stmt = $pdo->prepare('SELECT s.* FROM family_students s' . $where . ' ORDER BY s.nama_anak,s.id LIMIT ' . $limit . ' OFFSET ' . $offset); $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($students) {
        $ids = array_column($students, 'id');
        $parents = $pdo->prepare('SELECT r.student_id,r.relationship,r.is_primary,g.source_namespace,g.source_parent_id,g.wali_santri_id,g.source_name,g.guardian_type FROM family_relations r JOIN family_guardians g ON g.id=r.guardian_id WHERE r.is_current=1 AND r.student_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY g.guardian_type,g.source_name');
        $parents->execute($ids); $byStudent = [];
        foreach ($parents->fetchAll(PDO::FETCH_ASSOC) as $parent) $byStudent[$parent['student_id']][] = $parent;
        foreach ($students as &$student) $student['guardians'] = $byStudent[$student['id']] ?? [];
        unset($student);
    }
    return ['students' => $students, 'total' => (int) $count->fetchColumn()];
}

function familyValidatePackage(array $data): array
{
    if (($data['format'] ?? '') !== 'tahsin-family-v1'
        || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $data['source'] ?? '')
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data['snapshot_date'] ?? '')
        || date('Y-m-d', strtotime($data['snapshot_date'])) !== $data['snapshot_date']) {
        throw new RuntimeException('Format, sumber, atau tanggal paket keluarga tidak valid.');
    }
    $indexed = [];
    foreach (['guardians', 'students', 'links'] as $kind) {
        if (!isset($data[$kind]) || !is_array($data[$kind]) || !array_is_list($data[$kind]) || count($data[$kind]) > 10000) {
            throw new RuntimeException('Daftar ' . $kind . ' tidak valid atau terlalu besar.');
        }
        $indexed[$kind] = [];
        foreach ($data[$kind] as $row) {
            if (!is_array($row)) throw new RuntimeException('Baris keluarga tidak valid.');
            if ($kind === 'links') {
                foreach (['guardian_id', 'student_id'] as $field) {
                    if (!is_string($row[$field] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $row[$field])) throw new RuntimeException('ID relasi tidak valid.');
                }
                $key = $row['guardian_id'] . ':' . $row['student_id'];
                if (!isset($indexed['guardians'][$row['guardian_id']], $indexed['students'][$row['student_id']])) throw new RuntimeException('Relasi merujuk wali/anak yang tidak ada.');
                if (($row['relationship'] ?? '') !== $indexed['guardians'][$row['guardian_id']]['type']) throw new RuntimeException('Tipe relasi berbeda dari tipe wali.');
                if (!is_bool($row['primary_contact'] ?? null)) throw new RuntimeException('Primary contact harus boolean.');
            } else {
                $key = $row['source_id'] ?? '';
                if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $key)) throw new RuntimeException('ID sumber tidak valid.');
                $fields = $kind === 'guardians' ? ['name' => 100, 'phone' => 20, 'address' => 10000] : ['name' => 255, 'nis' => 64, 'class_name' => 100];
                foreach ($fields as $field => $max) {
                    $v = $row[$field] ?? null;
                    if (!is_string($v) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $v) || (function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v)) > $max) throw new RuntimeException('Kolom ' . $field . ' tidak valid pada ID ' . $key . '.');
                }
                if (trim($row['name']) === '') throw new RuntimeException('Nama wajib diisi.');
                if ($kind === 'guardians' && (!in_array($row['type'] ?? '', ['father', 'mother', 'guardian'], true) || !is_bool($row['active'] ?? null) || !is_bool($row['account_active'] ?? null))) throw new RuntimeException('Tipe/status wali tidak valid.');
                if ($kind === 'students' && !in_array($row['status'] ?? '', ['active', 'graduated', 'withdrawn', 'transferred', 'archived'], true)) throw new RuntimeException('Status anak tidak valid.');
            }
            if (isset($indexed[$kind][$key])) throw new RuntimeException('ID/relasi ganda: ' . $key);
            $indexed[$kind][$key] = $row;
        }
    }
    if (!$indexed['guardians'] || !$indexed['students']) throw new RuntimeException('Paket keluarga kosong.');
    return $indexed;
}

/** Group siblings by their exact source parent set for an understandable import preview. */
function familyImportPreviewRows(array $rows): array
{
    $relationsByStudent = [];
    foreach ($rows['links'] as $link) {
        $type = $rows['guardians'][$link['guardian_id']]['type'];
        $relationsByStudent[$link['student_id']][$type][] = (string) $link['guardian_id'];
    }

    $families = [];
    foreach ($rows['students'] as $studentId => $student) {
        $parents = ['father' => [], 'mother' => [], 'guardian' => []];
        foreach ($relationsByStudent[$studentId] ?? [] as $type => $ids) {
            $parents[$type] = array_values(array_unique($ids));
            sort($parents[$type], SORT_NATURAL);
        }
        $familyKey = $parents;
        // With no known parent, there is no evidence that two children are siblings.
        if (!$parents['father'] && !$parents['mother'] && !$parents['guardian']) $familyKey['unlinked_student'] = (string) $studentId;
        $key = json_encode($familyKey, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!isset($families[$key])) {
            $families[$key] = [
                'father_ids' => $parents['father'],
                'mother_ids' => $parents['mother'],
                'guardian_ids' => $parents['guardian'],
                'students' => [],
            ];
        }
        $families[$key]['students'][] = [
            'source_id' => (string) $studentId,
            'name' => $student['name'],
            'nis' => $student['nis'],
            'class_name' => $student['class_name'],
            'status' => $student['status'],
        ];
    }

    $families = array_values($families);
    foreach ($families as &$family) {
        usort($family['students'], static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    }
    unset($family);
    usort($families, static fn(array $a, array $b): int => strnatcasecmp($a['students'][0]['name'] ?? '', $b['students'][0]['name'] ?? ''));
    return $families;
}

/** Preview performs no writes. Name-only and ambiguous matches require a choice. */
function familyImportPlan(PDO $pdo, array $data, array $choices = []): array
{
    $rows = familyValidatePackage($data);
    $ready = familySchemaReady($pdo);
    $state = false;
    if ($ready) {
        $stmt = $pdo->prepare('SELECT * FROM family_import_state WHERE source_namespace=?');
        $stmt->execute([$data['source']]);
        $state = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    $digest = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    if ($state && ($state['snapshot_date'] > $data['snapshot_date'] || ($state['snapshot_date'] === $data['snapshot_date'] && $state['package_hash'] !== $digest))) throw new RuntimeException('Snapshot lebih lama atau isi berbeda pada tanggal yang sama. Gunakan ekspor bertanggal lebih baru.');
    $wali = $pdo->query('SELECT id,nama_bapak,no_hp FROM wali_santri ORDER BY id')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $children = $pdo->query('SELECT id,wali_santri_id,nama_anak FROM santri_detail')->fetchAll(PDO::FETCH_ASSOC);
    $kids = [];
    foreach ($children as $child) $kids[(int) $child['wali_santri_id']][familyNormalize($child['nama_anak'])] = true;
    $linked = [];
    foreach ($rows['links'] as $link) $linked[$link['guardian_id']][] = $rows['students'][$link['student_id']];
    $mapped = []; $claims = [];
    if ($ready) foreach ($pdo->query('SELECT * FROM family_guardians')->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $claims[(int) $m['wali_santri_id']] = $m['source_namespace'] . ':' . $m['source_parent_id'];
        if ($m['source_namespace'] === $data['source']) $mapped[$m['source_parent_id']] = $m;
    }
    foreach ($mapped as $sourceId => $m) if (!isset($rows['guardians'][$sourceId])) throw new RuntimeException('Snapshot tidak lengkap: wali sumber yang pernah diimpor hilang. Sertakan juga identitas yang diarsipkan.');
    if ($ready) {
        $known = $pdo->prepare('SELECT source_student_id FROM family_students WHERE source_namespace=?');
        $known->execute([$data['source']]);
        foreach ($known->fetchAll(PDO::FETCH_COLUMN) as $sourceId) if (!isset($rows['students'][$sourceId])) throw new RuntimeException('Snapshot tidak lengkap: anak sumber yang pernah diimpor hilang.');
    }
    $plan = ['guardians' => [], 'families' => familyImportPreviewRows($rows), 'conflicts' => 0, 'create' => 0, 'reuse' => 0, 'students' => count($rows['students']), 'links' => count($rows['links']), 'hash' => $digest, 'schema_ready' => $ready];
    foreach ($rows['guardians'] as $id => $g) {
        $matches = []; $strong = [];
        foreach ($wali as $localId => $w) {
            $nameMatch = familyNormalize($w['nama_bapak']) === familyNormalize($g['name']);
            $phoneMatch = familyPhone($g['phone']) !== '' && familyPhone((string) $w['no_hp']) === familyPhone($g['phone']);
            $childMatch = false;
            foreach ($linked[$id] ?? [] as $child) if (isset($kids[$localId][familyNormalize($child['name'])])) $childMatch = true;
            if ($nameMatch || ($g['type'] === 'father' && $phoneMatch && $childMatch)) $matches[(int) $localId] = $w['nama_bapak'];
            if ($nameMatch && $phoneMatch && $childMatch) $strong[] = (int) $localId;
        }
        $target = null; $reason = ''; $error = '';
        if (isset($mapped[$id])) {
            $target = (int) $mapped[$id]['wali_santri_id']; $reason = 'ID sumber sudah terhubung';
            if ($mapped[$id]['guardian_type'] !== $g['type']) $error = 'Tipe wali berubah; tinjau identitas sumber.';
            if (array_key_exists($id, $choices) && (string) $choices[$id] !== (string) $target) $error = 'Pemetaan ID sumber yang sudah tersimpan tidak boleh dipindahkan.';
        } elseif (array_key_exists($id, $choices)) {
            if ($choices[$id] === 'new') { $target = 0; $reason = 'Buat baru dipilih saat pratinjau'; }
            elseif (ctype_digit((string) $choices[$id]) && isset($wali[(int) $choices[$id]])) { $target = (int) $choices[$id]; $reason = 'ID lokal dipilih saat pratinjau'; }
            else $error = 'Pilihan ID lokal tidak valid.';
        } elseif (count($strong) === 1) { $target = $strong[0]; $reason = 'Nama, nomor HP, dan anak sama'; }
        elseif (!$matches) { $target = 0; $reason = 'Wali baru'; }
        else $error = 'Perlu pilihan: kecocokan belum pasti atau lebih dari satu kandidat.';
        $claim = $data['source'] . ':' . $id;
        if ($target && isset($claims[$target]) && $claims[$target] !== $claim) $error = 'ID lokal sudah diklaim wali lain. Dua wali tidak boleh digabung.';
        if ($target && !$error) $claims[$target] = $claim;
        $plan['guardians'][(string) $id] = ['source_id' => (string) $id, 'name' => $g['name'], 'type' => $g['type'], 'target_id' => $target, 'candidates' => $matches, 'reason' => $reason, 'error' => $error];
        if ($error) $plan['conflicts']++; elseif ($target) $plan['reuse']++; else $plan['create']++;
    }
    return $plan;
}

/** Atomic and serialized; never updates presensi, memberships, or legacy biodata. */
function familyImportApply(PDO $pdo, array $data, array $choices = []): array
{
    if (filter_var(getenv('DATA_INDUK_ENABLED'), FILTER_VALIDATE_BOOL)) throw new RuntimeException('Sinkronisasi Data Induk masih aktif. Petakan sumber identitas sebelum mengimpor snapshot keluarga agar kedua sumber tidak saling menimpa.');
    if (!familySchemaReady($pdo)) throw new RuntimeException('Jalankan migrate_family.php --apply terlebih dahulu.');
    $lockName = 'tahsin_family_' . substr(hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
    $lock = $pdo->prepare('SELECT GET_LOCK(?,10)'); $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) throw new RuntimeException('Impor lain sedang berjalan. Coba kembali.');
    try {
        $pdo->beginTransaction();
        // Recheck against current DB, never trust IDs or candidate lists from a browser.
        $pdo->query('SELECT id FROM wali_santri ORDER BY id FOR UPDATE')->fetchAll();
        $pdo->query('SELECT id FROM santri_detail ORDER BY id FOR UPDATE')->fetchAll();
        $plan = familyImportPlan($pdo, $data, $choices);
        if ($plan['conflicts']) throw new RuntimeException('Masih ada ' . $plan['conflicts'] . ' pemetaan wali yang perlu ditinjau. Tidak ada data diimpor.');
        $rows = familyValidatePackage($data);
        $source = $data['source']; $date = $data['snapshot_date'];
        $guardianIds = []; $studentIds = [];
        $activeChildren = [];
        foreach ($rows['links'] as $link) if ($rows['students'][$link['student_id']]['status'] === 'active') $activeChildren[$link['guardian_id']] = true;
        $insertWali = $pdo->prepare('INSERT INTO wali_santri(nama_bapak,no_hp,alamat,kategori,status_aktif) VALUES(?,?,?,?,?)');
        $upsertGuardian = $pdo->prepare('INSERT INTO family_guardians(source_namespace,source_parent_id,wali_santri_id,guardian_type,source_name,source_phone,source_address,source_active,account_active,snapshot_date) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE source_name=VALUES(source_name),source_phone=VALUES(source_phone),source_address=VALUES(source_address),source_active=VALUES(source_active),account_active=VALUES(account_active),snapshot_date=VALUES(snapshot_date)');
        $getGuardian = $pdo->prepare('SELECT id,wali_santri_id FROM family_guardians WHERE source_namespace=? AND source_parent_id=?');
        foreach ($rows['guardians'] as $id => $g) {
            $waliId = $plan['guardians'][$id]['target_id'];
            if (!$waliId) {
                $insertWali->execute([$g['name'], $g['phone'], $g['address'], 'reguler', $g['active'] && $g['account_active'] && isset($activeChildren[$id]) ? 1 : 0]);
                $waliId = (int) $pdo->lastInsertId();
            }
            $upsertGuardian->execute([$source, (string) $id, $waliId, $g['type'], $g['name'], $g['phone'], $g['address'], (int) $g['active'], (int) $g['account_active'], $date]);
            $getGuardian->execute([$source, (string) $id]); $guardianIds[$id] = $getGuardian->fetch(PDO::FETCH_ASSOC);
        }
        $upsertStudent = $pdo->prepare('INSERT INTO family_students(source_namespace,source_student_id,nis,nama_anak,kelas,school_status,snapshot_date) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nis=VALUES(nis),nama_anak=VALUES(nama_anak),kelas=VALUES(kelas),school_status=VALUES(school_status),snapshot_date=VALUES(snapshot_date)');
        $getStudent = $pdo->prepare('SELECT id FROM family_students WHERE source_namespace=? AND source_student_id=?');
        foreach ($rows['students'] as $id => $s) {
            $upsertStudent->execute([$source, (string) $id, $s['nis'], $s['name'], $s['class_name'], $s['status'], $date]);
            $getStudent->execute([$source, (string) $id]); $studentIds[$id] = (int) $getStudent->fetchColumn();
        }
        // This is a full source snapshot. Removed relationships are retained as inactive.
        $pdo->prepare('UPDATE family_relations r JOIN family_guardians g ON g.id=r.guardian_id SET r.is_current=0 WHERE g.source_namespace=?')->execute([$source]);
        $upsertLink = $pdo->prepare('INSERT INTO family_relations(guardian_id,student_id,relationship,is_primary,is_current) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE relationship=VALUES(relationship),is_primary=VALUES(is_primary),is_current=1');
        foreach ($rows['links'] as $link) $upsertLink->execute([$guardianIds[$link['guardian_id']]['id'], $studentIds[$link['student_id']], $link['relationship'], (int) $link['primary_contact']]);
        // Deduplicate displayed legacy children only when name + known parent identify one child.
        $legacy = $pdo->query('SELECT id,wali_santri_id,nama_anak FROM santri_detail')->fetchAll(PDO::FETCH_ASSOC);
        $byParent = [];
        foreach ($rows['links'] as $link) {
            $wid = $guardianIds[$link['guardian_id']]['wali_santri_id'];
            $byParent[$wid][familyNormalize($rows['students'][$link['student_id']]['name'])][] = $studentIds[$link['student_id']];
        }
        $bridge = $pdo->prepare('INSERT INTO family_legacy_children(santri_detail_id,student_id) VALUES(?,?) ON DUPLICATE KEY UPDATE student_id=VALUES(student_id)');
        foreach ($legacy as $child) {
            $candidates = array_unique($byParent[$child['wali_santri_id']][familyNormalize($child['nama_anak'])] ?? []);
            if (count($candidates) === 1) $bridge->execute([$child['id'], reset($candidates)]);
        }
        $pdo->prepare('INSERT INTO family_import_state(source_namespace,snapshot_date,package_hash) VALUES(?,?,?) ON DUPLICATE KEY UPDATE snapshot_date=VALUES(snapshot_date),package_hash=VALUES(package_hash),imported_at=CURRENT_TIMESTAMP')->execute([$source, $date, $plan['hash']]);
        $pdo->commit();
        return ['created' => $plan['create'], 'reused' => $plan['reuse'], 'students' => $plan['students'], 'links' => $plan['links']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}
