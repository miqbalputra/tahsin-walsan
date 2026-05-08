<?php
require_once 'config/database.php';
try {
    // Create settings table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) UNIQUE NOT NULL,
        setting_value LONGTEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // Seed the default template if not exists
    $template_content = file_get_contents('templates/rapor_template.php');
    $stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('rapor_template', ?)");
    $stmt->execute([$template_content]);

    echo "Settings table created and template seeded successfully.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
unlink(__FILE__);
