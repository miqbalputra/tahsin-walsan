<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_helper.php';
require_once __DIR__ . '/includes/achievement_helper.php';
checkRole(['admin','pj_tahfidz']);
$error=''; $context=null; $values=[]; $form=null; $token='';
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        requireValidCsrfToken($_POST['csrf_token']??null);
        $token=appRequestString($_POST,'achievement_token');
        $form=$_SESSION['achievement_forms'][$token]??null;
        if (!preg_match('/^[a-f0-9]{32}$/D',$token) || !is_array($form) || ($form['actor_id']??0)!==(int)$_SESSION['user_id'] || ($form['expires']??0)<=time()) throw new AchievementException('Formulir berakhir atau berasal dari sesi lain. Buka kembali editor dari daftar capaian.');
        foreach (['jenis_materi','jilid','nama_surat','halaman','hasil_talaqqi'] as $field) $values[$field]=appRequestString($_POST,$field,'',false);
        $result=achievementApply($pdo,(int)$_SESSION['user_id'],$form['mode'],$form['wali_id'],$form['record_id'],$values,$form['fingerprint'],$token);
        $_SESSION['achievement_flash']=['message'=>$result['mode']==='initial'?'Capaian awal tersimpan tanpa menambah presensi.':'Capaian catatan Hadir tersimpan.','operation_id'=>$result['operation_id'],'requires_review'=>$result['requires_review']];
        redirectTo('capaian.php?'.$form['back']);
    }
    $waliId=participantId($_GET['wali_id']??'');
    $recordRaw=appRequestString($_GET,'presensi_id'); $initial=appRequestString($_GET,'initial');
    if (!in_array($initial,['','1'],true) || ($initial==='1' && $recordRaw!=='')) throw new AchievementException('Pilih satu sumber capaian yang akan diubah.');
    $recordId=$recordRaw===''?null:filter_var($recordRaw,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if ($recordId===false) throw new AchievementException('ID catatan Hadir tidak valid.');
    $mode=$recordId===null?'initial':'attendance';
    $context=achievementReadContext($pdo,$mode,$waliId,$recordId);
    $backRaw=appRequestString($_GET,'back');
    if (strlen($backRaw)>2048) throw new AchievementException('Tautan kembali tidak valid. Buka editor dari daftar capaian.');
    parse_str($backRaw,$backQuery); $back=http_build_query(achievementFilters($backQuery));
    $token=bin2hex(random_bytes(16));
    $form=['actor_id'=>(int)$_SESSION['user_id'],'expires'=>time()+1800,'mode'=>$mode,'wali_id'=>$waliId,'record_id'=>$recordId,'fingerprint'=>$context['fingerprint'],'back'=>$back];
    $forms=is_array($_SESSION['achievement_forms']??null)?$_SESSION['achievement_forms']:[];
    $forms=array_filter($forms,static fn($state)=>is_array($state)&&($state['expires']??0)>time()); $forms[$token]=$form;
    $_SESSION['achievement_forms']=array_slice($forms,-8,null,true);
} catch (AchievementException|AttendanceHistoryException|ParticipantManagementException|InvalidArgumentException $failure) {
    if (!$form) appRequestError(422,'Editor capaian belum dapat digunakan',$failure->getMessage());
    http_response_code(422); $error=$failure->getMessage();
} catch (Throwable $failure) {
    reportApplicationError($failure,'achievement-editor');
    if (!$form) appRequestError(503,'Editor capaian belum dapat dibuka','Coba kembali sebentar lagi atau hubungi pengelola.');
    http_response_code(503); $error='Penyimpanan belum dapat dipastikan. Periksa riwayat perubahan sebelum mengirim kembali atau hubungi pengelola.';
}
if (!$context) {
    try { $context=achievementContext($pdo,$form['mode'],$form['wali_id'],$form['record_id']); }
    catch (Throwable $failure) { reportApplicationError($failure,'achievement-editor-reload'); appRequestError(503,'Data sumber belum dapat dibaca','Periksa riwayat perubahan atau buka editor terbaru sebelum melanjutkan.'); }
}
$previous=$context['mode']==='attendance'?$context['selected']:$context['baseline'];
foreach (['jenis_materi','jilid','nama_surat','halaman','hasil_talaqqi'] as $field) if (!array_key_exists($field,$values)) $values[$field]=(string)($previous[$field]??'');
$ready=participantSchemaReady($pdo)&&($context['mode']==='initial'||attendanceHistoryReady($pdo));
$freshPath='capaian-form.php?'.http_build_query(['wali_id'=>$form['wali_id'],'presensi_id'=>$form['record_id'],'initial'=>$form['mode']==='initial'?'1':'','back'=>$form['back']]);
$pageTitle=$context['mode']==='initial'?'Capaian Awal':'Ubah Capaian Catatan Hadir';
require __DIR__ . '/includes/header.php'; require __DIR__ . '/includes/sidebar.php';
?>
<div class="max-w-2xl mx-auto">
    <a class="inline-block underline text-blue-700 py-3" href="<?= appEscape(appUrl('capaian.php?'.$form['back'])) ?>">Kembali ke daftar capaian</a>
    <h1 class="text-2xl font-bold mb-3"><?= appEscape($pageTitle) ?></h1>
    <p class="font-semibold mb-3"><?= appEscape($context['participant']['parent']['nama_bapak']) ?></p>
    <?php if ($context['mode']==='attendance'): ?><p class="text-slate-600 mb-5">Tanggal pelaksanaan <?= appEscape($context['selected']['tanggal']) ?> · ID catatan <?= (int)$context['selected']['id'] ?>. Identitas, tanggal, dan status Hadir tetap dipertahankan.</p><?php else: ?><p class="text-slate-600 mb-5">Capaian awal disimpan terpisah dari presensi. Pengubahan ini tidak menambah Hadir atau hasil talaqqi.</p><?php endif; ?>
    <?php if ($error): ?><div role="alert" class="bg-red-50 text-red-700 rounded-xl p-4 mb-5"><p><?= appEscape($error) ?></p><p class="mt-2">Isian Anda tetap tersedia di bawah. Nilai saat ini: <?= appEscape($previous?tahsinMaterial($previous):'Belum ada capaian') ?>.</p><a class="inline-block underline py-2" href="<?= appEscape(appUrl($freshPath)) ?>">Buka editor versi terbaru untuk membandingkan</a></div><?php endif; ?>
    <?php if (!$ready): ?><p role="alert" class="bg-amber-50 text-amber-700 rounded-xl p-4 mb-5">Penyimpanan belum siap. Hubungi pengelola untuk menyiapkan riwayat perubahan sebelum mengubah data lama.</p><?php endif; ?>
    <form method="post" class="bg-white border border-slate-200 rounded-2xl p-6 space-y-5">
        <?php csrfField(); ?><input type="hidden" name="achievement_token" value="<?= appEscape($token) ?>">
        <div><label for="achievement-material" class="block font-bold text-sm mb-2">Jenis materi</label><select id="achievement-material" name="jenis_materi" required class="w-full rounded-xl border border-slate-200 p-3"><option value="">Pilih jenis materi</option><?php foreach (['Iqro','Al Quran'] as $type): ?><option <?= $values['jenis_materi']===$type?'selected':'' ?> value="<?= appEscape($type) ?>"><?= appEscape($type) ?></option><?php endforeach; ?></select></div>
        <div><label for="achievement-volume" class="block font-bold text-sm mb-2">Jilid untuk Iqro</label><select id="achievement-volume" name="jilid" class="w-full rounded-xl border border-slate-200 p-3"><option value="">Pilih jika materi Iqro</option><?php for ($level=1;$level<=6;$level++): ?><option value="<?= $level ?>" <?= $values['jilid']===(string)$level?'selected':'' ?>>Jilid <?= $level ?></option><?php endfor; ?></select></div>
        <div><label for="achievement-surah" class="block font-bold text-sm mb-2">Surat untuk Al Quran</label><input id="achievement-surah" name="nama_surat" maxlength="100" value="<?= appEscape($values['nama_surat']) ?>" class="w-full rounded-xl border border-slate-200 p-3"><p class="text-xs text-slate-500 mt-2">Rentang surat dapat ditulis dengan s/d sesuai catatan.</p></div>
        <div><label for="achievement-pages" class="block font-bold text-sm mb-2">Halaman / ayat</label><input id="achievement-pages" name="halaman" maxlength="<?= $context['mode']==='initial'?30:20 ?>" required value="<?= appEscape($values['halaman']) ?>" class="w-full rounded-xl border border-slate-200 p-3"><p class="text-xs text-slate-500 mt-2">Isi nomor atau rentang, misalnya 1-10. Teks lama yang tidak diubah tetap dipertahankan.</p></div>
        <?php if ($context['mode']==='attendance'): ?><div><label for="achievement-result" class="block font-bold text-sm mb-2">Hasil talaqqi</label><select id="achievement-result" name="hasil_talaqqi" required class="w-full rounded-xl border border-slate-200 p-3"><option value="">Pilih hasil</option><?php foreach (['Lulus','Ulang'] as $result): ?><option value="<?= $result ?>" <?= $values['hasil_talaqqi']===$result?'selected':'' ?>><?= $result ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <p class="text-sm text-slate-500">Versi lama disimpan dalam riwayat. Formulir berlaku 30 menit dan akan ditolak jika sumber data berubah.</p>
        <button type="submit" <?= !$ready?'disabled':'' ?> class="bg-blue-600 text-white rounded-xl px-5 py-3 font-bold">Simpan capaian</button>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
