<?php
/** Safe router for the local PHP development server; never a production route. */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$relative = ltrim($path, '/');
$privateDirectory = '~(^|/)(\.[^/]+|config|includes|templates|tests|scripts|docker|migrations|docs|temp_excel|backup|outputs|node_modules|vendor|next)(/|$)~i';
$publicPage = preg_match('~^(?:(?:ustadz|api)/)?[a-zA-Z0-9_-]+\.php$~D', $relative)
    && !preg_match('~(^|/)(migrate[^/]*|dev-router)\.php$~i', $relative);
$publicAsset = preg_match('~^assets/[a-zA-Z0-9_./-]+\.(css|js|png|svg|ico|webp|woff2?|ttf)$~iD', $relative)
    || in_array($relative, ['manifest.json', 'sw.js', 'icon-512.png', 'template_import_peserta.csv', 'template_santri_baru.csv'], true);
if (str_contains($path, "\0") || str_contains($path, '\\') || preg_match($privateDirectory, $relative)
    || !($publicPage || $publicAsset || $relative === '')) {
    http_response_code(403);
    header('Cache-Control: no-store');
    exit('Akses berkas tidak diizinkan.');
}
return false;
