<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/data_induk_helper.php';
$scope = $argv[1] ?? 'changes';
try {
    $stats = dataIndukSync($pdo, $scope);
    echo json_encode(['ok' => true, 'scope' => $scope, 'stats' => $stats], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok' => false, 'scope' => $scope, 'error' => $error instanceof DataIndukException ? $error->getMessage() : 'sync failed']) . PHP_EOL);
    exit(1);
}
