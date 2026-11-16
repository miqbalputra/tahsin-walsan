<?php
require_once __DIR__ . '/tahsin_reminder_helper.php';
require_once __DIR__ . '/security_helper.php';
/** Authenticate and rate-limit before the public endpoint opens a DB connection. */
function tahsinReminderAuthorize(): bool
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $apiKey = trim(getenv('N8N_API_KEY') ?: '');
    if ($apiKey === '') { http_response_code(503); echo json_encode(['error' => 'API belum dikonfigurasi.']); return false; }
    $headers = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
    // Key hanya via header X-API-Key agar tidak tertinggal di access log.
    $provided = $headers['x-api-key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
    if (!is_string($provided) || !hash_equals($apiKey, trim($provided))) {
        $failedLimit = apiRateLimitAllow('reminder-auth-failure', 10, 300);
        if ($failedLimit === null) { http_response_code(503); echo json_encode(['error' => 'Pembatasan percobaan autentikasi belum tersedia.']); return false; }
        if (!$failedLimit) { http_response_code(429); echo json_encode(['error' => 'Terlalu banyak percobaan autentikasi.']); return false; }
        http_response_code(401); echo json_encode(['error' => 'Unauthorized']); return false;
    }
    $rateLimit = apiRateLimitAllow('reminder', 120, 60);
    if ($rateLimit === null) { http_response_code(503); echo json_encode(['error' => 'Pembatasan permintaan belum tersedia.']); return false; }
    if (!$rateLimit) { http_response_code(429); echo json_encode(['error' => 'Terlalu banyak permintaan.']); return false; }
    return true;
}

/** Preserve the authenticated public helper contract for non-endpoint callers. */
function tahsinReminderApi(PDO $pdo, bool $daily): void
{
    if (!tahsinReminderAuthorize()) return;
    tahsinReminderResponse($pdo, $daily);
}

/** Build the response after the endpoint has authenticated and limited requests. */
function tahsinReminderResponse(PDO $pdo, bool $daily): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
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
