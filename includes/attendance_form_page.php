<?php
// Controller for the compatibility form; run entirely before emitting the layout.
require_once __DIR__ . '/attendance_form_helper.php';
$ustadz_id=(int)$_SESSION['user_id']; $submitted=[]; $message=''; $error=''; $form=null; $token='';
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        requireValidCsrfToken($_POST['csrf_token']??null);
        $token=appRequestString($_POST,'attendance_form_token'); $form=attendanceFormGet($token,$ustadz_id);
        $tanggal=appRequestString($_POST,'tanggal');
        if ($halaqoh_id!==$form['group_id'] || $tanggal!==$form['date']) throw new AttendanceFormException('Halaqoh atau tanggal tidak sesuai formulir yang dibuka.',409);
        $submitted=is_array($_POST['presensi']??null)?$_POST['presensi']:[];
        $expected=is_array($_POST['entry_revisions']??null)?$_POST['entry_revisions']:[];
        $operation=appRequestString($_POST,'attendance_operation_id');
        $result=attendanceFormSave($pdo,$form,$submitted,$expected,$operation,true);
        attendanceFormAccept($form,$expected,$result);
        $_SESSION['legacy_attendance_flash']=['group'=>$halaqoh_id,'date'=>$tanggal,'message'=>'Presensi tersimpan untuk '.$result['saved'].' peserta. '.$result['auto_alpha'].' peserta baru disimpan sebagai Alfa. '.$result['deleted'].' catatan dibatalkan; versi lamanya tetap tersimpan.'];
        redirectTo('ustadz/form-presensi.php?'.http_build_query(['id'=>$halaqoh_id,'tanggal'=>$tanggal,'legacy'=>1]));
    }
    $opened=attendanceFormOpen($pdo,$ustadz_id,$halaqoh_id,$requestedDate);
    $token=$opened['token']; $form=$opened['form']; $state=$opened['state']; $tanggal=$form['date'];
} catch (AttendanceFormException|AttendanceValidationException|AttendanceHistoryException|InvalidArgumentException $failure) {
    $status=$failure instanceof AttendanceFormException?$failure->httpStatus:422;
    if (!$form) appRequestError($status,'Formulir presensi belum dapat digunakan',$failure->getMessage());
    http_response_code($status); $error=$failure->getMessage();
} catch (Throwable $failure) {
    reportApplicationError($failure,'legacy-attendance-form');
    if (!$form) appRequestError(503,'Formulir presensi belum dapat dibuka','Coba kembali sebentar lagi atau hubungi pengelola.');
    http_response_code(503); $error='Penyimpanan belum dapat dipastikan. Periksa riwayat atau buka formulir terbaru sebelum mencoba kembali.';
}
if (!isset($state)) {
    try { $state=attendanceFormState($pdo,$ustadz_id,$form['group_id'],$form['date']); $tanggal=$form['date']; }
    catch (Throwable $failure) { reportApplicationError($failure,'legacy-attendance-reload'); appRequestError(409,'Sumber formulir berubah','Buka formulir terbaru dari daftar halaqoh. Catatan lama tidak diubah.'); }
}
$flash=$_SESSION['legacy_attendance_flash']??null;
if (is_array($flash) && ($flash['group']??null)===$halaqoh_id && ($flash['date']??null)===$tanggal) { $message=$flash['message']; unset($_SESSION['legacy_attendance_flash']); }
$halaqoh=$state['group']; $members=$state['members']; $existing_attendance=$state['entries'];
$sessionAttendance=['conflicts'=>$state['conflicts']];
$ready=attendanceFormReady($pdo)&&attendanceHistoryReady($pdo);
$freshUrl=appUrl('ustadz/form-presensi.php?'.http_build_query(['id'=>$halaqoh_id,'tanggal'=>$tanggal,'legacy'=>1]));
$holiday=null;
foreach ([tahsinSessionDate($tanggal),$tanggal] as $day) if (isset($state['holidays'][$day])) { $holiday=['keterangan'=>$state['holidays'][$day]]; break; }
if ((int)substr(tahsinSessionDate($tanggal),8,2)<=7) $holiday=['keterangan'=>'Ahad pertama setiap bulan'];
$pageTitle='Form Input Presensi';
require __DIR__ . '/header.php'; require __DIR__ . '/sidebar.php';
require __DIR__ . '/attendance_form_view.php';
require __DIR__ . '/footer.php';
