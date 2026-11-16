<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/tahsin_reminder_api.php';

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tahsin-reminder-auth-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create isolated reminder-auth directory.');
$oldPath = ini_get('session.save_path');
$oldKey = getenv('N8N_API_KEY');
$oldIp = $_SERVER['REMOTE_ADDR'] ?? null;
$checks = 0;
$passed = 0;
function reminderAuthCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function reminderAuthRequest(?string $key): array
{
    if ($key === null) unset($_SERVER['HTTP_X_API_KEY']);
    else $_SERVER['HTTP_X_API_KEY'] = $key;
    http_response_code(200);
    ob_start();
    $authorized = tahsinReminderAuthorize();
    $body = (string) ob_get_clean();
    return [$authorized, http_response_code(), $body];
}

try {
    ini_set('session.save_path', $directory);
    putenv('N8N_API_KEY=fixture-reminder-key');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.250';

    [$authorized, $status, $body] = reminderAuthRequest(null);
    reminderAuthCheck(!$authorized && $status === 401 && str_contains($body, 'Unauthorized'), 'Missing API key is rejected.');
    for ($attempt = 1; $attempt < 10; $attempt++) {
        [$authorized, $status] = reminderAuthRequest('wrong-reminder-key');
        reminderAuthCheck(!$authorized && $status === 401, 'Failed key attempt ' . ($attempt + 1) . ' remains unauthorized.');
    }
    [$authorized, $status] = reminderAuthRequest('wrong-reminder-key');
    reminderAuthCheck(!$authorized && $status === 429, 'The eleventh failed attempt is throttled.');
    [$authorized, $status] = reminderAuthRequest('fixture-reminder-key');
    reminderAuthCheck($authorized && $status === 200, 'A valid key uses its own request budget after failed attempts.');

    putenv('N8N_API_KEY=');
    [$authorized, $status] = reminderAuthRequest('fixture-reminder-key');
    reminderAuthCheck(!$authorized && $status === 503, 'Missing API configuration fails closed.');
    $passed = $checks;
} finally {
    if ($oldPath !== false) ini_set('session.save_path', $oldPath);
    if ($oldKey === false) putenv('N8N_API_KEY');
    else putenv('N8N_API_KEY=' . $oldKey);
    if ($oldIp === null) unset($_SERVER['REMOTE_ADDR']);
    else $_SERVER['REMOTE_ADDR'] = $oldIp;

    $realDirectory = realpath($directory);
    $realTemp = realpath(sys_get_temp_dir());
    if ($realDirectory === false || $realTemp === false || dirname($realDirectory) !== $realTemp
        || !str_starts_with(basename($realDirectory), 'tahsin-reminder-auth-')) {
        throw new RuntimeException('Refusing to clean an unexpected reminder-auth directory.');
    }
    foreach (scandir($realDirectory) ?: [] as $name) {
        if (in_array($name, ['.', '..'], true)) continue;
        $path = $realDirectory . DIRECTORY_SEPARATOR . $name;
        if (is_file($path) && !is_link($path)) unlink($path);
    }
    rmdir($realDirectory);
}
echo 'PASS ' . $passed . " reminder API authentication assertions\n";
