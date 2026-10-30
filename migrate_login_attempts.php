<?php
/**
 * Migration: Create login_attempts table for brute-force protection
 * 
 * Jalankan melalui CLI setelah backup: php migrate_login_attempts.php.
 * Skrip tidak boleh dijalankan melalui HTTP.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once 'config/database.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            username VARCHAR(50) NOT NULL,
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_username (ip_address, username),
            INDEX idx_attempted_at (attempted_at)
        ) ENGINE=InnoDB
    ");
    echo "Tabel login_attempts siap. Skrip migrasi tetap tersimpan untuk audit.\n";
} catch (PDOException $e) {
    fwrite(STDERR, "Migrasi gagal: " . $e->getMessage() . "\n");
    exit(1);
}
