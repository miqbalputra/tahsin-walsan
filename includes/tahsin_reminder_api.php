<?php
require_once __DIR__ . '/tahsin_reminder_helper.php';
require_once __DIR__ . '/security_helper.php';
function tahsinReminderApi(PDO $pdo, bool $daily): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $apiKey = trim(getenv('N8N_API_KEY') ?: '');
    if ($apiKey === '') { http_response_code(503); echo json_encode(['error' => 'API belum dikonfigurasi.']); return; }
    $headers = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
    // Key hanya via header X-API-Key agar tidak tertinggal di access log.
    $provided = trim((string) ($headers['x-api-key'] ?? ''));
    if (!hash_equals($apiKey, $provided)) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); return; }
    $rateLimit = apiRateLimitAllow('reminder', 120, 60);
    if ($rateLimit === null) { http_response_code(503); echo json_encode(['error' => 'Pembatasan permintaan belum tersedia.']); return; }
    if (!$rateLimit) { http_response_code(429); echo json_encode(['error' => 'Terlalu banyak permintaan.']); return; }
    $now = tahsinNow();
    $weekday = (int) $now->format('w');
    if (($daily && $weekday === 0) || (!$daily && $weekday !== 0)) {
        echo json_encode(['status' => 'skip', 'reason' => $daily ? 'Hari Ahad, sesi sedang berlangsung.' : 'Today is not Sunday']); return;
    }
    try {
        $dataset = tahsinLoadDataset($pdo, $now->format('Y-m-d'), $now);
        $overview = tahsinReminderOverview($dataset, $now, ($_GET['all_pending'] ?? '') === '1');
        $session = $overview['tanggal_tahsin'];
        echo json_encode(array_merge($overview, ['date' => $now->format('Y-m-d'), 'date_today' => $now->format('Y-m-d'),
            'day' => ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][$weekday],
            'tanggal_tahsin_formatted' => 'Ahad, ' . date('d/m/Y', strtotime($session)),
            'server_timezone' => 'Asia/Jakarta', 'server_time' => $now->format('Y-m-d H:i:s')]), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) { error_log('tahsin-reminder: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Terjadi kesalahan server.']); }
}
