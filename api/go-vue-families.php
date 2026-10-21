<?php
require_once __DIR__.'/../includes/go_vue_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function nextResponse(int $status, string $message): never {
    http_response_code($status);
    echo json_encode(['status'=>'error','message'=>$message],JSON_UNESCAPED_UNICODE);exit;
}
if (!goVueEnabled()) nextResponse(404,'Tidak ditemukan.');
require_once __DIR__.'/../includes/auth_helper.php';
if (empty($_SESSION['user_id'])) nextResponse(401,'Sesi login berakhir. Masuk kembali melalui aplikasi utama.');
if (!in_array($_SESSION['role'] ?? '',['admin','pj_tahfidz'],true)) nextResponse(403,'Pratinjau khusus admin dan PJ Tahfidz.');
if ($_SERVER['REQUEST_METHOD']!=='GET') { header('Allow: GET');nextResponse(405,'Pratinjau hanya membaca data.'); }
$user = (int) $_SESSION['user_id'];$role = (string) $_SESSION['role'];session_write_close();
try {
    $query = goVueQuery($_GET);
    $data = goVueReadFamilies($query,$user,$role);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) { nextResponse(422,'Parameter pratinjau tidak valid.'); }
catch (Throwable $e) { nextResponse(503,'Pratinjau belum tersedia. Aplikasi utama tetap dapat digunakan.'); }
