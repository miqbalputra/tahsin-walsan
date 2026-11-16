<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_helper.php';
require_once __DIR__ . '/includes/attendance_recovery_helper.php';
checkRole(['admin', 'pj_tahfidz']);
$error = '';
$message = $_SESSION['attendance_recovery_flash'] ?? '';
unset($_SESSION['attendance_recovery_flash']);
$preview = null; $records = []; $restoreToken = null;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireValidCsrfToken($_POST['csrf_token'] ?? null);
        $token = appRequestString($_POST, 'restore_token');
        $form = $_SESSION['attendance_restore_forms'][$token] ?? null;
        if (!preg_match('/^[a-f0-9]{32}$/D', $token) || !is_array($form) || ($form['actor_id'] ?? 0) !== (int) $_SESSION['user_id']
            || ($form['expires'] ?? 0) <= time() || appRequestString($_POST, 'confirmed') !== '1') throw new AttendanceHistoryException('Pratinjau berakhir atau belum dikonfirmasi. Buka kembali versi yang ingin dipulihkan.');
        $result = attendanceRecoveryApply($pdo, (int) $_SESSION['user_id'], $form['id'], $form['fingerprint']);
        $_SESSION['attendance_recovery_flash'] = $result['replayed'] ? 'Versi ini sudah dipulihkan sebelumnya. Tidak ada data lain yang diubah.' : 'Versi presensi berhasil dipulihkan dengan ID dan isi lama. Jejak pemulihan tersimpan di bawah.';
        redirectTo('presensi-riwayat.php?id=' . $result['history_id']);
    }
    $idRaw = appRequestString($_GET, 'id');
    $downloadRaw = appRequestString($_GET, 'download');
    if ($downloadRaw !== '') {
        $download = filter_var($downloadRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$download) throw new AttendanceHistoryException('Nomor versi tidak valid.');
        $entry = attendanceRecoveryEntry($pdo, $download);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="presensi-versi-' . $download . '.json"');
        echo json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        exit;
    }
    attendanceHistoryRequireReady($pdo);
    if ($idRaw !== '') {
        $id = filter_var($idRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) throw new AttendanceHistoryException('Nomor versi tidak valid.');
        $preview = attendanceRecoveryPreview($pdo, $id);
        if ($preview['can_restore']) {
            $forms = is_array($_SESSION['attendance_restore_forms'] ?? null) ? $_SESSION['attendance_restore_forms'] : [];
            $forms = array_filter($forms, static fn($form) => is_array($form) && ($form['expires'] ?? 0) > time());
            $restoreToken = bin2hex(random_bytes(16));
            $forms[$restoreToken] = ['id' => $id, 'actor_id' => (int) $_SESSION['user_id'], 'expires' => time() + 1800, 'fingerprint' => $preview['fingerprint']];
            $_SESSION['attendance_restore_forms'] = array_slice($forms, -8, null, true);
        }
    } else {
        $page = filter_var(appRequestString($_GET, 'page', '1'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if (!$page) throw new AttendanceHistoryException('Nomor halaman tidak valid.');
        $count = (int) $pdo->query('SELECT COUNT(*) FROM presensi_history')->fetchColumn();
        $pages = max(1, (int) ceil($count / 20)); $page = min($page, $pages);
        $records = $pdo->query('SELECT id,event,presensi_id,participant_ref,actor_name,origin_id,created_at FROM presensi_history ORDER BY id DESC LIMIT 20 OFFSET ' . (($page - 1) * 20))->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (AttendanceHistoryException | InvalidArgumentException $failure) {
    appRequestError(422, 'Riwayat belum dapat diproses', $failure->getMessage());
} catch (Throwable $failure) {
    reportApplicationError($failure, 'attendance-recovery-page');
    appRequestError(503, 'Pemulihan belum dapat dipastikan', 'Periksa riwayat terbaru sebelum mengirim kembali atau hubungi pengelola. Salinan lama tetap disimpan.');
}
function attendanceRecoveryRenderRow(?array $row): void
{
    if ($row === null) { echo '<p class="text-slate-500">Tidak ada catatan pada versi ini.</p>'; return; }
    $labels = ['id' => 'ID catatan', 'wali_santri_id' => 'ID peserta', 'halaqoh_id' => 'ID halaqoh', 'tanggal' => 'Tanggal',
        'status' => 'Status kehadiran', 'alasan' => 'Alasan / catatan', 'jenis_materi' => 'Jenis materi', 'jilid' => 'Jilid',
        'nama_surat' => 'Surat', 'halaman' => 'Halaman / ayat', 'hasil_talaqqi' => 'Hasil talaqqi', 'created_at' => 'Waktu pencatatan'];
    echo '<dl class="space-y-3">';
    foreach ($row as $column => $value) {
        $text = $value === null ? 'NULL' : ($value === '' ? '(kosong)' : $value);
        if ($value !== null && (!preg_match('//u', $value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value))) $text = 'Biner (hex): ' . bin2hex($value);
        echo '<div><dt class="text-xs font-bold text-slate-500">' . appEscape($labels[$column] ?? $column) . '</dt><dd class="text-sm whitespace-pre-wrap break-all">' . appEscape($text) . '</dd></div>';
    }
    echo '</dl>';
}
$pageTitle = 'Riwayat Pemulihan Presensi';
require __DIR__ . '/includes/header.php'; require __DIR__ . '/includes/sidebar.php';
?>
<div class="max-w-6xl mx-auto">
    <a class="inline-block underline text-blue-700 py-3" href="laporan.php">Kembali ke laporan</a>
    <h1 class="text-2xl font-bold mb-4">Riwayat Pemulihan Presensi</h1>
    <p class="text-slate-600 mb-5">Versi sebelum perubahan dan penghapusan disimpan sejak riwayat dipasang. Bandingkan salinan lama dengan catatan saat ini sebelum memulihkan. Draft perangkat tetap perlu ditinjau ulang setelah catatan resmi berubah.</p>
    <?php if ($message): ?><p role="status" class="bg-emerald-50 text-emerald-700 rounded-xl p-4 mb-5"><?= appEscape($message) ?></p><?php endif; ?>
    <?php if ($preview): $entry = $preview['entry']; $identity = attendanceHistoryDecode($entry['context_json']); ?>
        <a class="inline-block underline text-blue-700 py-3" href="presensi-riwayat.php">Semua versi</a>
        <h2 class="text-xl font-bold mb-3">Versi <?= (int) $entry['id'] ?> · catatan ID <?= appEscape($entry['presensi_id']) ?></h2>
        <p class="mb-3"><?= appEscape($entry['created_at']) ?> · <?= appEscape($entry['actor_name'] ?? 'Sistem / akun tidak tercatat') ?> · <?= $entry['origin_id'] !== null ? 'Pemulihan' : ($entry['event'] === 'delete' ? 'Penghapusan' : 'Perubahan') ?></p>
        <p class="mb-5"><?= appEscape($identity['parent_name'] ?? '') ?> · <?= appEscape($identity['group_name'] ?? 'Tanpa halaqoh') ?></p>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <?php foreach (['Versi sebelum tindakan' => attendanceHistoryDecode($entry['before_json']), 'Versi sesudah tindakan' => attendanceHistoryDecode($entry['after_json']), 'Catatan saat ini' => $preview['current']] as $label => $row): ?>
                <section class="bg-white border border-slate-200 rounded-xl p-4"><h3 class="font-bold mb-4"><?= appEscape($label) ?></h3><?php attendanceRecoveryRenderRow($row); ?></section>
            <?php endforeach; ?>
        </div>
        <?php if ($preview['can_restore']): ?>
            <form method="post" class="bg-blue-50 border border-blue-100 rounded-xl p-5 mb-5">
                <?php csrfField(); ?><input type="hidden" name="restore_token" value="<?= appEscape($restoreToken) ?>">
                <label for="confirm-restore" class="block mb-4"><input id="confirm-restore" type="checkbox" name="confirmed" value="1" required> Saya sudah memeriksa identitas, tanggal, dan versi lama yang akan dipulihkan.</label>
                <button class="bg-blue-600 text-white rounded-xl px-5 py-3 font-bold" type="submit">Pulihkan versi sebelum tindakan</button>
            </form>
        <?php else: ?><p role="status" class="bg-amber-50 text-amber-700 rounded-xl p-4 mb-4"><?= appEscape($preview['reason']) ?><?php if ($preview['restored_id'] !== null): ?> <a class="underline" href="?id=<?= $preview['restored_id'] ?>">Lihat jejak pemulihan</a><?php endif; ?></p><?php endif; ?>
        <?php if ($entry['origin_id'] !== null): ?><a class="inline-block underline text-blue-700 py-3" href="?id=<?= (int) $entry['origin_id'] ?>">Lihat versi asal pemulihan</a><?php endif; ?>
        <a class="inline-block underline text-blue-700 py-3" href="?download=<?= (int) $entry['id'] ?>">Unduh salinan lengkap versi ini (berisi data pribadi)</a>
    <?php elseif (!$records): ?><p>Belum ada versi perubahan atau penghapusan presensi yang tercatat.</p>
    <?php else: ?>
        <ul class="space-y-3"><?php foreach ($records as $record): ?><li class="bg-white border border-slate-200 rounded-xl p-4"><a class="underline text-blue-700" href="?id=<?= (int) $record['id'] ?>">Versi <?= (int) $record['id'] ?> · catatan <?= appEscape($record['presensi_id']) ?> · peserta <?= appEscape($record['participant_ref']) ?></a><p><?= appEscape($record['created_at']) ?> · <?= appEscape($record['actor_name'] ?? 'Sistem / akun tidak tercatat') ?> · <?= $record['origin_id'] !== null ? 'Pemulihan' : ($record['event'] === 'delete' ? 'Penghapusan' : 'Perubahan') ?></p></li><?php endforeach; ?></ul>
        <nav aria-label="Halaman riwayat presensi" class="flex gap-4 py-5"><p>Halaman <?= $page ?> dari <?= $pages ?></p><?php if ($page > 1): ?><a class="underline" href="?page=<?= $page - 1 ?>">Sebelumnya</a><?php endif; ?><?php if ($page < $pages): ?><a class="underline" href="?page=<?= $page + 1 ?>">Berikutnya</a><?php endif; ?></nav>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
