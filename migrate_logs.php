<?php
require_once 'config/database.php';
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
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
unlink(__FILE__);
