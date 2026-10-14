<?php
require_once __DIR__ . '/tahsin_report_helper.php';
require_once __DIR__ . '/tahsin_report_view.php';
try {
    $widgetGroups = array_values(array_filter(tahsinFetchPending($pdo, $_SESSION['role'], (int) $_SESSION['user_id']), static fn($g) => $g['belum_diisi'] > 0));
    $widgetTeachers = count(array_unique(array_column($widgetGroups, 'ustadz_id')));
    $widgetMissing = array_sum(array_column($widgetGroups, 'belum_diisi'));
    $widgetText = $widgetGroups ? $widgetTeachers . ' guru · ' . count($widgetGroups) . ' halaqoh-sesi · ' . $widgetMissing . ' data peserta perlu dilengkapi' : 'Tidak ada kekurangan pada sesi yang dapat dipantau';
} catch (Throwable $e) {
    reportApplicationError($e, 'tahsin-pending-widget');
    $widgetText = 'Data pemantauan belum dapat diambil; buka halaman pemantauan untuk memeriksa.';
}
$widgetBase = strpos($_SERVER['REQUEST_URI'], '/ustadz/') !== false ? '../' : '';
?>
<link rel="stylesheet" href="<?= $widgetBase ?>assets/css/tahsin-report.css">
<div class="tahsin-widget"><div><strong>Pemantauan Input Guru</strong><p><?= tahsinH($widgetText) ?></p><p>Data belum diinput bukan Alfa. Batas input Senin 10.00 WIB.</p></div><a class="tahsin-button" href="<?= $widgetBase ?>pantau-input.php">Lihat Kekurangan</a></div>
