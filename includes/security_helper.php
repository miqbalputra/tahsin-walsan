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
function apiRateLimitAllow(string $scope, int $maxRequests = 120, int $windowSeconds = 60): ?bool
{
    if ($scope === '' || $maxRequests < 1 || $windowSeconds < 1) return null;

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!is_string($ip) || $ip === '') $ip = 'unknown';
    $directory = ini_get('session.save_path') ?: sys_get_temp_dir();
    if (!is_dir($directory)) $directory = sys_get_temp_dir();
    $identity = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'api-rate-' . hash('sha256', $identity . "\0" . $scope . "\0" . $ip) . '.json';
    $now = time();

    // Lock around the complete read/decide/write sequence. Locking only the
    // write lets concurrent requests all observe the same old counter.
    $handle = @fopen($path, 'c+');
    if (!is_resource($handle)) return null;
    try {
        if (!flock($handle, LOCK_EX)) return null;
        @chmod($path, 0600);

        $stat = fstat($handle);
        if (!is_array($stat)) return null;
        $decoded = [];
        if (($stat['size'] ?? 0) <= 65536) {
            rewind($handle);
            $contents = stream_get_contents($handle, 65537);
            if (!is_string($contents)) return null;
            if ($contents !== '') {
                $stored = json_decode($contents, true);
                if (is_array($stored) && array_is_list($stored)) {
                    foreach ($stored as $stamp) {
                        if (!is_int($stamp)) {
                            $stored = [];
                            break;
                        }
                    }
                    $decoded = $stored;
                }
            }
        }

        $hits = array_values(array_filter($decoded, static fn($stamp) => $stamp > $now - $windowSeconds));
        if (count($hits) >= $maxRequests) return false;
        $hits[] = $now;
        $encoded = json_encode($hits, JSON_THROW_ON_ERROR);

        rewind($handle);
        if (!ftruncate($handle, 0)) return null;
        $offset = 0;
        while ($offset < strlen($encoded)) {
            $written = fwrite($handle, substr($encoded, $offset));
            if ($written === false || $written === 0) return null;
            $offset += $written;
        }
        if (!fflush($handle)) return null;
        return true;
    } catch (Throwable $error) {
        return null;
    } finally {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
}
