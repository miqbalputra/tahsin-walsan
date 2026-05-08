<?php
/**
 * API Endpoint for n8n to check pending attendance
 * Returns list of Ustadz who haven't filled attendance today
 */

require_once '../config/database.php';

// Simple API Key security. Set N8N_API_KEY in Coolify for production.
$apiKey = getenv('N8N_API_KEY') ?: "tahsin_secure_key_123";
$headers = getallheaders();
$providedKey = $headers['X-API-KEY'] ?? ($_GET['key'] ?? '');

if ($providedKey !== $apiKey) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$today = date('Y-m-d');
$dayOfWeek = date('w'); // 0 = Sunday
$dayOfMonth = date('j');

// 1. Check if today is Sunday
if ($dayOfWeek != 0) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'skip', 'reason' => 'Today is not Sunday']);
    exit;
}

// 2. Check if today is the first Sunday of the month (Holiday)
// If it's Sunday and date is 1-7, it's the first Sunday
if ($dayOfMonth <= 7) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'skip', 'reason' => 'First Sunday of the month is holiday']);
    exit;
}

try {
    // 3. Find Halaqohs that have members BUT no attendance records today
    $sql = "SELECT 
                h.id as halaqoh_id,
                h.nama_halaqoh,
                u.nama_lengkap as ustadz_name,
                u.no_hp as ustadz_phone
            FROM halaqoh h
            JOIN users u ON h.ustadz_id = u.id
            WHERE 
                -- Has at least one member
                EXISTS (SELECT 1 FROM halaqoh_members hm WHERE hm.halaqoh_id = h.id)
                -- NO attendance records today
                AND NOT EXISTS (SELECT 1 FROM presensi p WHERE p.halaqoh_id = h.id AND p.tanggal = ?)
            ORDER BY u.nama_lengkap";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$today]);
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'date' => $today,
        'count' => count($pending),
        'data' => $pending
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
