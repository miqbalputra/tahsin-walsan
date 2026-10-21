<?php
/** Additive migration; no attendance, participant or history rows are rewritten. */
function autosaveMigrationTables(): array
{
    return [
        'tahsin_autosave_drafts' => "CREATE TABLE IF NOT EXISTS tahsin_autosave_drafts (
            user_id INT NOT NULL, halaqoh_id INT NOT NULL, session_date DATE NOT NULL, wali_id INT NOT NULL,
            revision BIGINT NOT NULL DEFAULT 0, payload_json TEXT NOT NULL, official_revision CHAR(64) NOT NULL,
            context_revision CHAR(64) NOT NULL, state VARCHAR(20) NOT NULL DEFAULT 'draft',
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (user_id, halaqoh_id, session_date, wali_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'tahsin_autosave_operations' => "CREATE TABLE IF NOT EXISTS tahsin_autosave_operations (
            user_id INT NOT NULL, operation_id VARCHAR(80) NOT NULL, request_hash CHAR(64) NOT NULL,
            response_json MEDIUMTEXT NOT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (user_id, operation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'tahsin_autosave_revisions' => "CREATE TABLE IF NOT EXISTS tahsin_autosave_revisions (
            wali_id INT NOT NULL, session_date DATE NOT NULL, revision BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (wali_id, session_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'tahsin_autosave_sessions' => "CREATE TABLE IF NOT EXISTS tahsin_autosave_sessions (
            halaqoh_id INT NOT NULL, session_date DATE NOT NULL, completed_by INT NULL,
            completed_at DATETIME(6) NULL, fingerprint CHAR(64) NULL,
            PRIMARY KEY (halaqoh_id, session_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

function autosaveMigrationTriggers(): array
{
    $bump = static fn(string $r): string => "INSERT INTO tahsin_autosave_revisions (wali_id, session_date, revision)
        VALUES ({$r}.wali_santri_id, DATE_SUB({$r}.tanggal, INTERVAL (DAYOFWEEK({$r}.tanggal)-1) DAY), 1)
        ON DUPLICATE KEY UPDATE revision=revision+1;";
    return [
        'tahsin_autosave_ai' => 'CREATE TRIGGER tahsin_autosave_ai AFTER INSERT ON presensi FOR EACH ROW BEGIN ' . $bump('NEW') . ' END',
        'tahsin_autosave_au' => 'CREATE TRIGGER tahsin_autosave_au AFTER UPDATE ON presensi FOR EACH ROW BEGIN ' . $bump('OLD') . $bump('NEW') . ' END',
        'tahsin_autosave_ad' => 'CREATE TRIGGER tahsin_autosave_ad AFTER DELETE ON presensi FOR EACH ROW BEGIN ' . $bump('OLD') . ' END',
    ];
}

function autosaveApplyMigration(PDO $pdo): array
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('MySQL/MariaDB diperlukan.');
    $messages = [];
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='halaqoh' AND column_name='program_code'");
    if (!(int) $stmt->fetchColumn()) $pdo->exec("ALTER TABLE halaqoh ADD COLUMN program_code VARCHAR(20) NOT NULL DEFAULT 'bapak'");
    foreach (autosaveMigrationTables() as $name => $sql) { $pdo->exec($sql); $messages[] = "OK {$name}"; }
    foreach (autosaveMigrationTriggers() as $name => $sql) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name=?');
        $stmt->execute([$name]);
        if (!(int) $stmt->fetchColumn()) $pdo->exec($sql);
        $messages[] = "OK {$name}";
    }
    return $messages;
}
