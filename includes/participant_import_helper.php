<?php
require_once __DIR__ . '/security_helper.php';
require_once __DIR__ . '/csv_helper.php';
require_once __DIR__ . '/alumni_archive_helper.php';
require_once __DIR__ . '/data_induk_helper.php';
require_once __DIR__ . '/database_helper.php';
require_once __DIR__ . '/participant_identity_helper.php';

const PARTICIPANT_IMPORT_COLUMNS = ['nama_bapak','no_hp','alamat','kategori','nama_anak','kelas','tempat_tahsin','ustadz_luar'];
const PARTICIPANT_IMPORT_MAX_BYTES = 5 * 1024 * 1024;
const PARTICIPANT_IMPORT_MAX_JOURNAL_BYTES = 12 * 1024 * 1024;

function participantImportApplyMigration(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new RuntimeException('Jalankan migrasi jurnal impor di luar transaksi data.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS participant_import_batches (
        id CHAR(32) PRIMARY KEY, user_id INT NULL, actor_name VARCHAR(100) NOT NULL,
        source_name VARCHAR(255) NOT NULL, source_sha256 CHAR(64) NOT NULL, request_hash CHAR(64) NOT NULL,
        source_rows_json LONGTEXT NOT NULL, changes_json LONGTEXT NOT NULL, summary_json TEXT NOT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!participantImportSchemaReady($pdo)) throw new RuntimeException('Struktur jurnal impor belum lengkap. Periksa migrasi sebelum menggunakan fitur.');
    appRequireTransactionalTables($pdo,['participant_import_batches']);
}

function participantImportSchemaReady(PDO $pdo): bool
{
    try { $pdo->query('SELECT id,user_id,actor_name,source_name,source_sha256,request_hash,source_rows_json,changes_json,summary_json,created_at FROM participant_import_batches LIMIT 0'); return true; }
    catch (PDOException $error) { if (in_array($error->getCode(),['42S02','42S22'],true)) return false; throw $error; }
}

function participantImportName(string $value): string
{
    $value = preg_replace('/\s+/u',' ',trim($value));
    if ($value === null) throw new InvalidArgumentException('Gunakan CSV UTF-8 agar nama terbaca dengan benar.');
    if (class_exists(Normalizer::class)) $value = Normalizer::normalize($value,Normalizer::FORM_C);
    if (function_exists('mb_strtolower')) return mb_strtolower($value,'UTF-8');
    if (class_exists(IntlChar::class)) return preg_replace_callback('/./us',static fn($part)=>IntlChar::chr(IntlChar::foldCase(IntlChar::ord($part[0]))),$value);
    return strtolower($value);
}

function participantImportRow(array $input, int $line): array
{
    $limits = ['nama_bapak'=>100,'no_hp'=>20,'alamat'=>5000,'kategori'=>20,'nama_anak'=>100,'kelas'=>20,'tempat_tahsin'=>255,'ustadz_luar'=>100];
    $row = [];
    foreach ($limits as $column=>$limit) {
        $value = appRequestString($input,$column);
        if (!preg_match('//u',$value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$value) || appTextLength($value)>$limit) {
            throw new InvalidArgumentException('Baris ' . $line . ': isian ' . $column . ' tidak valid atau melebihi ' . $limit . ' karakter. Gunakan CSV UTF-8.');
        }
        $row[$column] = $value;
    }
    if ($row['nama_bapak']==='') throw new InvalidArgumentException('Baris ' . $line . ': nama wali wajib diisi.');
    $row['kategori'] = $row['kategori']==='' ? 'reguler' : strtolower($row['kategori']);
    if (!in_array($row['kategori'],['reguler','askar','tahsin_luar'],true)) throw new InvalidArgumentException('Baris ' . $line . ': kategori harus reguler, askar, atau tahsin_luar.');
    if ($row['nama_anak']==='' && $row['kelas']!=='') throw new InvalidArgumentException('Baris ' . $line . ': isi nama anak sebelum mengisi kelas.');
    $provided=$input['provided_columns']??array_keys($input);
    if (!is_array($provided) || array_filter($provided,static fn($column)=>!is_string($column)) || array_diff($provided,PARTICIPANT_IMPORT_COLUMNS) || array_diff(array_slice(PARTICIPANT_IMPORT_COLUMNS,0,6),$provided)) throw new InvalidArgumentException('Kolom data impor tidak lengkap. Gunakan template peserta.');
    $row['provided_columns']=array_values(array_intersect(PARTICIPANT_IMPORT_COLUMNS,$provided));
    $row['line'] = $line;
    return $row;
}

