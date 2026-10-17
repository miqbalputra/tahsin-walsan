<?php
if (!isset($report, $filters, $dataset, $role, $userId)) { http_response_code(404); exit; }
require_once __DIR__ . '/tahsin_activity_view.php';
$print = ($_GET['print'] ?? '') === '1';
$meeting = ($_GET['mode'] ?? '') === 'rapat';
$sort = in_array($_GET['sort'] ?? '', ['nama', 'hadir_desc', 'hadir_asc', 'alfa'], true) ? $_GET['sort'] : 'nama';
$activityQuery = $filters + ['sort' => $sort];
$activityRows = tahsinActivitySort($report['rows'], $sort);
$pageSize = $meeting ? 12 : 50;
$totalPages = max(1, (int) ceil(count($activityRows) / $pageSize));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);
$offset = $print ? 0 : ($page - 1) * $pageSize;
$rows = $print ? $activityRows : array_slice($activityRows, $offset, $pageSize);
$periodLabel = date('d/m/Y', strtotime($filters['start_date'])) . ' – ' . date('d/m/Y', strtotime($filters['end_date']));
$scopeLabels = [];
foreach ($dataset['halaqoh'] as $h) if ((int) $h['id'] === $filters['halaqoh_id']) $scopeLabels[] = $h['nama_halaqoh'];
if ($filters['ustadz_id']) {
    foreach (array_merge($dataset['halaqoh'], $dataset['history']) as $h) {
        if ((int) $h['ustadz_id'] === $filters['ustadz_id']) { $scopeLabels[] = $h['nama_ustadz']; break; }
    }
}
if ($filters['aktif'] !== '') $scopeLabels[] = $filters['aktif'] === '1' ? 'Wali aktif' : 'Wali arsip';
if ($filters['search']) $scopeLabels[] = 'Hasil pencarian';
foreach (['kelas' => 'Kelas', 'kategori' => 'Kategori', 'kelengkapan' => 'Kualitas', 'risk' => 'Status pemantauan', 'wali_santri_id' => 'ID wali'] as $key => $label) {
    if ($filters[$key]) $scopeLabels[] = $label . ': ' . $filters[$key];
}
$scopeLabel = implode(' · ', $scopeLabels) ?: ($role === 'ustadz' ? 'Kelompok tanggung jawab Anda' : 'Seluruh wali santri');
$formula = 'Keaktifan = persentase Hadir. Persentase setiap status = jumlah status ÷ (Hadir + Sakit + Izin + Alfa tercatat) × 100%. Data kosong dan konflik tidak masuk pembagi serta tidak dianggap Alfa. Tanpa presensi terkonfirmasi, persentase ditampilkan —. Total sekolah dihitung dari seluruh presensi, bukan rata-rata persentase wali.';

