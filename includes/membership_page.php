<?php
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/auth_helper.php';
require_once __DIR__.'/membership_helper.php';
checkRole(['admin','pj_tahfidz']);
$actorId=(int)$_SESSION['user_id']; $context=null; $form=null; $token=''; $error=''; $catalog=null;
try {
    $groupId=participantId($_GET['id']??''); $filters=membershipFilters($_GET);
    $ready=membershipReady($pdo);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        requireValidCsrfToken($_POST['csrf_token']??null);
        if (array_diff(array_keys($_POST),['csrf_token','membership_token'])) throw new MembershipException('Buka konfirmasi keanggotaan terbaru sebelum menyimpan.');
        $token=appRequestString($_POST,'membership_token');
        $form=$_SESSION['membership_forms'][$token]??null;
        if (!preg_match('/^[a-f0-9]{32}$/D',$token) || !is_array($form) || $form['actor']!==$actorId || $form['expires']<=time()) throw new MembershipException('Konfirmasi berakhir atau berasal dari sesi lain. Buka kembali daftar anggota.');
        if ($groupId!==$form['group']) throw new MembershipException('Halaqoh tidak sesuai konfirmasi yang dibuka.');
        $result=membershipApply($pdo,$actorId,$groupId,$form['wali'],$form['action'],$form['fingerprint'],$token);
        $_SESSION['membership_flash']=['group'=>$groupId,'operation'=>$token,'message'=>$result['action']==='remove'?'Keanggotaan dinonaktifkan. Relasi lama, presensi dan draft tetap tersimpan.':($result['changed']?'Keanggotaan ditambahkan. Penempatan program lain tetap dipertahankan.':'Peserta sudah aktif; tidak ada relasi ganda yang ditambahkan.')];
        redirectTo('halaqoh-members.php?'.http_build_query(['id'=>$groupId]+$form['back']));
    }
    if (!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)) appRequestError(405,'Metode tidak tersedia','Buka pengelolaan anggota melalui daftar halaqoh.');
    $action=appRequestString($_GET,'action');
    if (!in_array($action,['','add','remove'],true)) throw new MembershipException('Tindakan keanggotaan tidak tersedia.');
    $pdo->beginTransaction();
    if ($action==='') $catalog=membershipCatalog($pdo,$groupId,$filters);
    else {
        if (!$ready) throw new MembershipException('Jurnal peserta dan histori roster perlu disiapkan pengelola sebelum mengubah keanggotaan.');
        $waliId=participantId($_GET['wali_id']??'');
        $context=membershipContext($pdo,$groupId,$waliId); membershipValidate($context,$action);
    }
    $pdo->commit();
    if ($context) {
        $token=bin2hex(random_bytes(16));
        $form=['actor'=>$actorId,'group'=>$groupId,'wali'=>$waliId,'action'=>$action,'fingerprint'=>$context['fingerprint'],'back'=>$filters,'expires'=>time()+1800];
        $forms=is_array($_SESSION['membership_forms']??null)?$_SESSION['membership_forms']:[];
        $forms=array_filter($forms,static fn($item)=>is_array($item)&&($item['expires']??0)>time()); $forms[$token]=$form;
        $_SESSION['membership_forms']=array_slice($forms,-8,null,true);
    }
} catch (AttendanceHistoryException $failure) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    appRequestError(403,'Akses pengelolaan anggota berakhir',$failure->getMessage());
} catch (MembershipException|ParticipantManagementException|InvalidArgumentException $failure) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status=$failure instanceof MembershipException?$failure->httpStatus:422;
    if (!$context && !$form) appRequestError($status,'Keanggotaan belum dapat diproses',$failure->getMessage());
    http_response_code($status); $error=$failure->getMessage();
} catch (Throwable $failure) {
    if ($pdo->inTransaction()) $pdo->rollBack(); reportApplicationError($failure,'membership-management');
    if (!$context && !$form) appRequestError(503,'Anggota belum dapat dibuka','Coba kembali sebentar lagi atau hubungi pengelola.');
    http_response_code(503); $error='Penyimpanan belum dapat dipastikan. Periksa riwayat sebelum mengirim ulang konfirmasi yang sama.';
}
if ($form && !$context) {
    $action=$form['action'];
    try { $context=membershipContext($pdo,$form['group'],$form['wali']); }
    catch (Throwable $failure) { reportApplicationError($failure,'membership-reload'); appRequestError(409,'Sumber konfirmasi berubah','Buka daftar anggota terbaru dan periksa riwayat perubahan peserta.'); }
}
$groupId=$form['group']??$groupId;
$group=$context?$context['group']:$catalog['group']; $h=$group['group'];
$back=$form['back']??$filters; $backUrl=appUrl('halaqoh-members.php?'.http_build_query(['id'=>$groupId]+$back));
$flash=$_SESSION['membership_flash']??null;
if (!is_array($flash) || ($flash['group']??null)!==$groupId) $flash=null;
else unset($_SESSION['membership_flash']);
$pageTitle='Kelola Anggota Halaqoh';
require __DIR__.'/header.php'; require __DIR__.'/sidebar.php';
require __DIR__.'/membership_view.php';
require __DIR__.'/footer.php';