function participantImportRead($stream): array
{
    $size = fstat($stream)['size'] ?? 0;
    if ($size < 1 || $size > PARTICIPANT_IMPORT_MAX_BYTES) throw new InvalidArgumentException('Pilih CSV yang tidak kosong, maksimal 5 MB.');
    rewind($stream);
    $start = fread($stream,3)==="\xef\xbb\xbf" ? 3 : 0;
    fseek($stream,$start);
    $first = fgets($stream,4096);
    if ($first===false || !preg_match('//u',$first)) throw new InvalidArgumentException('Header tidak terbaca. Simpan berkas sebagai CSV UTF-8.');
    $delimiter = str_contains($first,';') ? ';' : ',';
    if (trim($first)==='sep=;' || trim($first)==='sep=,') { $delimiter=substr(trim($first),-1); $start=ftell($stream); }
    fseek($stream,$start);
    $header = null;
    $rows = [];
    foreach (appCsvRows($stream,$delimiter,'',65536) as $record) {
        if ($header===null) {
            $header = array_map(static fn($value)=>strtolower(trim((string)$value)),$record);
            if (count(array_unique($header))!==count($header) || array_diff($header,PARTICIPANT_IMPORT_COLUMNS)
                || array_diff(array_slice(PARTICIPANT_IMPORT_COLUMNS,0,6),$header)) throw new InvalidArgumentException('Header CSV tidak cocok. Gunakan template peserta; enam kolom pertama wajib tersedia.');
            continue;
        }
        if (count($record)!==count($header)) throw new InvalidArgumentException('Baris ' . (count($rows)+2) . ': jumlah kolom tidak sesuai header. Periksa tanda kutip dan pemisah CSV.');
        if (count($rows)>=5000) throw new InvalidArgumentException('Maksimal 5.000 baris data per berkas. Pisahkan berkas menjadi beberapa bagian.');
        $input = array_combine($header,array_map(static fn($value)=>(string)$value,$record));
        $rows[] = participantImportRow($input,count($rows)+2);
    }
    if (!$rows) throw new InvalidArgumentException('CSV belum berisi data peserta.');
    return $rows;
}

/** Read central mappings even when a partially applied family view is missing. */
function participantImportManagedIds(PDO $pdo): array
{
    return participantManagedIds($pdo);
}

