<?php
require_once __DIR__.'/data_quality_migration_helper.php';

function mergeUndoJson(mixed $value): string
{
    $clean=static function($v)use(&$clean){
        if (is_array($v)) { foreach ($v as &$item) $item=$clean($item); unset($item); }
        elseif (is_string($v) && !preg_match('//u',$v)) $v='0x'.bin2hex($v);
        return $v;
    };
    return json_encode($clean($value),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}

function mergeUndoKey(array $row,array $pk): string
{
    return hash('sha256',serialize(array_map(static fn($k)=>isset($row[$k])?(string)$row[$k]:null,$pk)));
}

/** Exact base64 snapshots, with schema/PK information, never SQL from a journal. */
function mergeUndoCapture(PDO $pdo,int $keep,int $source,string $operation,bool $lock=false): array
{
    $specs=['wali_santri'=>['id','id'],'santri_detail'=>['wali_santri_id','id'],'halaqoh_members'=>['wali_santri_id','id']]+participantMergeSpecs();
    $minutes=(int)$pdo->query('SELECT TIMESTAMPDIFF(MINUTE,UTC_TIMESTAMP(),NOW())')->fetchColumn();
    $timezone=($minutes<0?'-':'+').sprintf('%02d:%02d',intdiv(abs($minutes),60),abs($minutes)%60);
    $instance=$pdo->query('SELECT instance_key FROM presensi_history_state WHERE id=1')->fetchColumn();
    $state=['version'=>2,'keep'=>$keep,'source'=>$source,'timezone'=>$timezone,'instance_key'=>$instance,'tables'=>[]];
    foreach ($specs as $table=>[$owner,$order]) {
        try { $columns=$pdo->query('SHOW FULL COLUMNS FROM '.attendanceHistoryQuote($table))->fetchAll(PDO::FETCH_ASSOC); }
        catch (PDOException $e) { if ($e->getCode()==='42S02') continue; throw $e; }
        $pk=$pdo->query('SHOW KEYS FROM '.attendanceHistoryQuote($table)." WHERE Key_name='PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
        usort($pk,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']); $pk=array_column($pk,'Column_name');
        if (!$pk) throw new ParticipantManagementException('Tabel '.$table.' tidak memiliki primary key. Penggabungan aman memerlukan identitas baris.');
        foreach ($columns as &$column) unset($column['Privileges']); unset($column);
        $projection=$table==='participant_changes'?'operation_id,wali_id,request_hash,SHA2(before_json,256) AS before_sha256,SHA2(after_json,256) AS after_sha256':'*';
        $where=attendanceHistoryQuote($owner).' IN (?,?)'; $args=[$keep,$source];
        if ($table==='participant_changes') { $where.=' AND operation_id<>?'; $args[]=$operation; }
        if ($table==='data_induk_sync_conflicts') $where.=" AND entity_type='guardian'";
        $q=$pdo->prepare('SELECT '.$projection.' FROM '.attendanceHistoryQuote($table).' WHERE '.$where.' ORDER BY '.implode(',',array_map('attendanceHistoryQuote',$pk)).($lock?' FOR UPDATE':'')); $q->execute($args);
        $rows=[];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[mergeUndoKey($row,$pk)]=json_decode(attendanceHistoryEncode($row),true,64,JSON_THROW_ON_ERROR);
        $state['tables'][$table]=['owner'=>$owner,'pk'=>$pk,'columns'=>$columns,'rows'=>$rows];
    }
    return $state;
}

function mergeUndoDecodeRows(array $table): array
{
    $rows=[]; foreach ($table['rows'] as $key=>$encoded) $rows[$key]=attendanceHistoryDecode(json_encode($encoded,JSON_THROW_ON_ERROR)); return $rows;
}

/** Build a three-way inverse. Every post-merge edit/new row requires an explicit choice. */
function mergeUndoInspect(PDO $pdo,string $operation,bool $lock=false): array
{
    if (!preg_match('/^[a-f0-9]{32}$/D',$operation) || !dataQualitySchemaReady($pdo)) throw new ParticipantManagementException('Migrasi undo belum siap atau ID operasi tidak valid.');
    $q=$pdo->prepare("SELECT * FROM participant_changes WHERE operation_id=? AND action='merge'".($lock?' FOR UPDATE':'')); $q->execute([$operation]); $journal=$q->fetch(PDO::FETCH_ASSOC);
    if (!$journal) throw new ParticipantManagementException('Riwayat merge tidak ditemukan.');
    $before=json_decode($journal['before_json'],true,128,JSON_THROW_ON_ERROR); $after=json_decode($journal['after_json'],true,128,JSON_THROW_ON_ERROR);
    $a=$before['undo_state']??null; $b=$after['undo_state']??null;
    if (($a['version']??0)!==2 || ($b['version']??0)!==2) throw new ParticipantManagementException('Merge lama belum memiliki snapshot lengkap untuk undo otomatis. Unduh jurnal dan pulihkan pada database terpisah.');
    $q=$pdo->prepare('SELECT * FROM participant_merge_undo WHERE merge_operation=?'.($lock?' FOR UPDATE':'')); $q->execute([$operation]);
    if ($receipt=$q->fetch(PDO::FETCH_ASSOC)) return ['completed'=>json_decode($receipt['result_json'],true,32,JSON_THROW_ON_ERROR),'receipt'=>$receipt];
    $inspectionTimezone=$pdo->query('SELECT @@SESSION.time_zone')->fetchColumn();
    if (!preg_match('/^[+-][0-9]{2}:[0-9]{2}$/D',$a['timezone']??'')) throw new ParticipantManagementException('Zona waktu snapshot tidak valid.');
    $pdo->prepare('SET SESSION time_zone=?')->execute([$a['timezone']]);
    try {
    $keep=(int)$a['keep']; $source=(int)$a['source'];
    if (dataIndukConfig()['enabled'] || isset(participantManagedIds($pdo)[$keep])) throw new ParticipantManagementException('Identitas sekarang dikelola Data Induk/master keluarga. Undo lokal diblokir; periksa sumber identitas.');
    $q=$pdo->prepare('SELECT id FROM wali_santri WHERE id IN (?,?) ORDER BY id'.($lock?' FOR UPDATE':'')); $q->execute([$keep,$source]);
    if (array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN))!==[$keep]) throw new ParticipantManagementException('ID hasil hilang atau ID sumber sudah hidup. Periksa merge berikutnya sebelum undo.');
    $q=$pdo->prepare('SELECT * FROM participant_merge_aliases WHERE source_id=?'.($lock?' FOR UPDATE':'')); $q->execute([$source]); $alias=$q->fetch(PDO::FETCH_ASSOC);
    if (!$alias || (int)$alias['target_id']!==$keep || $alias['operation_id']!==$operation) throw new ParticipantManagementException('Pengalihan ID berubah. Batalkan merge berikutnya terlebih dahulu melalui riwayat.');
    $c=mergeUndoCapture($pdo,$keep,$source,$operation,$lock);
    if (empty($a['instance_key']) || $a['instance_key']!==$c['instance_key'] || $b['instance_key']!==$c['instance_key']) throw new ParticipantManagementException('Snapshot berasal dari instance database berbeda. Gunakan pemulihan terpisah.');
    if (array_keys($a['tables'])!==array_keys($c['tables']) || array_keys($a['tables'])!==array_keys($b['tables'])) throw new ParticipantManagementException('Daftar tabel berubah sejak merge. Pengelola perlu memeriksa pemulihan pada database terpisah.');
    $aliases=mergeUndoDecodeRows($c['tables']['participant_merge_aliases']); $expectedAliases=mergeUndoDecodeRows($b['tables']['participant_merge_aliases']);
    if ($aliases!==$expectedAliases) {
        $ops=array_unique(array_column($aliases,'operation_id'));
        throw new ParticipantManagementException('Ada penggabungan berikutnya atau pengalihan berubah. Batalkan operasi berikutnya lebih dahulu: '.implode(', ',$ops));
    }
    participantMergeCheckReferences($pdo,$before['parent'],$lock);
    $issues=[]; $desired=[];
    foreach ($a['tables'] as $table=>$meta) {
        if ($meta['columns']!==$c['tables'][$table]['columns'] || $meta['pk']!==$c['tables'][$table]['pk']) throw new ParticipantManagementException('Struktur '.$table.' berubah sejak merge. Undo otomatis belum dapat dilakukan.');
        $original=mergeUndoDecodeRows($meta); $merged=mergeUndoDecodeRows($b['tables'][$table]); $current=mergeUndoDecodeRows($c['tables'][$table]);
        $desired[$table]=$original;
        if (in_array($table,['tahsin_autosave_revisions','tahsin_program_revisions','participant_merge_aliases'],true)) continue;
        if ($table==='participant_changes') {
            foreach ($current as $key=>$row) if (!isset($original[$key])) $desired[$table][$key]=$row;
            continue;
        }
        // A source PK containing the owner may have collided with the keeper.
        $origins=[];
        foreach ($original as $key=>$row) {
            $canonical=$row;
            if ((int)$canonical[$meta['owner']]===$source) $canonical[$meta['owner']]=(string)$keep;
            $mergedKey=isset($merged[$key])?$key:mergeUndoKey($canonical,$meta['pk']);
            $origins[$mergedKey][$key]=$row;
        }
        foreach (array_unique(array_merge(array_keys($merged),array_keys($current))) as $key) {
            $old=$merged[$key]??null; $now=$current[$key]??null;
            if ($old===$now) continue;
            $orig=$origins[$key]??[];
            $issueKey=hash('sha256',$table.':'.$key);
            $kind=$old===null?'new':($now===null?'deleted':'changed');
            if ($table==='wali_santri') {
                // Changes to the merged biography can belong to either identity.
                $orig=$original;
            }
            $labels=['wali_santri'=>'Biodata peserta','santri_detail'=>'Anak','halaqoh_members'=>'Keanggotaan','presensi'=>'Presensi','tahsin_roster_history'=>'Riwayat penempatan','tahsin_capaian_awal'=>'Capaian awal','tahsin_autosave_drafts'=>'Draft server','tahsin_verifications'=>'Keputusan catatan resmi'];
            $issues[$issueKey]=['table'=>$table,'row_key'=>$key,'kind'=>$kind,'before_merge'=>$orig,'after_merge'=>$old,'current'=>$now,
                'label'=>($labels[$table]??'Data terkait').' · '.implode(', ',array_map(static fn($k)=>$k.'='.($now[$k]??$old[$k]??'?'),$meta['pk']))];
        }
    }
    $fingerprint=hash('sha256',serialize([$a,$b,$c]));
    return ['completed'=>null,'operation'=>$operation,'keep'=>$keep,'source'=>$source,'before'=>$a,'after'=>$b,'current'=>$c,'desired'=>$desired,'issues'=>$issues,'fingerprint'=>$fingerprint];
    } finally { $pdo->prepare('SET SESSION time_zone=?')->execute([$inspectionTimezone]); }
}

function mergeUndoChoices(array $preview,array $choices): array
{
    if (array_diff(array_keys($choices),array_keys($preview['issues']))) throw new ParticipantManagementException('Pilihan undo tidak sesuai pratinjau.');
    foreach ($preview['issues'] as $key=>$issue) {
        $value=appRequestString($choices,$key);
        $options=$issue['kind']==='new'?['keep','source']:($issue['kind']==='deleted'?['preserve','restore']:['keep','source','restore']);
        if (!in_array($value,$options,true)) throw new ParticipantManagementException('Tentukan penempatan/perlakuan setiap perubahan sesudah merge.');
        $choices[$key]=$value;
    }
    ksort($choices); return $choices;
}

function mergeUndoDesired(array $preview,array $choices): array
{
    $desired=$preview['desired'];
    foreach ($preview['issues'] as $key=>$issue) {
        $table=$issue['table']; $meta=$preview['before']['tables'][$table]; $choice=$choices[$key];
        if ($choice==='restore') continue;
        if ($choice==='preserve') {
            foreach ($issue['before_merge'] as $originalKey=>$row) unset($desired[$table][$originalKey]);
            continue;
        }
        $owner=$choice==='keep'?$preview['keep']:$preview['source']; $now=$issue['current'];
        if ($table==='wali_santri') {
            foreach ($desired[$table] as &$row) if ((int)$row['id']===$owner) {
                foreach ($now as $column=>$value) if ($column!=='id' && ($issue['after_merge'][$column]??null)!==$value) $row[$column]=$value;
            } unset($row); continue;
        }
        $selected=null;
        foreach ($issue['before_merge'] as $originalKey=>$row) if ((int)$row[$meta['owner']]===$owner) { $selected=$row; unset($desired[$table][$originalKey]); break; }
        if ($selected!==null) {
            foreach ($now as $column=>$value) if ($column!==$meta['owner'] && ($issue['after_merge'][$column]??null)!==$value) $selected[$column]=$value;
        } else {
            // A stable-ID row cannot be copied into both identities.
            if (!in_array($meta['owner'],$meta['pk'],true)) foreach ($issue['before_merge'] as $originalKey=>$row) unset($desired[$table][$originalKey]);
            $selected=$now;
        }
        $selected[$meta['owner']]=(string)$owner;
        $desired[$table][mergeUndoKey($selected,$meta['pk'])]=$selected;
    }
    return $desired;
}

function mergeUndoValidateMemberships(PDO $pdo,array $desired,array $before): void
{
    $programs=tahsinGroupPrograms($pdo); $sets=[]; $original=[];
    foreach (['desired'=>array_values($desired['halaqoh_members']),'original'=>array_values(mergeUndoDecodeRows($before['tables']['halaqoh_members']))] as $side=>$rows) foreach ($rows as $row) {
        if ($row['archived_at']!==null) continue;
        $bucket=$row['wali_santri_id'].':'.($programs[(int)$row['halaqoh_id']]??'unknown');
        if ($side==='desired') $sets[$bucket][$row['id']]=$row['halaqoh_id']; else $original[$bucket][$row['id']]=$row['halaqoh_id'];
    }
    foreach ($sets as $bucket=>$rows) {
        ksort($rows); $old=$original[$bucket]??[]; ksort($old);
        if (count($rows)>1 && $rows!==$old) throw new ParticipantManagementException('Pilihan undo menghasilkan lebih dari satu keanggotaan aktif pada program yang sama. Tinjau penempatan data baru terlebih dahulu.');
    }
}

/** Writes only known live-schema columns and verifies every resulting value. */
function mergeUndoWriteRow(PDO $pdo,string $table,array $meta,array $row,array $allowedOwners=[]): void
{
    $pk=$meta['pk']; $where=implode(' AND ',array_map(static fn($c)=>attendanceHistoryQuote($c).' <=> ?',$pk)); $ids=array_map(static fn($c)=>$row[$c],$pk);
    $q=$pdo->prepare('SELECT * FROM '.attendanceHistoryQuote($table).' WHERE '.$where.' FOR UPDATE'); $q->execute($ids); $existing=$q->fetch(PDO::FETCH_ASSOC)?:null;
    if ($existing && !in_array((int)$existing[$meta['owner']],$allowedOwners?:[(int)$row[$meta['owner']]],true)) throw new ParticipantManagementException('ID baris '.$table.' sudah dipakai identitas lain. Pemulihan dibatalkan.');
    $writable=[];
    foreach ($meta['columns'] as $c) if (array_key_exists($c['Field'],$row) && !str_contains(strtoupper($c['Extra']),'GENERATED')) $writable[$c['Field']]=$row[$c['Field']];
    if ($existing) {
        foreach ($pk as $col) unset($writable[$col]);
        $writable=array_filter($writable,static fn($v,$k)=>($existing[$k]===null?null:(string)$existing[$k])!==$v,ARRAY_FILTER_USE_BOTH);
        if ($writable) $pdo->prepare('UPDATE '.attendanceHistoryQuote($table).' SET '.implode(',',array_map(static fn($c)=>attendanceHistoryQuote($c).'=?',array_keys($writable))).' WHERE '.$where)->execute([...array_values($writable),...$ids]);
    } else {
        $pdo->prepare('INSERT INTO '.attendanceHistoryQuote($table).' ('.implode(',',array_map('attendanceHistoryQuote',array_keys($writable))).') VALUES('.implode(',',array_fill(0,count($writable),'?')).')')->execute(array_values($writable));
    }
    $q->execute($ids); $actual=$q->fetch(PDO::FETCH_ASSOC);
    foreach ($row as $col=>$value) if (!array_key_exists($col,$actual?:[]) || ($actual[$col]===null?null:(string)$actual[$col])!==$value) throw new ParticipantManagementException('Hasil pemulihan '.$table.' tidak sama dengan pilihan/snapshot. Semua perubahan dibatalkan.');
}

function mergeUndoAssertDeleteSafe(PDO $pdo,string $table,array $row): void
{
    $q=$pdo->prepare('SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME=? ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION'); $q->execute([$table]);
    $constraints=[]; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $key) $constraints[$key['TABLE_NAME'].':'.$key['CONSTRAINT_NAME']][]=$key;
    foreach ($constraints as $keys) {
        $where=[]; $values=[];
        foreach ($keys as $key) { $where[]=attendanceHistoryQuote($key['COLUMN_NAME']).'=?'; $values[]=$row[$key['REFERENCED_COLUMN_NAME']]; }
        $probe=$pdo->prepare('SELECT 1 FROM '.attendanceHistoryQuote($keys[0]['TABLE_NAME']).' WHERE '.implode(' AND ',$where).' LIMIT 1 FOR UPDATE'); $probe->execute($values);
        if ($probe->fetchColumn()) throw new ParticipantManagementException('Baris '.$table.' masih dirujuk '.$keys[0]['TABLE_NAME'].'. Undo memerlukan pemeriksaan relasi tersebut; tidak ada cascade yang dijalankan.');
    }
}

