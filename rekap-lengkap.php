<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_helper.php';
require_once __DIR__ . '/includes/tahsin_report_helper.php';
require_once __DIR__ . '/includes/tahsin_report_view.php';
checkRole(['admin', 'pj_tahfidz', 'kepsek', 'ustadz']);
$role = $_SESSION['role'];
$userId = (int) $_SESSION['user_id'];
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
} catch (InvalidArgumentException $e) {
    http_response_code(400); $error = $e->getMessage(); $filters = tahsinFilters();
}
try {
    $dataset = tahsinLoadDataset($pdo, $filters['end_date']);
    $report = tahsinAssembleReport($dataset, $filters, $role, $userId);
} catch (Throwable $e) {
    reportApplicationError($e, 'rekap-lengkap');
    http_response_code(500);
    $pageTitle = 'Rekap Lengkap';
    require __DIR__ . '/includes/header.php'; require __DIR__ . '/includes/sidebar.php';
    echo '<div role="alert" class="p-6 rounded-xl bg-amber-50 text-amber-900">Rekap belum dapat dimuat. Silakan muat ulang atau hubungi admin. Angka kehadiran belum tersedia.</div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}
$print = ($_GET['print'] ?? '') === '1';
$meeting = ($_GET['mode'] ?? '') === 'rapat';
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalPages = max(1, (int) ceil(count($report['rows']) / 50));
$page = min($page, $totalPages);
$rows = $print ? $report['rows'] : array_slice($report['rows'], ($page - 1) * 50, 50);
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
<link rel="stylesheet" href="assets/css/tahsin-report.css">
<?php if ($meeting): ?><script>document.body.classList.add('tahsin-meeting');</script><?php endif; ?>
<div class="tahsin-report">
<div class="tahsin-heading"><div><h2><?= tahsinH($pageTitle) ?> Tahsin Wali Santri</h2><p class="tahsin-note">Periode sesi <?= tahsinH($filters['start_date']) ?> s/d <?= tahsinH($filters['end_date']) ?> · Dibuat <?= tahsinNow()->format('d/m/Y H:i') ?> WIB<br>Hadir dan Alfa berdasarkan catatan guru. Data kosong tidak dinilai sebagai ketidakhadiran.</p></div>
<div class="tahsin-actions no-print">
<a class="tahsin-button" href="?<?= tahsinH(tahsinQuery($filters, ['mode' => $meeting ? '' : 'rapat', 'page' => $page])) ?>"><?= $meeting ? 'Keluar Mode Rapat' : 'Mode Rapat' ?></a>
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
<section class="tahsin-panel"><h3>Tren Kehadiran Pekanan</h3><div class="tahsin-chart"><canvas id="tahsin-trend" role="img" aria-label="Tren jumlah hadir, sakit, izin, alfa, dan data belum diinput per sesi"></canvas></div><p class="tahsin-note">Jumlah belum diinput hanya ditampilkan bila roster sesi diketahui. Grafik tidak mengubah data kosong menjadi Alfa.</p></section>
<div class="tahsin-group-grid"><?php tahsinRenderGroupTable($report['halaqoh']); tahsinRenderGroupTable($report['guru'], true); ?></div>
<section class="tahsin-panel"><h3>Rekap Seluruh Wali · <?= count($report['rows']) ?> orang</h3><?php tahsinRenderWaliTable($rows, $filters); ?>
<?php if (!$print): ?><div class="tahsin-pagination no-print"><span>Halaman <?= $page ?> / <?= $totalPages ?> · maksimal 50 wali per halaman</span><div class="tahsin-actions"><?php if ($page > 1): ?><a class="tahsin-button" href="?<?= tahsinH(tahsinQuery($filters, ['page' => $page - 1, 'mode' => $meeting ? 'rapat' : ''])) ?>">Sebelumnya</a><?php endif; ?><?php if ($page < $totalPages): ?><a class="tahsin-button" href="?<?= tahsinH(tahsinQuery($filters, ['page' => $page + 1, 'mode' => $meeting ? 'rapat' : ''])) ?>">Berikutnya</a><?php endif; ?></div></div><?php endif; ?></section>
<?php tahsinRenderDetails($rows, $canVerify && $report['schema_ready'] && !$print, $print); ?>
</div>
<script type="application/json" id="tahsin-chart-data"><?= json_encode($report['trend'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="assets/js/chart.umd.min.js"></script><script src="assets/js/tahsin-report.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
