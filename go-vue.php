<?php
require_once __DIR__.'/includes/go_vue_helper.php';
if (!goVueEnabled()) { http_response_code(404);exit('Tidak ditemukan.'); }
require_once __DIR__.'/includes/auth_helper.php';
checkLogin();checkRole(['admin','pj_tahfidz']);
header('Cache-Control: no-store, private');
$pageTitle='Pratinjau Keluarga';
$assets=goVueAssets();
require __DIR__.'/includes/header.php';require __DIR__.'/includes/sidebar.php';
?>
<?php if (!$assets): ?>
<div class="bg-white rounded-2xl p-6"><h1 class="text-xl font-bold">Pratinjau belum tersedia</h1><p class="mt-3">Persiapan tampilan baru belum selesai. Data keluarga tetap dapat dibuka di aplikasi utama.</p><a href="keluarga.php" class="block mt-4 text-blue-700">Buka Keluarga</a></div>
<?php else: ?>
<div id="go-vue-preview" x-ignore data-endpoint="api/go-vue-families.php"></div>
<noscript>Aktifkan JavaScript untuk membuka pratinjau. <a href="keluarga.php">Buka aplikasi utama</a>.</noscript>
<?php foreach ($assets['css'] ?? [] as $css): ?><link rel="stylesheet" href="<?= htmlspecialchars('assets/go-vue/'.$css,ENT_QUOTES,'UTF-8') ?>"><?php endforeach ?>
<script type="module" src="<?= htmlspecialchars('assets/go-vue/'.$assets['file'],ENT_QUOTES,'UTF-8') ?>"></script>
<?php endif ?>
<?php require __DIR__.'/includes/footer.php'; ?>
