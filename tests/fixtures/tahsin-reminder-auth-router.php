<?php
/** Isolated route table for authentication-before-database HTTP checks. */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$rateDirectory = getenv('SESSION_SAVE_PATH') ?: '';
if ($rateDirectory !== '' && is_dir($rateDirectory)) ini_set('session.save_path', $rateDirectory);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/__ready') { http_response_code(204); exit; }
if ($path === '/api/daily-reminder.php') {
    require dirname(__DIR__, 2) . '/api/daily-reminder.php';
    exit;
}
if ($path === '/api/notifications-pending.php') {
    require dirname(__DIR__, 2) . '/api/notifications-pending.php';
    exit;
}
http_response_code(404);
