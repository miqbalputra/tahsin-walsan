<?php
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
    $css = function_exists('appUrl') ? appUrl('assets/css/app.css') : '/assets/css/app.css';
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
