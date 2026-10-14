<?php
require_once __DIR__ . '/tahsin_report_helper.php';
function tahsinReminderApi(PDO $pdo, bool $daily): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $apiKey = trim(getenv('N8N_API_KEY') ?: '');
    if ($apiKey === '') { http_response_code(503); echo json_encode(['error' => 'API belum dikonfigurasi.']); return; }
    $headers = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
    $provided = trim((string) ($headers['x-api-key'] ?? ($_GET['key'] ?? '')));
    if (!hash_equals($apiKey, $provided)) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); return; }
    $now = tahsinNow();
    $weekday = (int) $now->format('w');
    if (($daily && $weekday === 0) || (!$daily && $weekday !== 0)) {
        echo json_encode(['status' => 'skip', 'reason' => $daily ? 'Hari Ahad, sesi sedang berlangsung.' : 'Today is not Sunday']); return;
    }
    try {
        $dataset = tahsinLoadDataset($pdo, $now->format('Y-m-d'), $now);
        $latest = tahsinLatestSession($now->format('Y-m-d'), $dataset['holidays']);
        // Existing scheduled workflows continue reminding the latest session.
        // Backlog is opt-in; the application's manual reminder page always includes it.
        $query = ($_GET['all_pending'] ?? '') === '1' ? [] : ['start_date' => $latest ?? $now->format('Y-m-d')];
        $groups = array_values(array_filter(tahsinFetchPending($pdo, 'admin', 0, $query, $now), static fn($g) => $g['belum_diisi'] > 0));
        if (!$daily && !tahsinSessionActive($now->format('Y-m-d'), $dataset['holidays'])) {
            echo json_encode(['status' => 'skip', 'reason' => 'Sesi Ahad ini libur.']); return;
        }
        echo json_encode(['status' => 'success', 'date' => $now->format('Y-m-d'), 'date_today' => $now->format('Y-m-d'),
            'day' => ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][$weekday],
            'tanggal_tahsin' => $latest, 'tanggal_tahsin_formatted' => $latest ? 'Ahad, ' . date('d/m/Y', strtotime($latest)) : null,
            'server_timezone' => 'Asia/Jakarta', 'server_time' => $now->format('Y-m-d H:i:s'), 'count' => count($groups), 'data' => $groups], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) { error_log('tahsin-reminder: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Terjadi kesalahan server.']); }
}
