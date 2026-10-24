<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_helper.php';
require_once __DIR__ . '/includes/tahsin_report_helper.php';
require_once __DIR__ . '/includes/tahsin_report_view.php';
checkRole(['admin', 'pj_tahfidz', 'kepsek', 'ustadz']);
$role = $_SESSION['role'];
$userId = (int) $_SESSION['user_id'];
$activityView = !empty($tahsinActivityView);
$activitySummary = $activityView && ($_GET['view'] ?? '') === 'ringkasan';
$canVerify = in_array($role, ['admin', 'pj_tahfidz'], true);
$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canVerify || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        $error = 'Anda tidak berwenang atau token keamanan tidak valid.';
    } elseif (($_POST['action'] ?? '') === 'verify') {
        try {
            if (!isset($_POST['selected_id']) || !preg_match('/^\d+$/', (string) $_POST['selected_id'])) throw new InvalidArgumentException('Pilih catatan yang sah.');
            tahsinSaveVerification($pdo, (int) ($_POST['wali_id'] ?? 0), (string) ($_POST['session'] ?? ''), (string) ($_POST['fingerprint'] ?? ''), (int) $_POST['selected_id'] ?: null, $userId, (string) ($_POST['note'] ?? ''));
            addLog($pdo, 'VERIFY_TAHSIN_SESSION', 'Wali ID ' . (int) $_POST['wali_id'] . ', sesi ' . $_POST['session'] . ', pilihan #' . (int) $_POST['selected_id']);
            redirectTo('rekap-lengkap.php?' . http_build_query(array_merge($_GET, ['msg' => 'verified'])) . '#detail-wali');
        } catch (PDOException $e) { reportApplicationError($e, 'verify-tahsin-database'); $error = 'Verifikasi gagal disimpan. Silakan coba kembali.'; }
        catch (InvalidArgumentException | RuntimeException $e) { $error = $e->getMessage(); }
        catch (Throwable $e) { reportApplicationError($e, 'verify-tahsin'); $error = 'Verifikasi gagal disimpan. Silakan coba kembali.'; }
    }
}
try {
    $filters = tahsinFilters($_GET);
    if ($activitySummary) $filters = array_replace($filters, ['wali_santri_id' => 0, 'kelas' => '', 'search' => '', 'kelengkapan' => '', 'risk' => '']);
} catch (InvalidArgumentException $e) {
    http_response_code(400); $error = $e->getMessage(); $filters = tahsinFilters();
}
try {
    $dataset = tahsinLoadDataset($pdo, $filters['end_date']);
    $report = tahsinAssembleReport($dataset, $filters, $role, $userId);
    $summaryFilters = array_replace($filters, ['wali_santri_id' => 0, 'kelas' => '', 'search' => '', 'kategori' => '', 'aktif' => '', 'kelengkapan' => '', 'risk' => '']);
    if (!$activityView) $meetingReport = tahsinAssembleReport($dataset, $summaryFilters, $role, $userId);
} catch (Throwable $e) {
    reportApplicationError($e, 'rekap-lengkap');
    http_response_code(500);
    $pageTitle = $activityView ? 'Persentase & Keaktifan' : 'Rekap Lengkap';
    require __DIR__ . '/includes/header.php'; require __DIR__ . '/includes/sidebar.php';
    echo '<div role="alert" class="p-6 rounded-xl bg-amber-50 text-amber-900">Rekap belum dapat dimuat. Silakan muat ulang atau hubungi admin. Angka kehadiran belum tersedia.</div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}
if ($activityView) {
    require __DIR__ . '/includes/tahsin_activity_page.php';
    exit;
}
$print = ($_GET['print'] ?? '') === '1';
$summaryOnly = ($_GET['summary_only'] ?? '') === '1';
$meeting = ($_GET['mode'] ?? '') === 'rapat';
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalPages = max(1, (int) ceil(count($report['rows']) / 50));
$page = min($page, $totalPages);
$rows = $print ? $report['rows'] : array_slice($report['rows'], ($page - 1) * 50, 50);
if (($_GET['export'] ?? '') === 'summary_excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="Ringkasan_Presensi_Tahsin_' . $summaryFilters['start_date'] . '_' . $summaryFilters['end_date'] . '.xls"');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>body{font-family:Arial,sans-serif}td{mso-number-format:"\\@"}table{border-collapse:collapse}td,th{border:1px solid #999;padding:6px}h1,h2{color:#17365d}</style></head><body><h1>Ringkasan Statistik Presensi Tahsin</h1><p>Periode sesi ' . tahsinH($summaryFilters['start_date'] . ' s/d ' . $summaryFilters['end_date']) . '</p><p>Data agregat tanpa nama wali dan anak. Data kosong bukan Alfa.</p>';
    tahsinRenderMeetingSummary($meetingReport, true);
    echo '</body></html>'; exit;
}
if (($_GET['export'] ?? '') === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="Rekap_Lengkap_Tahsin_' . $filters['start_date'] . '_' . $filters['end_date'] . '.xls"');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>td{mso-number-format:"\\@"}table{border-collapse:collapse}td,th{border:1px solid #ddd}</style></head><body><h1>Rekap Lengkap Tahsin Wali Santri</h1><p>Periode sesi ' . tahsinH($filters['start_date'] . ' s/d ' . $filters['end_date']) . '</p>';
    tahsinRenderCards($report['summary']); tahsinRenderGroupTable($report['halaqoh']); tahsinRenderGroupTable($report['guru'], true);
    tahsinRenderWaliTable($report['rows'], $filters, true);
    // Export every source detail as well, with formula-safe text cells.
    echo '<h2>Detail Pekanan</h2><table><thead><tr><th>Wali</th><th>Sesi Ahad</th><th>Status data</th><th>Tanggal pelaksanaan</th><th>H/S/I/A</th><th>Alasan / Capaian</th></tr></thead><tbody>';
    foreach ($report['rows'] as $r) foreach ($r['details'] as $cell) foreach ($cell['records'] ?: [null] as $record) {
        echo '<tr>';
        foreach ([$r['nama_bapak'], $cell['session_date'], tahsinCellLabel($cell), $record['tanggal'] ?? '—', $record['status'] ?? '—', $record ? ($record['status'] === 'H' ? tahsinMaterial($record) : ($record['alasan'] ?? '—')) : 'Belum diinput'] as $value) {
            if (preg_match('/^[=+@\-\t\r]/', (string) $value)) $value = "'" . $value;
            echo '<td>' . tahsinH($value) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></body></html>'; exit;
}
$pageTitle = $tahsinPageTitle ?? 'Rekap Lengkap';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= appEscape(appAssetUrl('assets/css/tahsin-report.css')) ?>">
<?php if ($meeting): ?><script>document.body.classList.add('tahsin-meeting');</script><?php endif; ?>
<?php if ($summaryOnly): ?><script>document.body.classList.add('tahsin-summary-print');</script>
<div class="tahsin-report tahsin-summary-page">
<div class="tahsin-heading"><div><h2>Ringkasan Statistik Presensi Tahsin</h2><p class="tahsin-note">Periode sesi <?= tahsinH($summaryFilters['start_date']) ?> s/d <?= tahsinH($summaryFilters['end_date']) ?> · Data agregat tanpa nama wali atau anak.</p></div><div class="tahsin-actions no-print"><button class="tahsin-button primary" onclick="window.print()">Cetak / Simpan PDF</button><button class="tahsin-button" type="button" data-export-meeting-png>Unduh PNG</button><a class="tahsin-button" href="?<?= tahsinH(tahsinQuery($summaryFilters, ['export' => 'summary_excel'])) ?>">Unduh Excel</a><a class="tahsin-button" href="?<?= tahsinH(tahsinQuery($filters)) ?>#ringkasan-pertemuan">Kembali ke Rekap</a></div></div>
<?php if (!$meetingReport['schema_ready']): ?><div class="tahsin-alert">Histori roster belum diaktifkan. Presensi tersimpan tetap ditampilkan, tetapi jumlah peserta dan kekosongan roster lama belum dapat diverifikasi.</div><?php endif; ?>
<details class="tahsin-panel no-print" open><summary><strong>Filter Ringkasan</strong></summary><form method="get" class="tahsin-filters" style="margin-top:15px"><input type="hidden" name="summary_only" value="1"><label>Awal periode sesi<input type="date" name="start_date" value="<?= tahsinH($summaryFilters['start_date']) ?>" required></label><label>Akhir periode sesi<input type="date" name="end_date" value="<?= tahsinH($summaryFilters['end_date']) ?>" required></label>
<label>Halaqoh<select name="halaqoh_id"><option value="">Semua halaqoh</option><?php foreach ($dataset['halaqoh'] as $h): if ($role === 'ustadz' && (int) $h['ustadz_id'] !== $userId && !array_filter($dataset['history'], static fn($m) => (int) $m['ustadz_id'] === $userId && (int) $m['halaqoh_id'] === (int) $h['id'])) continue; ?><option value="<?= (int) $h['id'] ?>" <?= $summaryFilters['halaqoh_id'] === (int) $h['id'] ? 'selected' : '' ?>><?= tahsinH($h['nama_halaqoh']) ?></option><?php endforeach; ?></select></label>
<?php if ($role !== 'ustadz'): $teachers = []; foreach ($dataset['halaqoh'] as $h) $teachers[(int) $h['ustadz_id']] = $h['nama_ustadz']; foreach ($dataset['history'] as $h) $teachers[(int) $h['ustadz_id']] = $h['nama_ustadz']; ?><label>Guru<select name="ustadz_id"><option value="">Semua guru</option><?php foreach ($teachers as $id => $name): ?><option value="<?= $id ?>" <?= $summaryFilters['ustadz_id'] === $id ? 'selected' : '' ?>><?= tahsinH($name) ?></option><?php endforeach; ?></select></label><?php endif; ?><div class="tahsin-actions" style="align-items:end"><button class="tahsin-button primary">Terapkan</button><a class="tahsin-button" href="rekap-lengkap.php?summary_only=1">Reset</a></div></form></details>
<section class="tahsin-panel"><h3>Grafik Ringkas per Pertemuan</h3><div class="tahsin-chart"><canvas id="tahsin-trend" role="img" aria-label="Grafik ringkasan presensi per sesi Ahad"></canvas></div></section>
<?php tahsinRenderMeetingSummary($meetingReport); ?>
</div>
<script type="application/json" id="tahsin-chart-data"><?= json_encode($meetingReport['trend'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script type="application/json" id="tahsin-meeting-export-data"><?= json_encode(['payload' => tahsinMeetingPayload($meetingReport), 'start_date' => $summaryFilters['start_date'], 'end_date' => $summaryFilters['end_date']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= appEscape(appAssetUrl('assets/js/chart.umd.min.js')) ?>"></script><script src="<?= appEscape(appAssetUrl('assets/js/tahsin-report.js')) ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; exit; endif; ?>
<div class="tahsin-report">
<div class="tahsin-heading"><div><h2><?= tahsinH($pageTitle) ?> Tahsin Wali Santri</h2><p class="tahsin-note">Periode sesi <?= tahsinH($filters['start_date']) ?> s/d <?= tahsinH($filters['end_date']) ?> · Dibuat <?= tahsinNow()->format('d/m/Y H:i') ?> WIB<br>Hadir dan Alfa berdasarkan catatan guru. Data kosong tidak dinilai sebagai ketidakhadiran.</p></div>
<div class="tahsin-actions no-print">
<a class="tahsin-button primary" href="persentase-keaktifan.php?<?= tahsinH(tahsinQuery($filters, ['view' => 'ringkasan'])) ?>">Ringkasan Total Periode</a>
<a class="tahsin-button primary" href="persentase-keaktifan.php?<?= tahsinH(tahsinQuery($filters)) ?>">Persentase &amp; Keaktifan</a>
<a class="tahsin-button" href="?<?= tahsinH(tahsinQuery($filters, ['mode' => $meeting ? '' : 'rapat', 'page' => $page])) ?>"><?= $meeting ? 'Keluar Mode Rapat' : 'Mode Rapat' ?></a>
<a class="tahsin-button" href="?<?= tahsinH(tahsinQuery($summaryFilters, ['summary_only' => '1'])) ?>">Ringkasan Pertemuan</a>
<a class="tahsin-button" href="?<?= tahsinH(tahsinQuery($filters, ['export' => 'excel'])) ?>">Unduh Excel</a>
<?php if ($print): ?><button class="tahsin-button primary" onclick="window.print()">Cetak / Simpan PDF</button><?php else: ?><a class="tahsin-button primary" target="_blank" rel="noopener" href="?<?= tahsinH(tahsinQuery($filters, ['print' => '1'])) ?>">Cetak Semua / PDF</a><?php endif; ?>
<a class="tahsin-button" href="pantau-input.php">Pemantauan Input</a></div></div>
<?php if ($error): ?><div class="tahsin-alert error" role="alert"><?= tahsinH($error) ?></div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'verified'): ?><div class="tahsin-alert" role="status">Keputusan verifikasi tersimpan. Rekap dihitung ulang dari catatan terpilih.</div><?php endif; ?>
<?php if (!$report['schema_ready']): ?><div class="tahsin-alert">Histori roster belum diaktifkan. Rekap presensi tetap tersedia; kelengkapan historis belum terverifikasi. Admin perlu menerapkan migrasi pelaporan.</div><?php endif; ?>
<details class="tahsin-panel no-print tahsin-hide-meeting" open><summary><strong>Filter Rekap</strong></summary><form method="get" class="tahsin-filters" style="margin-top:15px">
<label>Awal periode sesi<input type="date" name="start_date" value="<?= tahsinH($filters['start_date']) ?>" required></label><label>Akhir periode sesi<input type="date" name="end_date" value="<?= tahsinH($filters['end_date']) ?>" required></label>
<label>Halaqoh<select name="halaqoh_id"><option value="">Semua halaqoh</option><?php foreach ($dataset['halaqoh'] as $h): if ($role === 'ustadz' && (int) $h['ustadz_id'] !== $userId && !array_filter($dataset['history'], static fn($m) => (int) $m['ustadz_id'] === $userId && (int) $m['halaqoh_id'] === (int) $h['id'])) continue; ?><option value="<?= (int) $h['id'] ?>" <?= $filters['halaqoh_id'] === (int) $h['id'] ? 'selected' : '' ?>><?= tahsinH($h['nama_halaqoh']) ?></option><?php endforeach; ?></select></label>
<?php if ($role !== 'ustadz'): $teachers = []; foreach ($dataset['halaqoh'] as $h) $teachers[(int) $h['ustadz_id']] = $h['nama_ustadz']; foreach ($dataset['history'] as $h) $teachers[(int) $h['ustadz_id']] = $h['nama_ustadz']; ?><label>Guru<select name="ustadz_id"><option value="">Semua guru</option><?php foreach ($teachers as $id => $name): ?><option value="<?= $id ?>" <?= $filters['ustadz_id'] === $id ? 'selected' : '' ?>><?= tahsinH($name) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<label>Kelas anak<select name="kelas"><option value="">Semua kelas</option><?php $classes = array_unique(array_filter(array_column($dataset['children'], 'kelas'))); sort($classes); foreach ($classes as $class): ?><option <?= $filters['kelas'] === $class ? 'selected' : '' ?>><?= tahsinH($class) ?></option><?php endforeach; ?></select></label>
<label>Kategori saat ini<select name="kategori"><option value="">Semua kategori</option><?php foreach (['reguler' => 'Reguler', 'askar' => 'Askar', 'tahsin_luar' => 'Tahsin Luar'] as $key => $label): ?><option value="<?= $key ?>" <?= $filters['kategori'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<label>Kepesertaan saat ini<select name="aktif"><option value="">Aktif dan Arsip</option><option value="1" <?= $filters['aktif'] === '1' ? 'selected' : '' ?>>Aktif</option><option value="0" <?= $filters['aktif'] === '0' ? 'selected' : '' ?>>Arsip</option></select></label>
<label>Kualitas data<select name="kelengkapan"><option value="">Semua kualitas</option><?php foreach (['complete' => 'Lengkap', 'missing' => 'Belum Diinput', 'partial' => 'Sebagian Diinput', 'capaian' => 'Capaian Belum Lengkap', 'conflict' => 'Perlu Verifikasi', 'unverified' => 'Belum Terverifikasi', 'external' => 'Tahsin Luar Tanpa Data', 'unassigned' => 'Belum Punya Halaqoh', 'archived' => 'Arsip Tanpa Data Periode', 'no_data' => 'Tidak Ada Sesi'] as $key => $label): ?><option value="<?= $key ?>" <?= $filters['kelengkapan'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<label>Status pemantauan wali<select name="risk"><option value="">Semua status</option><?php foreach (['rawan' => 'Alfa tercatat ≥ 3', 'pantau' => 'Alfa tercatat 1–2', 'stabil' => 'Tanpa Alfa tercatat', 'belum_data' => 'Belum Ada Data'] as $key => $label): ?><option value="<?= $key ?>" <?= $filters['risk'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<label>Cari wali / anak / halaqoh<input name="search" value="<?= tahsinH($filters['search']) ?>" maxlength="100" placeholder="Nama wali atau anak"></label>
<?php if ($filters['wali_santri_id']): ?><input type="hidden" name="wali_santri_id" value="<?= $filters['wali_santri_id'] ?>"><?php endif; ?>
<div class="tahsin-actions" style="align-items:end"><button class="tahsin-button primary">Terapkan</button><a class="tahsin-button" href="rekap-lengkap.php">Reset</a></div></form></details>
<?php tahsinRenderCards($report['summary']); ?>
<div class="tahsin-summary-export-actions no-print"><a class="tahsin-button primary" href="?<?= tahsinH(tahsinQuery($summaryFilters, ['summary_only' => '1'])) ?>">Buka Ringkasan Pertemuan</a><a class="tahsin-button" href="?<?= tahsinH(tahsinQuery($summaryFilters, ['export' => 'summary_excel'])) ?>">Excel Statistik</a><a class="tahsin-button" target="_blank" rel="noopener" href="?<?= tahsinH(tahsinQuery($summaryFilters, ['summary_only' => '1'])) ?>">Cetak / PDF Statistik</a><button class="tahsin-button" type="button" data-export-meeting-png>Unduh PNG Statistik</button></div>
<section class="tahsin-panel"><h3>Grafik Ringkas per Pertemuan</h3><div class="tahsin-chart"><canvas id="tahsin-trend" role="img" aria-label="Grafik ringkasan presensi per sesi Ahad"></canvas></div></section>
<?php tahsinRenderMeetingSummary($meetingReport); ?>
<section class="tahsin-panel"><h3>Rekap Seluruh Wali · <?= count($report['rows']) ?> orang</h3><?php tahsinRenderWaliTable($rows, $filters); ?>
<?php if (!$print): ?><div class="tahsin-pagination no-print"><span>Halaman <?= $page ?> / <?= $totalPages ?> · maksimal 50 wali per halaman</span><div class="tahsin-actions"><?php if ($page > 1): ?><a class="tahsin-button" href="?<?= tahsinH(tahsinQuery($filters, ['page' => $page - 1, 'mode' => $meeting ? 'rapat' : ''])) ?>">Sebelumnya</a><?php endif; ?><?php if ($page < $totalPages): ?><a class="tahsin-button" href="?<?= tahsinH(tahsinQuery($filters, ['page' => $page + 1, 'mode' => $meeting ? 'rapat' : ''])) ?>">Berikutnya</a><?php endif; ?></div></div><?php endif; ?></section>
<?php tahsinRenderDetails($rows, $canVerify && $report['schema_ready'] && !$print, $print); ?>
</div>
<script type="application/json" id="tahsin-chart-data"><?= json_encode($meetingReport['trend'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script type="application/json" id="tahsin-meeting-export-data"><?= json_encode(['payload' => tahsinMeetingPayload($meetingReport), 'start_date' => $summaryFilters['start_date'], 'end_date' => $summaryFilters['end_date']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= appEscape(appAssetUrl('assets/js/chart.umd.min.js')) ?>"></script><script src="<?= appEscape(appAssetUrl('assets/js/tahsin-report.js')) ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
