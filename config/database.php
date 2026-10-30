<?php
require_once __DIR__ . '/../includes/security_helper.php';
require_once __DIR__ . '/../includes/database_connection_helper.php';
/**
 * Database Connection using PDO
 */

// Set timezone ke WIB agar semua fungsi date() menggunakan waktu Indonesia.
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Jakarta');

try {
    $databaseSettings = appDatabaseConnectionSettings();
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => $databaseSettings['timeout'],
    ];
    $pdo = new PDO($databaseSettings['dsn'], $databaseSettings['username'], $databaseSettings['password'], $options);
    // History and audit timestamps must use the same WIB clock as PHP.
    $pdo->exec("SET time_zone = '+07:00'");
} catch (\Throwable $e) {
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
