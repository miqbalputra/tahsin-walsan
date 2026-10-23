<?php
/** Read mappings directly, including when integration is disabled or its view is missing. */
function participantManagedIds(PDO $pdo): array
{
    $ids = [];
    foreach (['SELECT wali_santri_id FROM family_guardians',
        "SELECT id FROM wali_santri WHERE data_induk_guardian_id IS NOT NULL AND data_induk_guardian_id<>''",
        "SELECT wali_santri_id FROM santri_detail WHERE data_induk_student_id IS NOT NULL AND data_induk_student_id<>''"] as $sql) {
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
