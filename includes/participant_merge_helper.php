<?php
require_once __DIR__ . '/participant_helper.php';
require_once __DIR__ . '/tahsin_report_helper.php';

const PARTICIPANT_MERGE_FIELDS = ['nama_bapak','no_hp','alamat','kategori','tempat_tahsin','ustadz_luar','status_aktif','lanjut_tahsin'];

function participantMergeApplyMigration(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new RuntimeException('Jalankan migrasi penggabungan di luar transaksi data.');
    participantApplyMigration($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS participant_merge_aliases (
        source_id INT PRIMARY KEY, target_id INT NOT NULL, operation_id CHAR(32) NOT NULL,
        merged_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        KEY idx_participant_merge_target(target_id),
        FOREIGN KEY(target_id) REFERENCES wali_santri(id) ON DELETE RESTRICT,
        FOREIGN KEY(operation_id) REFERENCES participant_changes(operation_id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!participantMergeSchemaReady($pdo)) throw new RuntimeException('Struktur penggabungan peserta belum lengkap.');
    appRequireTransactionalTables($pdo,['participant_merge_aliases']);
}

function participantMergeSchemaReady(PDO $pdo): bool
{
    if (!participantSchemaReady($pdo)) return false;
    try { $pdo->query('SELECT source_id,target_id,operation_id,merged_at FROM participant_merge_aliases LIMIT 0'); return true; }
    catch (PDOException $error) { if (in_array($error->getCode(),['42S02','42S22'],true)) return false; throw $error; }
}

function participantMergeTarget(PDO $pdo, int $id): ?int
{
    if (!participantMergeSchemaReady($pdo)) return null;
    $statement=$pdo->prepare('SELECT target_id FROM participant_merge_aliases WHERE source_id=?'); $statement->execute([$id]);
    $value=$statement->fetchColumn();
    return $value===false?null:(int)$value;
}

function participantMergeSpecs(): array
{
    return [
        'presensi'=>['wali_santri_id','id'],
        'tahsin_roster_history'=>['wali_santri_id','id'],
        'tahsin_verifications'=>['wali_santri_id','id'],
        'tahsin_capaian_awal'=>['wali_santri_id','wali_santri_id'],
        'tahsin_autosave_drafts'=>['wali_id','user_id,halaqoh_id,session_date,wali_id'],
        'tahsin_autosave_revisions'=>['wali_id','session_date,wali_id'],
        'participant_changes'=>['wali_id','operation_id'],
        'participant_merge_aliases'=>['target_id','source_id'],
        'data_induk_sync_conflicts'=>['local_id','id'],
    ];
}

function participantMergeRelated(PDO $pdo, int $keepId, int $sourceId, bool $lock=false, ?string $omitOperation=null): array
{
    $tables=$pdo->query("SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    $related=[];
    foreach (participantMergeSpecs() as $table=>[$column,$order]) {
        if (!in_array($table,$tables,true)) continue;
        $projection=$table==='participant_changes'
            ? 'operation_id,wali_id,request_hash,SHA2(before_json,256) AS before_sha256,SHA2(after_json,256) AS after_sha256'
            : '*';
        $where="`$column` IN (?,?)"; $parameters=[$keepId,$sourceId];
        if ($table==='data_induk_sync_conflicts') $where.=" AND entity_type='guardian'";
        if ($table==='participant_changes' && $omitOperation!==null) { $where.=' AND operation_id<>?'; $parameters[]=$omitOperation; }
        $statement=$pdo->prepare("SELECT $projection FROM `$table` WHERE $where ORDER BY $order" . ($lock?' FOR UPDATE':''));
        $statement->execute($parameters); $related[$table]=$statement->fetchAll(PDO::FETCH_ASSOC);
    }
    return $related;
}

/** Unknown references fail before any write, including undeclared wali_id conventions. */
function participantMergeCheckReferences(PDO $pdo, array $source, bool $lock): void
{
    $id=(int)$source['id'];
    $allowed=['santri_detail'=>'wali_santri_id','halaqoh_members'=>'wali_santri_id'];
    foreach (participantMergeSpecs() as $table=>$spec) $allowed[$table]=$spec[0];
    $suffix=$lock?' FOR UPDATE':'';
    $quote=static fn($name)=>'`'.str_replace('`','``',$name).'`';
    $constraints=[];
    foreach ($pdo->query("SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='wali_santri' ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC) as $key) $constraints[$key['TABLE_NAME'].'/'.$key['CONSTRAINT_NAME']][]=$key;
    foreach ($constraints as $keys) {
        $first=$keys[0];
        if (count($keys)===1 && $first['REFERENCED_COLUMN_NAME']==='id' && ($allowed[$first['TABLE_NAME']]??null)===$first['COLUMN_NAME']) continue;
        $where=[]; $values=[];
        foreach ($keys as $key) { $where[]=$quote($key['COLUMN_NAME']).'=?'; $values[]=$source[$key['REFERENCED_COLUMN_NAME']]; }
        $statement=$pdo->prepare('SELECT 1 FROM '.$quote($first['TABLE_NAME']).' WHERE '.implode(' AND ',$where).' LIMIT 1'.$suffix); $statement->execute($values);
        if ($statement->fetchColumn()) throw new ParticipantManagementException('Peserta masih terhubung ke tabel '.$first['TABLE_NAME'].' yang belum mendukung penggabungan. Hubungi pengelola; data belum diubah.');
    }
    foreach ($pdo->query("SELECT c.TABLE_NAME,c.COLUMN_NAME FROM information_schema.columns c JOIN information_schema.tables t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE' AND c.COLUMN_NAME IN ('wali_santri_id','wali_id')")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        if (($allowed[$column['TABLE_NAME']]??null)===$column['COLUMN_NAME']) continue;
        $statement=$pdo->prepare('SELECT 1 FROM '.$quote($column['TABLE_NAME']).' WHERE '.$quote($column['COLUMN_NAME']).'=? LIMIT 1'.$suffix); $statement->execute([$id]);
        if ($statement->fetchColumn()) throw new ParticipantManagementException('Ada relasi peserta pada '.$column['TABLE_NAME'].' yang perlu diperiksa pengelola. Penggabungan belum dilakukan.');
    }
    // A collision may remove a source draft/baseline/revision row. Future
    // foreign keys to those rows need their own transfer rules, never CASCADE.
    foreach (['tahsin_autosave_drafts'=>'wali_id','tahsin_capaian_awal'=>'wali_santri_id','tahsin_autosave_revisions'=>'wali_id'] as $table=>$idColumn) {
        $statement=$pdo->prepare("SELECT k.TABLE_NAME,k.COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE k WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME=? AND k.REFERENCED_COLUMN_NAME=?"); $statement->execute([$table,$idColumn]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $reference) {
            $probe=$pdo->prepare('SELECT 1 FROM '.$quote($reference['TABLE_NAME']).' WHERE '.$quote($reference['COLUMN_NAME']).'=? LIMIT 1'.$suffix); $probe->execute([$id]);
            if ($probe->fetchColumn()) throw new ParticipantManagementException('Draft atau capaian awal memiliki relasi tambahan pada '.$reference['TABLE_NAME'].'. Hubungi pengelola sebelum menggabungkan.');
        }
    }
    $childKeys=[];
    foreach ($pdo->query("SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='santri_detail' ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC) as $key) $childKeys[$key['TABLE_NAME'].'/'.$key['CONSTRAINT_NAME']][]=$key;
    foreach ($childKeys as $keys) {
        if (!in_array('wali_santri_id',array_column($keys,'REFERENCED_COLUMN_NAME'),true)) continue;
        $join=[];
        foreach ($keys as $key) $join[]='r.'.$quote($key['COLUMN_NAME']).'=s.'.$quote($key['REFERENCED_COLUMN_NAME']);
        $statement=$pdo->prepare('SELECT 1 FROM '.$quote($keys[0]['TABLE_NAME']).' r JOIN santri_detail s ON '.implode(' AND ',$join).' WHERE s.wali_santri_id=? LIMIT 1'.$suffix); $statement->execute([$id]);
        if ($statement->fetchColumn()) throw new ParticipantManagementException('Anak memiliki relasi gabungan pada '.$keys[0]['TABLE_NAME'].'. Pengelola perlu memeriksa pemindahannya; data belum diubah.');
    }
}

function participantMergePlan(PDO $pdo, int $keepId, int $sourceId, bool $lock=false): array
{
    if ($keepId<1 || $sourceId<1 || $keepId===$sourceId) throw new ParticipantManagementException('Pilih dua ID peserta yang berbeda.');
    if (!participantMergeSchemaReady($pdo)) throw new ParticipantManagementException('Pengelola perlu menyiapkan jurnal dan pengalihan ID penggabungan.');
    if (dataIndukConfig()['enabled']) throw new ParticipantManagementException('Biodata dikelola Data Induk. Penggabungan identitas dilakukan dari sumber tersebut.');
    $snapshots=[]; $ids=[$keepId,$sourceId]; sort($ids);
    foreach ($ids as $id) $snapshots[$id]=participantSnapshot($pdo,$id,$lock);
    $keep=$snapshots[$keepId]; $source=$snapshots[$sourceId];
    if ($keep['managed'] || $source['managed']) throw new ParticipantManagementException('Identitas atau anak peserta dikelola master keluarga / Data Induk. Periksa pemetaan melalui sumber tersebut sebelum menggabungkan.');
    foreach ($snapshots as $snapshot) {
        foreach (PARTICIPANT_MERGE_FIELDS as $field) if (!array_key_exists($field,$snapshot['parent'])) throw new ParticipantManagementException('Skema peserta belum lengkap. Hubungi pengelola untuk memasang pembaruan sebelum menggabungkan.');
        foreach ($snapshot['memberships'] as $membership) if (!in_array($membership['participant_program'],['bapak','ibu'],true)) throw new ParticipantManagementException('Ada program halaqoh yang belum didukung halaman penggabungan. Hubungi pengelola; data belum diubah.');
    }
    $alias=$pdo->prepare('SELECT 1 FROM participant_merge_aliases WHERE source_id IN (?,?) LIMIT 1'.($lock?' FOR UPDATE':'')); $alias->execute([$keepId,$sourceId]);
    if ($alias->fetchColumn()) throw new ParticipantManagementException('Ada ID peserta hidup yang juga tercatat sebagai ID lama penggabungan. Minta pengelola memeriksa data sebelum melanjutkan.');
    participantMergeCheckReferences($pdo,$source['parent'],$lock);
    $related=participantMergeRelated($pdo,$keepId,$sourceId,$lock);
    foreach ($related['data_induk_sync_conflicts']??[] as $conflict) if ($conflict['status']==='pending') throw new ParticipantManagementException('Ada pemetaan Data Induk yang masih menunggu keputusan. Selesaikan pemetaan tersebut sebelum menggabungkan peserta.');
    $records=$related['presensi']??[];
    $suspects=tahsinSuspectIds($pdo,$records); ksort($suspects);
    $plan=['version'=>1,'keep_id'=>$keepId,'source_id'=>$sourceId,'keep'=>$keep,'source'=>$source,'related'=>$related,'suspects'=>$suspects];
    $json=json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    if (strlen($json)>4*1024*1024) throw new ParticipantManagementException('Riwayat kedua peserta terlalu besar untuk halaman ini. Hubungi pengelola untuk penggabungan dengan cadangan terpisah. Data belum diubah.');
    $plan['fingerprint']=hash('sha256',$json);
    return $plan;
}

function participantMergeDraftKey(array $row): string
{
    return hash('sha256',json_encode([(int)$row['user_id'],(int)$row['halaqoh_id'],$row['session_date']],JSON_THROW_ON_ERROR));
}

function participantMergeDrafts(array $plan): array
{
    $pairs=[];
    foreach ($plan['related']['tahsin_autosave_drafts']??[] as $row) $pairs[participantMergeDraftKey($row)][(int)$row['wali_id']===$plan['keep_id']?'keep':'source']=$row;
    ksort($pairs);
    return $pairs;
}

function participantMergeChoices(array $plan, array $input): array
{
    $fields=$input['fields']??[]; $memberships=$input['memberships']??[]; $drafts=$input['drafts']??[];
    if (!is_array($fields) || array_diff(array_keys($fields),PARTICIPANT_MERGE_FIELDS) || !is_array($memberships) || array_diff(array_keys($memberships),['bapak','ibu']) || !is_array($drafts)) throw new ParticipantManagementException('Pilihan data penggabungan tidak valid.');
    foreach (PARTICIPANT_MERGE_FIELDS as $field) {
        $fields[$field]=appRequestString($fields,$field,'keep');
        if (!in_array($fields[$field],['keep','source'],true)) throw new ParticipantManagementException('Pilih sumber biodata yang akan digunakan.');
    }
    foreach (['bapak','ibu'] as $program) {
        $active=[];
        foreach (['keep','source'] as $side) {
            $active[$side]=array_values(array_unique(array_map(static fn($row)=>(int)$row['halaqoh_id'],array_filter($plan[$side]['memberships'],static fn($row)=>$row['participant_program']===$program && $row['archived_at']===null))));
            sort($active[$side]);
        }
        $conflict=$active['keep'] && $active['source'] && $active['keep']!==$active['source'];
        $memberships[$program]=appRequestString($memberships,$program,$conflict?'':'combine');
        if (!in_array($memberships[$program],['keep','source','combine'],true)) throw new ParticipantManagementException('Tentukan penempatan program '.ucfirst($program).' karena kedua peserta memiliki halaqoh berbeda.');
    }
    $pairs=participantMergeDrafts($plan);
    if (array_diff(array_keys($drafts),array_keys($pairs))) throw new ParticipantManagementException('Pilihan draft tidak ditemukan pada pratinjau.');
    foreach ($pairs as $key=>$pair) {
        $conflict=isset($pair['keep'],$pair['source']) && $pair['keep']['payload_json']!==$pair['source']['payload_json'];
        $drafts[$key]=appRequestString($drafts,$key,$conflict?'':(isset($pair['keep'])?'keep':'source'));
        if (!isset($pair[$drafts[$key]])) throw new ParticipantManagementException('Pilih draft aktif untuk setiap benturan. Versi lainnya disimpan di jurnal penggabungan.');
    }
    $baseline=appRequestString($input,'baseline');
    $baselines=[];
    foreach ($plan['related']['tahsin_capaian_awal']??[] as $row) $baselines[(int)$row['wali_santri_id']===$plan['keep_id']?'keep':'source']=$row;
    if (count($baselines)<2 && $baseline==='') $baseline=isset($baselines['keep'])?'keep':(isset($baselines['source'])?'source':'none');
    if ($baselines && !isset($baselines[$baseline])) throw new ParticipantManagementException('Pilih capaian awal yang akan aktif. Kedua versi asal tetap tersimpan dalam jurnal.');
    if (!$baselines && $baseline!=='none') throw new ParticipantManagementException('Pilihan capaian awal tidak valid.');
    ksort($fields); ksort($memberships); ksort($drafts);
    return ['fields'=>$fields,'memberships'=>$memberships,'drafts'=>$drafts,'baseline'=>$baseline];
}

function participantMergeMemberships(PDO $pdo, array $plan, array $choices): void
{
    $now=(string)$pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
    $allowed=[];
    foreach (['bapak','ibu'] as $program) {
        foreach (['keep','source'] as $side) {
            if ($choices[$program]!=='combine' && $choices[$program]!==$side) continue;
            foreach ($plan[$side]['memberships'] as $row) if ($row['participant_program']===$program && $row['archived_at']===null) $allowed[(int)$row['halaqoh_id']]=true;
        }
    }
    $open=[];
    foreach (['keep','source'] as $side) {
        foreach ($plan[$side]['memberships'] as $row) {
            $archive=$row['archived_at']; $reason=$row['archive_reason'];
            if ($archive===null) {
                if (isset($allowed[(int)$row['halaqoh_id']]) && !isset($open[(int)$row['halaqoh_id']])) $open[(int)$row['halaqoh_id']]=true;
                else { $archive=$now; $reason='MERGE_REASSIGN'; }
            }
            if ($side==='source' || $archive!==$row['archived_at']) $pdo->prepare('UPDATE halaqoh_members SET wali_santri_id=?,archived_at=?,archive_reason=? WHERE id=?')->execute([$plan['keep_id'],$archive,$reason,$row['id']]);
        }
    }
}

function participantMergeApply(PDO $pdo, int $actorId, array $plan, array $input, string $operationId): array
{
    if ($pdo->inTransaction()) throw new RuntimeException('Selesaikan transaksi lain sebelum menggabungkan peserta.');
    if (($plan['version']??0)!==1 || !preg_match('/^[a-f0-9]{32}$/D',$operationId) || !preg_match('/^[a-f0-9]{64}$/D',$plan['fingerprint']??'')) throw new ParticipantManagementException('Pratinjau tidak valid. Pilih ulang peserta.');
    if (appRequestString($input,'confirmed')!=='1') throw new ParticipantManagementException('Centang konfirmasi setelah memeriksa kedua identitas dan pilihan data.');
    $choices=participantMergeChoices($plan,$input);
    $requestHash=hash('sha256',json_encode([$plan['keep_id'],$plan['source_id'],$plan['fingerprint'],$choices],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $lockName=participantWriteLockName($pdo); $statement=$pdo->prepare('SELECT GET_LOCK(?,5)'); $statement->execute([$lockName]);
    if ((int)$statement->fetchColumn()!==1) throw new ParticipantManagementException('Perubahan peserta lain sedang diproses. Tunggu sebentar lalu coba lagi.');
    $mode=null;
    try {
        appRequireTransactionalTables($pdo); $mode=appUseStrictSqlMode($pdo); $pdo->beginTransaction();
        $statement=$pdo->prepare('SELECT role,nama_lengkap FROM users WHERE id=? FOR UPDATE'); $statement->execute([$actorId]); $actor=$statement->fetch(PDO::FETCH_ASSOC);
        if (!$actor || $actor['role']!=='admin') throw new ParticipantManagementException('Penggabungan peserta hanya dapat dilakukan admin.');
        $statement=$pdo->prepare('SELECT * FROM participant_changes WHERE operation_id=? FOR UPDATE'); $statement->execute([$operationId]);
        if ($receipt=$statement->fetch(PDO::FETCH_ASSOC)) {
            if ((int)$receipt['actor_id']!==$actorId || $receipt['action']!=='merge' || !hash_equals($receipt['request_hash'],$requestHash)) throw new ParticipantManagementException('Pratinjau ini sudah digunakan untuk perubahan lain.');
            $pdo->commit(); return json_decode($receipt['result_json'],true,32,JSON_THROW_ON_ERROR);
        }
        $fresh=participantMergePlan($pdo,(int)$plan['keep_id'],(int)$plan['source_id'],true);
        if (!hash_equals($fresh['fingerprint'],$plan['fingerprint'])) throw new ParticipantManagementException('Data berubah sejak pratinjau. Buat pratinjau baru sebelum menggabungkan.');
        $keepId=$fresh['keep_id']; $sourceId=$fresh['source_id'];
        $before=$fresh['keep']; $before['merge_source']=$fresh['source']; $before['merge_related']=$fresh['related']; $before['merge_choices']=$choices;
        $beforeJson=json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $values=[];
        foreach ($choices['fields'] as $field=>$side) $values[$field]=$fresh[$side]['parent'][$field];
        // Move children in place; matching names never prove the same child.
        $pdo->prepare('UPDATE santri_detail SET wali_santri_id=? WHERE wali_santri_id=?')->execute([$keepId,$sourceId]);
        participantMergeMemberships($pdo,$fresh,$choices['memberships']);
        $pdo->prepare('UPDATE presensi SET wali_santri_id=? WHERE wali_santri_id=?')->execute([$keepId,$sourceId]);
        foreach (['tahsin_roster_history','tahsin_verifications','participant_changes','participant_merge_aliases','data_induk_sync_conflicts'] as $table) {
            if (!array_key_exists($table,$fresh['related'])) continue;
            $column=participantMergeSpecs()[$table][0];
            $where=$table==='data_induk_sync_conflicts'?" AND entity_type='guardian'":'';
            $pdo->prepare("UPDATE `$table` SET `$column`=? WHERE `$column`=?$where")->execute([$keepId,$sourceId]);
        }
        $baselines=[];
        foreach ($fresh['related']['tahsin_capaian_awal']??[] as $row) $baselines[(int)$row['wali_santri_id']===$keepId?'keep':'source']=$row;
        if (isset($baselines['source'])) {
            if (isset($baselines['keep'])) {
                if ($choices['baseline']==='source') {
                    $selected=$baselines['source']; unset($selected['wali_santri_id']);
                    $assignments=implode(',',array_map(static fn($column)=>"`$column`=?",array_keys($selected)));
                    $pdo->prepare("UPDATE tahsin_capaian_awal SET $assignments WHERE wali_santri_id=?")->execute([...array_values($selected),$keepId]);
                }
                $pdo->prepare('DELETE FROM tahsin_capaian_awal WHERE wali_santri_id=?')->execute([$sourceId]);
            } else $pdo->prepare('UPDATE tahsin_capaian_awal SET wali_santri_id=? WHERE wali_santri_id=?')->execute([$keepId,$sourceId]);
        }
        foreach (participantMergeDrafts($fresh) as $key=>$pair) {
            $selected=$pair[$choices['drafts'][$key]];
            $revision=max(array_column($pair,'revision'))+1;
            $sentinel=hash('sha256','participant-merge:'.$operationId.':'.$key);
            if (isset($pair['keep'])) {
                $pdo->prepare("UPDATE tahsin_autosave_drafts SET payload_json=?,revision=?,official_revision=?,context_revision=?,updated_at=?,state='conflict' WHERE user_id=? AND halaqoh_id=? AND session_date=? AND wali_id=?")->execute([$selected['payload_json'],$revision,$sentinel,$sentinel,$selected['updated_at'],$selected['user_id'],$selected['halaqoh_id'],$selected['session_date'],$keepId]);
                if (isset($pair['source'])) $pdo->prepare('DELETE FROM tahsin_autosave_drafts WHERE user_id=? AND halaqoh_id=? AND session_date=? AND wali_id=?')->execute([$selected['user_id'],$selected['halaqoh_id'],$selected['session_date'],$sourceId]);
            } else $pdo->prepare("UPDATE tahsin_autosave_drafts SET wali_id=?,revision=?,official_revision=?,context_revision=?,state='conflict' WHERE user_id=? AND halaqoh_id=? AND session_date=? AND wali_id=?")->execute([$keepId,$revision,$sentinel,$sentinel,$selected['user_id'],$selected['halaqoh_id'],$selected['session_date'],$sourceId]);
        }
        if (isset($fresh['related']['tahsin_autosave_revisions'])) {
            $statement=$pdo->prepare('SELECT * FROM tahsin_autosave_revisions WHERE wali_id=? ORDER BY session_date FOR UPDATE'); $statement->execute([$sourceId]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $revision) $pdo->prepare('INSERT INTO tahsin_autosave_revisions(wali_id,session_date,revision) VALUES(?,?,?) ON DUPLICATE KEY UPDATE revision=GREATEST(revision,VALUES(revision))+1')->execute([$keepId,$revision['session_date'],(int)$revision['revision']+1]);
            $pdo->prepare('DELETE FROM tahsin_autosave_revisions WHERE wali_id=?')->execute([$sourceId]);
        }
        $reviewSessions=[];
        foreach ($fresh['related']['presensi']??[] as $record) if (isset($fresh['suspects'][(int)$record['id']])) $reviewSessions[tahsinSessionDate($record['tanggal'])]=true;
        if ($reviewSessions && !isset($fresh['related']['tahsin_verifications'])) throw new ParticipantManagementException('Riwayat capaian awal memerlukan fitur verifikasi sebelum dapat digabung.');
        foreach (array_keys($reviewSessions) as $session) $pdo->prepare('INSERT INTO tahsin_verifications(wali_santri_id,session_date,fingerprint,selected_presensi_id,decided_by,note) VALUES(?,?,?,NULL,?,?)')->execute([$keepId,$session,hash('sha256','merge-review:'.$operationId.':'.$session),$actorId,'Bukti dugaan capaian awal dipertahankan saat penggabungan. Sumber perlu diverifikasi ulang.']);
        $changed=array_filter($values,static fn($value,$field)=>$fresh['keep']['parent'][$field]!==$value,ARRAY_FILTER_USE_BOTH);
        if ($changed) {
            $assignments=implode(',',array_map(static fn($column)=>"`$column`=?",array_keys($changed)));
            $pdo->prepare("UPDATE wali_santri SET $assignments WHERE id=?")->execute([...array_values($changed),$keepId]);
        }
        if ((int)$values['status_aktif']===0) $pdo->prepare("UPDATE halaqoh_members SET archived_at=CURRENT_TIMESTAMP,archive_reason='MERGE_INACTIVE' WHERE wali_santri_id=? AND archived_at IS NULL")->execute([$keepId]);
        archiveEligibleAlumni($pdo,[$keepId],'AUTO_MERGE_PESERTA');
        // Triggers may have created intervals during the transfer; no history
        // may still point at the source before its FK cascade can run.
        if (isset($fresh['related']['tahsin_roster_history'])) $pdo->prepare('UPDATE tahsin_roster_history SET wali_santri_id=? WHERE wali_santri_id=?')->execute([$keepId,$sourceId]);
        $pdo->prepare('DELETE FROM wali_santri WHERE id=?')->execute([$sourceId]);
        $result=['id'=>$keepId,'source_id'=>$sourceId,'operation_id'=>$operationId,'action'=>'merge',
            'children_moved'=>count($fresh['source']['children']),'memberships_moved'=>count($fresh['source']['memberships']),
            'attendance_moved'=>count(array_filter($fresh['related']['presensi']??[],static fn($row)=>(int)$row['wali_santri_id']===$sourceId)),'drafts_review'=>count(participantMergeDrafts($fresh))];
        $pdo->prepare('INSERT INTO participant_changes(operation_id,actor_id,actor_name,action,wali_id,request_hash,before_json,after_json,result_json) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$operationId,$actorId,$actor['nama_lengkap'],'merge',$keepId,$requestHash,$beforeJson,'null',json_encode($result,JSON_THROW_ON_ERROR)]);
        $pdo->prepare('INSERT INTO participant_merge_aliases(source_id,target_id,operation_id) VALUES(?,?,?)')->execute([$sourceId,$keepId,$operationId]);
        $after=participantSnapshot($pdo,$keepId,true);
        $after['merge_related']=participantMergeRelated($pdo,$keepId,$sourceId,true,$operationId);
        $after['merge_result']=$result;
        $afterJson=json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (strlen($beforeJson)+strlen($afterJson)>8*1024*1024) throw new ParticipantManagementException('Jurnal penggabungan melebihi batas halaman ini. Hubungi pengelola; seluruh perubahan dibatalkan.');
        $pdo->prepare('UPDATE participant_changes SET after_json=? WHERE operation_id=?')->execute([$afterJson,$operationId]);
        $pdo->commit(); return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally {
        try { if ($mode!==null) $pdo->prepare('SET SESSION sql_mode=?')->execute([$mode]); } catch (Throwable $error) { error_log('Merge SQL mode restore: '.$error->getMessage()); }
        try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); } catch (Throwable $error) { error_log('Merge lock release: '.$error->getMessage()); }
    }
}
