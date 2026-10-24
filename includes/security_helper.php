<?php
require_once __DIR__.'/asset_helper.php';
/** Shared request primitives. No database connection or session side effects. */
const APP_USER_ROLES = ['admin', 'pj_tahfidz', 'kepsek', 'ustadz'];

function appEscape($value): string
{
    return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function appJsonAttribute($value): string
{
    return appEscape(json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
        | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
}

function appTextLength(string $value): int
{
    $length = preg_match_all('/./us', $value);
    if ($length === false) throw new InvalidArgumentException('Gunakan teks yang valid pada formulir.');
    return $length;
}

function appRequestString(array $input, string $key, string $default = '', bool $trim = true): string
{
    if (!isset($input[$key])) return $default;
    if (!is_string($input[$key])) {
        throw new InvalidArgumentException('Format isian tidak valid. Buka kembali formulir lalu coba lagi.');
    }
    return $trim ? trim($input[$key]) : $input[$key];
}

/** Return an actionable error page, including when a layout was already buffered. */
function appRequestError(int $status, string $title, string $message): never
{
    if (ob_get_level() > 0) ob_clean();
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    $returnPath = $_SERVER['SCRIPT_NAME'] ?? '/login.php';
    if (!is_string($returnPath) || !preg_match('~^/[a-zA-Z0-9_./-]+\.php$~D', $returnPath)) {
        $returnPath = function_exists('appUrl') ? appUrl('login.php') : '/login.php';
    }
    $css = appAssetUrl('assets/css/app.css');
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . appEscape($title) . ' — Presensi Tahsin</title>'
        . '<link rel="stylesheet" href="' . appEscape($css) . '"></head>'
        . '<body class="bg-slate-50 text-slate-800"><main class="max-w-xl mx-auto p-6">'
        . '<section role="alert" class="bg-white rounded-2xl p-6 border border-slate-200">'
        . '<h1 class="text-2xl font-bold mb-4">' . appEscape($title) . '</h1>'
        . '<p class="mb-6">' . appEscape($message) . '</p>'
        . '<a class="inline-flex rounded-xl bg-blue-600 text-white px-5 py-3" href="'
        . appEscape($returnPath) . '">Buka kembali halaman</a></section></main></body></html>';
    exit;
}

function requireValidCsrfToken($token): void
{
    if (!verifyCsrfToken($token)) {
        appRequestError(403, 'Formulir perlu dibuka kembali',
            'Sesi keamanan formulir berubah atau kedaluwarsa. Permintaan ini belum diproses. Buka kembali halaman, periksa isian, lalu kirim ulang.');
    }
}

/**
 * Rate limiter berbasis file untuk API tanpa sesi (hermes/n8n). Cukup untuk
 * membatasi abuse per IP; bukan pengganti proteksi DDoS level infrastruktur.
 */
function apiRateLimitAllow(string $scope, int $maxRequests = 120, int $windowSeconds = 60): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!is_string($ip) || $ip === '') $ip = 'unknown';
    $directory = ini_get('session.save_path') ?: sys_get_temp_dir();
    if (!is_dir($directory)) $directory = sys_get_temp_dir();
    $path = $directory . '/api-rate-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $scope . '-' . $ip) . '.json';
    $now = time();
    $hits = [];
    if (is_file($path)) {
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (is_array($decoded)) {
            $hits = array_values(array_filter($decoded, static fn($stamp) => is_int($stamp) && $stamp > $now - $windowSeconds));
        }
    }
    if (count($hits) >= $maxRequests) return false;
    $hits[] = $now;
    @file_put_contents($path, json_encode($hits), LOCK_EX);
    return true;
}
