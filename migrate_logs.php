<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/config/database.php';
try {
    $sql = "CREATE TABLE IF NOT EXISTS activity_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        username VARCHAR(50),
        action VARCHAR(100) NOT NULL,
        description TEXT,
        ip_address VARCHAR(45),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB";

    $pdo->exec($sql);
    echo "Activity Logs table created successfully.";
} catch (Throwable $error) {
    error_log('Activity log migration failed: ' . $error->getMessage());
    fwrite(STDERR, "Migrasi log aktivitas gagal. " . $error->getMessage() . "\n");
    exit(1);
}
