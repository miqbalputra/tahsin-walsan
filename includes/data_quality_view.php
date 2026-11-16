<?php
/** Presentation only: these helpers never write to the database. */
function qualityViewDate(string $date): string
{
    if (!tahsinDate($date)) return $date;
    $months=[1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $d=new DateTimeImmutable($date);
    return $d->format('j').' '.$months[(int)$d->format('n')].' '.$d->format('Y');
}
function qualityViewStatus(?string $status): string
{
    return ['H'=>'Hadir','S'=>'Sakit','I'=>'Izin','A'=>'Alfa'][$status??'']??($status?:'Belum diisi');
}
function qualityViewValue(array $row,string $field): string
{
    if ($field==='status') return qualityViewStatus($row[$field]??null);
    $value=$row[$field]??null;
    return $value===null || $value===''?'—':(string)$value;
}
function qualityViewIcon(string $name): void
{
    $paths=[
        'identity'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/><circle cx="9" cy="7" r="4"/>',
        'membership'=>'<path d="M3 10 12 3l9 7v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1Zm6 11v-8h6v8"/>',
        'attendance'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18m-13 5h2m4 0h2"/>',
        'draft'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Zm0 0v6h6M8 13h8m-8 4h5"/>',
        'shield'=>'<path d="m12 3 9 4v5c0 5-9 10-9 10S3 17 3 12V7Z"/><path d="m8 12 3 3 5-6"/>',
        'arrow'=>'<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'back'=>'<path d="M19 12H5m6-6-6 6 6 6"/>',
        'search'=>'<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
        'check'=>'<path d="m5 12 4 4L19 6"/>',
        'download'=>'<path d="M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4"/>',
        'info'=>'<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v1"/>',
        'chevron'=>'<path d="m6 9 6 6 6-6"/>',
    ];
    echo '<svg class="dq-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.($paths[$name]??$paths['info']).'</svg>';
}
$categoryHelp=['identity'=>'Nama atau nomor HP mirip','membership'=>'Lebih dari satu halaqoh aktif','attendance'=>'Beberapa catatan dalam satu sesi','draft'=>'Isian server perlu dicocokkan'];
$programLabels=['bapak'=>'Program Bapak','ibu'=>'Program Ibu','unknown'=>'Program belum diketahui'];
$pendingCount=count(array_filter($findings??[],static fn($f)=>$f['status']==='pending'));
$advanced=!empty($filters['kind']) || !empty($filters['halaqoh']) || !empty($filters['from']) || !empty($filters['to']);
?>
<link rel="stylesheet" href="<?= appEscape(appAssetUrl('assets/css/data-quality.css')) ?>">
<div class="dq-page">
    <a class="dq-back" href="peserta.php"><?php qualityViewIcon('back'); ?> Kembali ke data peserta</a>
    <div class="dq-hero">
        <div><p class="dq-eyebrow">KUALITAS DATA</p><h1>Pemeriksaan data ganda</h1><p>Temukan masalahnya, bandingkan sumbernya, lalu tentukan langkah yang tepat.</p></div>
        <a class="dq-button dq-button--secondary" href="#dq-guide"><?php qualityViewIcon('info'); ?> Cara memeriksa</a>
    </div>
    <div class="dq-notice dq-notice--safe"><?php qualityViewIcon('shield'); ?><div><strong>Data asli tetap tersimpan.</strong><p>Tidak ada penggabungan atau penghapusan otomatis. Nama atau nomor HP yang sama perlu diperiksa terlebih dahulu.</p></div></div>
    <?php if ($error): ?><div role="alert" class="dq-notice dq-notice--error"><?php qualityViewIcon('info'); ?><div><strong>Pemeriksaan belum selesai</strong><p><?= appEscape($error) ?></p></div></div><?php endif; ?>
    <?php if (($message??'')==='saved'): ?><div role="status" class="dq-notice dq-notice--success"><?php qualityViewIcon('check'); ?><div><strong>Keputusan tersimpan.</strong><p>Catatan sumber tetap tersedia. Anda dapat memeriksa hasilnya di bawah.</p></div></div><?php endif; ?>
    <?php if (!$ready): ?><div class="dq-notice dq-notice--warning"><?php qualityViewIcon('info'); ?><div><strong>Saat ini hanya dapat memeriksa.</strong><p>Pengelola perlu menyiapkan sistem pemeriksaan sebelum keputusan dapat disimpan.</p></div></div><?php endif; ?>
    <section aria-labelledby="dq-summary-title">
        <div class="dq-section-heading"><h2 id="dq-summary-title">Ringkasan masalah</h2><span><?= $pendingCount ?> temuan perlu ditinjau</span></div>
        <p class="dq-caption">Pilih kategori untuk melihat semua temuannya, termasuk yang sudah ditinjau.</p>
        <div class="dq-categories">
        <?php foreach ($labels as $kind=>$label): $active=($filters['kind']??'')===$kind; ?>
            <a class="dq-category dq-tone-<?= $kind ?> <?= $active?'dq-category--active':'' ?>" href="?<?= appEscape(http_build_query(['kind'=>$kind,'status'=>'all'])) ?>#dq-results" <?= $active?'aria-current="true"':'' ?>>
                <div class="dq-category-top"><span class="dq-category-icon"><?php qualityViewIcon($kind); ?></span><strong><?= (int)($counts[$kind]??0) ?></strong></div>
                <span class="dq-category-name"><?= appEscape($label) ?></span><span class="dq-category-help"><?= appEscape($categoryHelp[$kind]) ?></span>
                <span class="dq-category-link">Lihat temuan <?php qualityViewIcon('arrow'); ?></span>
            </a>
        <?php endforeach; ?>
        </div>
    </section>
    <form method="get" class="dq-filters" aria-labelledby="dq-filter-title">
        <h2 id="dq-filter-title"><?php qualityViewIcon('search'); ?> Cari & saring temuan</h2>
        <div class="dq-filter-grid">
            <label class="dq-search">Nama / ID peserta<input name="search" maxlength="100" placeholder="Contoh: Purwanto atau 72" value="<?= appEscape($filters['search']??'') ?>"></label>
            <?php foreach (['program'=>[''=>'Semua program']+$programLabels,'status'=>['pending'=>'Perlu ditinjau','all'=>'Semua status','resolved'=>'Catatan resmi dipilih','distinct'=>'Identitas berbeda']] as $field=>$options): ?>
                <label><?= $field==='program'?'Program':'Status' ?><select name="<?= $field ?>"><?php foreach ($options as $value=>$label): ?><option value="<?= appEscape($value) ?>" <?= ($filters[$field]??'')===$value?'selected':'' ?>><?= appEscape($label) ?></option><?php endforeach; ?></select></label>
            <?php endforeach; ?>
        </div>
        <details class="dq-advanced" <?= $advanced?'open':'' ?>><summary>Filter lanjutan <span>Jenis masalah, halaqoh & tanggal</span><?php qualityViewIcon('chevron'); ?></summary>
            <div class="dq-filter-grid dq-filter-grid--advanced">
                <label>Jenis masalah<select name="kind"><option value="">Semua jenis</option><?php foreach ($labels as $value=>$label): ?><option value="<?= $value ?>" <?= ($filters['kind']??'')===$value?'selected':'' ?>><?= appEscape($label) ?></option><?php endforeach; ?></select></label>
                <label>Halaqoh<select name="halaqoh"><option value="">Semua halaqoh</option><?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= ($filters['halaqoh']??'')===(string)$g['id']?'selected':'' ?>><?= appEscape($g['nama_halaqoh']) ?></option><?php endforeach; ?></select></label>
                <?php foreach (['from'=>'Dari tanggal','to'=>'Sampai tanggal'] as $field=>$label): ?><label><?= $label ?><input type="date" name="<?= $field ?>" value="<?= appEscape($filters[$field]??'') ?>"></label><?php endforeach; ?>
            </div>
        </details>
        <div class="dq-filter-actions"><div class="dq-actions"><button class="dq-button dq-button--primary" type="submit"><?php qualityViewIcon('search'); ?> Tampilkan hasil</button><a class="dq-button dq-button--quiet" href="periksa-data.php">Reset filter</a></div>
            <a class="dq-export" href="?<?= appEscape(http_build_query($filters+['download'=>'json'])) ?>"><?php qualityViewIcon('download'); ?><span>Unduh hasil JSON<small>Berisi data pribadi · simpan dengan aman</small></span></a>
        </div>
    </form>
    <div class="dq-results-heading" id="dq-results"><div><h2>Hasil pemeriksaan <span class="dq-count"><?= $total ?></span></h2><p><?= $total?'Menampilkan '.(($page-1)*20+1).'–'.min($page*20,$total).' dari '.$total.' temuan':'Tidak ada temuan yang cocok dengan filter' ?></p></div><span class="dq-page-number">Halaman <?= $page ?> dari <?= $pages ?></span></div>
    <?php if (!$rows): ?><div class="dq-empty"><?php qualityViewIcon('check'); ?><h3><?= $error?'Coba periksa kembali':'Tidak ada temuan pada filter ini' ?></h3><p><?= $error?'Periksa pesan di atas, lalu muat ulang pemeriksaan.':'Coba kategori lain atau reset filter untuk melihat semua hasil. Draft yang hanya ada di HP belum dapat diperiksa oleh server.' ?></p><a class="dq-button dq-button--secondary" href="periksa-data.php">Reset filter</a></div><?php endif; ?>
    <div class="dq-findings">
    <?php foreach ($rows as $f):
        $op=bin2hex(random_bytes(16));
        $_SESSION['quality_previews'][$op]=['actor'=>$actor,'expires'=>time()+1800,'kind'=>$f['kind'],'key'=>$f['key'],'fingerprint'=>$f['fingerprint'],
            'wali'=>$f['wali_ids'][0],'program'=>$f['program'],'session'=>$f['session'],'source_fingerprint'=>tahsinFingerprint($f['records']),'decision_id'=>(int)($f['decision']['id']??0)];
        while (count($_SESSION['quality_previews'])>80) array_shift($_SESSION['quality_previews']);
        $heading='finding-title-'.$f['key'];
    ?>
        <section class="dq-finding dq-tone-<?= appEscape($f['kind']) ?>" id="finding-<?= appEscape($f['key']) ?>" aria-labelledby="<?= $heading ?>">
            <div class="dq-finding-heading"><div><h3 id="<?= $heading ?>"><?php qualityViewIcon($f['kind']); ?><?= appEscape($labels[$f['kind']]) ?></h3><p class="dq-person"><?= appEscape(implode(' · ',$f['names'])) ?></p><div class="dq-meta"><?php if ($f['program']): ?><span><?= appEscape($programLabels[$f['program']]??$f['program']) ?></span><?php endif; ?><?php if ($f['session']): ?><span>Sesi <?= appEscape(qualityViewDate($f['session'])) ?></span><?php endif; ?></div></div><span class="dq-status dq-status--<?= appEscape($f['status']) ?>"><?php if ($f['status']!=='pending') qualityViewIcon('check'); ?><?= appEscape($states[$f['status']]??$f['status']) ?></span></div>
            <div class="dq-finding-body">
                <div class="dq-evidence"><strong>Yang ditemukan</strong><ul><?php foreach ($f['evidence'] as $e): ?><li><?= appEscape($e) ?></li><?php endforeach; ?></ul></div>
                <?php if ($f['kind']==='identity'): ?>
                    <p class="dq-next">Bandingkan profil dan data anak sebelum memutuskan apakah ini orang yang sama.</p>
                    <div class="dq-actions"><?php if (($_SESSION['role']??'')==='admin'): ?><a class="dq-button dq-button--primary" href="<?= appEscape('merge-peserta.php?'.http_build_query(['keep_id'=>$f['wali_ids'][0],'delete_id'=>$f['wali_ids'][1]])) ?>">Bandingkan untuk merge (admin) <?php qualityViewIcon('arrow'); ?></a><?php else: ?><p class="dq-caption">Penggabungan profil dilakukan oleh admin.</p><?php endif; ?></div>
                    <?php if ($ready): ?><details class="dq-review"><summary><?= $f['status']==='distinct'?'Buka kembali pemeriksaan identitas':'Sudah yakin keduanya orang berbeda?' ?><?php qualityViewIcon('chevron'); ?></summary><form method="post" class="dq-decision-form"><?php csrfField(); ?><input type="hidden" name="operation" value="<?= $op ?>"><input type="hidden" name="action" value="<?= $f['status']==='distinct'?'pending':'distinct' ?>"><p>Penandaan ini menyimpan hasil pemeriksaan dan tidak mengubah profil peserta.</p><label>Alasan pemeriksaan<textarea name="note" maxlength="2000" rows="2" placeholder="Jelaskan bukti atau hasil konfirmasi Anda" required></textarea></label><button class="dq-button dq-button--secondary"><?= $f['status']==='distinct'?'Buka pemeriksaan kembali':'Tandai sebagai orang berbeda' ?></button></form></details><?php endif; ?>
                    <?php if (!empty($f['review']['note'])): ?><p class="dq-decision-note"><strong>Catatan pemeriksaan:</strong> <?= appEscape($f['review']['note']) ?></p><?php endif; ?>
                <?php elseif ($f['kind']==='membership'): ?>
                    <div class="dq-groups"><?php foreach ($f['group_ids'] as $gid): ?><span><?php qualityViewIcon('membership'); ?><?= appEscape($groupNames[$gid]??'Halaqoh #'.$gid) ?></span><?php endforeach; ?></div>
                    <p class="dq-next">Konfirmasi halaqoh yang benar, lalu pilih satu penempatan aktif pada program ini. Penempatan lama diarsipkan; presensi dan riwayat tetap tersimpan.</p>
                    <a class="dq-button dq-button--primary" href="peserta-form.php?id=<?= $f['wali_ids'][0] ?>">Tinjau penempatan peserta <?php qualityViewIcon('arrow'); ?></a>
                <?php elseif ($f['kind']==='attendance'):
                    $payloadLabels=['status'=>'Kehadiran','alasan'=>'Alasan','jenis_materi'=>'Materi','jilid'=>'Jilid','nama_surat'=>'Surat','halaman'=>'Halaman / ayat','hasil_talaqqi'=>'Hasil']; $different=[];
                    foreach ($payloadLabels as $field=>$label) if (count(array_unique(array_map(static fn($r)=>qualityViewValue($r,$field),$f['records'])))>1) $different[]=$field;
                ?>
                    <div class="dq-record-previews" aria-label="Ringkasan catatan sumber"><?php foreach ($f['records'] as $r): ?><div><span class="dq-record-id">#<?= (int)$r['id'] ?></span><span><?= appEscape($groupNames[$r['halaqoh_id']]??'Halaqoh belum diketahui') ?></span><span class="dq-attendance dq-attendance--<?= appEscape($r['status']) ?>"><?= appEscape(qualityViewStatus($r['status'])) ?></span></div><?php endforeach; ?></div>
                    <details class="dq-comparison"><summary>Bandingkan <?= count($f['records']) ?> catatan & pilih catatan resmi <?php qualityViewIcon('chevron'); ?></summary>
                        <div class="dq-comparison-content"><p class="dq-comparison-tip"><?php qualityViewIcon('info'); ?><?= $different?'Bagian berlabel “Berbeda” perlu dicocokkan dengan konfirmasi pengajar.':'Isi catatan terlihat sama. Periksa tanggal dan halaqoh sebelum menentukan catatan resmi.' ?></p>
                            <div class="dq-records">
                            <?php foreach ($f['records'] as $r): $official=$f['status']==='resolved' && (int)($f['decision']['selected_presensi_id']??0)===(int)$r['id']; ?>
                                <article class="dq-record <?= $official?'dq-record--official':'' ?>" aria-label="Catatan #<?= (int)$r['id'] ?>"><div class="dq-record-heading"><strong>Catatan #<?= (int)$r['id'] ?></strong><?php if ($official): ?><span class="dq-official"><?php qualityViewIcon('check'); ?>Resmi dipilih</span><?php endif; ?></div><p class="dq-record-location"><?= appEscape($groupNames[$r['halaqoh_id']]??'Halaqoh belum diketahui') ?><span><?= appEscape(qualityViewDate($r['tanggal'])) ?></span></p>
                                    <dl class="dq-payload"><?php foreach ($payloadLabels as $field=>$label): if ($field==='jilid' && !in_array($field,$different,true) && qualityViewValue($r,$field)==='—') continue; $diff=in_array($field,$different,true); ?><div class="<?= $diff?'dq-field--different':'' ?>"><dt><?= $label ?><?php if ($diff): ?><span>Berbeda</span><?php endif; ?></dt><dd><?php if ($field==='status'): ?><span class="dq-attendance dq-attendance--<?= appEscape($r['status']) ?>"><?= appEscape(qualityViewValue($r,$field)) ?></span><?php else: ?><?= appEscape(qualityViewValue($r,$field)) ?><?php endif; ?></dd></div><?php endforeach; ?></dl>
                                </article>
                            <?php endforeach; ?>
                            </div>
                            <?php if ($ready): ?><form method="post" class="dq-decision-form"><?php csrfField(); ?><input type="hidden" name="operation" value="<?= $op ?>"><h4>Tentukan catatan resmi</h4><p>Konfirmasikan terlebih dahulu kepada pengajar. Pilihan ini tidak menghapus catatan sumber.</p>
                                <label>Catatan resmi<select name="selected_id"><option value="">Pilih setelah memeriksa sumber</option><?php foreach ($f['records'] as $r): ?><option value="<?= (int)$r['id'] ?>">#<?= (int)$r['id'] ?> · <?= appEscape($groupNames[$r['halaqoh_id']]??'Tanpa halaqoh') ?> · <?= appEscape(qualityViewDate($r['tanggal'])) ?> · <?= appEscape(qualityViewStatus($r['status'])) ?></option><?php endforeach; ?><option value="0">Semua hanya capaian awal, bukan kehadiran</option></select></label>
                                <label>Alasan / sumber konfirmasi<textarea name="note" required maxlength="2000" rows="2" placeholder="Contoh: sudah dikonfirmasi kepada pengajar H-9"></textarea></label>
                                <div class="dq-actions"><button class="dq-button dq-button--primary" name="action" value="select"><?php qualityViewIcon('check'); ?>Simpan pilihan catatan resmi</button><?php if (!empty($f['decision']['operation_id'])): ?><button class="dq-button dq-button--secondary" name="action" value="undo_decision">Batalkan keputusan terakhir</button><?php endif; ?></div>
                                <?php if (!empty($f['decision']['operation_id'])): ?><p class="dq-decision-note"><strong>Keputusan terakhir #<?= (int)$f['decision']['id'] ?>:</strong> <?= appEscape($f['decision']['note']) ?>. Pembatalan menambahkan jejak baru.</p><?php endif; ?>
                            </form><?php endif; ?>
                        </div>
                    </details>
                <?php else: ?><p class="dq-next">Minta pengajar membuka kembali sesi untuk membandingkan isian HP, draft server, dan catatan resmi. Unduh cadangan draft HP sebelum mengganti perangkat.</p><div class="dq-notice dq-notice--compact"><?php qualityViewIcon('info'); ?><p>Draft yang hanya ada di HP tidak dapat diperiksa oleh server.</p></div><?php endif; ?>
            </div>
            <div class="dq-finding-footer"><span><?php qualityViewIcon('shield'); ?>Catatan sumber tetap tersimpan</span><a href="peserta-riwayat.php?id=<?= $f['wali_ids'][0] ?>">Riwayat peserta <?php qualityViewIcon('arrow'); ?></a></div>
        </section>
    <?php endforeach; ?>
    </div>
    <?php if ($pages>1): ?><nav class="dq-pagination" aria-label="Halaman pemeriksaan"><?php if ($page>1): ?><a class="dq-button dq-button--secondary" href="?<?= appEscape(http_build_query($filters+['page'=>$page-1])) ?>#dq-results"><?php qualityViewIcon('back'); ?>Sebelumnya</a><?php endif; ?><span>Halaman <?= $page ?> dari <?= $pages ?></span><?php if ($page<$pages): ?><a class="dq-button dq-button--secondary" href="?<?= appEscape(http_build_query($filters+['page'=>$page+1])) ?>#dq-results">Berikutnya <?php qualityViewIcon('arrow'); ?></a><?php endif; ?></nav><?php endif; ?>
    <details class="dq-guide" id="dq-guide"><summary><?php qualityViewIcon('info'); ?>Cara memeriksa dengan aman <?php qualityViewIcon('chevron'); ?></summary><ol><li><strong>Pilih masalahnya.</strong> Gunakan kartu kategori atau cari nama peserta.</li><li><strong>Bandingkan dan konfirmasi.</strong> Periksa profil, halaqoh, atau catatan bersama pengajar.</li><li><strong>Simpan keputusan yang sesuai.</strong> Catatan sumber tetap disimpan. Merge peserta dilakukan admin melalui pratinjau dan dapat dibatalkan dari riwayat merge baru.</li></ol><p>Hasil pemeriksaan bukan bukti semua isian HP sudah tersimpan. Simpan cadangan draft perangkat sebelum mengganti atau membersihkan HP.</p></details>
</div>
