<?php
require_once __DIR__ . '/../includes/security_helper.php';
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
$connectTimeout = max(1, (int) (getenv('DB_CONNECT_TIMEOUT') ?: 5));

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT => $connectTimeout,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    // History and audit timestamps must use the same WIB clock as PHP.
    $pdo->exec("SET time_zone = '+07:00'");
} catch (\PDOException $e) {
    // Log error internally
    error_log("Database Connection Error: " . $e->getMessage());
    // A failed database connection must never be mistaken for a healthy HTTP 200.
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Layanan database belum tersedia. Periksa koneksi sebelum menjalankan kembali perintah.\n");
        exit(1);
    }
    http_response_code(503);
    header('Retry-After: 30');
    header('Cache-Control: no-store, private');
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (basename($script) === 'health.php' || preg_match('~(?:^|/)api(?:/|-)~', $script)
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(basename($script) === 'health.php' ? ['ok' => false] : [
            'status' => 'error', 'message' => 'Layanan data belum tersedia. Tunggu sebentar lalu coba lagi.',
        ]);
        exit;
    }
    appRequestError(503, 'Layanan sedang tidak tersedia', 'Data belum dapat diakses. Permintaan ini belum diproses. Tunggu sebentar lalu buka kembali halaman.');
}
