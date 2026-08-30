<?php
require_once '../config/database.php';
require_once '../includes/auth_helper.php';
require_once '../includes/attendance_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ustadz') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.']);
    exit();
}

$ustadzId = (int) $_SESSION['user_id'];
$halaqohId = filter_var($_POST['halaqoh_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$waliId = filter_var($_POST['wali_santri_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$tanggal = normalizeDateInput($_POST['tanggal'] ?? '', '');
if ($halaqohId === false || $waliId === false || $tanggal === '') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Data presensi tidak valid.']);
    exit();
}

$payload = [
    'intent' => $_POST['intent'] ?? 'save',
    'status' => $_POST['status'] ?? 'A',
    'alasan' => $_POST['alasan'] ?? '',
    'jenis_materi' => $_POST['jenis_materi'] ?? '',
    'jilid' => $_POST['jilid'] ?? '',
    'nama_surat_dari' => $_POST['nama_surat_dari'] ?? '',
    'nama_surat_sampai' => $_POST['nama_surat_sampai'] ?? '',
    'halaman_dari' => $_POST['halaman_dari'] ?? '',
    'halaman_sampai' => $_POST['halaman_sampai'] ?? '',
    'hasil_talaqqi' => $_POST['hasil_talaqqi'] ?? '',
];

try {
    $result = attendanceSaveEntries($pdo, $halaqohId, $ustadzId, $tanggal, [$waliId => $payload]);
    $message = $result['deleted'] > 0 || strtolower((string) $payload['intent']) === 'reset' || strtoupper((string) $payload['status']) === 'RESET'
        ? 'Presensi dibatalkan'
        : 'Draft tersimpan otomatis';
    echo json_encode(['status' => 'success', 'message' => $message, 'saved' => $result['saved'], 'deleted' => $result['deleted']]);
} catch (AttendanceValidationException $e) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    reportApplicationError($e, 'api-save-single');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan presensi. Silakan coba lagi.']);
}
