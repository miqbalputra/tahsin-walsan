<?php
/**
 * Migration: Create login_attempts table for brute-force protection
 * 
 * Jalankan file ini SEKALI di browser untuk membuat tabel.
 * Setelah berhasil, file ini bisa dihapus.
 */
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
    echo "<h2 style='color:green'>✅ Tabel login_attempts berhasil dibuat!</h2>";
    echo "<p>Silakan hapus file <code>migrate_login_attempts.php</code> setelah migrasi selesai.</p>";
} catch (PDOException $e) {
    echo "<h2 style='color:red'>❌ Gagal: " . $e->getMessage() . "</h2>";
}
