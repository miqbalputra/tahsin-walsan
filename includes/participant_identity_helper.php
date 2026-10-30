<?php
/** Read mappings directly, including when integration is disabled or its view is missing. */
function participantManagedIds(PDO $pdo): array
{
    $ids = [];
    foreach (['SELECT wali_santri_id FROM family_guardians',
        'SELECT s.wali_santri_id FROM santri_detail s JOIN family_legacy_children c ON c.santri_detail_id=s.id',
        "SELECT id FROM wali_santri WHERE data_induk_guardian_id IS NOT NULL AND data_induk_guardian_id<>''",
        "SELECT wali_santri_id FROM santri_detail WHERE data_induk_student_id IS NOT NULL AND data_induk_student_id<>''",
        "SELECT wali_santri_id FROM santri_detail WHERE data_induk_enrollment_id IS NOT NULL AND data_induk_enrollment_id<>''",
        "SELECT wali_santri_id FROM santri_detail WHERE data_induk_class_id IS NOT NULL AND data_induk_class_id<>''"] as $sql) {
        try {
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[(int) $id] = true;
        } catch (PDOException $error) {
            if (!in_array($error->getCode(), ['42S02', '42S22'], true)) throw $error;
        }
    }
    return $ids;
}

/** CSV and form writes use the same lock; row locks still protect other writers. */
function participantWriteLockName(PDO $pdo): string
{
    return 'participant-write:' . substr(hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
}

/** Old databases may reuse the highest deleted AUTO_INCREMENT after restart. */
function participantAssertAvailableId(PDO $pdo, int $id): void
{
    try {
        $statement=$pdo->prepare('SELECT 1 FROM participant_merge_aliases WHERE source_id=? FOR UPDATE'); $statement->execute([$id]);
        if ($statement->fetchColumn()) throw new InvalidArgumentException('Nomor peserta baru masih dipakai pengalihan ID lama. Perubahan dibatalkan; coba simpan kembali agar nomor berikutnya digunakan.');
    } catch (PDOException $error) {
        if ($error->getCode()!=='42S02') throw $error;
    }
}

/** Capture every new local guardian ID through one transactional alias guard. */
function participantCaptureInsertedId(PDO $pdo): int
{
    if (!$pdo->inTransaction()) throw new LogicException('Pemeriksaan ID peserta baru harus berada di dalam transaksi.');
    $id = (int) $pdo->lastInsertId();
    if ($id < 1) throw new RuntimeException('Database tidak memberikan ID peserta baru yang valid.');
    participantAssertAvailableId($pdo, $id);
    return $id;
}
