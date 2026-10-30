<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/config/database.php';
try {
    // Ensure settings table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) UNIQUE NOT NULL,
        setting_value LONGTEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // Add PJ Tahfidz Name
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('pj_tahfidz_name', ?) ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)");
    $stmt->execute(['Admin Sekolah']);

    // Add PJ Tahfidz Title
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('pj_tahfidz_title', ?) ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)");
    $stmt->execute(['PJ Tahfidz']);

    echo "Additional settings added successfully.";
} catch (Throwable $error) {
    error_log('PJ settings migration failed: ' . $error->getMessage());
    fwrite(STDERR, "Migrasi pengaturan PJ gagal. " . $error->getMessage() . "\n");
    exit(1);
}
