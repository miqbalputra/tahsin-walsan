<?php
require_once __DIR__ . '/participant_helper.php';
require_once __DIR__ . '/attendance_history_helper.php';
require_once __DIR__ . '/attendance_helper.php';

final class MembershipException extends RuntimeException
{
    public function __construct(string $message, public int $httpStatus=422) { parent::__construct($message); }
}

function membershipReady(PDO $pdo): bool
{
    try { $pdo->query('SELECT archived_at,archive_reason FROM halaqoh_members LIMIT 0'); }
    catch (PDOException $error) { if (in_array($error->getCode(),['42S02','42S22'],true)) return false; throw $error; }
    return participantSchemaReady($pdo)&&tahsinSchemaReady($pdo);
}

function membershipFilters(array $query): array
{
    $search=appRequestString($query,'search');
    if (!preg_match('//u',$search) || preg_match('/[\x00-\x1f\x7f]/',$search) || appTextLength($search)>100) throw new MembershipException('Pencarian maksimal 100 karakter teks yang valid.');
    $filters=['search'=>$search];
    foreach (['members_page','available_page'] as $key) {
        $page=filter_var(appRequestString($query,$key,'1'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
        if (!$page) throw new MembershipException('Nomor halaman tidak valid.'); $filters[$key]=$page;
    }
    return $filters;
}

/** Same authoritative school policy as writes; no GROUP_CONCAT truncation or NULL loss. */
function membershipChildSource(PDO $pdo): string
{
    $dataIndukArchived = familyDataIndukRosterColumnsReady($pdo)
        ? "WHEN sd.data_induk_student_id IS NOT NULL AND sd.status_roster='archived' THEN 'archived' "
        : '';
    $legacy="SELECT sd.wali_santri_id,CONVERT(sd.nama_anak USING utf8mb4) COLLATE utf8mb4_unicode_ci AS nama_anak,CONVERT(sd.kelas USING utf8mb4) COLLATE utf8mb4_unicode_ci AS kelas,(CASE {$dataIndukArchived}WHEN LOWER(TRIM(COALESCE(sd.kelas,'')))='lulus' THEN 'graduated' ELSE 'active' END) COLLATE utf8mb4_unicode_ci AS school_status FROM santri_detail sd";
    try { $pdo->query('SELECT id FROM family_guardians LIMIT 0'); }
    catch (PDOException $error) { if ($error->getCode()==='42S02') return $legacy; throw $error; }
    return "SELECT g.wali_santri_id,CONVERT(s.nama_anak USING utf8mb4) COLLATE utf8mb4_unicode_ci AS nama_anak,CONVERT(s.kelas USING utf8mb4) COLLATE utf8mb4_unicode_ci AS kelas,CONVERT(s.school_status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS school_status FROM family_guardians g JOIN family_relations r ON r.guardian_id=g.id JOIN family_students s ON s.id=r.student_id WHERE r.is_current=1
        UNION ALL $legacy WHERE NOT EXISTS(SELECT 1 FROM family_guardians g WHERE g.wali_santri_id=sd.wali_santri_id)";
}

function membershipCatalog(PDO $pdo, int $groupId, array $filters): array
{
    $group=membershipGroup($pdo,$groupId); $program=$group['group']['program_code']; $childrenSql=membershipChildSource($pdo);
    $active=attendanceSupportsMemberArchiving($pdo)?'hm.archived_at IS NULL':'1=1';
    $programColumn=participantHasPrograms($pdo)?'h.program_code':"'bapak'";
    $search=''; $searchParams=[];
    if ($filters['search']!=='') {
        $search=" AND (w.nama_bapak LIKE ? OR CAST(w.id AS CHAR)=? OR EXISTS(SELECT 1 FROM ($childrenSql) c WHERE c.wali_santri_id=w.id AND c.nama_anak LIKE ?))";
        $searchParams=['%'.$filters['search'].'%',$filters['search'],'%'.$filters['search'].'%'];
    }
    $sets=[
        'members'=>["EXISTS(SELECT 1 FROM halaqoh_members hm WHERE hm.wali_santri_id=w.id AND hm.halaqoh_id=? AND $active)",[$groupId],'members_page'],
        'available'=>["w.status_aktif=1 AND w.kategori IN ('reguler','askar') AND EXISTS(SELECT 1 FROM ($childrenSql) c WHERE c.wali_santri_id=w.id AND c.school_status='active' AND TRIM(COALESCE(c.kelas,''))<>'') AND NOT EXISTS(SELECT 1 FROM halaqoh_members hm JOIN halaqoh h ON h.id=hm.halaqoh_id WHERE hm.wali_santri_id=w.id AND $active AND $programColumn=?)",[$program],'available_page']
    ];
    $result=['group'=>$group];
    foreach ($sets as $key=>[$where,$params,$pageKey]) {
        $params=[...$params,...$searchParams]; $where.=$search;
        $statement=$pdo->prepare('SELECT COUNT(*) FROM wali_santri w WHERE '.$where); $statement->execute($params); $count=(int)$statement->fetchColumn();
        $pages=max(1,(int)ceil($count/50)); $page=min($filters[$pageKey],$pages);
        $statement=$pdo->prepare('SELECT w.id,w.nama_bapak,w.no_hp,w.status_aktif,w.kategori FROM wali_santri w WHERE '.$where.' ORDER BY w.nama_bapak,w.id LIMIT 50 OFFSET '.(($page-1)*50)); $statement->execute($params);
        $result[$key]=['rows'=>$statement->fetchAll(PDO::FETCH_ASSOC),'count'=>$count,'page'=>$page,'pages'=>$pages,'page_key'=>$pageKey];
    }
    $ids=array_values(array_unique([...array_column($result['members']['rows'],'id'),...array_column($result['available']['rows'],'id')])); $byParent=[];
    if ($ids) {
        $statement=$pdo->prepare("SELECT * FROM ($childrenSql) c WHERE c.wali_santri_id IN (".implode(',',array_fill(0,count($ids),'?')).') ORDER BY c.nama_anak,c.kelas,c.school_status'); $statement->execute($ids);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $child) $byParent[(int)$child['wali_santri_id']][]=$child['nama_anak'].' ['.($child['kelas']??'kelas belum tersedia').']';
    }
    foreach (['members','available'] as $key) foreach ($result[$key]['rows'] as &$row) $row['children']=implode(' | ',$byParent[(int)$row['id']]??[]);
    unset($row);
    return $result;
}

function membershipGroup(PDO $pdo, int $id, bool $lock=false): array
{
    $statement=$pdo->prepare('SELECT * FROM halaqoh WHERE id=?'.($lock?' FOR UPDATE':'')); $statement->execute([$id]); $group=$statement->fetch(PDO::FETCH_ASSOC);
    if (!$group) throw new MembershipException('Halaqoh tidak ditemukan. Buka kembali daftar halaqoh.');
    $group['program_code']=$group['program_code']??'bapak';
    if (!in_array($group['program_code'],['bapak','ibu'],true)) throw new MembershipException('Program halaqoh belum dikenali. Pengelola perlu meninjau data program sebelum mengubah anggota.');
    $statement=$pdo->prepare('SELECT id,username,nama_lengkap,role FROM users WHERE id=?'.($lock?' FOR UPDATE':'')); $statement->execute([$group['ustadz_id']]);
    $teacher=$statement->fetch(PDO::FETCH_ASSOC);
    return ['group'=>$group,'teacher'=>$teacher?:null];
}

function membershipContext(PDO $pdo, int $groupId, int $waliId, bool $lock=false): array
{
    $group=membershipGroup($pdo,$groupId,$lock);
    if ($lock) { $statement=$pdo->prepare('SELECT id FROM wali_santri WHERE id=? FOR UPDATE'); $statement->execute([$waliId]); $statement->fetchColumn(); }
    $school=participantSchoolSnapshot($pdo,$waliId,$lock);
    $participant=participantSnapshot($pdo,$waliId,$lock); $history=[];
    if (tahsinSchemaReady($pdo)) {
        $statement=$pdo->prepare('SELECT * FROM tahsin_roster_history WHERE wali_santri_id=? ORDER BY id'.($lock?' FOR UPDATE':'')); $statement->execute([$waliId]); $history=$statement->fetchAll(PDO::FETCH_ASSOC);
    }
    $fingerprint=hash('sha256',serialize([$group,$participant,$school,$history]));
    return compact('group','participant','school','history','fingerprint');
}

function membershipValidate(array $context, string $action): array
{
    if (!in_array($action,['add','remove'],true)) throw new MembershipException('Pilih tindakan keanggotaan yang tersedia.');
    $group=$context['group']['group'];
    $active=array_filter($context['participant']['memberships'],static fn($row)=>$row['archived_at']===null);
    $matching=array_values(array_filter($active,static fn($row)=>(int)$row['halaqoh_id']===(int)$group['id']));
    if ($action==='remove') {
        if (!$matching) throw new MembershipException('Peserta sudah tidak aktif pada halaqoh ini. Buka daftar terbaru.');
        return $matching;
    }
    $parent=$context['participant']['parent'];
    if ((int)$parent['status_aktif']!==1 || !in_array($parent['kategori'],['reguler','askar'],true) || !$context['school']['eligible']) throw new MembershipException('Penempatan baru memerlukan peserta Reguler/Askar aktif dengan anak yang masih sekolah.');
    if (($context['group']['teacher']['role']??null)!=='ustadz') throw new MembershipException('Pengampu halaqoh perlu diperbarui ke akun pengajar aktif sebelum menambah anggota.');
    foreach ($active as $row) if ($row['participant_program']===$group['program_code'] && (int)$row['halaqoh_id']!==(int)$group['id']) throw new MembershipException('Peserta sudah memiliki halaqoh aktif pada program ini. Gunakan formulir peserta untuk meninjau dan memindahkan penempatan secara eksplisit.');
    return $matching;
}

/** Human-readable standard snapshot plus exact base64 rows for all extension columns. */
function membershipJournal(array $context): array
{
    $snapshot=$context['participant']; $full=$snapshot;
    foreach (['parent'] as $key) foreach ($snapshot[$key] as &$value) if (is_string($value)&&!preg_match('//u',$value)) $value='0x'.bin2hex($value);
    unset($value);
    foreach (['children','memberships'] as $key) foreach ($snapshot[$key] as &$row) {
        foreach ($row as &$value) if (is_string($value)&&!preg_match('//u',$value)) $value='0x'.bin2hex($value);
        unset($value);
    }
    unset($row);
    $snapshot['membership_source']=['group'=>attendanceHistoryEncode($context['group']['group']),'parent'=>attendanceHistoryEncode($full['parent']),
        'children'=>array_map('attendanceHistoryEncode',$full['children']),'memberships'=>array_map('attendanceHistoryEncode',$full['memberships']),
        'roster_history'=>array_map('attendanceHistoryEncode',$context['history'])];
    foreach (['guardians','relations','students','legacy'] as $key) $snapshot['membership_source']['school'][$key]=array_map('attendanceHistoryEncode',$context['school'][$key]);
    return $snapshot;
}

function membershipApply(PDO $pdo, int $actorId, int $groupId, int $waliId, string $action, string $fingerprint, string $operation): array
{
    if ($pdo->inTransaction()) throw new RuntimeException('Selesaikan transaksi lain sebelum mengubah keanggotaan.');
    if ($groupId<1 || $waliId<1 || !in_array($action,['add','remove'],true) || !preg_match('/^[a-f0-9]{64}$/D',$fingerprint) || !preg_match('/^[a-f0-9]{32}$/D',$operation)) throw new MembershipException('Formulir keanggotaan tidak valid.');
    if (!membershipReady($pdo)) throw new MembershipException('Jurnal peserta atau histori roster belum siap. Hubungi pengelola; keanggotaan lama belum diubah.');
    $requestHash=hash('sha256',serialize([$groupId,$waliId,$action,$fingerprint]));
    $lockName=participantWriteLockName($pdo); $statement=$pdo->prepare('SELECT GET_LOCK(?,5)'); $statement->execute([$lockName]);
    if ((int)$statement->fetchColumn()!==1) throw new MembershipException('Perubahan peserta lain sedang diproses. Coba kembali sebentar lagi.',409);
    $mode=null;
    try {
        appRequireTransactionalTables($pdo); $mode=appUseStrictSqlMode($pdo); $pdo->beginTransaction();
        $actor=attendanceHistoryActor($pdo,$actorId,['admin','pj_tahfidz']);
        $statement=$pdo->prepare('SELECT actor_id,request_hash,result_json FROM participant_changes WHERE operation_id=? FOR UPDATE'); $statement->execute([$operation]);
        if ($receipt=$statement->fetch(PDO::FETCH_ASSOC)) {
            if ((int)$receipt['actor_id']!==$actorId || !hash_equals($receipt['request_hash'],$requestHash)) throw new MembershipException('Formulir sudah digunakan untuk tindakan lain. Buka konfirmasi baru.',409);
            $pdo->commit(); return json_decode($receipt['result_json'],true,32,JSON_THROW_ON_ERROR);
        }
        $before=membershipContext($pdo,$groupId,$waliId,true);
        if (!hash_equals($before['fingerprint'],$fingerprint)) throw new MembershipException('Keanggotaan, data peserta, sumber sekolah atau pengampu berubah sejak konfirmasi dibuka. Buka konfirmasi terbaru untuk membandingkan.',409);
        $matching=membershipValidate($before,$action); $changed=false; $membershipId=null;
        if ($action==='remove') {
            $pdo->prepare("UPDATE halaqoh_members SET archived_at=CURRENT_TIMESTAMP,archive_reason='MANUAL_REMOVE' WHERE halaqoh_id=? AND wali_santri_id=? AND archived_at IS NULL")->execute([$groupId,$waliId]); $changed=true;
        } elseif ($matching) $membershipId=(int)$matching[0]['id'];
        else {
            $old=array_values(array_filter($before['participant']['memberships'],static fn($row)=>(int)$row['halaqoh_id']===$groupId));
            if ($old) {
                $membershipId=(int)$old[0]['id'];
                $pdo->prepare('UPDATE halaqoh_members SET archived_at=NULL,archive_reason=NULL WHERE id=?')->execute([$membershipId]);
            } else {
                $pdo->prepare('INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(?,?)')->execute([$groupId,$waliId]); $membershipId=(int)$pdo->lastInsertId();
            }
            $changed=true;
        }
        $after=membershipContext($pdo,$groupId,$waliId,true);
        $beforeJson=json_encode(membershipJournal($before),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); $afterJson=json_encode(membershipJournal($after),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (strlen($beforeJson)+strlen($afterJson)>8*1024*1024) throw new MembershipException('Riwayat peserta terlalu besar. Hubungi pengelola; perubahan dibatalkan.');
        $result=['id'=>$waliId,'group_id'=>$groupId,'action'=>$action,'changed'=>$changed,'membership_id'=>$membershipId,'operation_id'=>$operation];
        $pdo->prepare('INSERT INTO participant_changes(operation_id,actor_id,actor_name,action,wali_id,request_hash,before_json,after_json,result_json) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$operation,$actorId,$actor['nama_lengkap'],'membership_'.$action,$waliId,$requestHash,$beforeJson,$afterJson,json_encode($result,JSON_THROW_ON_ERROR)]);
        $pdo->commit(); return $result;
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    finally {
        try { if ($mode!==null) $pdo->prepare('SET SESSION sql_mode=?')->execute([$mode]); $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
        catch (Throwable $error) { error_log('Membership connection cleanup: '.$error->getMessage()); }
    }
}
