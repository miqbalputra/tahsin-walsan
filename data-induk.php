<?php
$pageTitle = 'Status Data Induk';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
require_once 'config/database.php';
require_once 'includes/auth_helper.php';
require_once 'includes/data_induk_helper.php';
checkRole(['admin']);

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        exit('CSRF token tidak valid.');
    }
    try {
        if (($_POST['action'] ?? '') === 'sync') {
            $stats = dataIndukSync($pdo, (string) ($_POST['scope'] ?? 'all'));
            $message = 'Sinkronisasi selesai: ' . htmlspecialchars(json_encode($stats, JSON_UNESCAPED_UNICODE));
        } elseif (($_POST['action'] ?? '') === 'conflict') {
            dataIndukResolveConflict($pdo, (int) ($_POST['conflict_id'] ?? 0), (string) ($_POST['decision'] ?? 'ignore'));
            $message = 'Konflik telah diproses. Jalankan sync kembali untuk mengambil profil pusat terbaru.';
        }
    } catch (Throwable $exception) {
        reportApplicationError($exception, 'data-induk-admin');
        $error = $exception instanceof DataIndukException ? $exception->getMessage() : 'Operasi gagal. Periksa konfigurasi dan log server.';
    }
}

$state = null;
$runs = [];
$conflicts = [];
$snapshotCounts = ['wali' => 0, 'anak' => 0, 'ustadz' => 0];
try {
    dataIndukRequireSchema($pdo);
    $state = $pdo->query('SELECT * FROM data_induk_sync_state WHERE id=1')->fetch();
    $runs = $pdo->query('SELECT * FROM data_induk_sync_runs ORDER BY id DESC LIMIT 10')->fetchAll();
    $conflicts = $pdo->query("SELECT * FROM data_induk_sync_conflicts WHERE status='pending' ORDER BY created_at DESC LIMIT 50")->fetchAll();
    $snapshotCounts = [
        'wali' => (int) $pdo->query('SELECT COUNT(*) FROM wali_santri WHERE data_induk_guardian_id IS NOT NULL')->fetchColumn(),
        'anak' => (int) $pdo->query('SELECT COUNT(*) FROM santri_detail WHERE data_induk_student_id IS NOT NULL')->fetchColumn(),
        'ustadz' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE data_induk_teacher_id IS NOT NULL')->fetchColumn(),
    ];
} catch (Throwable $exception) {
    $error = $error ?: 'Migrasi belum tersedia. Jalankan php migrate_data_induk.php --apply setelah backup database.';
}
$config = dataIndukConfig();
$isStale = $state && $state['last_success_at'] && strtotime($state['last_success_at']) < time() - ($config['stale_minutes'] * 60);
?>
<div class="mb-8 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
    <div><h2 class="text-3xl font-black text-slate-800">Status Data Induk</h2><p class="text-slate-500">Snapshot identitas lokal untuk Presensi Tahsin Bapak.</p></div>
    <form method="post" class="flex gap-2"><input type="hidden" name="action" value="sync"><?php csrfField(); ?>
        <select name="scope" class="rounded-xl border-slate-200 text-sm"><option value="all">Sinkronisasi penuh</option><option value="references">Referensi</option><option value="roster">Roster & keluarga</option><option value="teachers">Ustadz</option><option value="changes">Perubahan incremental</option></select>
        <button class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700" <?= dataIndukIsConfigured() ? '' : 'disabled' ?>>Sync sekarang</button>
    </form>
