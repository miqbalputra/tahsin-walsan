<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/config/database.php';

try {
    // 1. Create holidays table
    $pdo->exec("CREATE TABLE IF NOT EXISTS holidays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tanggal DATE NOT NULL,
        keterangan VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_tanggal (tanggal)
    ) ENGINE=InnoDB");

    echo "Table 'holidays' created successfully.\n";

    // 2. Add setting for holiday cleanup if not exists
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('libur_cleaning_status', 'ready') ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)");
    $stmt->execute();

    echo "Settings updated.\n";

} catch (Throwable $error) {
    error_log('Holiday migration failed: ' . $error->getMessage());
    fwrite(STDERR, "Migrasi libur gagal. " . $error->getMessage() . "\n");
    exit(1);
}
