<?php
if (ob_get_level() === 0) {
    ob_start();
}

/**
 * Security and request defaults are configured in one place so every page
 * gets the same production behaviour without changing application flows.
 */
$request_is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $request_is_https ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

if (getenv('APP_PERF_LOG') === '1') {
    $request_started_at = hrtime(true);
    register_shutdown_function(static function () use ($request_started_at): void {
        $durationMs = round((hrtime(true) - $request_started_at) / 1e6, 2);
        error_log('[perf] ' . json_encode([
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'status' => http_response_code(),
            'duration_ms' => $durationMs,
            'memory_peak_bytes' => memory_get_peak_usage(true),
        ], JSON_UNESCAPED_SLASHES));
    });
}

// Set session lifetime to 30 days (2592000 seconds)
$session_lifetime = 30 * 24 * 60 * 60;

// ============================================================
// Custom session save path — mencegah cPanel cron menghapus session.
// Pada shared hosting, cron job cPanel menghapus session files
// berdasarkan gc_maxlifetime default (24 menit), mengabaikan
// ini_set() dari script. Dengan custom path, session kita aman.
// ============================================================
$session_dir = getenv('SESSION_SAVE_PATH') ?: (__DIR__ . '/../.sessions');
if (!is_dir($session_dir)) {
    mkdir($session_dir, 0700, true);
}
ini_set('session.save_path', $session_dir);

ini_set('session.gc_maxlifetime', $session_lifetime);
ini_set('session.cookie_lifetime', $session_lifetime);

// Kurangi frekuensi GC agar tidak terlalu sering (1% chance)
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);

// Set secure session cookie parameters before starting session
$is_https = $request_is_https;

if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => $session_lifetime,
        'path' => '/',
        'domain' => '',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
} else {
    session_set_cookie_params($session_lifetime, '/; HttpOnly; SameSite=Lax', '', $is_https, true);
}

session_start();

// Refresh session cookie on every request to extend the 30-day period
if (isset($_SESSION['user_id'])) {
    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => time() + $session_lifetime,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

/**
 * CSRF Protection Functions
 */
function generateCsrfToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token)
{
    if (!isset($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField()
{
    $token = generateCsrfToken();
    echo '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Security: Ensure session regeneration on login
 */
function regenerateSession($persistent = false)
{
    session_regenerate_id(true);
}

/**
 * Return a safe YYYY-MM-DD value or the supplied fallback.
 */
function normalizeDateInput($value, $fallback)
{
    $value = trim((string) $value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return $fallback;
    }

    return $date->format('Y-m-d');
}

/**
 * Log a production-safe error without exposing internals to the browser.
 */
function reportApplicationError(Throwable $error, $context = '')
{
    $prefix = $context !== '' ? '[' . $context . '] ' : '';
    error_log($prefix . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine());
}

function appBasePath()
{
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($scriptDir === '/' || $scriptDir === '.') {
        return '';
    }

    if (str_ends_with($scriptDir, '/ustadz')) {
        $scriptDir = substr($scriptDir, 0, -7);
    }

    return rtrim($scriptDir, '/');
}

function appUrl($path = '')
{
    return appBasePath() . '/' . ltrim($path, '/');
}

function redirectTo($path)
{
    header('Location: ' . appUrl($path));
    exit();
}

function roleHomePath($role = null)
{
    $role = $role ?? ($_SESSION['role'] ?? '');
    return $role === 'ustadz' ? 'ustadz/dashboard.php' : 'dashboard.php';
}

/**
 * Check if the user is logged in
 * If not, redirect to login page
 */
function checkLogin()
{
    if (!isset($_SESSION['user_id'])) {
        redirectTo('login.php');
    }
}

/**
 * Log activity to database
 */
function addLog($pdo, $action, $description = '')
{
    $user_id = $_SESSION['user_id'] ?? null;
    $username = $_SESSION['username'] ?? 'guest';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    try {
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, description, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $username, $action, $description, $ip]);
    } catch (Throwable $e) {
        // Activity logging must never break a valid user operation.
        reportApplicationError($e, 'activity-log');
    }
}

/**
 * Check if the user has a specific role
 * @param array $allowed_roles
 */
function checkRole($allowed_roles)
{
    checkLogin();
    if (!in_array($_SESSION['role'], $allowed_roles)) {
        redirectTo(roleHomePath());
    }
}

/**
 * ==============================================
 * Rate Limiting (Anti Brute-Force) Functions
 * ==============================================
 * Aturan: Maksimal 5 percobaan gagal dalam 15 menit.
 * Setelah itu, login diblokir sementara.
 */

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);

/**
 * Cek apakah IP/username sudah diblokir karena terlalu banyak percobaan gagal.
 * @return array ['blocked' => bool, 'remaining_attempts' => int, 'retry_after_minutes' => int]
 */
function checkLoginAttempts($pdo, $username, $ip)
{
    $lockout_seconds = LOGIN_LOCKOUT_MINUTES * 60;
    $since = date('Y-m-d H:i:s', time() - $lockout_seconds);

    // Hitung percobaan gagal dari IP ATAU username dalam periode lockout
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as attempt_count, MAX(attempted_at) as last_attempt
        FROM login_attempts 
        WHERE (ip_address = ? OR username = ?) 
        AND attempted_at > ?
    ");
    $stmt->execute([$ip, $username, $since]);
    $result = $stmt->fetch();

    $attempt_count = (int) $result['attempt_count'];
    $remaining = max(0, MAX_LOGIN_ATTEMPTS - $attempt_count);

    if ($attempt_count >= MAX_LOGIN_ATTEMPTS) {
        // Hitung sisa waktu blokir
        $last_attempt = strtotime($result['last_attempt']);
        $unlock_time = $last_attempt + $lockout_seconds;
        $retry_after = max(0, ceil(($unlock_time - time()) / 60));

        return [
            'blocked' => true,
            'remaining_attempts' => 0,
            'retry_after_minutes' => $retry_after
        ];
    }

    return [
        'blocked' => false,
        'remaining_attempts' => $remaining,
        'retry_after_minutes' => 0
    ];
}

/**
 * Catat percobaan login yang gagal.
 */
function recordFailedLogin($pdo, $username, $ip)
{
    $stmt = $pdo->prepare("
        INSERT INTO login_attempts (ip_address, username, attempted_at) 
        VALUES (?, ?, NOW())
    ");
    $stmt->execute([$ip, $username]);

    // Bersihkan data lama secara berkala (1% chance per request)
    if (mt_rand(1, 100) === 1) {
        cleanOldLoginAttempts($pdo);
    }
}

/**
 * Hapus catatan percobaan setelah login berhasil.
 */
function clearLoginAttempts($pdo, $username, $ip)
{
    $stmt = $pdo->prepare("
        DELETE FROM login_attempts 
        WHERE ip_address = ? OR username = ?
    ");
    $stmt->execute([$ip, $username]);
}

/**
 * Bersihkan data percobaan login yang sudah lebih dari 1 jam (housekeeping).
 */
function cleanOldLoginAttempts($pdo)
{
    $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
}
