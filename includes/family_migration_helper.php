<?php
function familyApplyMigration(PDO $pdo): void
{
    $dataIndukRoster = false;
    try {
        $pdo->query('SELECT data_induk_student_id,status_roster FROM santri_detail LIMIT 0');
        $dataIndukRoster = true;
    } catch (PDOException $error) {
        if (!in_array($error->getCode(), ['42S02', '42S22'], true)) throw $error;
    }
    $legacyArchived = $dataIndukRoster
        ? "WHEN sd.data_induk_student_id IS NOT NULL AND sd.status_roster='archived' THEN 'archived' "
        : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS family_guardians (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_namespace VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
        source_parent_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
        wali_santri_id INT NOT NULL,
        guardian_type ENUM('father','mother','guardian') NOT NULL,
        source_name VARCHAR(100) NOT NULL, source_phone VARCHAR(20) NOT NULL, source_address TEXT NOT NULL,
        source_active TINYINT NOT NULL, account_active TINYINT NOT NULL, snapshot_date DATE NOT NULL,
        UNIQUE KEY uq_family_guardian_source(source_namespace,source_parent_id),
        UNIQUE KEY uq_family_guardian_wali(wali_santri_id),
        FOREIGN KEY(wali_santri_id) REFERENCES wali_santri(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS family_students (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_namespace VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
        source_student_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
        nis VARCHAR(64) NOT NULL, nama_anak VARCHAR(255) NOT NULL, kelas VARCHAR(100) NOT NULL,
        school_status ENUM('active','graduated','withdrawn','transferred','archived') NOT NULL, snapshot_date DATE NOT NULL,
        UNIQUE KEY uq_family_student_source(source_namespace,source_student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS family_relations (
        guardian_id BIGINT UNSIGNED NOT NULL, student_id BIGINT UNSIGNED NOT NULL,
        relationship ENUM('father','mother','guardian') NOT NULL,
        is_primary TINYINT NOT NULL DEFAULT 0, is_current TINYINT NOT NULL DEFAULT 1,
        PRIMARY KEY(guardian_id,student_id), KEY idx_family_student(student_id),
        FOREIGN KEY(guardian_id) REFERENCES family_guardians(id) ON DELETE RESTRICT,
        FOREIGN KEY(student_id) REFERENCES family_students(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS family_legacy_children (
        santri_detail_id INT PRIMARY KEY, student_id BIGINT UNSIGNED NOT NULL,
        FOREIGN KEY(santri_detail_id) REFERENCES santri_detail(id) ON DELETE RESTRICT,
        FOREIGN KEY(student_id) REFERENCES family_students(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS family_import_state (
        source_namespace VARCHAR(64) COLLATE utf8mb4_bin PRIMARY KEY, snapshot_date DATE NOT NULL,
        package_hash CHAR(64) NOT NULL, imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE OR REPLACE VIEW family_children AS
        SELECT g.wali_santri_id,CONVERT(s.nama_anak USING utf8mb4) COLLATE utf8mb4_unicode_ci AS nama_anak,
            CONVERT(s.kelas USING utf8mb4) COLLATE utf8mb4_unicode_ci AS kelas,
            CONVERT(s.school_status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS school_status,
            (CASE WHEN s.school_status='active' THEN 'active' ELSE 'archived' END) COLLATE utf8mb4_unicode_ci AS status_roster,
            s.source_namespace,s.source_student_id
        FROM family_relations r JOIN family_guardians g ON g.id=r.guardian_id JOIN family_students s ON s.id=r.student_id
        WHERE r.is_current=1
        UNION ALL
        SELECT sd.wali_santri_id,CONVERT(sd.nama_anak USING utf8mb4) COLLATE utf8mb4_unicode_ci,
            CONVERT(COALESCE(sd.kelas,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci,
            (CASE {$legacyArchived}WHEN LOWER(TRIM(COALESCE(sd.kelas,'')))='lulus' THEN 'graduated' ELSE 'active' END) COLLATE utf8mb4_unicode_ci,
            (CASE {$legacyArchived}WHEN LOWER(TRIM(COALESCE(sd.kelas,'')))='lulus' THEN 'archived' ELSE 'active' END) COLLATE utf8mb4_unicode_ci,
            NULL,NULL
        FROM santri_detail sd
        WHERE NOT EXISTS (SELECT 1 FROM family_legacy_children lc
            JOIN family_relations r ON r.student_id=lc.student_id
            JOIN family_guardians g ON g.id=r.guardian_id
            WHERE lc.santri_detail_id=sd.id AND g.wali_santri_id=sd.wali_santri_id)");
}
