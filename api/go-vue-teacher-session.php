<?php
// Shadow read only: the editor and every write still use the existing PHP API.
require_once __DIR__ . '/../includes/go_vue_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function teacherReadResponse(int $code, string $message): never {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE); exit;
}
if (!goVueEnabled() || getenv('TAHSIN_NEXT_TEACHER_READ_ENABLED') !== '1') teacherReadResponse(404, 'Tidak ditemukan.');
require_once __DIR__ . '/../includes/auth_helper.php';
header('Cache-Control: no-store, private'); // session_start's default limiter may replace earlier headers.
if (empty($_SESSION['user_id'])) teacherReadResponse(401, 'Sesi login berakhir. Draft di HP tetap tersedia.');
if (($_SESSION['role'] ?? '') !== 'ustadz') teacherReadResponse(403, 'Akses khusus guru.');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); teacherReadResponse(405, 'Pratinjau hanya membaca data.'); }
$teacherId = (int)$_SESSION['user_id']; session_write_close();
try {
    $query = goVueTeacherQuery($_GET);
    $group = (int)$_GET['halaqoh_id']; $date = $_GET['tanggal'];
    if (!goVueTeacherReadEnabled($group)) teacherReadResponse(404, 'Tidak ditemukan.');
    require __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/autosave_helper.php';
    // SELECT-only Go cannot inspect triggers in MySQL. The authenticated PHP
    // gateway validates the fully installed schema with its existing account.
    if (!autosaveEnabled($pdo, $group) || !tahsinSchemaReady($pdo)) teacherReadResponse(503, 'Skema baca sesi belum siap.');
    autosaveContext($pdo, $teacherId, $group, $date); // Same assignment/history rules; Go checks independently too.
    $data = goVueReadSignedResponse('/api/v1/teacher-session', $query, $teacherId, 'ustadz');
    if (($data['status'] ?? '') !== 'success' || !is_array($data['data']['entries'] ?? null) || !isset($data['data']['context_revision'])) throw new RuntimeException('Respons tidak valid.');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) { teacherReadResponse(422, 'Kelompok atau tanggal tidak valid.'); }
catch (AutosaveException $e) { teacherReadResponse($e->httpStatus, $e->getMessage()); }
catch (Throwable $e) { teacherReadResponse(503, 'Baca sesi Go belum tersedia. Pengisian PHP tetap dapat digunakan.'); }
