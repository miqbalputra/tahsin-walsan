<?php
require_once __DIR__ . '/config/database.php';
try {
    $pdo->query('SELECT 1')->fetchColumn();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'version' => getenv('APP_VERSION') ?: 'unknown', 'time' => gmdate('c')]);
} catch (Throwable $error) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false]);
}
