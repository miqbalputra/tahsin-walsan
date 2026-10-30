<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/achievement_helper.php';
checkRole(!empty($achievementTeacherOnly) ? ['ustadz'] : ['admin','pj_tahfidz','ustadz']);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    requireValidCsrfToken($_POST['csrf_token']??null);
    appRequestError(422,'Formulir capaian diperbarui','Buka kembali daftar capaian lalu gunakan tautan editor pada peserta yang dipilih. Isian lama belum diproses.');
}
if (!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)) appRequestError(405,'Metode tidak tersedia','Buka daftar capaian melalui tautan aplikasi.');
$role=$_SESSION['role']; $userId=(int)$_SESSION['user_id']; $canManage=in_array($role,['admin','pj_tahfidz'],true);
$catalogPath=!empty($achievementTeacherOnly)?'ustadz/capaian.php':'capaian.php';
try {
    $filters=achievementFilters($_GET);
    $catalog=achievementCatalog($pdo,$role,$userId,$filters);
} catch (AchievementException|InvalidArgumentException $failure) {
    appRequestError(422,'Filter capaian belum dapat digunakan',$failure->getMessage());
} catch (Throwable $failure) {
    reportApplicationError($failure,'achievement-catalog');
    appRequestError(503,'Capaian belum dapat dibuka','Coba kembali sebentar lagi atau hubungi pengelola.');
}
$flash=is_array($_SESSION['achievement_flash']??null)?$_SESSION['achievement_flash']:null;
unset($_SESSION['achievement_flash']);
$pageTitle='Daftar Capaian Peserta';
require __DIR__ . '/header.php'; require __DIR__ . '/sidebar.php';
require __DIR__ . '/achievement_view.php';
require __DIR__ . '/footer.php';
