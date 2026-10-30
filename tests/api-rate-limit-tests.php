<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/security_helper.php';

if (($argv[1] ?? '') === 'worker') {
    $directory = $argv[2] ?? '';
    $barrier = $argv[3] ?? '';
    if (!is_dir($directory) || $barrier === '') exit(2);
    ini_set('session.save_path', $directory);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.25';
    $deadline = microtime(true) + 10;
    while (!is_file($barrier) && microtime(true) < $deadline) usleep(1000);
    if (!is_file($barrier)) exit(2);
    echo apiRateLimitAllow('parallel', 4, 60) === true ? '1' : '0';
    exit(0);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tahsin-rate-limit-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create isolated rate-limit test directory.');
$checks = 0;
function rateCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

try {
    ini_set('session.save_path', $directory);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    rateCheck(apiRateLimitAllow('basic', 3, 60) === true, 'First request is allowed.');
    rateCheck(apiRateLimitAllow('basic', 3, 60) === true, 'Requests below the limit are allowed.');
    rateCheck(apiRateLimitAllow('basic', 3, 60) === true, 'The final request within the limit is allowed.');
    rateCheck(apiRateLimitAllow('basic', 3, 60) === false, 'Requests above the limit are denied.');
    rateCheck(apiRateLimitAllow('other-scope', 3, 60) === true, 'Rate-limit scopes have separate counters.');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.11';
    rateCheck(apiRateLimitAllow('basic', 3, 60) === true, 'Client IPs have separate counters.');
    rateCheck(apiRateLimitAllow('basic', 0, 60) === null, 'Invalid limiter configuration is reported as unavailable.');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.12';
    $corruptFile = $directory . DIRECTORY_SEPARATOR . 'api-rate-' . hash('sha256', (realpath(dirname(__DIR__)) ?: dirname(__DIR__)) . "\0corrupt\0" . $_SERVER['REMOTE_ADDR']) . '.json';
    file_put_contents($corruptFile, '{broken');
    rateCheck(apiRateLimitAllow('corrupt', 1, 60) === true, 'A corrupt counter is recovered with a fresh window.');
    rateCheck(apiRateLimitAllow('corrupt', 1, 60) === false, 'The recovered counter enforces its limit.');

    if (function_exists('proc_open')) {
        $barrier = $directory . DIRECTORY_SEPARATOR . 'start';
        $workers = [];
        for ($index = 0; $index < 16; $index++) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', $directory, $barrier], [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes, __DIR__);
            if (!is_resource($process)) throw new RuntimeException('Could not start concurrent limiter worker.');
            fclose($pipes[0]);
            $workers[] = [$process, $pipes[1], $pipes[2]];
        }
        file_put_contents($barrier, 'go');
        $allowed = 0;
        $denied = 0;
        foreach ($workers as [$process, $stdout, $stderr]) {
            $result = stream_get_contents($stdout);
            $error = stream_get_contents($stderr);
            fclose($stdout);
            fclose($stderr);
            $exitCode = proc_close($process);
            if ($exitCode !== 0) throw new RuntimeException('Concurrent limiter worker failed: ' . $error);
            if ($result === '1') $allowed++;
            elseif ($result === '0') $denied++;
            else throw new RuntimeException('Concurrent limiter worker returned an invalid result.');
        }
        rateCheck($allowed === 4 && $denied === 12, 'Concurrent requests cannot exceed the shared limit.');
    }

    echo "PASS $checks API rate-limit assertions\n";
} finally {
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        if (is_file($path)) @unlink($path);
    }
    @rmdir($directory);
}