</div>
<?php if ($message): ?><div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-700"><?= $message ?></div><?php endif; ?>
<?php if ($error): ?><div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="grid gap-4 md:grid-cols-3 xl:grid-cols-6 mb-8">
    <div class="rounded-2xl bg-white p-5 shadow-sm border border-slate-100"><p class="text-xs font-bold uppercase text-slate-400">Konfigurasi</p><p class="mt-2 font-bold <?= dataIndukIsConfigured() ? 'text-emerald-600' : 'text-amber-600' ?>"><?= dataIndukIsConfigured() ? 'Siap sync' : 'Belum aktif' ?></p></div>
    <div class="rounded-2xl bg-white p-5 shadow-sm border border-slate-100"><p class="text-xs font-bold uppercase text-slate-400">Sync terakhir</p><p class="mt-2 font-bold <?= $isStale ? 'text-amber-600' : 'text-slate-700' ?>"><?= htmlspecialchars($state['last_success_at'] ?? 'Belum pernah') ?></p></div>
    <div class="rounded-2xl bg-white p-5 shadow-sm border border-slate-100"><p class="text-xs font-bold uppercase text-slate-400">Data stale</p><p class="mt-2 font-bold <?= $isStale ? 'text-amber-600' : 'text-emerald-600' ?>"><?= $isStale ? 'Perlu sync' : 'Aman' ?></p></div>
    <div class="rounded-2xl bg-white p-5 shadow-sm border border-slate-100"><p class="text-xs font-bold uppercase text-slate-400">Snapshot</p><p class="mt-2 text-sm font-bold text-slate-700"><?= $snapshotCounts['wali'] ?> wali · <?= $snapshotCounts['anak'] ?> anak · <?= $snapshotCounts['ustadz'] ?> ustadz</p></div>
    <div class="rounded-2xl bg-white p-5 shadow-sm border border-slate-100"><p class="text-xs font-bold uppercase text-slate-400">Konflik tertunda</p><p class="mt-2 text-2xl font-black text-slate-700"><?= count($conflicts) ?></p></div>
    <div class="rounded-2xl bg-white p-5 shadow-sm border border-slate-100"><p class="text-xs font-bold uppercase text-slate-400">Error terakhir</p><p class="mt-2 truncate text-xs font-bold text-amber-700" title="<?= htmlspecialchars((string) ($state['last_error'] ?? '')) ?>"><?= htmlspecialchars((string) ($state['last_error'] ?? 'Tidak ada')) ?></p></div>
</div>
<div class="grid gap-6 lg:grid-cols-2">
    <section class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm"><div class="border-b p-5 font-bold">Riwayat sinkronisasi</div><div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="p-3">Waktu</th><th class="p-3">Scope</th><th class="p-3">Status</th><th class="p-3">Durasi</th></tr></thead><tbody><?php foreach ($runs as $run): ?><tr class="border-t"><td class="p-3"><?= htmlspecialchars($run['started_at']) ?></td><td class="p-3"><?= htmlspecialchars($run['scope']) ?></td><td class="p-3"><?= htmlspecialchars($run['status']) ?></td><td class="p-3"><?= htmlspecialchars((string) (json_decode($run['stats_json'] ?? '{}', true)['duration_ms'] ?? '-')) ?> ms</td></tr><?php endforeach; ?></tbody></table></div></section>
    <section class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm"><div class="border-b p-5 font-bold">Konflik pemetaan</div><div class="divide-y"><?php foreach ($conflicts as $conflict): ?><div class="p-4 text-sm"><p class="font-bold"><?= htmlspecialchars($conflict['entity_type']) ?> · <?= htmlspecialchars($conflict['reason']) ?></p><p class="mt-1 text-xs text-slate-500">UUID pusat: <?= htmlspecialchars($conflict['master_id']) ?> · Kandidat lokal: <?= htmlspecialchars((string) $conflict['local_id']) ?></p><form method="post" class="mt-3 flex gap-2"><input type="hidden" name="action" value="conflict"><input type="hidden" name="conflict_id" value="<?= (int) $conflict['id'] ?>"><?php csrfField(); ?><button name="decision" value="approve" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white">Setujui</button><button name="decision" value="ignore" class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-bold">Abaikan</button></form></div><?php endforeach; ?><?php if (!$conflicts): ?><p class="p-5 text-sm text-slate-500">Tidak ada konflik tertunda.</p><?php endif; ?></div></section>
</div>
<?php require_once 'includes/footer.php'; ?>
