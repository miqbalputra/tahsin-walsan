<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_helper.php';
require_once __DIR__ . '/../includes/attendance_form_helper.php';
header('Content-Type: application/json; charset=utf-8');
function legacyAttendanceJsonError(int $status, string $message): void {
    http_response_code($status); echo json_encode(['status'=>'error','message'=>$message],JSON_UNESCAPED_UNICODE); exit;
}
try { requireApiSession($pdo,['ustadz']); }
catch (RuntimeException $failure) {
    $status=in_array($failure->getCode(),[401,403],true)?$failure->getCode():503;
    if ($status===503) reportApplicationError($failure,'legacy-attendance-session');
    legacyAttendanceJsonError($status,$status===503?'Akun belum dapat diperiksa. Coba kembali sebentar lagi.':$failure->getMessage());
}
if ($_SERVER['REQUEST_METHOD']!=='POST') { header('Allow: POST'); legacyAttendanceJsonError(405,'Gunakan formulir presensi untuk menyimpan.'); }
if (!verifyCsrfToken($_POST['csrf_token']??null)) legacyAttendanceJsonError(403,'Token keamanan tidak valid. Buka kembali formulir sebelum mencoba lagi.');
try {
    $form=attendanceFormGet(appRequestString($_POST,'attendance_form_token'),(int)$_SESSION['user_id']);
    $group=filter_var(appRequestString($_POST,'halaqoh_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $id=filter_var(appRequestString($_POST,'wali_santri_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $date=appRequestString($_POST,'tanggal');
    if (!$id || !$group || !tahsinDate($date)) throw new AttendanceFormException('Peserta, halaqoh atau tanggal tidak valid.');
    if ($group!==$form['group_id'] || $date!==$form['date']) throw new AttendanceFormException('Halaqoh atau tanggal tidak sesuai formulir yang dibuka.',409);
    $expected=[$id=>appRequestString($_POST,'entry_revision')];
    $result=attendanceFormSave($pdo,$form,[$id=>attendanceReadPayload($_POST)],$expected,appRequestString($_POST,'operation_id'),false);
    attendanceFormAccept($form,$expected,$result);
    echo json_encode(['status'=>'success','message'=>$result['deleted']?'Presensi dibatalkan; versi lama tersimpan.':'Catatan tersimpan.']+$result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (AttendanceFormException $failure) { legacyAttendanceJsonError($failure->httpStatus,$failure->getMessage()); }
catch (AttendanceValidationException|AttendanceHistoryException|InvalidArgumentException $failure) { legacyAttendanceJsonError(422,$failure->getMessage()); }
catch (Throwable $failure) { reportApplicationError($failure,'legacy-attendance-save'); legacyAttendanceJsonError(503,'Penyimpanan belum dapat dipastikan. Periksa riwayat atau coba ulang pengiriman yang sama.'); }
