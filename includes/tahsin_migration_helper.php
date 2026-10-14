<?php
/** Additive, resumable MySQL/MariaDB migration. All existing attendance remains intact. */
function tahsinMigrationTables(): array
{
    return [
        'tahsin_tracking' => "CREATE TABLE IF NOT EXISTS tahsin_tracking (
            id TINYINT PRIMARY KEY, tracked_from DATETIME(6) NOT NULL, schema_version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'tahsin_roster_history' => "CREATE TABLE IF NOT EXISTS tahsin_roster_history (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, membership_id INT NOT NULL, wali_santri_id INT NOT NULL,
            halaqoh_id INT NOT NULL, ustadz_id INT NOT NULL, kategori VARCHAR(30) NOT NULL,
            nama_halaqoh VARCHAR(100) NOT NULL, nama_ustadz VARCHAR(100), ustadz_phone VARCHAR(20),
            valid_from DATETIME(6) NOT NULL, valid_until DATETIME(6) NULL,
            open_membership_id INT GENERATED ALWAYS AS (CASE WHEN valid_until IS NULL THEN membership_id ELSE NULL END) STORED,
            UNIQUE KEY uq_tahsin_open_member (open_membership_id),
            KEY idx_tahsin_history_wali (wali_santri_id, valid_from, valid_until),
            KEY idx_tahsin_history_owner (halaqoh_id, ustadz_id, valid_from)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'tahsin_verifications' => "CREATE TABLE IF NOT EXISTS tahsin_verifications (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, wali_santri_id INT NOT NULL, session_date DATE NOT NULL,
            fingerprint CHAR(64) NOT NULL, selected_presensi_id INT NULL, decided_by INT NOT NULL,
            note TEXT NOT NULL, decided_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            KEY idx_tahsin_verification_session (wali_santri_id, session_date, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'tahsin_capaian_awal' => "CREATE TABLE IF NOT EXISTS tahsin_capaian_awal (
            wali_santri_id INT PRIMARY KEY, jenis_materi VARCHAR(20) NOT NULL, jilid INT NULL,
            nama_surat VARCHAR(100) NULL, halaman VARCHAR(30) NOT NULL,
            updated_by INT NOT NULL, updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

function tahsinMigrationTriggers(): array
{
    $columns = 'membership_id, wali_santri_id, halaqoh_id, ustadz_id, kategori, nama_halaqoh, nama_ustadz, ustadz_phone, valid_from';
    $memberInsert = "INSERT INTO tahsin_roster_history ({$columns})
        SELECT NEW.id, NEW.wali_santri_id, NEW.halaqoh_id, h.ustadz_id, w.kategori, h.nama_halaqoh, u.nama_lengkap, u.no_hp, NOW(6)
        FROM wali_santri w JOIN halaqoh h ON h.id = NEW.halaqoh_id LEFT JOIN users u ON u.id = h.ustadz_id
        WHERE w.id = NEW.wali_santri_id AND w.status_aktif = 1 AND w.kategori IN ('reguler', 'askar') AND NEW.archived_at IS NULL;";
    $waliInsert = "INSERT INTO tahsin_roster_history ({$columns})
        SELECT hm.id, NEW.id, hm.halaqoh_id, h.ustadz_id, NEW.kategori, h.nama_halaqoh, u.nama_lengkap, u.no_hp, NOW(6)
        FROM halaqoh_members hm JOIN halaqoh h ON h.id = hm.halaqoh_id LEFT JOIN users u ON u.id = h.ustadz_id
        WHERE hm.wali_santri_id = NEW.id AND hm.archived_at IS NULL AND NEW.status_aktif = 1 AND NEW.kategori IN ('reguler', 'askar');";
    $halaqohInsert = "INSERT INTO tahsin_roster_history ({$columns})
        SELECT hm.id, hm.wali_santri_id, NEW.id, NEW.ustadz_id, w.kategori, NEW.nama_halaqoh, u.nama_lengkap, u.no_hp, NOW(6)
        FROM halaqoh_members hm JOIN wali_santri w ON w.id = hm.wali_santri_id LEFT JOIN users u ON u.id = NEW.ustadz_id
        WHERE hm.halaqoh_id = NEW.id AND hm.archived_at IS NULL AND w.status_aktif = 1 AND w.kategori IN ('reguler', 'askar');";
    $definitions = [
        'tahsin_hm_ai' => ['AFTER INSERT', 'halaqoh_members', $memberInsert],
        'tahsin_hm_au' => ['AFTER UPDATE', 'halaqoh_members', "IF NOT (NEW.wali_santri_id <=> OLD.wali_santri_id) OR NOT (NEW.halaqoh_id <=> OLD.halaqoh_id) OR NOT (NEW.archived_at <=> OLD.archived_at) THEN
            UPDATE tahsin_roster_history SET valid_until = NOW(6) WHERE membership_id = OLD.id AND valid_until IS NULL;
            {$memberInsert} END IF;"],
        'tahsin_hm_ad' => ['AFTER DELETE', 'halaqoh_members', 'UPDATE tahsin_roster_history SET valid_until = NOW(6) WHERE membership_id = OLD.id AND valid_until IS NULL;'],
        'tahsin_wali_au' => ['AFTER UPDATE', 'wali_santri', "IF NOT (NEW.status_aktif <=> OLD.status_aktif) OR NOT (NEW.kategori <=> OLD.kategori) THEN
            UPDATE tahsin_roster_history SET valid_until = NOW(6) WHERE wali_santri_id = OLD.id AND valid_until IS NULL;
            {$waliInsert} END IF;"],
        // Cascading foreign-key deletes do not execute child-table triggers.
        'tahsin_wali_bd' => ['BEFORE DELETE', 'wali_santri', 'UPDATE tahsin_roster_history SET valid_until = NOW(6) WHERE wali_santri_id = OLD.id AND valid_until IS NULL;'],
        'tahsin_halaqoh_au' => ['AFTER UPDATE', 'halaqoh', "IF NOT (NEW.ustadz_id <=> OLD.ustadz_id) OR NOT (NEW.nama_halaqoh <=> OLD.nama_halaqoh) THEN
            UPDATE tahsin_roster_history SET valid_until = NOW(6) WHERE halaqoh_id = OLD.id AND valid_until IS NULL;
            {$halaqohInsert} END IF;"],
        'tahsin_halaqoh_bd' => ['BEFORE DELETE', 'halaqoh', 'UPDATE tahsin_roster_history SET valid_until = NOW(6) WHERE halaqoh_id = OLD.id AND valid_until IS NULL;'],
    ];
    $triggers = [];
    foreach ($definitions as $name => [$event, $table, $body]) {
        $triggers[$name] = "CREATE TRIGGER {$name} {$event} ON {$table} FOR EACH ROW BEGIN
            IF EXISTS (SELECT 1 FROM tahsin_tracking WHERE id = 1) THEN {$body} END IF;
        END";
    }
    return $triggers;
}

function tahsinApplyMigration(PDO $pdo): array
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('Migrasi ini membutuhkan MySQL/MariaDB.');
    $pdo->exec("SET time_zone = '+07:00'");
    foreach (['archived_at' => 'DATETIME NULL', 'archive_reason' => 'VARCHAR(50) NULL'] as $column => $definition) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'halaqoh_members' AND column_name = ?");
        $stmt->execute([$column]);
        if (!(int) $stmt->fetchColumn()) $pdo->exec("ALTER TABLE halaqoh_members ADD COLUMN {$column} {$definition}");
    }
    $messages = [];
    foreach (tahsinMigrationTables() as $table => $sql) {
        $pdo->exec($sql);
        $messages[] = "OK tabel {$table}";
    }
    foreach (tahsinMigrationTriggers() as $name => $sql) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name = ?');
        $stmt->execute([$name]);
        if (!(int) $stmt->fetchColumn()) $pdo->exec($sql);
        $messages[] = "OK trigger {$name}";
    }
    $pdo->beginTransaction();
    try {
        // Prevent roster mutations from racing with the first baseline snapshot.
        $pdo->query('SELECT id FROM halaqoh FOR UPDATE')->fetchAll();
        $pdo->query('SELECT id FROM wali_santri FOR UPDATE')->fetchAll();
        $pdo->query('SELECT id FROM halaqoh_members FOR UPDATE')->fetchAll();
        if (!$pdo->query('SELECT tracked_from FROM tahsin_tracking WHERE id = 1 FOR UPDATE')->fetchColumn()) {
            $pdo->exec('INSERT INTO tahsin_tracking (id, tracked_from) VALUES (1, NOW(6))');
            $pdo->exec("INSERT INTO tahsin_roster_history
                (membership_id, wali_santri_id, halaqoh_id, ustadz_id, kategori, nama_halaqoh, nama_ustadz, ustadz_phone, valid_from)
                SELECT hm.id, w.id, h.id, h.ustadz_id, w.kategori, h.nama_halaqoh, u.nama_lengkap, u.no_hp, t.tracked_from
                FROM halaqoh_members hm JOIN wali_santri w ON w.id = hm.wali_santri_id JOIN halaqoh h ON h.id = hm.halaqoh_id
                LEFT JOIN users u ON u.id = h.ustadz_id JOIN tahsin_tracking t ON t.id = 1
                WHERE hm.archived_at IS NULL AND w.status_aktif = 1 AND w.kategori IN ('reguler', 'askar')");
            $messages[] = 'OK baseline roster; histori lama tidak ditebak';
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $messages;
}
