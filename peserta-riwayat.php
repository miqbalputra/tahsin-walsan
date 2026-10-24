<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_helper.php';
require_once __DIR__ . '/includes/participant_merge_helper.php';
require_once __DIR__ . '/includes/participant_import_view.php';
require_once __DIR__ . '/includes/participant_merge_view.php';
checkRole(['admin', 'pj_tahfidz']);
$record = null;
$records = [];
try {
    $id = participantId($_GET['id'] ?? '', true);
    if ($id!==null && ($redirectId=participantMergeTarget($pdo,$id))!==null) $id=$redirectId;
    $pageRaw = appRequestString($_GET, 'page', '1');
    $page = filter_var($pageRaw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>1000000]]);
    if (!$page) throw new ParticipantManagementException('Nomor halaman tidak valid.');
    $operation = appRequestString($_GET, 'operation');
    $download = appRequestString($_GET, 'download');
    if ($operation !== '' && $download !== '') throw new ParticipantManagementException('Pilih satu rincian riwayat.');
    $key = $operation ?: $download;
    $ready = participantSchemaReady($pdo);
    if ($key !== '') {
        if (!preg_match('/^[a-f0-9]{32}$/D',$key) || !$ready) throw new ParticipantManagementException('Riwayat perubahan tidak ditemukan.');
        $statement=$pdo->prepare('SELECT * FROM participant_changes WHERE operation_id=?'); $statement->execute([$key]);
        $record=$statement->fetch(PDO::FETCH_ASSOC);
        if (!$record || ($id !== null && (int)$record['wali_id'] !== $id)) throw new ParticipantManagementException('Riwayat perubahan tidak ditemukan.');
        if ($download !== '') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="perubahan-peserta-' . $key . '.json"');
            echo json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            exit;
        }
        $record['before']=json_decode($record['before_json'],true,64,JSON_THROW_ON_ERROR);
        $record['after']=json_decode($record['after_json'],true,64,JSON_THROW_ON_ERROR);
        $change=['before'=>$record['before'],'after_parent'=>$record['after']['parent']??null,'after_children'=>$record['after']['children']??null];
        participantImportValidateChanges([$change]);
        $snapshots=[$record['before'],$record['after']];
        if ($record['action']==='merge') {
            $source=$record['before']['merge_source']??null;
            if (!is_array($source)) throw new RuntimeException('Snapshot sumber penggabungan tidak valid.');
            participantImportValidateChanges([['before'=>null,'after_parent'=>$source['parent']??null,'after_children'=>$source['children']??null]]);
            $snapshots[]=$source;
            foreach ($snapshots as $snapshot) participantMergeValidateSnapshot($snapshot);
            foreach ([$record['before'],$record['after']] as $snapshot) {
                if (!is_array($snapshot['merge_related']??null)) throw new RuntimeException('Rincian data penggabungan tidak valid.');
                foreach ($snapshot['merge_related'] as $rows) {
                    if (!is_array($rows)) throw new RuntimeException('Rincian tabel penggabungan tidak valid.');
                    foreach ($rows as $row) {
                        if (!is_array($row)) throw new RuntimeException('Rincian baris penggabungan tidak valid.');
                        foreach ($row as $value) if ($value!==null && !is_scalar($value)) throw new RuntimeException('Nilai rincian penggabungan tidak valid.');
                    }
                }
            }
            foreach (array_merge($record['before']['merge_related']['tahsin_autosave_drafts']??[],$record['after']['merge_related']['tahsin_autosave_drafts']??[]) as $draft) {
                if (!is_string($draft['payload_json']??null) || !is_string($draft['session_date']??null) || !filter_var($draft['user_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) || !filter_var($draft['halaqoh_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) || !filter_var($draft['wali_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])) throw new RuntimeException('Draft asal penggabungan tidak valid.');
            }
            foreach (array_merge($record['before']['merge_related']['tahsin_capaian_awal']??[],$record['after']['merge_related']['tahsin_capaian_awal']??[]) as $baseline) if (!filter_var($baseline['wali_santri_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])) throw new RuntimeException('Capaian awal asal penggabungan tidak valid.');
            $mergeResult=$record['after']['merge_result']??null;
            if (!is_array($mergeResult)) throw new RuntimeException('Ringkasan penggabungan tidak valid.');
            foreach (['children_moved','memberships_moved','attendance_moved','drafts_review'] as $counter) if (filter_var($mergeResult[$counter]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false || !isset($mergeResult[$counter])) throw new RuntimeException('Hitungan ringkasan penggabungan tidak valid.');
        }
        foreach ($snapshots as $snapshot) {
            if ($snapshot===null) continue;
            if (!is_array($snapshot['memberships']??null)) throw new RuntimeException('Struktur riwayat keanggotaan tidak valid.');
            foreach ($snapshot['memberships'] as $membership) {
                if (!is_array($membership)) throw new RuntimeException('Keanggotaan riwayat tidak valid.');
                foreach (['participant_group_name','participant_program','archived_at','id'] as $field) if (isset($membership[$field]) && !is_scalar($membership[$field])) throw new RuntimeException('Isian riwayat keanggotaan tidak valid.');
            }
        }
    } elseif ($ready) {
        $where=$id===null?'':' WHERE wali_id=?'; $parameters=$id===null?[]:[$id];
        $statement=$pdo->prepare('SELECT COUNT(*) FROM participant_changes' . $where); $statement->execute($parameters);
        $count=(int)$statement->fetchColumn(); $pages=max(1,(int)ceil($count/20)); $page=min($page,$pages);
        $statement=$pdo->prepare('SELECT operation_id,actor_name,action,wali_id,created_at FROM participant_changes' . $where . ' ORDER BY created_at DESC,operation_id DESC LIMIT 20 OFFSET ' . (($page-1)*20));
        $statement->execute($parameters); $records=$statement->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (ParticipantManagementException|InvalidArgumentException $failure) {
    appRequestError(422,'Riwayat belum dapat dibuka',$failure->getMessage());
} catch (Throwable $failure) {
    reportApplicationError($failure,'participant-history');
    appRequestError(503,'Rincian riwayat belum dapat dibuka','Coba lagi atau hubungi pengelola. Data peserta tidak diubah.');
}
$pageTitle='Riwayat Perubahan Peserta';
require __DIR__ . '/includes/header.php'; require __DIR__ . '/includes/sidebar.php';
?>
<div class="max-w-4xl mx-auto">
    <a href="peserta.php" class="inline-block underline text-blue-700 py-3">Kembali ke daftar peserta</a>
    <h1 class="text-2xl font-bold mb-4">Riwayat perubahan peserta<?= $id!==null?' · ID '.$id:'' ?></h1>
    <p class="text-slate-600 mb-5">Riwayat ini berisi perubahan melalui formulir dan penggabungan peserta sejak jurnal dipasang. Data lama sebelum pemasangan jurnal dan perubahan melalui fitur lain memiliki pencatatan masing-masing.</p>
    <?php if (!$ready): ?><p>Jurnal perubahan belum disiapkan oleh pengelola.</p>
    <?php elseif ($record): ?>
        <p class="mb-4"><?= appEscape($record['created_at']) ?> · <?= appEscape($record['actor_name']) ?> · <?= $record['action']==='merge'?'Gabung peserta':($record['action']==='archive'?'Arsipkan peserta':'Simpan peserta') ?></p>
        <?php if ($record['action']==='merge'): ?><p role="status" class="bg-blue-50 text-blue-800 rounded-xl p-4 mb-5">Penggabungan tersimpan. Pada operasi ini, ID sumber <?= (int)$record['before']['merge_source']['parent']['id'] ?> digabung ke ID <?= (int)$record['after']['parent']['id'] ?>. Draft hasil penggabungan perlu ditinjau ulang. Versi asal tersedia di bawah. <?php if ($record['wali_id']!==null): ?><a class="underline" href="peserta-form.php?id=<?= (int)$record['wali_id'] ?>">Lihat data peserta saat ini (ID <?= (int)$record['wali_id'] ?>)</a><?php endif; ?></p><?php endif; ?>
        <?php if ($record['action']==='merge'): ?><ul class="list-disc pl-5 mb-5"><?php foreach (['children_moved'=>'anak dipindahkan dengan ID lama','attendance_moved'=>'catatan presensi dipindahkan','memberships_moved'=>'keanggotaan sumber dipertahankan sebagai aktif/arsip','drafts_review'=>'draft perlu ditinjau ulang'] as $counter=>$label): ?><li><?= (int)$mergeResult[$counter] ?> <?= $label ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php participantImportRenderChange($change, 'perubahan'); ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php foreach (['Sebelum perubahan'=>$record['before'],'Sesudah perubahan'=>$record['after']] as $label=>$snapshot): ?>
                <section class="bg-white rounded-xl border border-slate-200 p-4"><h2 class="font-bold mb-3">Keanggotaan — <?= $label ?></h2>
                    <?php if (empty($snapshot['memberships'])): ?><p>Belum ada keanggotaan.</p><?php else: ?><ul class="space-y-3"><?php foreach ($snapshot['memberships'] as $membership): ?><li><?= appEscape($membership['participant_group_name']??'Halaqoh') ?> · <?= appEscape($membership['participant_program']??'') ?> · ID <?= appEscape($membership['id']??'') ?>: <?= ($membership['archived_at']??null)===null?'aktif':'arsip sejak '.appEscape($membership['archived_at']) ?></li><?php endforeach; ?></ul><?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
        <?php if ($record['action']==='merge'): ?><section class="mt-6"><h2 class="font-bold text-xl mb-4">Capaian dan draft sesudah penggabungan</h2><?php participantMergeRenderRelated($record['after']['merge_related'],'sesudah penggabungan'); ?></section><?php participantMergeRenderAlternatives($record['before']); endif; ?>
        <a class="inline-block underline text-blue-700 py-4" href="?download=<?= appEscape($record['operation_id']) ?>">Unduh salinan rincian (berisi data pribadi)</a>
    <?php elseif (!$records): ?><p>Belum ada perubahan yang tercatat pada jurnal ini.</p>
    <?php else: ?>
        <ul class="space-y-3"><?php foreach ($records as $row): ?><li class="border border-slate-200 rounded-xl p-4 bg-white"><a class="underline text-blue-700" href="?operation=<?= appEscape($row['operation_id']) ?>"><?= appEscape($row['created_at']) ?> — ID peserta <?= (int)$row['wali_id'] ?></a><p><?= appEscape($row['actor_name']) ?> · <?= $row['action']==='merge'?'Gabung peserta':($row['action']==='archive'?'Arsipkan peserta':'Simpan peserta') ?></p></li><?php endforeach; ?></ul>
        <nav aria-label="Halaman riwayat peserta" class="flex gap-4 py-5"><p>Halaman <?= $page ?> dari <?= $pages ?></p><?php if ($page>1): ?><a class="underline text-blue-700" href="?page=<?= $page-1 ?><?= $id!==null?'&amp;id='.$id:'' ?>">Sebelumnya</a><?php endif; ?><?php if ($page<$pages): ?><a class="underline text-blue-700" href="?page=<?= $page+1 ?><?= $id!==null?'&amp;id='.$id:'' ?>">Berikutnya</a><?php endif; ?></nav>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