function participantImportPlan(PDO $pdo, array $rows, string $sha256, string $sourceName, bool $lock=false): array
{
    if (dataIndukConfig()['enabled']) throw new InvalidArgumentException('Biodata dikelola Data Induk. Gunakan sinkronisasi Data Induk untuk peserta dan anak.');
    if (!participantImportSchemaReady($pdo)) throw new RuntimeException('Pengelola perlu menjalankan migrasi jurnal impor sebelum fitur ini digunakan.');
    if (!$rows || count($rows)>5000 || !preg_match('/^[a-f0-9]{64}$/D',$sha256) || appTextLength($sourceName)>255) throw new InvalidArgumentException('Berkas atau data pratinjau tidak valid. Pilih ulang CSV.');
    $groups = [];
    foreach ($rows as $index=>$input) {
        $row = participantImportRow($input,$index+2);
        $key = hash('sha256',participantImportName($row['nama_bapak']) . "\0" . familyPhone($row['no_hp']));
        $profile = array_intersect_key($row,array_flip(['alamat','kategori','tempat_tahsin','ustadz_luar']));
        if (!isset($groups[$key])) $groups[$key]=['key'=>$key,'profile'=>$row,'rows'=>[],'candidates'=>[],'default_target'=>'new'];
        elseif (array_intersect_key($groups[$key]['profile'],$profile)!==$profile) throw new InvalidArgumentException('Baris ' . ($index+2) . ': biodata wali yang sama berbeda antarbaris. Samakan alamat, kategori, dan informasi tahsin sebelum mengunggah ulang.');
        $groups[$key]['rows'][] = $row;
    }
    $inputLimit = (int)ini_get('max_input_vars');
    $groupLimit = $inputLimit>0 ? min(500,max(1,$inputLimit-10)) : 500;
    if (count($groups)>$groupLimit) throw new InvalidArgumentException('Maksimal ' . $groupLimit . ' keluarga per pratinjau. Pisahkan CSV agar seluruh pilihan dapat diperiksa.');
    $index = [];
    foreach ($pdo->query('SELECT * FROM wali_santri ORDER BY id' . ($lock?' FOR UPDATE':''))->fetchAll(PDO::FETCH_ASSOC) as $parent) $index[participantImportName($parent['nama_bapak'])][]=$parent;
    $children = [];
    foreach ($pdo->query('SELECT * FROM santri_detail ORDER BY id' . ($lock?' FOR UPDATE':''))->fetchAll(PDO::FETCH_ASSOC) as $child) $children[(int)$child['wali_santri_id']][]=$child;
    $managed = participantImportManagedIds($pdo);
    foreach ($groups as &$group) {
        $matches = [];
        foreach ($index[participantImportName($group['profile']['nama_bapak'])] ?? [] as $parent) {
            $id=(int)$parent['id'];
            $group['candidates'][$id]=['parent'=>$parent,'children'=>$children[$id]??[],'managed'=>isset($managed[$id])];
            if (familyPhone($group['profile']['no_hp'])!=='' && familyPhone($parent['no_hp']??'')===familyPhone($group['profile']['no_hp'])) $matches[]=$id;
        }
        if ($group['candidates']) $group['default_target']=count($matches)===1?($group['candidates'][$matches[0]]['managed']?'skip':(string)$matches[0]):'';
    }
    unset($group);
    $plan=['version'=>1,'rows'=>$rows,'groups'=>$groups,'source_sha256'=>$sha256,'source_name'=>$sourceName];
    $plan['fingerprint']=hash('sha256',json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    return $plan;
}

function participantImportApply(PDO $pdo, int $actorId, array $plan, array $choices, string $batchId): array
{
    if ($pdo->inTransaction()) throw new RuntimeException('Selesaikan transaksi lain sebelum mengimpor peserta.');
    if (($plan['version']??0)!==1 || !preg_match('/^[a-f0-9]{32}$/D',$batchId)) throw new InvalidArgumentException('Pratinjau kedaluwarsa. Unggah ulang CSV.');
    $targets=$choices['targets']??null;
    $classPolicy=appRequestString($choices,'class_policy','preserve');
    $profilePolicy=appRequestString($choices,'profile_policy','preserve');
    if (!is_array($targets) || array_diff(array_keys($plan['groups']),array_keys($targets))
        || !in_array($classPolicy,['preserve','update','new'],true) || !in_array($profilePolicy,['preserve','update'],true)) throw new InvalidArgumentException('Pilihan impor belum lengkap. Periksa semua peserta pada pratinjau.');
    foreach ($targets as $value) if (!is_string($value) || !preg_match('/^(?:new|skip|[1-9][0-9]{0,18})$/D',$value)) throw new InvalidArgumentException('Pilihan peserta tidak valid.');
    ksort($targets);
    $requestHash=hash('sha256',json_encode([$plan['source_sha256'],$plan['rows'],$targets,$classPolicy,$profilePolicy],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $lockName=participantWriteLockName($pdo);
    $lock=$pdo->prepare('SELECT GET_LOCK(?,5)'); $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn()!==1) throw new RuntimeException('Impor lain sedang diproses. Tunggu sebentar lalu coba lagi.');
    $originalSqlMode=null;
    try {
        $originalSqlMode=appUseStrictSqlMode($pdo);
        $pdo->beginTransaction();
        appRequireTransactionalTables($pdo);
        $actor=$pdo->prepare('SELECT id,nama_lengkap,role FROM users WHERE id=? FOR UPDATE'); $actor->execute([$actorId]); $actor=$actor->fetch(PDO::FETCH_ASSOC);
        if (!$actor || !in_array($actor['role'],['admin','pj_tahfidz'],true)) throw new InvalidArgumentException('Akun Anda tidak dapat mengimpor peserta.');
        $receipt=$pdo->prepare('SELECT * FROM participant_import_batches WHERE id=? FOR UPDATE'); $receipt->execute([$batchId]); $receipt=$receipt->fetch(PDO::FETCH_ASSOC);
        if ($receipt) {
            if ((int)$receipt['user_id']!==$actorId || !hash_equals($receipt['request_hash'],$requestHash)) throw new InvalidArgumentException('ID impor sudah dipakai untuk pilihan lain. Buat pratinjau baru.');
            $pdo->commit(); return json_decode($receipt['summary_json'],true,32,JSON_THROW_ON_ERROR);
        }
        $fresh=participantImportPlan($pdo,$plan['rows'],$plan['source_sha256'],$plan['source_name'],true);
        if (!hash_equals($plan['fingerprint'],$fresh['fingerprint'])) throw new InvalidArgumentException('Data peserta berubah sejak pratinjau. Periksa ulang pilihan sebelum menyimpan.');
        $summary=['batch_id'=>$batchId,'created_wali'=>0,'reused_wali'=>0,'skipped_wali'=>0,'created_children'=>0,'skipped_children'=>0,'updated_classes'=>0,'updated_profiles'=>0,'archived_wali'=>0];
        $changes=[]; $affected=[];
        $executionGroups=[];
        foreach ($fresh['groups'] as $key=>$group) {
            $target=$targets[$key];
            $bucket=in_array($target,['new','skip'],true)?$target.':'.$key:'existing:'.$target;
            if (!isset($executionGroups[$bucket])) { $group['target']=$target; $executionGroups[$bucket]=$group; continue; }
            if ($profilePolicy==='update') {
                foreach (['nama_bapak','no_hp','alamat','kategori','tempat_tahsin','ustadz_luar'] as $column) {
                    if (in_array($column,$group['profile']['provided_columns'],true) && $executionGroups[$bucket]['profile'][$column]!==$group['profile'][$column]) throw new InvalidArgumentException('Satu peserta dipilih untuk beberapa biodata CSV yang berbeda. Samakan biodata pada CSV atau pertahankan biodata lama sebelum melanjutkan.');
                }
            }
            $executionGroups[$bucket]['rows']=array_merge($executionGroups[$bucket]['rows'],$group['rows']);
        }
        foreach ($executionGroups as $group) {
            $target=$group['target']; $profile=$group['profile'];
            if ($target==='skip') {
                $summary['skipped_wali']++;
                $changes[]=['wali_id'=>null,'nama_wali'=>$profile['nama_bapak'],'skipped'=>true,'unchanged'=>true,'before'=>null];
                continue;
            }
            $before=null; $childChanged=false; $profileChanged=false; $reactivateForChild=false;
            if ($target==='new') {
                foreach ($group['candidates'] as $candidate) {
                    if ($candidate['managed'] && familyPhone($candidate['parent']['no_hp']??'')===familyPhone($profile['no_hp'])) throw new InvalidArgumentException('Biodata ini sudah dikelola master keluarga/Data Induk. Gunakan sumber identitas agar tidak membuat duplikat lokal.');
                }
                $insert=$pdo->prepare('INSERT INTO wali_santri(nama_bapak,no_hp,alamat,kategori,tempat_tahsin,ustadz_luar) VALUES(?,?,?,?,?,?)');
                $insert->execute(array_map(static fn($column)=>$profile[$column],['nama_bapak','no_hp','alamat','kategori','tempat_tahsin','ustadz_luar']));
                $waliId=(int)$pdo->lastInsertId(); $summary['created_wali']++;
            } else {
                $waliId=filter_var($target,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                $candidate=$group['candidates'][$waliId]??null;
                if (!$candidate) throw new InvalidArgumentException('Pilih peserta yang tersedia pada pratinjau.');
                if ($candidate['managed']) throw new InvalidArgumentException('Identitas atau anak peserta ini dikelola master keluarga/Data Induk. Gunakan menu sumber identitas untuk pembaruan.');
                $before=$candidate; $summary['reused_wali']++;
                $profileFields=array_values(array_intersect(['nama_bapak','no_hp','alamat','kategori','tempat_tahsin','ustadz_luar'],$profile['provided_columns']));
                foreach ($profileFields as $column) {
                    if (($candidate['parent'][$column]??null)!==$profile[$column]) $profileChanged=true;
                }
                $profileChanged = $profileChanged && $profilePolicy==='update';
                if ($profileChanged) {
                    $assignments=implode(',',array_map(static fn($column)=>"`$column`=?",$profileFields));
                    $pdo->prepare("UPDATE wali_santri SET $assignments WHERE id=?")->execute(array_merge(array_map(static fn($column)=>$profile[$column],$profileFields),[$waliId]));
                    $summary['updated_profiles']++;
                }
            }
            $initialChildIds=array_fill_keys(array_column($before['children']??[],'id'),true);
            $incomingClasses=[];
            foreach ($group['rows'] as $row) {
                if ($row['nama_anak']!=='') $incomingClasses[participantImportName($row['nama_anak'])][participantImportName($row['kelas'])]=true;
            }
            foreach ($group['rows'] as $row) {
                if ($row['nama_anak']==='') continue;
                $query=$pdo->prepare('SELECT * FROM santri_detail WHERE wali_santri_id=? ORDER BY id FOR UPDATE'); $query->execute([$waliId]);
                $matches=array_values(array_filter($query->fetchAll(PDO::FETCH_ASSOC),static fn($child)=>participantImportName($child['nama_anak'])===participantImportName($row['nama_anak'])));
                $sameClass=array_filter($matches,static fn($child)=>participantImportName($child['kelas']??'')===participantImportName($row['kelas']));
                // A different class in a second CSV row may be a different
                // child. Preserve/update policies concern data that existed
                // before the batch, not children just inserted by this CSV.
                $matches=array_values(array_filter($matches,static fn($child)=>isset($initialChildIds[$child['id']])));
                if ($sameClass || ($matches && $classPolicy==='preserve')) { $summary['skipped_children']++; continue; }
                if ($matches && $classPolicy==='update') {
                    if (count($matches)!==1 || count($incomingClasses[participantImportName($row['nama_anak'])])!==1) throw new InvalidArgumentException('Ada beberapa kelas atau identitas untuk anak bernama ' . $row['nama_anak'] . '. Tinjau identitas anak, atau pilih tambah sebagai anak berbeda jika memang orang yang berbeda.');
                    $pdo->prepare('UPDATE santri_detail SET kelas=? WHERE id=?')->execute([$row['kelas'],$matches[0]['id']]);
                    $summary['updated_classes']++;
                    $childChanged=true;
                    if (($matches[0]['kelas']===null || trim($matches[0]['kelas'])==='' || isAlumniClass($matches[0]['kelas'])) && $row['kelas']!=='' && !isAlumniClass($row['kelas'])) $reactivateForChild=true;
                } else {
                    $pdo->prepare('INSERT INTO santri_detail(wali_santri_id,nama_anak,kelas) VALUES(?,?,?)')->execute([$waliId,$row['nama_anak'],$row['kelas']]);
                    $summary['created_children']++;
                    $childChanged=true;
                    if ($row['kelas']!=='' && !isAlumniClass($row['kelas'])) $reactivateForChild=true;
                }
            }
            if ($reactivateForChild) reactivateWaliIfHasActiveChild($pdo,$waliId);
            if ($childChanged || $profileChanged) $affected[]=$waliId;
            $unchanged=$before!==null && !$childChanged && !$profileChanged;
            $changes[]=['wali_id'=>$waliId,'nama_wali'=>$before['parent']['nama_bapak']??$profile['nama_bapak'],'unchanged'=>$unchanged,'before'=>$unchanged?null:$before];
        }
        $archive=archiveEligibleAlumni($pdo,$affected,'AUTO_IMPORT_PESERTA'); $summary['archived_wali']=$archive['archived_wali'];
        foreach ($changes as &$change) {
            if ($change['unchanged']) continue;
            $query=$pdo->prepare('SELECT * FROM wali_santri WHERE id=?'); $query->execute([$change['wali_id']]); $change['after_parent']=$query->fetch(PDO::FETCH_ASSOC);
            $query=$pdo->prepare('SELECT * FROM santri_detail WHERE wali_santri_id=? ORDER BY id'); $query->execute([$change['wali_id']]); $change['after_children']=$query->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($change);
        $journal=$pdo->prepare('INSERT INTO participant_import_batches(id,user_id,actor_name,source_name,source_sha256,request_hash,source_rows_json,changes_json,summary_json) VALUES(?,?,?,?,?,?,?,?,?)');
        $jsonFlags=JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR;
        $sourceJson=json_encode($plan['rows'],$jsonFlags); $changesJson=json_encode($changes,$jsonFlags);
        if (strlen($sourceJson)+strlen($changesJson)>PARTICIPANT_IMPORT_MAX_JOURNAL_BYTES) throw new InvalidArgumentException('Rincian perubahan terlalu besar untuk satu impor. Pisahkan CSV menjadi lebih sedikit keluarga; seluruh perubahan batch ini dibatalkan.');
        $journal->execute([$batchId,$actorId,$actor['nama_lengkap'],$plan['source_name'],$plan['source_sha256'],$requestHash,$sourceJson,$changesJson,json_encode($summary,$jsonFlags)]);
        $pdo->commit(); return $summary;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally {
        if ($originalSqlMode!==null) {
            try { $pdo->prepare('SET SESSION sql_mode=?')->execute([$originalSqlMode]); }
            catch (Throwable $modeError) { if (function_exists('reportApplicationError')) reportApplicationError($modeError,'participant-import-sql-mode'); }
        }
        try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
        catch (Throwable $releaseError) { if (function_exists('reportApplicationError')) reportApplicationError($releaseError,'participant-import-lock'); }
    }
}