if (($_GET['export'] ?? '') === 'excel') {
    if ($error) { http_response_code(400); header('Content-Type: text/plain; charset=utf-8'); echo $error; return; }
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="Persentase_Keaktifan_Tahsin_' . $filters['start_date'] . '_' . $filters['end_date'] . '.xls"');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>body{font-family:Arial,sans-serif}table{border-collapse:collapse}th,td{border:1px solid #aaa;padding:8px}td{mso-number-format:"\\@"}small{display:block}h1,h2,h3{color:#17365d}</style></head><body><h1>Persentase Kehadiran &amp; Keaktifan Wali Santri</h1><p>Periode sesi ' . tahsinH($periodLabel) . '</p><p>' . tahsinMeetingText($scopeLabel, true) . '</p><p>' . tahsinH($formula) . '</p>';
    tahsinRenderActivitySummary($report);
    echo '<h2>Persentase Masing-masing Wali</h2>';
    tahsinRenderActivityWali($activityRows, $filters, true);
    tahsinRenderActivityGroups($report['halaqoh'], false, true);
    tahsinRenderActivityGroups($report['guru'], true, true);
    echo '</body></html>';
    return;
}
$pageTitle = 'Persentase & Keaktifan';
require __DIR__ . '/header.php';
require __DIR__ . '/sidebar.php';
?>
<link rel="stylesheet" href="assets/css/tahsin-report.css">
<link rel="stylesheet" href="assets/css/tahsin-activity.css">
<?php if ($meeting): ?><script>document.body.classList.add('tahsin-meeting');</script><?php endif; ?>
<div class="tahsin-report tahsin-activity-page">
<div class="tahsin-heading"><div><p class="tahsin-activity-eyebrow">TAHSIN WALI SANTRI</p><h2>Persentase Kehadiran &amp; Keaktifan</h2><p class="tahsin-note">Periode sesi <strong><?= tahsinH($periodLabel) ?></strong><br><?= tahsinH($scopeLabel) ?> · Dibuat <?= tahsinNow()->format('d/m/Y H:i') ?> WIB</p></div>
<div class="tahsin-actions no-print">
<a class="tahsin-button primary" href="?<?= tahsinH(tahsinQuery($activityQuery, ['mode' => $meeting ? '' : 'rapat'])) ?>"><?= $meeting ? 'Keluar Mode Rapat' : 'Mode Rapat / Proyektor' ?></a>
<?php if ($meeting): ?><button class="tahsin-button" type="button" data-activity-fullscreen>Layar penuh</button><?php endif; ?>
<a class="tahsin-button" href="?<?= tahsinH(tahsinQuery($activityQuery, ['export' => 'excel'])) ?>">Unduh Excel Semua Wali</a>
<?php if ($print): ?><button class="tahsin-button" type="button" onclick="window.print()">Cetak / Simpan PDF</button><?php else: ?><a class="tahsin-button" target="_blank" rel="noopener" href="?<?= tahsinH(tahsinQuery($activityQuery, ['print' => '1'])) ?>">Cetak Semua / PDF</a><?php endif; ?>
<a class="tahsin-button" href="rekap-lengkap.php?<?= tahsinH(tahsinQuery($filters)) ?>">Rekap Lengkap</a>
</div></div>
<?php if ($error): ?><div class="tahsin-alert error" role="alert"><?= tahsinH($error) ?> Periode ditampilkan kembali ke bulan berjalan. Terapkan filter yang benar sebelum membagikan laporan.</div><?php endif; ?>
<details class="tahsin-panel no-print tahsin-hide-meeting" open><summary><strong>Pilih periode dan kelompok</strong></summary>
<form method="get" class="tahsin-filters" style="margin-top:15px">
<label>Awal periode sesi<input type="date" name="start_date" value="<?= tahsinH($filters['start_date']) ?>" required></label>
<label>Akhir periode sesi<input type="date" name="end_date" value="<?= tahsinH($filters['end_date']) ?>" required></label>
<label>Halaqoh<select name="halaqoh_id"><option value="">Semua halaqoh</option><?php foreach ($dataset['halaqoh'] as $h): if ($role === 'ustadz' && (int) $h['ustadz_id'] !== $userId && !array_filter($dataset['history'], static fn($m) => (int) $m['ustadz_id'] === $userId && (int) $m['halaqoh_id'] === (int) $h['id'])) continue; ?><option value="<?= (int) $h['id'] ?>" <?= $filters['halaqoh_id'] === (int) $h['id'] ? 'selected' : '' ?>><?= tahsinH($h['nama_halaqoh']) ?></option><?php endforeach; ?></select></label>
<?php if ($role !== 'ustadz'): $teachers = []; foreach (array_merge($dataset['halaqoh'], $dataset['history']) as $h) $teachers[(int) $h['ustadz_id']] = $h['nama_ustadz']; ?><label>Guru<select name="ustadz_id"><option value="">Semua guru</option><?php foreach ($teachers as $id => $name): ?><option value="<?= $id ?>" <?= $filters['ustadz_id'] === $id ? 'selected' : '' ?>><?= tahsinH($name) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<label>Kepesertaan<select name="aktif"><option value="">Aktif dan arsip</option><option value="1" <?= $filters['aktif'] === '1' ? 'selected' : '' ?>>Aktif</option><option value="0" <?= $filters['aktif'] === '0' ? 'selected' : '' ?>>Arsip</option></select></label>
<label>Cari wali / anak<input name="search" value="<?= tahsinH($filters['search']) ?>" maxlength="100" placeholder="Nama wali atau anak"></label>
<label>Urutkan wali<select name="sort"><?php foreach (['nama' => 'Nama A–Z', 'hadir_desc' => 'Hadir tertinggi', 'hadir_asc' => 'Hadir terendah', 'alfa' => 'Alfa tertinggi'] as $key => $label): ?><option value="<?= $key ?>" <?= $sort === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<?php foreach (['kelas', 'kategori', 'kelengkapan', 'risk', 'wali_santri_id'] as $key): if (!$filters[$key]) continue; ?><input type="hidden" name="<?= $key ?>" value="<?= tahsinH($filters[$key]) ?>"><?php endforeach; ?>
<div class="tahsin-actions" style="align-items:end"><button class="tahsin-button primary">Terapkan</button><a class="tahsin-button" href="persentase-keaktifan.php">Reset</a></div>
</form>
<div class="tahsin-actions tahsin-activity-presets"><?php $today = tahsinNow(); foreach (['Bulan ini' => $today->format('Y-m-01'), 'Bulan lalu' => $today->modify('first day of previous month')->format('Y-m-d'), 'Tahun ini' => $today->format('Y-01-01')] as $label => $start): $end = $label === 'Bulan lalu' ? $today->modify('last day of previous month')->format('Y-m-d') : $today->format('Y-m-d'); ?><a class="tahsin-button" href="?<?= tahsinH(tahsinQuery($activityQuery, ['start_date' => $start, 'end_date' => $end])) ?>"><?= $label ?></a><?php endforeach; ?></div>
</details>
<nav class="tahsin-actions tahsin-activity-sections no-print" aria-label="Bagian persentase"><a class="tahsin-button" href="#ringkasan-keaktifan">Ringkasan</a><a class="tahsin-button" href="#persentase-wali">Masing-masing Wali</a><a class="tahsin-button" href="#persentase-kelompok">Halaqoh &amp; Guru</a></nav>
<p class="tahsin-activity-formula"><?= tahsinH($formula) ?></p>
<?php if (!$report['schema_ready']): ?><div class="tahsin-alert">Histori roster belum diaktifkan. Presensi tersimpan tetap ditampilkan; jumlah kewajiban sesi dan kelengkapan histori belum terverifikasi.</div><?php endif; ?>
<?php tahsinRenderActivitySummary($report); ?>
<section class="tahsin-panel" id="persentase-wali"><div class="tahsin-pending-header"><h3>Persentase Masing-masing Wali · <?= count($activityRows) ?> orang</h3><span class="tahsin-note">Hadir, Izin, Alfa, dan Sakit dari presensi tercatat</span></div>
<?php tahsinRenderActivityWali($rows, $filters, false, $offset); ?>
<?php if (!$print): ?><div class="tahsin-pagination no-print"><span>Halaman <?= $page ?> / <?= $totalPages ?> · <?= count($activityRows) ?> wali · <?= $pageSize ?> wali per halaman. Excel dan PDF mencakup semua wali.</span><div class="tahsin-actions"><?php if ($page > 1): ?><a class="tahsin-button" href="?<?= tahsinH(tahsinQuery($activityQuery, ['page' => $page - 1, 'mode' => $meeting ? 'rapat' : ''])) ?>#persentase-wali">Sebelumnya</a><?php endif; ?><?php if ($page < $totalPages): ?><a class="tahsin-button primary" href="?<?= tahsinH(tahsinQuery($activityQuery, ['page' => $page + 1, 'mode' => $meeting ? 'rapat' : ''])) ?>#persentase-wali">Berikutnya</a><?php endif; ?></div></div><?php endif; ?>
<p class="tahsin-note">Persentase tanpa data ditampilkan —, bukan 0%. Wali dengan beberapa anak dihitung sekali per sesi. Halaqoh/guru pada tabel adalah penempatan terakhir yang tersedia dalam periode.</p></section>
<div id="persentase-kelompok"><?php tahsinRenderActivityGroups($report['halaqoh']); tahsinRenderActivityGroups($report['guru'], true); ?></div>
<p class="tahsin-note no-print" data-activity-fullscreen-status role="status"></p>
</div>
<script src="assets/js/tahsin-activity.js"></script>
<?php require __DIR__ . '/footer.php'; ?>
