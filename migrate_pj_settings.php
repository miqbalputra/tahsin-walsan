<?php
require_once 'config/database.php';
try {
    // Ensure settings table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) UNIQUE NOT NULL,
        setting_value LONGTEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // Add PJ Tahfidz Name
    $stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('pj_tahfidz_name', ?)");
    $stmt->execute(['Admin Sekolah']);

    // Add PJ Tahfidz Title
    $stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('pj_tahfidz_title', ?)");
    $stmt->execute(['PJ Tahfidz']);

    echo "Additional settings added successfully.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
unlink(__FILE__);
