<?php
/**
 * Database Connection using PDO
 */

// Set timezone ke WIB agar semua fungsi date() menggunakan waktu Indonesia.
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Jakarta');

$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$db = getenv('DB_DATABASE') ?: 'presensi_tahsin';
$user = getenv('DB_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Log error internally
    error_log("Database Connection Error: " . $e->getMessage());
    // Security: Do not display detailed error to user
    die("Maaf, terjadi masalah koneksi ke database. Silakan coba beberapa saat lagi.");
}
