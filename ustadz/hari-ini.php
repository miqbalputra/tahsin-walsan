<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_helper.php';
require_once __DIR__ . '/../includes/autosave_helper.php';
checkRole(['ustadz']);
$pageTitle = 'Hari Ini';
$teacherNavProvided = true;
$stmt = $pdo->prepare('SELECT h.*, (SELECT COUNT(*) FROM halaqoh_members hm JOIN wali_santri w ON w.id=hm.wali_santri_id WHERE hm.halaqoh_id=h.id' . attendanceActiveMembershipCondition($pdo) . ' AND w.status_aktif=1) AS total_member FROM halaqoh h WHERE h.ustadz_id=? ORDER BY h.nama_halaqoh');
$stmt->execute([(int) $_SESSION['user_id']]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
$today = tahsinNow()->format('Y-m-d');
$session = tahsinLatestSession($today, tahsinHolidayMap($pdo));
$groupStates = [];
if ($session) foreach ($groups as $group) {
    if (!autosaveEnabled($pdo, (int) $group['id'])) continue;
    $groupState = autosaveSessionState($pdo, (int) $_SESSION['user_id'], (int) $group['id'], $session);
    $complete = count(array_filter($groupState['entries'], static fn($e) => $e['state'] === 'official' && $e['official_complete']));
    $started = (bool) array_filter($groupState['entries'], static fn($e) => $e['state'] !== 'empty');
    $groupStates[$group['id']] = $groupState['completed_at'] ? 'Selesai' : ($started ? 'Dalam pengisian' : 'Belum mulai');
    $groupStates[$group['id']] .= ' · ' . $complete . '/' . count($groupState['entries']) . ' lengkap';
}
if (count($groups) === 1 && $session === $today && autosaveEnabled($pdo, (int) $groups[0]['id'])) {
    redirectTo('ustadz/form-presensi.php?' . http_build_query(['id' => $groups[0]['id'], 'tanggal' => $today]));
}
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= appEscape(appAssetUrl('assets/css/tahsin-autosave.css')) ?>">
<section class="tas-app"><header class="tas-heading"><p class="tas-eyebrow">Tahsin Bapak &amp; Ibu</p><h1>Hari Ini</h1><p><?= date('d/m/Y', strtotime($today)) ?> · Pilih kelompok untuk mulai atau melanjutkan pendataan.</p></header>
<?php if ($session !== $today): ?><p class="tas-alert">Tidak ada sesi Ahad aktif hari ini. Sesi terakhir: <?= $session ? date('d/m/Y', strtotime($session)) : 'belum tersedia' ?>. Tanggal pertemuan dapat dipilih di dalam kelompok.</p><?php endif; ?>
<div class="tas-list"><?php foreach ($groups as $group): ?>
    <article class="tas-person"><p class="tas-eyebrow">Tahsin <?= htmlspecialchars(ucfirst($group['program_code'] ?? 'bapak')) ?></p><h2><?= htmlspecialchars($group['nama_halaqoh']) ?></h2><p><?= (int) $group['total_member'] ?> peserta · Sesi <?= $session ? date('d/m/Y', strtotime($session)) : '—' ?></p><p><?= htmlspecialchars($groupStates[$group['id']] ?? '') ?></p>
    <a class="tas-button" style="display:inline-block;margin-top:12px" href="form-presensi.php?<?= htmlspecialchars(http_build_query(['id' => $group['id'], 'tanggal' => $session ?? $today])) ?>">Buka daftar peserta →</a></article>
<?php endforeach; ?></div>
<?php if (!$groups): ?><p class="tas-alert">Belum ada kelompok yang ditugaskan kepada Anda. Hubungi admin.</p><?php endif; ?>
<nav class="tas-nav" aria-label="Navigasi guru"><a href="hari-ini.php" aria-current="page">Hari Ini</a><a href="riwayat.php">Riwayat</a><a href="akun.php">Akun</a></nav></section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
