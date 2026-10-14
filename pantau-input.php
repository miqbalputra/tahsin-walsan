<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_helper.php';
require_once __DIR__ . '/includes/tahsin_report_helper.php';
require_once __DIR__ . '/includes/tahsin_report_view.php';
checkRole(['admin', 'pj_tahfidz', 'kepsek', 'ustadz']);
$role = $_SESSION['role']; $userId = (int) $_SESSION['user_id'];
$error = '';
try {
    $groups = tahsinFetchPending($pdo, $role, $userId, $_GET);
} catch (InvalidArgumentException $e) { $error = $e->getMessage(); $groups = []; }
catch (Throwable $e) { reportApplicationError($e, 'pantau-input'); $error = 'Data pemantauan belum dapat diambil. Silakan muat ulang.'; $groups = []; }
$allGroups = $groups;
$status = (string) ($_GET['status_isi'] ?? 'pending');
$groups = array_values(array_filter($groups, static fn($g) => $status === 'all' || ($status === 'pending' ? $g['belum_diisi'] > 0 : $g['status_isi'] === $status)));
$guruCount = count(array_unique(array_column(array_filter($allGroups, static fn($g) => $g['belum_diisi'] > 0), 'ustadz_id')));
$canMessage = in_array($role, ['admin', 'pj_tahfidz'], true);
$configuredUrl = rtrim((string) getenv('APP_URL'), '/');
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$base = filter_var($configuredUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#', $configuredUrl) ? $configuredUrl . '/' :
    (preg_match('/^[a-zA-Z0-9.\-]+(?::\d+)?$/', $host) ? ($is_https ? 'https://' : 'http://') . $host . appBasePath() . '/' : appBasePath() . '/');
$pageTitle = 'Pemantauan Input';
require __DIR__ . '/includes/header.php'; require __DIR__ . '/includes/sidebar.php';
?>
<link rel="stylesheet" href="assets/css/tahsin-report.css">
<div class="tahsin-report">
<div class="tahsin-heading"><div><h2>Pemantauan Input Guru Tahsin</h2><p class="tahsin-note">Pantau presensi dan capaian yang masih kurang, termasuk sesi sebelumnya.<br>Data kosong belum menunjukkan status wali. Batas input: Senin 10.00 WIB, termasuk sesi pengganti.</p></div><a class="tahsin-button" href="rekap-lengkap.php">Buka Rekap Lengkap</a></div>
<?php if ($error): ?><div class="tahsin-alert error" role="alert"><?= tahsinH($error) ?></div><?php endif; ?>
<div class="tahsin-cards"><div class="tahsin-card purple"><span>Guru perlu melengkapi</span><strong><?= $guruCount ?></strong></div><div class="tahsin-card amber"><span>Presensi perlu dilengkapi</span><strong><?= array_sum(array_column($allGroups, 'missing_presensi')) + array_sum(array_column($allGroups, 'missing_keterangan')) ?></strong></div><div class="tahsin-card amber"><span>Capaian belum lengkap</span><strong><?= array_sum(array_column($allGroups, 'missing_capaian')) ?></strong></div><div class="tahsin-card amber"><span>Perlu verifikasi</span><strong><?= array_sum(array_column($allGroups, 'conflicts')) ?></strong></div></div>
<form class="tahsin-panel tahsin-filters no-print" method="get" style="margin-top:20px">
<label>Awal sesi (opsional)<input type="date" name="start_date" value="<?= tahsinH($_GET['start_date'] ?? '') ?>"></label><label>Akhir sesi (opsional)<input type="date" name="end_date" value="<?= tahsinH($_GET['end_date'] ?? '') ?>"></label>
<label>Status input<select name="status_isi"><?php foreach (['pending' => 'Semua kekurangan', 'all' => 'Semua, termasuk lengkap', 'belum_sama_sekali' => 'Belum Diinput', 'belum_lengkap' => 'Sebagian Diinput', 'capaian_belum_lengkap' => 'Capaian Belum Lengkap', 'perlu_verifikasi' => 'Perlu Verifikasi', 'lengkap' => 'Lengkap'] as $key => $label): ?><option value="<?= $key ?>" <?= $status === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<label>Guru / Halaqoh<select name="halaqoh_id"><option value="">Semua tanggung jawab</option><?php $options = []; foreach ($allGroups as $g) $options[$g['halaqoh_id']] = $g['ustadz_name'] . ' · ' . $g['nama_halaqoh']; foreach ($options as $id => $label): ?><option value="<?= $id ?>" <?= (int) ($_GET['halaqoh_id'] ?? 0) === $id ? 'selected' : '' ?>><?= tahsinH($label) ?></option><?php endforeach; ?></select></label>
<div class="tahsin-actions" style="align-items:end"><button class="tahsin-button primary">Terapkan</button><a class="tahsin-button" href="pantau-input.php">Reset</a></div></form>
<div class="tahsin-pending">
<?php if (!$groups && !$error): ?><section class="tahsin-panel"><h3>Tidak ada kekurangan pada filter ini</h3><p class="tahsin-note">Kelengkapan historis sebelum pemantauan diaktifkan tetap belum terverifikasi. Periksa rekap lengkap untuk rincian data lama.</p></section><?php endif; ?>
<?php foreach ($groups as $index => $g):
    $inputPath = 'ustadz/form-presensi.php?id=' . $g['halaqoh_id'] . '&tanggal=' . $g['tanggal_tahsin'];
    $reviewPath = 'rekap-lengkap.php?' . http_build_query(['start_date' => $g['tanggal_tahsin'], 'end_date' => $g['tanggal_tahsin'], 'halaqoh_id' => $g['halaqoh_id']]);
    $message = "Assalamualaikum Ustadz " . $g['ustadz_name'] . ",\n\nMohon melengkapi presensi dan capaian Tahsin kelompok " . $g['nama_halaqoh'] . " untuk sesi Ahad " . date('d/m/Y', strtotime($g['tanggal_tahsin'])) . ".\n\n" . $g['keterangan'] . ".\nPresensi belum diinput: " . $g['missing_presensi'] . "; capaian belum lengkap: " . $g['missing_capaian'] . "; perlu verifikasi: " . $g['conflicts'] . ".\nBatas input: " . date('d/m/Y H:i', strtotime($g['deadline_at'])) . " WIB.\n\n" . $base . $inputPath . "\n\nData yang belum diinput belum dianggap Alfa. Jazakumullah khairan.";
    if ($g['missing_keterangan']) $message .= "\nKeterangan sakit/izin yang perlu dilengkapi: " . $g['missing_keterangan'] . ".";
    if (!$g['data_verified']) $message .= "\nDaftar peserta sesi ini belum terverifikasi; mohon konfirmasi kepesertaan sesuai sesi tersebut.";
?>
<section class="tahsin-panel"><div class="tahsin-pending-header"><div><h3 style="margin-bottom:4px"><?= tahsinH($g['ustadz_name']) ?> · <?= tahsinH($g['nama_halaqoh']) ?></h3><p class="tahsin-note" style="margin:0">Sesi Ahad <?= date('d/m/Y', strtotime($g['tanggal_tahsin'])) ?> · Batas <?= date('d/m/Y H:i', strtotime($g['deadline_at'])) ?> WIB</p></div><div><span class="tahsin-badge <?= $g['belum_diisi'] ? 'partial' : 'complete' ?>"><?= tahsinH($g['status_label']) ?></span> <span class="tahsin-badge"><?= tahsinH($g['timeliness']) ?></span></div></div>
<div class="tahsin-pending-stats"><strong><?= $g['has_expected_roster'] ? 'Lengkap ' . $g['complete_input'] . ' / ' . $g['total_anggota'] . ' (' . tahsinPercent($g['coverage_percent']) . ')' : $g['belum_diisi'] . ' catatan peserta perlu perbaikan; cakupan sesi belum terverifikasi' ?></strong><span>Belum presensi <?= $g['missing_presensi'] ?></span><span>Keterangan belum lengkap <?= $g['missing_keterangan'] ?></span><span>Belum capaian <?= $g['missing_capaian'] ?></span><span>Verifikasi <?= $g['conflicts'] ?></span></div>
<p class="tahsin-note">Dasar peserta: <?= tahsinH($g['roster_source']) ?></p>
<ul class="tahsin-pending-list"><?php foreach ($g['pending_participants'] as $p): ?><li><strong><?= tahsinH($p['nama_bapak']) ?></strong><p class="tahsin-note" style="margin:3px 0"><?= tahsinH(implode(', ', $p['missing_fields'])) ?></p><a href="<?= tahsinH($reviewPath . '&wali_santri_id=' . $p['wali_santri_id']) ?>#detail-wali">Lihat rincian</a></li><?php endforeach; ?></ul>
<div class="tahsin-actions no-print"><a class="tahsin-button primary" href="<?= tahsinH($role === 'ustadz' ? $inputPath : $reviewPath) ?>"><?= $role === 'ustadz' ? 'Lengkapi Presensi dan Capaian' : 'Tinjau Data Sesi' ?></a>
<?php if ($canMessage && $g['belum_diisi']): ?><?php if ($g['ustadz_phone']): ?><a class="tahsin-button whatsapp" target="_blank" rel="noopener noreferrer" href="<?= tahsinH('https://wa.me/' . $g['ustadz_phone'] . '?text=' . rawurlencode($message)) ?>">Buka Pengingat WhatsApp</a><?php else: ?><span class="tahsin-note">Nomor guru belum tersedia; salin pesan di bawah.</span><?php endif; ?><button class="tahsin-button" data-copy-message="message-<?= $index ?>" type="button">Salin Pesan</button><?php endif; ?></div>
<?php if ($canMessage && $g['belum_diisi']): ?><details class="no-print" style="margin-top:12px"><summary>Lihat pesan pengingat</summary><textarea readonly id="message-<?= $index ?>" class="tahsin-message" aria-label="Pesan pengingat untuk <?= tahsinH($g['ustadz_name']) ?>"><?= tahsinH($message) ?></textarea><p class="tahsin-note">Tombol membuka pesan siap kirim. Pengiriman dilakukan oleh Anda.</p></details><?php endif; ?></section>
<?php endforeach; ?>
</div></div><script src="assets/js/tahsin-report.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
