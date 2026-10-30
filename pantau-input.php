<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_helper.php';
require_once __DIR__ . '/includes/tahsin_report_helper.php';
require_once __DIR__ . '/includes/tahsin_report_view.php';
checkRole(['admin', 'pj_tahfidz', 'kepsek', 'ustadz']);
$role = $_SESSION['role']; $userId = (int) $_SESSION['user_id'];
$error = '';
$emptySummary = [
    'scheduled_sessions' => 0, 'verified_sessions' => 0, 'unverified_sessions' => 0,
    'pending_teachers' => 0, 'missing_presensi' => 0, 'missing_keterangan' => 0,
    'missing_capaian' => 0, 'conflicts' => 0, 'pending_participants' => 0,
    'unverified_record_issues' => 0,
];
$overview = ['sessions' => [], 'groups' => [], 'summary' => $emptySummary, 'filters' => [], 'tracked_from' => null];
try {
    $overview = tahsinFetchPendingOverview($pdo, $role, $userId, $_GET);
} catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
catch (Throwable $e) { reportApplicationError($e, 'pantau-input'); $error = 'Data pemantauan belum dapat diambil. Silakan muat ulang.'; }
$allGroups = $overview['groups'];
$summary = $overview['summary'];
$status = (string) ($_GET['status_isi'] ?? 'pending');
$groups = array_values(array_filter($allGroups, static fn($g) => $status === 'all' || ($status === 'pending' ? $g['belum_diisi'] > 0 : $g['status_isi'] === $status)));
$canMessage = in_array($role, ['admin', 'pj_tahfidz'], true);
$configuredUrl = rtrim((string) getenv('APP_URL'), '/');
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$base = filter_var($configuredUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#', $configuredUrl) ? $configuredUrl . '/' :
    (preg_match('/^[a-zA-Z0-9.\-]+(?::\d+)?$/', $host) ? ($is_https ? 'https://' : 'http://') . $host . appBasePath() . '/' : appBasePath() . '/');
$pageTitle = 'Pemantauan Input';
require __DIR__ . '/includes/header.php'; require __DIR__ . '/includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= appEscape(appAssetUrl('assets/css/tahsin-report.css')) ?>">
<div class="tahsin-report">
<div class="tahsin-heading"><div><h2>Pemantauan Input Guru Tahsin</h2><p class="tahsin-note">Semua sesi Ahad dalam rentang ditampilkan, dikelompokkan menurut tanggal.<br>Data kosong belum menunjukkan status wali. Batas input: Senin 10.00 WIB, termasuk sesi pengganti.</p></div><a class="tahsin-button" href="rekap-lengkap.php">Buka Rekap Lengkap</a></div>
<?php if ($error): ?><div class="tahsin-alert error" role="alert"><?= tahsinH($error) ?></div><?php endif; ?>
<?php if ($summary['unverified_sessions']): ?><div class="tahsin-alert tahsin-unverified-alert"><strong><?= (int) $summary['unverified_sessions'] ?> sesi belum memiliki histori roster terverifikasi.</strong> Pekan berjalan diperiksa memakai roster aktif yang sama dengan formulir input. Kekosongan presensi tidak dihitung sebagai kekurangan guru untuk sesi lama yang belum terverifikasi. Nama guru lama hanya bisa dipastikan bila histori penempatan tersedia.</div><?php endif; ?>
<div class="tahsin-cards tahsin-monitor-cards">
<div class="tahsin-card purple"><span>Guru dengan kekurangan input</span><strong><?= (int) $summary['pending_teachers'] ?></strong></div>
<div class="tahsin-card amber"><span>Presensi/keterangan perlu dilengkapi</span><strong><?= (int) ($summary['missing_presensi'] + $summary['missing_keterangan']) ?></strong></div>
<div class="tahsin-card amber"><span>Capaian belum lengkap</span><strong><?= (int) $summary['missing_capaian'] ?></strong></div>
<div class="tahsin-card amber"><span>Perlu verifikasi</span><strong><?= (int) $summary['conflicts'] ?></strong></div>
<div class="tahsin-card blue"><span>Sesi terjadwal</span><strong><?= (int) $summary['scheduled_sessions'] ?></strong></div>
<div class="tahsin-card green"><span>Roster terverifikasi</span><strong><?= (int) $summary['verified_sessions'] ?></strong></div>
<div class="tahsin-card purple"><span>Roster belum terverifikasi</span><strong><?= (int) $summary['unverified_sessions'] ?></strong></div>
<div class="tahsin-card amber"><span>Catatan lama perlu ditinjau</span><strong><?= (int) $summary['unverified_record_issues'] ?></strong></div>
</div>
<form class="tahsin-panel tahsin-filters no-print" method="get" style="margin-top:20px">
<label>Awal sesi (opsional)<input type="date" name="start_date" value="<?= tahsinH($_GET['start_date'] ?? '') ?>"></label>
<label>Akhir sesi (opsional)<input type="date" name="end_date" value="<?= tahsinH($_GET['end_date'] ?? '') ?>"></label>
<label>Status rincian guru<select name="status_isi"><?php foreach (['pending' => 'Semua kekurangan input', 'all' => 'Semua, termasuk lengkap', 'belum_sama_sekali' => 'Belum Diinput', 'belum_lengkap' => 'Sebagian Diinput', 'capaian_belum_lengkap' => 'Capaian Belum Lengkap', 'perlu_verifikasi' => 'Perlu Verifikasi', 'lengkap' => 'Lengkap'] as $key => $label): ?><option value="<?= $key ?>" <?= $status === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<label>Guru / Halaqoh<select name="halaqoh_id"><option value="">Semua tanggung jawab</option><?php $options = []; foreach ($allGroups as $g) $options[$g['halaqoh_id']] = $g['ustadz_name'] . ' · ' . $g['nama_halaqoh']; foreach ($options as $id => $label): ?><option value="<?= (int) $id ?>" <?= (int) ($_GET['halaqoh_id'] ?? 0) === (int) $id ? 'selected' : '' ?>><?= tahsinH($label) ?></option><?php endforeach; ?></select></label>
<div class="tahsin-actions" style="align-items:end"><button class="tahsin-button primary">Terapkan</button><a class="tahsin-button" href="pantau-input.php">Reset</a></div>
<p class="tahsin-note tahsin-filter-note">Filter status berlaku untuk rincian kelompok; semua sesi terjadwal dalam rentang tetap ditampilkan.</p>
</form>
<div class="tahsin-monitor-sessions">
<?php if (!$overview['sessions'] && !$error): ?><section class="tahsin-panel"><h3>Tidak ada sesi Ahad aktif dalam rentang ini</h3><p class="tahsin-note">Ahad pertama dan tanggal libur tidak menjadi sesi Tahsin.</p></section><?php endif; ?>
<?php foreach ($overview['sessions'] as $session):
    $sessionGroups = array_values(array_filter($groups, static fn($g) => $g['tanggal_tahsin'] === $session['tanggal']));
?>
<section class="tahsin-panel tahsin-monitor-session">
<div class="tahsin-pending-header"><div><h3 style="margin-bottom:4px">Sesi Ahad <?= date('d/m/Y', strtotime($session['tanggal'])) ?></h3><p class="tahsin-note" style="margin:0">Batas input <?= date('d/m/Y H:i', strtotime(tahsinDeadline($session['tanggal']))) ?> WIB</p></div><span class="tahsin-badge <?= $session['roster_verified'] ? 'complete' : 'unverified' ?>"><?= $session['roster_verified'] ? 'Roster Terverifikasi' : (!empty($session['current_roster_available']) ? 'Roster Aktif Pekan Ini' : 'Belum Terverifikasi') ?></span></div>
<?php if ($session['roster_verified'] || !empty($session['current_roster_available'])): ?>
<div class="tahsin-pending-stats"><strong>Roster <?= (int) $session['roster_count'] ?> · Lengkap <?= (int) $session['complete_input'] ?> / <?= (int) $session['roster_count'] ?></strong><span>Belum presensi <?= (int) $session['missing_presensi'] ?></span><span>Keterangan belum lengkap <?= (int) $session['missing_keterangan'] ?></span><span>Belum capaian <?= (int) $session['missing_capaian'] ?></span><span>Verifikasi <?= (int) $session['conflicts'] ?></span></div>
<?php if (!$sessionGroups): ?><p class="tahsin-note">Tidak ada rincian kelompok yang cocok dengan filter status pada sesi ini.</p><?php endif; ?>
<div class="tahsin-pending tahsin-session-groups">
<?php foreach ($sessionGroups as $index => $g):
    $inputPath = 'ustadz/form-presensi.php?id=' . (int) $g['halaqoh_id'] . '&tanggal=' . rawurlencode($g['tanggal_tahsin']);
    $reviewPath = 'rekap-lengkap.php?' . http_build_query(['start_date' => $g['tanggal_tahsin'], 'end_date' => $g['tanggal_tahsin'], 'halaqoh_id' => $g['halaqoh_id']]);
    $message = "Assalamualaikum Ustadz " . $g['ustadz_name'] . ",\n\nMohon melengkapi presensi dan capaian Tahsin kelompok " . $g['nama_halaqoh'] . " untuk sesi Ahad " . date('d/m/Y', strtotime($g['tanggal_tahsin'])) . ".\n\n" . $g['keterangan'] . ".\nPresensi belum diinput: " . $g['missing_presensi'] . "; capaian belum lengkap: " . $g['missing_capaian'] . "; perlu verifikasi: " . $g['conflicts'] . ".\nBatas input: " . date('d/m/Y H:i', strtotime($g['deadline_at'])) . " WIB.\n\n" . $base . $inputPath . "\n\nData yang belum diinput belum dianggap Alfa. Jazakumullah khairan.";
    if ($g['missing_keterangan']) $message .= "\nKeterangan sakit/izin yang perlu dilengkapi: " . $g['missing_keterangan'] . ".";
?>
<article class="tahsin-panel tahsin-pending-group"><div class="tahsin-pending-header"><div><h4><?= tahsinH($g['ustadz_name']) ?> · <?= tahsinH($g['nama_halaqoh']) ?></h4><p class="tahsin-note" style="margin:0">Status input per kelompok</p></div><div><span class="tahsin-badge <?= $g['belum_diisi'] ? 'partial' : 'complete' ?>"><?= tahsinH($g['status_label']) ?></span> <span class="tahsin-badge"><?= tahsinH($g['timeliness']) ?></span></div></div>
<div class="tahsin-pending-stats"><strong>Lengkap <?= (int) $g['complete_input'] ?> / <?= (int) $g['total_anggota'] ?> (<?= tahsinPercent($g['coverage_percent']) ?>)</strong><span>Belum presensi <?= (int) $g['missing_presensi'] ?></span><span>Keterangan belum lengkap <?= (int) $g['missing_keterangan'] ?></span><span>Belum capaian <?= (int) $g['missing_capaian'] ?></span><span>Verifikasi <?= (int) $g['conflicts'] ?></span></div>
<p class="tahsin-note">Dasar peserta: <?= tahsinH($g['roster_source']) ?>.</p>
<ul class="tahsin-pending-list"><?php foreach ($g['pending_participants'] as $p): ?><li><strong><?= tahsinH($p['nama_bapak']) ?></strong><p class="tahsin-note" style="margin:3px 0"><?= tahsinH(implode(', ', $p['missing_fields'])) ?></p><a href="<?= tahsinH($reviewPath . '&wali_santri_id=' . (int) $p['wali_santri_id']) ?>#detail-wali">Lihat rincian</a></li><?php endforeach; ?></ul>
<div class="tahsin-actions no-print"><a class="tahsin-button primary" href="<?= tahsinH($role === 'ustadz' ? $inputPath : $reviewPath) ?>"><?= $role === 'ustadz' ? 'Lengkapi Presensi dan Capaian' : 'Tinjau Data Sesi' ?></a>
<?php if ($canMessage && $g['belum_diisi']): ?><?php if ($g['ustadz_phone']): ?><a class="tahsin-button whatsapp" target="_blank" rel="noopener noreferrer" href="<?= tahsinH('https://wa.me/' . $g['ustadz_phone'] . '?text=' . rawurlencode($message)) ?>">Buka Pengingat WhatsApp</a><?php else: ?><span class="tahsin-note">Nomor guru belum tersedia; salin pesan di bawah.</span><?php endif; ?><button class="tahsin-button" data-copy-message="message-<?= (int) $index ?>-<?= tahsinH($session['tanggal']) ?>" type="button">Salin Pesan</button><?php endif; ?></div>
<?php if ($canMessage && $g['belum_diisi']): ?><details class="no-print" style="margin-top:12px"><summary>Lihat pesan pengingat</summary><textarea readonly id="message-<?= (int) $index ?>-<?= tahsinH($session['tanggal']) ?>" class="tahsin-message" aria-label="Pesan pengingat untuk <?= tahsinH($g['ustadz_name']) ?>"><?= tahsinH($message) ?></textarea><p class="tahsin-note">Tombol membuka pesan siap kirim. Pengiriman dilakukan oleh Anda.</p></details><?php endif; ?></article>
<?php endforeach; ?></div>
<?php else: ?>
<?php $sessionReviewPath = 'rekap-lengkap.php?' . http_build_query(['start_date' => $session['tanggal'], 'end_date' => $session['tanggal']]); ?>
<div class="tahsin-unverified-session"><p><strong>Daftar peserta dan guru penanggung jawab sesi ini belum dapat dipastikan.</strong> Kekosongan presensi tidak dihitung sebagai kekurangan guru atau Alfa. Sesi ini tetap ditampilkan agar batas periode dan data yang belum terverifikasi terlihat jelas.</p>
<?php if ($session['issues']): ?><h4>Catatan tersimpan yang perlu ditinjau</h4><?php foreach ($session['issues'] as $issue): ?><div class="tahsin-unverified-issue"><strong><?= tahsinH($issue['placement_label']) ?> · <?= tahsinH($issue['teacher_label']) ?></strong><ul class="tahsin-pending-list"><?php foreach ($issue['participants'] as $participant): $reviewPath = 'rekap-lengkap.php?' . http_build_query(['start_date' => $session['tanggal'], 'end_date' => $session['tanggal'], 'wali_santri_id' => $participant['wali_santri_id']]); ?><li><strong><?= tahsinH($participant['nama_bapak']) ?></strong><p class="tahsin-note" style="margin:3px 0"><?= tahsinH(implode(', ', $participant['missing_fields'])) ?></p><a href="<?= tahsinH($reviewPath) ?>#detail-wali">Tinjau catatan tersimpan</a></li><?php endforeach; ?></ul></div><?php endforeach; ?><?php else: ?><p class="tahsin-note">Tidak ada catatan tersimpan yang dapat mengidentifikasi kekurangan tertentu pada sesi ini.</p><?php endif; ?>
<a class="tahsin-button" href="<?= tahsinH($sessionReviewPath) ?>">Buka rincian sesi</a></div>
<?php endif; ?>
</section>
<?php endforeach; ?>
</div></div><script src="<?= appEscape(appAssetUrl('assets/js/tahsin-report.js')) ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
