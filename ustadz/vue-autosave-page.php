<?php
// Included only after the existing teacher authorization, schema checks and roster lookup.
if (!isset($pdo, $boot, $ctx, $vueAssets) || empty($_SESSION['user_id'])) { http_response_code(404); exit; }
$boot['view'] = ['title' => $pageTitle, 'group_name' => $ctx['group']['nama_halaqoh'], 'today' => date('Y-m-d'), 'surahs' => tahsinSurahNames(),
    'session_label' => date('d/m/Y', strtotime($ctx['session'])),
    'members' => array_map(static fn($m) => ['id' => (int)$m['id'], 'name' => $m['nama_bapak'],
        'children' => strip_tags(str_replace('<br>', ' · ', $m['info_anak'] ?? '')),
        'reference' => isset($references[$m['id']]) ? tahsinMaterial($references[$m['id']]) : 'belum ada capaian'], $ctx['members'])];
$fallback = '?' . http_build_query(['id' => $groupId, 'tanggal' => $date, 'renderer' => 'php']);
header('Cache-Control: no-store, private');
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<link rel="stylesheet" href="../assets/css/tahsin-autosave.css?v=1">
<?php foreach ($vueAssets['css'] as $css): ?><link rel="stylesheet" href="../assets/go-vue/<?= htmlspecialchars($css, ENT_QUOTES) ?>"><?php endforeach; ?>
<p class="tas-alert" style="max-width:880px;margin:0 auto 12px">Tampilan guru baru · <a id="vue-teacher-fallback" href="<?= htmlspecialchars($fallback, ENT_QUOTES) ?>">Buka tampilan sebelumnya</a></p>
<div id="vue-teacher-root" x-ignore aria-busy="true"><p role="status">Menyiapkan daftar dan memulihkan draft… Jika tidak terbuka, gunakan tautan tampilan sebelumnya di atas.</p></div>
<noscript><p class="tas-alert">JavaScript diperlukan untuk autosave. <a href="<?= htmlspecialchars($fallback, ENT_QUOTES) ?>">Buka tampilan sebelumnya</a>.</p></noscript>
<script id="tahsin-autosave-data" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script>
<!-- A new URL avoids an older service worker's cached v1 runtime without clearing user drafts. -->
<script defer src="../assets/js/tahsin-autosave.js?v=2"></script>
<script type="module" src="../assets/go-vue/<?= htmlspecialchars($vueAssets['file'], ENT_QUOTES) ?>"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