function mergeUndoApply(PDO $pdo,int $actorId,string $operation,string $fingerprint,array $input,string $undoOperation): array
{
    if ($pdo->inTransaction() || !preg_match('/^[a-f0-9]{32}$/D',$undoOperation) || !preg_match('/^[a-f0-9]{64}$/D',$fingerprint) || appRequestString($input,'confirmed')!=='1' || !is_array($input['choices']??[])) throw new ParticipantManagementException('Periksa pratinjau dan konfirmasi undo terlebih dahulu.');
    $lock=participantWriteLockName($pdo); $q=$pdo->prepare('SELECT GET_LOCK(?,5)'); $q->execute([$lock]);
    if ((int)$q->fetchColumn()!==1) throw new ParticipantManagementException('Perubahan peserta lain sedang berjalan. Coba kembali.');
    $mode=null; $timezone=$pdo->query('SELECT @@SESSION.time_zone')->fetchColumn();
    $hash=hash('sha256',serialize([$actorId,$operation,$fingerprint,$input['choices']]));
    try {
        appRequireTransactionalTables($pdo); attendanceHistoryRequireReady($pdo); $mode=appUseStrictSqlMode($pdo,true); $pdo->beginTransaction();
        $actor=attendanceHistoryActor($pdo,$actorId,['admin']);
        $p=mergeUndoInspect($pdo,$operation,true);
        if ($p['completed']!==null) {
            if ($p['receipt']['undo_operation']!==$undoOperation || !hash_equals($p['receipt']['request_hash'],$hash)) throw new ParticipantManagementException('Merge ini sudah dibatalkan. Buka riwayat hasil undo.');
            $pdo->commit(); return $p['completed'];
        }
        if (!hash_equals($p['fingerprint'],$fingerprint)) throw new ParticipantManagementException('Data berubah sejak pratinjau undo. Periksa versi terbaru sebelum melanjutkan.');
        $choices=mergeUndoChoices($p,$input['choices']); $desired=mergeUndoDesired($p,$choices);
        mergeUndoValidateMemberships($pdo,$desired,$p['before']);
        $pdo->prepare('SET SESSION time_zone=?')->execute([$p['before']['timezone']]);
        $keep=$p['keep']; $source=$p['source'];
        // Remove only this exact alias; generic backup/ID guards remain unchanged.
        $pdo->prepare('DELETE FROM participant_merge_aliases WHERE source_id=? AND operation_id=?')->execute([$source,$operation]);
        foreach ($desired['wali_santri'] as $row) mergeUndoWriteRow($pdo,'wali_santri',$p['before']['tables']['wali_santri'],$row,[$keep,$source]);
        // Identity restoration may produce history through existing roster triggers.
        foreach ($desired as $table=>$rows) {
            if (in_array($table,['wali_santri','tahsin_roster_history','tahsin_autosave_revisions','tahsin_program_revisions','participant_merge_aliases','participant_changes'],true)) continue;
            $meta=$p['before']['tables'][$table];
            $current=mergeUndoCapture($pdo,$keep,$source,$operation,true); $live=mergeUndoDecodeRows($current['tables'][$table]);
            foreach ($live as $key=>$row) if (!isset($rows[$key])) {
                mergeUndoAssertDeleteSafe($pdo,$table,$row);
                $where=implode(' AND ',array_map(static fn($c)=>attendanceHistoryQuote($c).' <=> ?',$meta['pk']));
                $pdo->prepare('DELETE FROM '.attendanceHistoryQuote($table).' WHERE '.$where)->execute(array_map(static fn($c)=>$row[$c],$meta['pk']));
            }
            foreach ($rows as $row) {
                if ($table==='tahsin_autosave_drafts') {
                    $row['revision']=(string)(max(array_map(static fn($r)=>(int)$r['revision'],$live?:[$row]))+1);
                    $row['official_revision']=$row['context_revision']=hash('sha256','undo:'.$undoOperation); $row['state']='conflict';
                }
                mergeUndoWriteRow($pdo,$table,$meta,$row,[$keep,$source]);
                $desired[$table][mergeUndoKey($row,$meta['pk'])]=$row;
            }
        }
        if (isset($desired['tahsin_roster_history'])) {
            $live=mergeUndoCapture($pdo,$keep,$source,$operation,true);
            foreach (mergeUndoDecodeRows($live['tables']['tahsin_roster_history']) as $key=>$row) if (!isset($desired['tahsin_roster_history'][$key])) {
                mergeUndoAssertDeleteSafe($pdo,'tahsin_roster_history',$row);
                $pdo->prepare('DELETE FROM tahsin_roster_history WHERE id=?')->execute([$row['id']]);
            }
            // Closed intervals first, then open ones; generated uniqueness stays enabled.
            uasort($desired['tahsin_roster_history'],static fn($a,$b)=>[($a['valid_until']===null?1:0),(int)$a['id']]<=>[($b['valid_until']===null?1:0),(int)$b['id']]);
            foreach ($desired['tahsin_roster_history'] as $row) mergeUndoWriteRow($pdo,'tahsin_roster_history',$p['before']['tables']['tahsin_roster_history'],$row,[$keep,$source]);
        }
        foreach ($desired['participant_changes']??[] as $row) $pdo->prepare('UPDATE participant_changes SET wali_id=? WHERE operation_id=?')->execute([$row['wali_id'],$row['operation_id']]);
        foreach ($desired['participant_merge_aliases']??[] as $row) mergeUndoWriteRow($pdo,'participant_merge_aliases',$p['before']['tables']['participant_merge_aliases'],$row,[$keep,$source]);
        foreach (['tahsin_autosave_revisions','tahsin_program_revisions'] as $table) if (isset($p['before']['tables'][$table])) {
            $live=mergeUndoCapture($pdo,$keep,$source,$operation,true); $meta=$p['before']['tables'][$table];
            $all=array_merge(array_values(mergeUndoDecodeRows($live['tables'][$table])),array_values(mergeUndoDecodeRows($p['before']['tables'][$table])));
            $max=0; foreach ($all as $row) $max=max($max,(int)$row['revision']);
            $rows=[]; foreach ($all as $row) { $row['revision']=(string)($max+1); $rows[mergeUndoKey($row,$meta['pk'])]=$row; }
            foreach ($rows as $row) mergeUndoWriteRow($pdo,$table,$meta,$row,[$keep,$source]);
        }
        $final=mergeUndoCapture($pdo,$keep,$source,$operation,true);
        foreach ($desired as $table=>$rows) {
            if (in_array($table,['tahsin_autosave_revisions','tahsin_program_revisions'],true)) continue;
            $actual=mergeUndoDecodeRows($final['tables'][$table]); ksort($actual); ksort($rows);
            foreach ($rows as &$row) foreach ($row as &$value) if ($value!==null) $value=(string)$value; unset($row,$value);
            if ($actual!==$rows) throw new ParticipantManagementException('Pemeriksaan akhir '.$table.' berbeda dari pilihan pemulihan. Semua perubahan dibatalkan.');
        }
        $after=participantSnapshot($pdo,$keep,true); $after['undo_state']=$final;
        $before=participantSnapshotFromUndo($p['current'],$keep); $before['undo_state']=$p['current'];
        $result=['id'=>$keep,'source_id'=>$source,'operation_id'=>$undoOperation,'merge_operation'=>$operation,'action'=>'merge_undo'];
        $bj=mergeUndoJson($before); $aj=mergeUndoJson($after);
        if (strlen($bj)+strlen($aj)>16*1024*1024) throw new ParticipantManagementException('Jurnal undo terlalu besar. Semua perubahan dibatalkan.');
        $pdo->prepare('INSERT INTO participant_changes(operation_id,actor_id,actor_name,action,wali_id,request_hash,before_json,after_json,result_json) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$undoOperation,$actorId,$actor['nama_lengkap'],'merge_undo',$keep,$hash,$bj,$aj,json_encode($result,JSON_THROW_ON_ERROR)]);
        $pdo->prepare('INSERT INTO participant_merge_undo(merge_operation,undo_operation,actor_id,request_hash,result_json) VALUES(?,?,?,?,?)')->execute([$operation,$undoOperation,$actorId,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
        $pdo->commit(); return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally {
        try { $pdo->prepare('SET SESSION time_zone=?')->execute([$timezone]); if ($mode!==null) $pdo->prepare('SET SESSION sql_mode=?')->execute([$mode]); $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
        catch (Throwable $e) { error_log('Undo connection cleanup: '.$e->getMessage()); }
    }
}

function participantSnapshotFromUndo(array $state,int $keep): array
{
    $parent=array_values(mergeUndoDecodeRows($state['tables']['wali_santri']))[0];
    $members=array_values(mergeUndoDecodeRows($state['tables']['halaqoh_members']));
    // History renderer can display absent labels; exact rows remain in undo_state.
    return ['parent'=>$parent,'children'=>array_values(mergeUndoDecodeRows($state['tables']['santri_detail'])),'memberships'=>$members];
}
