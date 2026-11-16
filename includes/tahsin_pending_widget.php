<?php
require_once __DIR__ . '/tahsin_report_helper.php';
require_once __DIR__ . '/tahsin_report_view.php';
try {
    $widgetOverview = tahsinFetchPendingOverview($pdo, $_SESSION['role'], (int) $_SESSION['user_id']);
    $widgetGroups = array_values(array_filter($widgetOverview['groups'], static fn($g) => $g['belum_diisi'] > 0));
    $widgetTeachers = (int) $widgetOverview['summary']['pending_teachers'];
    $widgetMissing = (int) $widgetOverview['summary']['pending_participants'];
    $widgetText = $widgetGroups ? $widgetTeachers . ' guru · ' . count($widgetGroups) . ' halaqoh-sesi · ' . $widgetMissing . ' data peserta perlu dilengkapi' : 'Tidak ada kekurangan pada roster yang diperiksa';
    if (array_filter($widgetGroups, static fn($g) => ($g['roster_basis'] ?? '') === 'current_roster')) $widgetText .= ' · pekan ini memakai roster aktif';
    if ($widgetOverview['summary']['unverified_sessions']) $widgetText .= ' · ' . (int) $widgetOverview['summary']['unverified_sessions'] . ' sesi belum terverifikasi';
    if ($widgetOverview['summary']['unverified_record_issues']) $widgetText .= ' · ' . (int) $widgetOverview['summary']['unverified_record_issues'] . ' catatan lama perlu ditinjau';
} catch (Throwable $e) {
    reportApplicationError($e, 'tahsin-pending-widget');
    $widgetText = 'Data pemantauan belum dapat diambil; buka halaman pemantauan untuk memeriksa.';
}
$widgetBase = strpos($_SERVER['REQUEST_URI'], '/ustadz/') !== false ? '../' : '';
?>
<link rel="stylesheet" href="<?= appEscape(appAssetUrl('assets/css/tahsin-report.css')) ?>">
<div class="tahsin-widget"><div><strong>Pemantauan Input Guru</strong><p><?= tahsinH($widgetText) ?></p><p>Data belum diinput bukan Alfa. Batas input Senin 10.00 WIB.</p></div><a class="tahsin-button" href="<?= $widgetBase ?>pantau-input.php">Lihat Kekurangan</a></div>
