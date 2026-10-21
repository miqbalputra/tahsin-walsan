<?php
// Included by form-presensi.php after authentication. No independent direct access.
if (!isset($pdo) || empty($_SESSION['user_id'])) { http_response_code(404); exit; }
$groupId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$date = normalizeDateInput($_GET['tanggal'] ?? date('Y-m-d'), date('Y-m-d'));
try {
    $state = autosaveSessionState($pdo, (int) $_SESSION['user_id'], (int) $groupId, $date);
    $ctx = autosaveContext($pdo, (int) $_SESSION['user_id'], (int) $groupId, $date);
} catch (AutosaveException $e) { http_response_code($e->httpStatus); echo htmlspecialchars($e->getMessage()); return; }
$pageTitle = 'Tahsin ' . ucfirst($state['program']);
$teacherNavProvided = true;
$autosavePageActive = true;
$boot = ['user_id' => (int) $_SESSION['user_id'], 'halaqoh_id' => (int) $groupId, 'tanggal' => $date,
    'csrf_token' => generateCsrfToken(), 'api' => 'api-autosave.php', 'state' => $state];
// Load last confirmed material in a single query, not one request per participant.
$ids = array_map('intval', array_column($ctx['members'], 'id'));
$references = [];
if ($ids) {
    $stmt = $pdo->prepare("SELECT p.* FROM presensi p WHERE p.wali_santri_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND p.tanggal < ? AND p.status='H' ORDER BY p.tanggal DESC,p.id DESC");
    $stmt->execute(array_merge($ids, [$ctx['session']]));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) if (!isset($references[$r['wali_santri_id']]) && !tahsinMissingFields($r)) $references[$r['wali_santri_id']] = $r;
}
if (tahsinSchemaReady($pdo) && $ids) {
    $stmt = $pdo->prepare('SELECT * FROM tahsin_capaian_awal WHERE wali_santri_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $references[$r['wali_santri_id']] ??= $r;
}
$boot['references'] = array_map('autosaveFromRecord', $references);
require_once __DIR__ . '/../includes/go_vue_helper.php';
require_once __DIR__ . '/../includes/tahsin_surah_helper.php';
if (goVueTeacherEnabled((int)$groupId) && ($_GET['renderer'] ?? '') !== 'php' && ($vueAssets = goVueAssets('src/teacher.ts'))) {
    require __DIR__ . '/vue-autosave-page.php';
    return;
}
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<link rel="stylesheet" href="../assets/css/tahsin-autosave.css?v=1">
<section id="tahsin-autosave" class="tas-app" aria-label="Pencatatan Tahsin otomatis">
    <header class="tas-heading">
        <a href="hari-ini.php" class="tas-back">← Hari Ini</a>
        <p class="tas-eyebrow"><?= htmlspecialchars($pageTitle) ?></p>
        <h1><?= htmlspecialchars($ctx['group']['nama_halaqoh']) ?></h1>
        <form method="get" class="tas-date"><input type="hidden" name="id" value="<?= (int) $groupId ?>">
            <?php if (($_GET['renderer'] ?? '') === 'php'): ?><input type="hidden" name="renderer" value="php"><?php endif; ?>
            <label>Tanggal pertemuan <input type="date" name="tanggal" value="<?= htmlspecialchars($date) ?>" max="<?= date('Y-m-d') ?>" required></label>
            <button type="submit" class="tas-button">Buka</button>
        </form>
        <p>Sesi Ahad <?= date('d/m/Y', strtotime($ctx['session'])) ?> · Presensi dan capaian tersimpan otomatis.</p>
    </header>
    <noscript><p class="tas-alert">Aktifkan JavaScript untuk autosave. <a href="?<?= htmlspecialchars(http_build_query(['id' => $groupId, 'tanggal' => $date, 'legacy' => 1])) ?>">Buka formulir daring dengan simpan manual</a>.</p></noscript>
    <div data-banner class="tas-alert" role="status" hidden></div>
    <?php if (!$state['active']): ?><p class="tas-alert">Sesi ini libur. Data tersimpan dapat dilihat, pencatatan baru tidak dikirim.</p><?php endif; ?>
    <div class="tas-overview" aria-live="polite"><strong data-progress>Memuat keadaan penyimpanan…</strong><span data-pending></span></div>
    <div class="tas-toolbar">
        <label class="tas-search"><span class="sr-only">Cari peserta atau anak</span><input type="search" data-search placeholder="Cari nama peserta atau anak…" autocomplete="off"></label>
        <div class="tas-filters" aria-label="Filter peserta"><button type="button" data-filter="all" aria-pressed="true">Semua</button><button type="button" data-filter="incomplete" aria-pressed="false">Belum lengkap</button></div>
    </div>
    <div class="tas-list">
    <?php foreach ($ctx['members'] as $member): $id = (int) $member['id']; $reference = $references[$id] ?? null; ?>
        <article class="tas-person" data-person="<?= $id ?>" data-search-name="<?= htmlspecialchars(strip_tags($member['nama_bapak'] . ' ' . ($member['info_anak'] ?? '')), ENT_QUOTES) ?>">
            <div class="tas-person-header"><div><h2><?= htmlspecialchars($member['nama_bapak']) ?></h2>
                <?php if ($member['info_anak']): ?><p class="tas-child">Anak: <?= htmlspecialchars(str_replace('<br>', ' · ', $member['info_anak'])) ?></p><?php endif; ?></div>
                <span data-completeness class="tas-badge">Belum dicatat</span>
            </div>
            <div class="tas-status" role="group" aria-label="Kehadiran <?= htmlspecialchars($member['nama_bapak'], ENT_QUOTES) ?>">
                <?php foreach (['H' => 'Hadir', 'S' => 'Sakit', 'I' => 'Izin', 'A' => 'Alfa'] as $code => $label): ?>
                <button type="button" data-status="<?= $code ?>" aria-pressed="false"><?= $label ?></button>
                <?php endforeach; ?>
            </div>
            <button type="button" class="tas-details-toggle" data-toggle aria-expanded="false" aria-controls="tas-detail-<?= $id ?>"><span data-detail-label>Capaian / keterangan</span><span aria-hidden="true">⌄</span></button>
            <div class="tas-detail" id="tas-detail-<?= $id ?>" hidden>
                <div data-achievement hidden>
                    <p class="tas-reference">Referensi terakhir: <?= $reference ? htmlspecialchars(tahsinMaterial($reference)) : 'belum ada capaian' ?>. Konfirmasikan bacaan hari ini.</p>
                    <div class="tas-grid">
                        <label>Materi<select data-field="jenis_materi"><option value="">Pilih materi</option><option value="Iqro">Iqro</option><option value="Al Quran">Al-Qur’an</option></select></label>
                        <label data-iqro>Jilid<select data-field="jilid"><option value="">Pilih jilid</option><?php for ($j = 1; $j <= 6; $j++): ?><option value="<?= $j ?>"><?= $j ?></option><?php endfor; ?></select></label>
                        <label data-quran hidden>Surat dari<input data-field="nama_surat_dari" list="tas-surahs" maxlength="100" placeholder="Cari surat" autocomplete="off"></label>
                        <label data-quran hidden>Surat sampai<input data-field="nama_surat_sampai" list="tas-surahs" maxlength="100" placeholder="Cari surat" autocomplete="off"></label>
                        <label><span data-range-start>Halaman dari</span><input data-field="halaman_dari" type="text" inputmode="numeric" maxlength="4" autocomplete="off"></label>
                        <label><span data-range-end>Halaman sampai</span><input data-field="halaman_sampai" type="text" inputmode="numeric" maxlength="4" autocomplete="off"></label>
                    </div>
                    <div class="tas-result" role="group" aria-label="Hasil bacaan"><span>Hasil hari ini</span><button type="button" data-result="Lulus" aria-pressed="false">Lulus</button><button type="button" data-result="Ulang" aria-pressed="false">Ulang</button></div>
                </div>
                <label data-reason hidden>Alasan / keterangan<input data-field="alasan" maxlength="500" placeholder="Tulis keterangan singkat" autocomplete="off"></label>
                <p data-missing class="tas-missing"></p>
                <p data-official-note class="tas-missing" hidden>Laporan masih memakai catatan resmi sebelumnya sampai perubahan ini lengkap.</p>
                <div data-conflict class="tas-conflict" hidden><strong>Perubahan perlu ditinjau</strong><p data-conflict-message></p><p data-server-version></p>
                    <button type="button" data-resolve="server" class="tas-button">Gunakan versi server</button>
                    <button type="button" data-resolve="mine" class="tas-button">Pertahankan isian saya</button>
                </div>
                <button type="button" class="tas-button tas-next" data-next>Berikutnya ↓</button>
            </div>
            <div class="tas-save"><span data-save-status role="status">Memuat…</span><button type="button" data-retry hidden>Coba Lagi</button></div>
        </article>
    <?php endforeach; ?>
    </div>
    <p data-no-results hidden>Tidak ada peserta yang sesuai.</p>
    <?php if (!$ctx['members']): ?><p class="tas-alert">Belum ada peserta untuk sesi ini. Hubungi admin untuk memeriksa penempatan.</p><?php endif; ?>
    <section class="tas-summary" data-summary hidden tabindex="-1"><h2>Ringkasan sesi</h2><div data-summary-counts></div><p data-summary-state></p><button type="button" class="tas-button tas-primary" data-finalize>Selesaikan Sesi</button></section>
    <div class="tas-bottom"><span data-bottom-state>Perubahan disimpan otomatis</span><button type="button" class="tas-button tas-primary" data-show-summary>Lihat Ringkasan</button></div>
    <nav class="tas-nav" aria-label="Navigasi guru"><a href="hari-ini.php" aria-current="page">Hari Ini</a><a href="riwayat.php">Riwayat</a><a href="akun.php">Akun</a></nav>
</section>
<datalist id="tas-surahs"><?php
$surahs = tahsinSurahNames();
foreach ($surahs as $name) echo '<option value="' . htmlspecialchars($name, ENT_QUOTES) . '"></option>';
?></datalist>
<script id="tahsin-autosave-data" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script>
<script defer src="../assets/js/tahsin-autosave.js?v=1"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
