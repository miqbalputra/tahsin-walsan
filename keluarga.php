<?php
require_once 'config/database.php';
require_once 'includes/auth_helper.php';
require_once 'includes/family_helper.php';
checkRole(['admin', 'pj_tahfidz']);
header('Cache-Control: no-store');
$pageTitle = 'Keluarga Anak–Bapak–Ibu';
$message = ''; $error = ''; $plan = null;
$types = ['father' => 'Bapak', 'mother' => 'Ibu', 'guardian' => 'Wali'];
function familyHtml($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
if (($_SESSION['family_import']['expires'] ?? 0) < time()) unset($_SESSION['family_import']);
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); throw new RuntimeException('Token tidak valid. Muat ulang halaman.'); }
        $action = $_POST['action'] ?? '';
        if ($action === 'preview') {
            $upload = $_FILES['family_file'] ?? [];
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($upload['size'] ?? 0) > 5 * 1024 * 1024 || !is_uploaded_file($upload['tmp_name'])) throw new RuntimeException('Pilih paket JSON, maksimal 5 MB.');
            $data = json_decode(file_get_contents($upload['tmp_name']), true, 512, JSON_THROW_ON_ERROR);
            familyValidatePackage($data);
            $_SESSION['family_import'] = ['data' => $data, 'choices' => [], 'expires' => time() + 1800];
        } elseif ($action === 'cancel') {
            unset($_SESSION['family_import']);
        } elseif (in_array($action, ['review', 'apply'], true)) {
            if (empty($_SESSION['family_import'])) throw new RuntimeException('Pratinjau kedaluwarsa. Unggah paket kembali.');
            foreach (is_array($_POST['choices'] ?? null) ? $_POST['choices'] : [] as $id => $choice) {
                if ($choice !== '' && is_scalar($choice)) $_SESSION['family_import']['choices'][(string) $id] = (string) $choice;
            }
            if ($action === 'apply') {
                $pending = $_SESSION['family_import'];
                $result = familyImportApply($pdo, $pending['data'], $pending['choices']);
                unset($_SESSION['family_import']);
                $message = 'Impor selesai: ' . $result['created'] . ' wali baru, ' . $result['reused'] . ' wali dihubungkan, ' . $result['students'] . ' anak, ' . $result['links'] . ' relasi.';
                addLog($pdo, 'IMPORT_FAMILY', $message);
            }
        }
    }
    if (!empty($_SESSION['family_import'])) $plan = familyImportPlan($pdo, $_SESSION['family_import']['data'], $_SESSION['family_import']['choices']);
} catch (RuntimeException $e) { $error = $e->getMessage(); }
catch (Throwable $e) {
    reportApplicationError($e, 'keluarga-impor');
    $error = 'Impor keluarga belum selesai. Coba lagi atau hubungi pengelola.';
}
$search = substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$page = max(1, (int) ($_GET['page'] ?? 1));
$families = familyFetchStudents($pdo, 50, ($page - 1) * 50, $search);
require 'includes/header.php'; require 'includes/sidebar.php';
?>
<div class="max-w-7xl mx-auto space-y-6">
    <div><h1 class="text-2xl font-bold text-slate-800">Keluarga Anak–Bapak–Ibu</h1><p class="text-sm text-slate-600 mt-2">Satu anak terhubung ke Bapak dan Ibu. Setiap wali memiliki catatan Tahsin sendiri.</p></div>
    <?php if ($message): ?><p role="status" class="rounded-xl bg-green-50 p-4 text-green-800"><?= familyHtml($message) ?></p><?php endif ?>
    <?php if ($error): ?><p role="alert" class="rounded-xl bg-red-50 p-4 text-red-800"><?= familyHtml($error) ?></p><?php endif ?>
    <?php if (!familySchemaReady($pdo)): ?><p class="rounded-xl bg-amber-50 p-4">Fitur keluarga belum diaktifkan di database. Admin server perlu menjalankan migrasi keluarga sebelum impor. Pratinjau tetap tersedia.</p><?php endif ?>
    <form method="post" enctype="multipart/form-data" class="bg-white rounded-2xl p-5 border space-y-3">
        <input type="hidden" name="csrf_token" value="<?= familyHtml(generateCsrfToken()) ?>"><input type="hidden" name="action" value="preview">
        <label for="family-file" class="block font-semibold">Paket impor keluarga (.json)</label>
        <p class="text-sm text-slate-600">Unggah keluarga-tahsin.json untuk pratinjau dan pencocokan wali lama. Kelompok, presensi, dan capaian tetap tersimpan.</p>
        <input id="family-file" name="family_file" type="file" accept=".json,application/json" required class="block w-full"><button class="bg-blue-600 text-white rounded-xl px-4 py-2">Pratinjau Impor</button>
    </form>
    <?php if ($plan): $pending = $_SESSION['family_import']; ?>
    <form method="post" class="bg-white rounded-2xl p-5 border space-y-4">
        <input type="hidden" name="csrf_token" value="<?= familyHtml(generateCsrfToken()) ?>">
        <h2 class="text-lg font-bold">Pratinjau snapshot <?= familyHtml($pending['data']['snapshot_date']) ?></h2>
        <p><?= $plan['create'] ?> wali baru · <?= $plan['reuse'] ?> terhubung · <?= $plan['conflicts'] ?> wali perlu ditinjau · <?= $plan['students'] ?> anak · <?= $plan['links'] ?> relasi · <?= count($plan['families']) ?> keluarga</p>
        <p class="text-sm text-slate-600">Satu baris menampilkan satu keluarga. Anak bersaudara yang memiliki pasangan orang tua sama ditampilkan bersama. Pemetaan wali yang ditinjau berlaku untuk semua anak yang terhubung ke wali tersebut.</p>
        <?php $renderedMappings = []; $familyRowNumber = 0; ?>
        <div class="overflow-x-auto max-h-96"><table class="min-w-full text-sm" style="min-width: 900px"><thead class="bg-slate-100"><tr><th class="text-left p-3">Anak</th><th class="text-left p-3">Ayah</th><th class="text-left p-3">Ibu</th><th class="text-left p-3">Wali lain</th></tr></thead><tbody>
        <?php foreach ($plan['families'] as $family): $familyRowNumber++; ?>
            <tr class="border-b align-top" id="family-row-<?= $familyRowNumber ?>">
                <td class="p-3"><div class="space-y-3">
                    <?php foreach ($family['students'] as $student): ?>
                        <div><strong><?= familyHtml($student['name']) ?></strong><span class="block text-slate-500">NIS <?= familyHtml($student['nis']) ?> · <?= familyHtml($student['class_name']) ?> · <?= familyHtml($student['status']) ?></span></div>
                    <?php endforeach ?>
                </div></td>
                <?php foreach ([['father', $family['father_ids']], ['mother', $family['mother_ids']], ['guardian', $family['guardian_ids']]] as [$parentType, $parentIds]): ?>
                    <td class="p-3"><div class="space-y-3">
                    <?php if (!$parentIds): ?><span class="text-amber-800">Tidak tercantum di sumber</span><?php endif ?>
                    <?php foreach ($parentIds as $id): $g = $plan['guardians'][(string) $id] ?? null; if (!$g) continue; ?>
                        <div class="rounded-lg border border-slate-200 p-3">
                            <strong><?= familyHtml($g['name']) ?></strong><span class="block text-slate-500"><?= $types[$parentType] ?> · ID sumber <?= familyHtml($id) ?></span>
                            <?php if ($g['error']): ?>
                                <?php if (isset($renderedMappings[$id])): ?>
                                    <p class="mt-2 text-red-700">Perlu ditinjau. <a class="underline font-semibold" href="#family-map-<?= familyHtml($id) ?>">Atur pemetaan wali ini</a>; pilihan berlaku untuk semua anaknya.</p>
                                <?php else: $renderedMappings[$id] = true; ?>
                                    <p class="mt-2 text-red-700"><?= familyHtml($g['error']) ?></p>
                                    <label class="block mt-2 text-sm" for="family-map-<?= familyHtml($id) ?>">Cocokkan dengan data Tahsin:</label>
                                    <select id="family-map-<?= familyHtml($id) ?>" name="choices[<?= familyHtml($id) ?>]" aria-label="Pemetaan <?= familyHtml($g['name']) ?>" class="w-full border rounded-lg p-2 mt-1"><option value="">Pilih setelah ditinjau</option><?php foreach ($g['candidates'] as $localId => $name): ?><option value="<?= (int) $localId ?>">Hubungkan ke peserta #<?= (int) $localId ?> · <?= familyHtml($name) ?></option><?php endforeach ?><option value="new">Buat wali baru (orang berbeda)</option></select>
                                <?php endif ?>
                            <?php elseif ($g['target_id']): ?>
                                <span class="block mt-1 text-emerald-700">Terhubung ke peserta Tahsin #<?= (int) $g['target_id'] ?></span><span class="block text-slate-500"><?= familyHtml($g['reason']) ?></span>
                            <?php else: ?>
                                <span class="block mt-1">Buat wali baru</span><span class="block text-slate-500"><?= familyHtml($g['reason']) ?></span>
                            <?php endif ?>
                        </div>
                    <?php endforeach ?>
                    </div></td>
                <?php endforeach ?>
            </tr>
        <?php endforeach ?>
        </tbody></table></div>
        <div class="flex flex-wrap gap-3"><button name="action" value="review" class="border rounded-xl px-4 py-2">Periksa Ulang Pemetaan</button><button name="action" value="apply" <?= $plan['conflicts'] || !$plan['schema_ready'] ? 'disabled' : '' ?> class="bg-blue-600 disabled:opacity-40 text-white rounded-xl px-4 py-2">Impor Keluarga</button><button name="action" value="cancel" class="text-slate-600 px-4 py-2">Batal</button></div>
    </form>
    <?php endif ?>
    <section class="bg-white rounded-2xl p-5 border space-y-4"><h2 class="text-lg font-bold">Data keluarga · <?= $families['total'] ?> anak</h2>
        <form method="get" class="flex gap-2"><input name="q" value="<?= familyHtml($search) ?>" aria-label="Cari anak atau wali" placeholder="Cari anak, NIS, atau wali" class="border rounded-xl p-2 flex-1"><button class="border rounded-xl px-4">Cari</button></form>
        <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-slate-100"><tr><th class="text-left p-2">Anak / NIS</th><th class="text-left p-2">Kelas / status</th><th class="text-left p-2">Bapak</th><th class="text-left p-2">Ibu</th></tr></thead><tbody>
        <?php foreach ($families['students'] as $s): ?><tr class="border-b"><td class="p-3"><?= familyHtml($s['nama_anak']) ?><br><span class="text-slate-500"><?= familyHtml($s['nis']) ?></span></td><td class="p-3"><?= familyHtml($s['kelas']) ?><br><span class="text-slate-500"><?= familyHtml($s['school_status']) ?></span></td>
        <?php foreach (['father','mother'] as $type): ?><td class="p-3"><?php $found = false; foreach ($s['guardians'] as $g) { if ($g['guardian_type'] !== $type) continue; $found = true; ?><span class="block"><?= familyHtml($g['source_name']) ?> · #<?= (int) $g['wali_santri_id'] ?></span><?php } if (!$found) echo '<span class="text-amber-800">Belum ada relasi di sumber</span>'; ?></td><?php endforeach ?></tr><?php endforeach ?>
        <?php if (!$families['students']): ?><tr><td colspan="4" class="p-4 text-slate-500">Belum ada data yang sesuai.</td></tr><?php endif ?></tbody></table></div>
        <div class="flex gap-4"><?php if ($page > 1): ?><a href="?q=<?= rawurlencode($search) ?>&amp;page=<?= $page - 1 ?>">Sebelumnya</a><?php endif ?><?php if ($page * 50 < $families['total']): ?><a href="?q=<?= rawurlencode($search) ?>&amp;page=<?= $page + 1 ?>">Berikutnya</a><?php endif ?></div>
    </section>
</div>
<?php require 'includes/footer.php'; ?>
