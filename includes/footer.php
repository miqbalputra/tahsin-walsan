<?php
if (($_SESSION['role'] ?? '') === 'ustadz' && empty($autosavePageActive) && isset($pdo)) {
    require_once __DIR__ . '/autosave_helper.php';
    if (autosaveEnabled($pdo)) {
        $pendingBoot = ['user_id' => (int) $_SESSION['user_id'], 'csrf_token' => generateCsrfToken(),
            'api' => appUrl('ustadz/api-autosave.php'), 'form' => appUrl('ustadz/form-presensi.php')];
        ?>
        <div id="tahsin-pending-recovery" class="tas-alert" role="status" hidden></div>
        <script id="tahsin-pending-data" type="application/json"><?= json_encode($pendingBoot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
        <script defer src="<?= htmlspecialchars($assetBase ?? '') ?>assets/js/tahsin-autosave.js?v=1"></script>
        <?php
    }
}
?>
</main>
</div> <!-- End Main Content Area Wrapper -->
</div> <!-- End Layout Flex Wrapper -->

<?php if (($_SESSION['role'] ?? '') === 'ustadz' && empty($teacherNavProvided) && basename($_SERVER['PHP_SELF']) !== 'form-presensi.php'): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase ?? '') ?>assets/css/tahsin-autosave.css?v=1">
<style>main{padding-bottom:100px!important}</style>
<nav class="tas-nav" aria-label="Navigasi guru"><a href="<?= htmlspecialchars(appUrl('ustadz/hari-ini.php')) ?>">Hari Ini</a><a href="<?= htmlspecialchars(appUrl('ustadz/riwayat.php')) ?>" <?= basename($_SERVER['PHP_SELF']) === 'riwayat.php' ? 'aria-current="page"' : '' ?>>Riwayat</a><a href="<?= htmlspecialchars(appUrl('ustadz/akun.php')) ?>">Akun</a></nav>
<?php endif; ?>

<script>
    // Global scripts can go here
</script>
</body>

</html>
