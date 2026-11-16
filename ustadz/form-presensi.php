<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_helper.php';
require_once __DIR__ . '/../includes/autosave_helper.php';
checkRole(['ustadz']);
try {
    $groupRaw=appRequestString($_GET,'id');
    $halaqoh_id=filter_var($groupRaw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $requestedDate=appRequestString($_GET,'tanggal',tahsinNow()->format('Y-m-d'));
    $legacy=appRequestString($_GET,'legacy');
    if (!$halaqoh_id || !tahsinDate($requestedDate) || !in_array($legacy,['','1'],true)) throw new InvalidArgumentException('Pilih halaqoh dan tanggal yang valid dari daftar.');
} catch (InvalidArgumentException $failure) { appRequestError(422,'Formulir belum dapat dibuka',$failure->getMessage()); }
if ($_SERVER['REQUEST_METHOD']==='GET' && $legacy==='' && autosaveEnabled($pdo,$halaqoh_id)) {
    require __DIR__ . '/autosave-page.php';
    return;
}
if (!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD','POST'],true)) appRequestError(405,'Metode tidak tersedia','Buka formulir melalui tautan aplikasi.');
require __DIR__ . '/../includes/attendance_form_page.php';
