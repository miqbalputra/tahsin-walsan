<?php $groupNames=array_column($catalog['groups'],'nama_halaqoh','id'); $back=http_build_query($filters); ?>
<div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-800">Daftar Capaian Peserta</h1>
    <p class="text-slate-500 text-sm">Capaian lengkap terakhir dari sesi terkonfirmasi. Guru melihat peserta pada penempatan aktifnya dan sumber bacaan sesuai penugasan.</p>
</div>
<?php if ($flash): ?><div role="status" class="bg-emerald-50 text-emerald-700 rounded-xl p-4 mb-5"><p><?= appEscape($flash['message']??'Capaian tersimpan.') ?></p><?php if (preg_match('/^[a-f0-9]{32}$/D',$flash['operation_id']??'')): ?><a class="underline" href="<?= appEscape(appUrl('peserta-riwayat.php?operation='.$flash['operation_id'])) ?>">Lihat riwayat sebelum/sesudah</a><?php endif; ?><?php if (!empty($flash['requires_review'])): ?><p>Keputusan sesi perlu diverifikasi ulang setelah pengubahan sumber. <a class="underline" href="<?= appEscape(appUrl('rekap-lengkap.php')) ?>">Buka Rekap Lengkap</a></p><?php endif; ?></div><?php endif; ?>
<form method="get" class="bg-white rounded-2xl border border-slate-100 p-5 mb-6 grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
    <div><label for="achievement-group" class="block text-sm font-bold mb-2">Halaqoh</label><select id="achievement-group" name="halaqoh_id" class="w-full border border-slate-200 rounded-xl p-3"><option value="">Semua halaqoh</option><?php foreach ($catalog['groups'] as $group): ?><option value="<?= (int)$group['id'] ?>" <?= (string)$group['id']===(string)$filters['halaqoh_id']?'selected':'' ?>><?= appEscape($group['nama_halaqoh']) ?></option><?php endforeach; ?></select></div>
    <div><label for="achievement-search" class="block text-sm font-bold mb-2">Cari peserta, anak, atau halaqoh</label><input id="achievement-search" name="search" type="search" maxlength="100" value="<?= appEscape($filters['search']) ?>" class="w-full border border-slate-200 rounded-xl p-3"></div>
    <?php if ($canManage): ?><div><label for="achievement-active" class="block text-sm font-bold mb-2">Status peserta</label><select id="achievement-active" name="aktif" class="w-full border border-slate-200 rounded-xl p-3"><?php foreach (['1'=>'Aktif','0'=>'Arsip',''=>'Semua status'] as $value=>$label): ?><option value="<?= $value ?>" <?= (string)$value===$filters['aktif']?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <button type="submit" class="bg-blue-600 text-white rounded-xl p-3 font-bold">Terapkan filter</button>
</form>
<p class="text-sm text-slate-500 mb-4"><?= $catalog['total'] ?> peserta · halaman <?= $catalog['page'] ?> dari <?= $catalog['pages'] ?></p>
<div class="bg-white rounded-2xl border border-slate-100 overflow-x-auto">
    <table class="w-full text-left"><thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="p-4">Peserta</th><th class="p-4">Penempatan aktif</th><th class="p-4">Anak dan kelas</th><th class="p-4">Capaian terakhir terkonfirmasi</th><th class="p-4">Tindakan</th></tr></thead>
    <tbody class="divide-y divide-slate-100 text-sm">
    <?php if (!$catalog['rows']): ?><tr><td colspan="5" class="p-8 text-center text-slate-500">Tidak ada peserta sesuai filter.</td></tr><?php endif; ?>
    <?php foreach ($catalog['rows'] as $row): ?>
        <tr><td class="p-4 align-top"><p class="font-bold"><?= appEscape($row['nama_bapak']) ?></p><?php if (!$row['status_aktif']): ?><span class="text-xs text-slate-500">Arsip</span><?php endif; ?>
            <?php $phone=preg_replace('/\D/','',(string)$row['no_hp']); if (str_starts_with($phone,'0')) $phone='62'.substr($phone,1); if ($phone!==''): $wa='https://wa.me/'.$phone.'?text='.rawurlencode('Assalamualaikum, '.$row['nama_bapak'].'. Capaian Tahsin tersimpan: '.$row['material'].'.'); ?><a class="block underline text-emerald-700 py-2" target="_blank" rel="noopener noreferrer" href="<?= appEscape($wa) ?>">Buka WhatsApp</a><?php endif; ?>
        </td><td class="p-4 align-top"><?php if (!$row['groups']): ?><span class="text-slate-500">Tanpa penempatan aktif</span><?php else: ?><ul class="space-y-2"><?php foreach ($row['groups'] as $label): ?><li><?= appEscape($label) ?></li><?php endforeach; ?></ul><?php endif; ?></td>
        <td class="p-4 align-top"><?= appEscape($row['anak']?:'Belum ada data anak') ?></td>
        <td class="p-4 align-top"><p class="font-semibold"><?= appEscape($row['material']) ?></p><?php if ($row['initial']): ?><p class="text-xs text-slate-500 mt-2">Capaian awal · terpisah dari presensi</p><?php elseif ($row['date']): ?><p class="text-xs text-slate-500 mt-2">Tanggal pelaksanaan <?= appEscape($row['date']) ?></p><?php endif; ?><?php if ($row['edit_date'] && $row['edit_date']!==$row['date']): ?><p class="text-xs text-amber-700 mt-2">Ada catatan Hadir yang belum lengkap pada <?= appEscape($row['edit_date']) ?>.</p><?php endif; ?></td>
        <td class="p-4 align-top"><div class="flex flex-col gap-3">
            <?php if ($canManage): ?>
                <?php if ($row['edit_record_id']!==null): ?><a class="underline text-blue-700" href="<?= appEscape(appUrl('capaian-form.php?'.http_build_query(['wali_id'=>$row['id'],'presensi_id'=>$row['edit_record_id'],'back'=>$back]))) ?>">Ubah catatan Hadir <?= appEscape($row['edit_date']) ?></a><?php endif; ?>
                <a class="underline text-blue-700" href="<?= appEscape(appUrl('capaian-form.php?'.http_build_query(['wali_id'=>$row['id'],'initial'=>'1','back'=>$back]))) ?>">Capaian awal</a>
                <a class="underline text-slate-600" href="<?= appEscape(appUrl('laporan.php?wali_santri_id='.$row['id'])) ?>">Tinjau catatan peserta</a>
            <?php else: foreach ($row['current_groups'] as $groupId): ?><a class="underline text-blue-700" href="<?= appEscape(appUrl('ustadz/form-presensi.php?id='.$groupId)) ?>">Input <?= appEscape($groupNames[$groupId]??'presensi') ?></a><?php endforeach; endif; ?>
        </div></td></tr>
    <?php endforeach; ?></tbody></table>
</div>
<nav aria-label="Halaman daftar capaian" class="flex gap-4 py-5"><?php if ($catalog['page']>1): ?><a class="underline text-blue-700" href="<?= appEscape(appUrl($catalogPath.'?'.http_build_query(array_merge($filters,['page'=>$catalog['page']-1])))) ?>">Sebelumnya</a><?php endif; ?><?php if ($catalog['page']<$catalog['pages']): ?><a class="underline text-blue-700" href="<?= appEscape(appUrl($catalogPath.'?'.http_build_query(array_merge($filters,['page'=>$catalog['page']+1])))) ?>">Berikutnya</a><?php endif; ?></nav>
