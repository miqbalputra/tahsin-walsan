<?php
require_once __DIR__ . '/security_helper.php';

function participantImportValidateChanges(array $changes): void
{
    foreach ($changes as $change) {
        if (!is_array($change)) throw new RuntimeException('Struktur rincian jurnal tidak valid.');
        if (!empty($change['unchanged'])) continue;
        $before=$change['before']??null;
        if (!is_array($change['after_parent']??null) || !is_array($change['after_children']??null)
            || ($before!==null && (!is_array($before) || !is_array($before['parent']??null) || !is_array($before['children']??null)))) throw new RuntimeException('Snapshot jurnal impor belum lengkap.');
        foreach ([$before['parent']??[],$change['after_parent']] as $parent) {
            foreach ($parent as $value) if ($value!==null && !is_scalar($value)) throw new RuntimeException('Isian snapshot jurnal tidak valid.');
        }
        foreach ([$before['children']??[],$change['after_children']] as $children) {
            foreach ($children as $child) {
                if (!is_array($child) || !is_scalar($child['id']??null) || !is_string($child['nama_anak']??null) || (isset($child['kelas']) && !is_string($child['kelas']))) throw new RuntimeException('Rincian anak dalam jurnal tidak valid.');
            }
        }
    }
}

function participantImportRenderChange(array $change, string $operationLabel = 'impor'): void
{
    $before=$change['before']['parent']??null;
    $after=$change['after_parent']??null;
    $name=$after['nama_bapak']??$change['nama_wali']??$before['nama_bapak']??'Peserta';
    ?>
    <details class="border border-slate-200 rounded-xl p-4 mb-4">
        <summary class="font-bold cursor-pointer py-2"><?= appEscape($name) ?><?= !empty($change['wali_id'])?' · ID '.(int)$change['wali_id']:'' ?></summary>
        <?php if (!empty($change['skipped'])): ?>
            <p class="text-slate-600 mt-2">Keluarga ini dilewati sesuai pilihan pratinjau. Data saat itu tidak diubah.</p>
        <?php elseif (!empty($change['unchanged'])): ?>
            <p class="text-slate-600 mt-2">Tidak ada perubahan pada peserta ini. Data yang sudah cocok dipertahankan.</p>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-3">
            <?php foreach (['Sebelum ' . $operationLabel=>$before,'Sesudah ' . $operationLabel=>$after] as $title=>$parent): ?>
                <section class="border border-slate-200 rounded-xl p-4">
                    <h4 class="font-bold mb-3"><?= appEscape($title) ?></h4>
                    <?php if (!is_array($parent)): ?><p class="text-slate-600">Peserta baru; belum ada data sebelumnya.</p>
                    <?php else: ?>
                    <dl class="space-y-3 text-sm">
                        <?php foreach (['nama_bapak'=>'Nama wali','no_hp'=>'Nomor telepon','alamat'=>'Alamat','kategori'=>'Kategori','tempat_tahsin'=>'Tempat tahsin luar','ustadz_luar'=>'Pengajar luar','status_aktif'=>'Status','lanjut_tahsin'=>'Catatan lanjut tahsin'] as $column=>$label):
                            $value=$parent[$column]??'';
                            if ($column==='status_aktif' && array_key_exists($column,$parent)) $value=(int)$value===1?'Aktif':'Arsip';
                            if ($column==='lanjut_tahsin' && array_key_exists($column,$parent)) $value=(int)$value===1?'Ya':'Tidak'; ?>
                            <div><dt class="font-bold"><?= appEscape($label) ?></dt><dd class="whitespace-pre-line break-words"><?= appEscape($value===''?'—':$value) ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                    <?php endif; ?>
                    <?php $children=$title==='Sebelum ' . $operationLabel?($change['before']['children']??[]):($change['after_children']??[]); ?>
                    <h5 class="font-bold mt-4 mb-2"><?= appEscape($change['children_label']??'Anak') ?></h5>
                    <?php if (!$children): ?><p class="text-sm text-slate-600">Belum ada data anak.</p><?php else: ?>
                    <ul class="list-disc pl-5 text-sm space-y-2"><?php foreach ($children as $child): ?><li>ID <?= (int)$child['id'] ?>: <?= appEscape($child['nama_anak']) ?> — <?= appEscape(($child['kelas']??'')?:'kelas belum diisi') ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </details>
    <?php
}
