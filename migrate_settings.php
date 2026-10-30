<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/config/database.php';
try {
    // Create settings table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) UNIQUE NOT NULL,
        setting_value LONGTEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // Read/seed the default only when absent; never replace an existing template.
    $exists = $pdo->prepare('SELECT 1 FROM settings WHERE setting_key=?');
    $exists->execute(['rapor_template']);
    if ($exists->fetchColumn() === false) {
        $templatePath = __DIR__ . '/templates/rapor_template.php';
        if (!is_file($templatePath) || !is_readable($templatePath)) throw new RuntimeException('Template rapor default tidak dapat dibaca. Nilai settings lama tetap utuh.');
        $templateContent = file_get_contents($templatePath);
        if (!is_string($templateContent) || trim($templateContent) === '') throw new RuntimeException('Template rapor default kosong. Nilai settings lama tetap utuh.');
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('rapor_template', ?) ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)");
        $stmt->execute([$templateContent]);
    }

    echo "Settings table created and template seeded successfully.";
} catch (Throwable $error) {
    error_log('Settings migration failed: ' . $error->getMessage());
    fwrite(STDERR, "Migrasi settings gagal. " . $error->getMessage() . "\n");
    exit(1);
}
