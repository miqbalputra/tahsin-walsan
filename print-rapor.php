<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_helper.php';
require_once __DIR__ . '/includes/rapor_helper.php';

checkLogin();
checkRole(['admin', 'pj_tahfidz', 'kepsek', 'ustadz']);
try {
    $filters = raporFilters($_GET);
    $participant = raporParticipant($pdo, $filters, $_SESSION['role'], (int) $_SESSION['user_id']);
    if (!$participant) {
        appRequestError(404, 'Rapor tidak tersedia', 'Peserta tidak ditemukan atau berada di luar kelompok yang dapat Anda akses pada periode ini. Pilih peserta dari halaman rekap.');
    }
    $settings = raporSettings($pdo);
    $template = raporSelectTemplate($settings['rapor_template'] ?? '');
    $html = raporRender($template['html'], $participant, $filters, $settings, $template['notice']);
} catch (InvalidArgumentException $error) {
    appRequestError(422, 'Periksa pilihan rapor', $error->getMessage());
} catch (Throwable $error) {
    reportApplicationError($error, 'print-rapor');
    appRequestError(503, 'Rapor belum dapat dibuka', 'Coba buka kembali halaman rekap. Jika masalah berlanjut, hubungi pengelola. Data peserta dan presensi tetap tersimpan.');
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'unsafe-inline'; img-src 'self' https: data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
echo $html;
