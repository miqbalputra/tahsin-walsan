<?php
require_once __DIR__ . '/../includes/auth_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
try {
    if (empty($_SESSION['user_id'])) throw new RuntimeException('Login berakhir. Masuk kembali; draft di HP tetap tersedia.', 401);
    require_once __DIR__ . '/../config/database.php';
    requireApiSession($pdo, ['ustadz']);
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['GET', 'POST'], true)) { header('Allow: GET, POST'); throw new RuntimeException('Metode tidak didukung.', 405); }
    if ($method === 'POST') {
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        if (strlen($raw) > 65536) throw new RuntimeException('Permintaan terlalu besar.', 413);
        $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input)) throw new RuntimeException('Format permintaan tidak valid.', 422);
        if (!verifyCsrfToken($input['csrf_token'] ?? '')) throw new RuntimeException('Sesi keamanan berubah. Buka kembali halaman; draft dipertahankan.', 403);
    } else $input = $_GET;
    $userId = (int) $_SESSION['user_id'];
    session_write_close();
    require_once __DIR__ . '/../includes/autosave_helper.php';
    $groupId = filter_var($input['halaqoh_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $date = is_string($input['tanggal'] ?? null) ? $input['tanggal'] : '';
    if (!$groupId || !tahsinDate($date)) throw new AutosaveException('Kelompok atau tanggal tidak valid.');
    if (!autosaveEnabled($pdo, $groupId)) throw new AutosaveException('Autosave belum diaktifkan untuk kelompok ini.', 503);
    if ($method === 'GET') $data = autosaveSessionState($pdo, $userId, $groupId, $date);
    elseif (($input['action'] ?? 'save') === 'save') $data = autosaveWrite($pdo, $userId, $groupId, $date, $input);
    elseif ($input['action'] === 'finalize') $data = autosaveFinalize($pdo, $userId, $groupId, $date, $input);
    else throw new AutosaveException('Operasi tidak dikenal.');
    echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $status = $e instanceof AutosaveException ? $e->httpStatus : (in_array($e->getCode(), [401,403,405,413,422], true) ? $e->getCode() : 500);
    if ($e instanceof JsonException) $status = 422;
    if ($status === 500) reportApplicationError($e, 'autosave-v2');
    http_response_code($status);
    echo json_encode(['status' => 'error', 'message' => $status === 500 ? 'Server belum dapat menyimpan. Draft dipertahankan dan pengiriman akan dicoba lagi.' : $e->getMessage(),
        'details' => $e instanceof AutosaveException ? $e->details : new stdClass()], JSON_UNESCAPED_UNICODE);
}
