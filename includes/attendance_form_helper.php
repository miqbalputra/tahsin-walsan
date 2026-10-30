<?php
require_once __DIR__ . '/autosave_helper.php';

final class AttendanceFormException extends RuntimeException
{
    public function __construct(string $message, public int $httpStatus = 422) { parent::__construct($message); }
}

function attendanceFormReady(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT operation_id,actor_id,actor_name,halaqoh_id,tanggal,request_hash,before_json,after_json,result_json,created_at FROM presensi_form_operations LIMIT 0');
        return true;
    } catch (PDOException $error) {
        if (in_array($error->getCode(), ['42S02','42S22'], true)) return false;
        throw $error;
    }
}

function attendanceFormApplyMigration(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new RuntimeException('Jalankan migrasi formulir di luar transaksi data.');
    appRequireTransactionalTables($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS presensi_form_operations (
        operation_id CHAR(32) PRIMARY KEY, actor_id INT NOT NULL, actor_name VARCHAR(100) NOT NULL,
        halaqoh_id INT NOT NULL, tanggal DATE NOT NULL, request_hash CHAR(64) NOT NULL,
        before_json LONGTEXT NOT NULL, after_json LONGTEXT NOT NULL, result_json LONGTEXT NOT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        KEY idx_presensi_form_group(halaqoh_id,tanggal,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!attendanceFormReady($pdo)) throw new RuntimeException('Struktur jurnal formulir belum lengkap.');
    appRequireTransactionalTables($pdo, ['presensi_form_operations']);
}

/** Consistent view of roster, all session copies, decisions and unsent server drafts. */
function attendanceFormState(PDO $pdo, int $actorId, int $groupId, string $date, bool $lock = false): array
{
    if (!tahsinDate($date) || $date > tahsinNow()->format('Y-m-d')) throw new AttendanceFormException('Tanggal presensi tidak valid atau masih di masa depan.');
    $group = attendanceFetchOwnedHalaqoh($pdo,$groupId,$actorId,$date,$lock);
    if (!$group) throw new AttendanceFormException('Halaqoh bukan penugasan Anda pada sesi ini.',403);
    $ids = attendanceFetchActiveMemberIds($pdo,$groupId,$actorId,$lock,$date);
    $members = attendanceFetchActiveMembers($pdo,$groupId,$actorId,$date);
    $session = tahsinSessionDate($date); $end = (new DateTimeImmutable($session))->modify('+6 days')->format('Y-m-d');
    $holidays = array_filter(tahsinHolidayMap($pdo), static fn($day) => $day >= $session && $day <= $end, ARRAY_FILTER_USE_KEY); ksort($holidays);
    $sources = $decisions = $drafts = $revisions = $history = $identities = [];
    if ($ids) {
        $in = implode(',',array_fill(0,count($ids),'?')); $suffix = $lock ? ' FOR UPDATE' : '';
        $statement=$pdo->prepare("SELECT id,nama_bapak,created_at FROM wali_santri WHERE id IN ($in) ORDER BY id".$suffix); $statement->execute($ids); $identities=$statement->fetchAll(PDO::FETCH_ASSOC);
        $statement=$pdo->prepare("SELECT * FROM presensi WHERE wali_santri_id IN ($in) AND tanggal BETWEEN ? AND ? ORDER BY id".$suffix); $statement->execute([...$ids,$session,$end]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $sources[(int)$row['wali_santri_id']][]=$row;
        if (tahsinSchemaReady($pdo)) {
            $statement=$pdo->prepare("SELECT * FROM tahsin_verifications WHERE wali_santri_id IN ($in) AND session_date=? ORDER BY id".$suffix); $statement->execute([...$ids,$session]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $decisions[(int)$row['wali_santri_id']]=$row;
        }
        foreach (['drafts'=>['SELECT * FROM tahsin_autosave_drafts WHERE wali_id IN ('.$in.') AND session_date=? ORDER BY user_id,halaqoh_id,wali_id','wali_id'],
                  'revisions'=>['SELECT * FROM tahsin_autosave_revisions WHERE wali_id IN ('.$in.') AND session_date=? ORDER BY wali_id','wali_id']] as $kind=>[$query,$key]) {
            try {
                $statement=$pdo->prepare($query.$suffix); $statement->execute([...$ids,$session]);
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) ${$kind}[(int)$row[$key]][]=$row;
            } catch (PDOException $error) { if (!in_array($error->getCode(),['42S02','42S22'],true)) throw $error; }
        }
        try {
            $statement=$pdo->prepare("SELECT participant_ref,MAX(id) AS revision FROM presensi_history WHERE participant_ref IN ($in) GROUP BY participant_ref".$suffix); $statement->execute($ids);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $history[(int)$row['participant_ref']]=$row['revision'];
        } catch (PDOException $error) { if (!in_array($error->getCode(),['42S02','42S22'],true)) throw $error; }
    }
    $contextRevision=hash('sha256',serialize([$group,$date,$ids,$identities,$holidays,attendanceHistoryBoundary($pdo,$date)]));
    $entries=[]; $entryRevisions=[]; $conflicts=0;
    $allSuspects=tahsinSuspectIds($pdo,array_merge(...array_values($sources)));
    foreach ($ids as $id) {
        $source=$sources[$id]??[]; $decision=$decisions[$id]??null;
        $suspects=array_intersect_key($allSuspects,array_fill_keys(array_column($source,'id'),true)); ksort($suspects);
        $entryRevisions[$id]=hash('sha256',serialize([$source,$decision,$suspects,$history[$id]??null,$drafts[$id]??[],$revisions[$id]??[]]));
        $resolved=tahsinResolveSession($source,$decision,$suspects,$holidays);
        if (in_array($resolved['state'],['conflict','invalid_date','baseline'],true) || ($resolved['record'] && (int)$resolved['record']['halaqoh_id']!==$groupId)) $conflicts++;
        elseif ($resolved['record']) $entries[$id]=$resolved['record'];
    }
    return compact('group','date','ids','members','session','holidays','sources','entries','entryRevisions','contextRevision','conflicts');
}

function attendanceFormOpen(PDO $pdo, int $actorId, int $groupId, string $date): array
{
    $pdo->beginTransaction();
    try { $state=attendanceFormState($pdo,$actorId,$groupId,$date); $pdo->commit(); }
    catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    $token=bin2hex(random_bytes(16));
    $form=['actor_id'=>$actorId,'group_id'=>$groupId,'date'=>$date,'expires'=>time()+1800,'context'=>$state['contextRevision'],'revisions'=>$state['entryRevisions'],'token'=>$token];
    $forms=is_array($_SESSION['attendance_forms']??null)?$_SESSION['attendance_forms']:[];
    $forms=array_filter($forms,static fn($item)=>is_array($item)&&($item['expires']??0)>time()); $forms[$token]=$form;
    $_SESSION['attendance_forms']=array_slice($forms,-8,null,true);
    return ['token'=>$token,'form'=>$form,'state'=>$state];
}

function attendanceFormGet($token, int $actorId): array
{
    if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D',$token)) throw new AttendanceFormException('Buka kembali formulir presensi untuk memperoleh versi sumber terbaru.');
    $form=$_SESSION['attendance_forms'][$token]??null;
    if (!is_array($form) || $form['actor_id']!==$actorId || $form['expires']<=time()) throw new AttendanceFormException('Formulir berakhir atau berasal dari sesi lain. Isian belum disimpan; buka kembali formulir.');
    return $form;
}

function attendanceFormPack(array $state): string
{
    return json_encode(['context'=>$state['contextRevision'],'sources'=>array_map(static fn($rows)=>array_map('attendanceHistoryEncode',$rows),$state['sources'])],JSON_THROW_ON_ERROR);
}

function attendanceFormSave(PDO $pdo, array $form, array $entries, array $expected, string $operation, bool $finalize): array
{
    if ($pdo->inTransaction()) throw new RuntimeException('Selesaikan transaksi lain sebelum menyimpan formulir.');
    foreach ($entries as &$entry) $entry=attendanceReadPayload($entry);
    unset($entry); ksort($entries,SORT_NUMERIC); ksort($expected,SORT_NUMERIC);
    if (!preg_match('/^[a-f0-9]{32}$/D',$operation) || !$entries || array_keys($entries)!==array_keys($expected)) throw new AttendanceFormException('Isian atau versi sumber tidak lengkap. Buka kembali formulir.');
    foreach ($expected as $id=>$revision) if (!filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) || !is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D',$revision)) throw new AttendanceFormException('Versi catatan peserta tidak valid.');
    $requestHash=hash('sha256',serialize([$form['token'],$form['actor_id'],$form['group_id'],$form['date'],$entries,$expected,$finalize]));
    if (!attendanceFormReady($pdo)) throw new AttendanceFormException('Jurnal formulir belum disiapkan. Hubungi pengelola; catatan lama belum diubah.');
    attendanceHistoryRequireReady($pdo); $oldMode=appUseStrictSqlMode($pdo); $pdo->beginTransaction();
    try {
        $actor=attendanceHistoryActor($pdo,$form['actor_id'],['ustadz']);
        $receipt=$pdo->prepare('SELECT actor_id,request_hash,result_json FROM presensi_form_operations WHERE operation_id=? FOR UPDATE'); $receipt->execute([$operation]);
        if ($old=$receipt->fetch(PDO::FETCH_ASSOC)) {
            if ((int)$old['actor_id']!==$form['actor_id'] || !hash_equals($old['request_hash'],$requestHash)) throw new AttendanceFormException('ID pengiriman sudah digunakan untuk isian lain.',409);
            $pdo->commit(); return json_decode($old['result_json'],true,32,JSON_THROW_ON_ERROR);
        }
        $before=attendanceFormState($pdo,$form['actor_id'],$form['group_id'],$form['date'],true);
        if (!hash_equals($before['contextRevision'],$form['context'])) throw new AttendanceFormException('Penugasan, daftar peserta, atau kalender berubah. Isian tetap tersedia; bandingkan dengan formulir terbaru.',409);
        if ($finalize && array_keys($entries)!==$before['ids']) throw new AttendanceFormException('Daftar peserta tidak lengkap atau berubah. Tidak ada catatan yang disimpan.',409);
        foreach ($expected as $id=>$revision) {
            if (!isset($form['revisions'][$id],$before['entryRevisions'][$id]) || !hash_equals($form['revisions'][$id],$revision) || !hash_equals($before['entryRevisions'][$id],$revision)) throw new AttendanceFormException('Catatan atau draft berubah sejak ditampilkan. Isian Anda tetap tersedia. Buka formulir terbaru untuk membandingkan sebelum menyimpan.',409);
        }
        $result=attendanceSaveEntries($pdo,$form['group_id'],$form['actor_id'],$form['date'],$entries,$finalize);
        $after=attendanceFormState($pdo,$form['actor_id'],$form['group_id'],$form['date'],true);
        $result+=['operation_id'=>$operation,'context_revision'=>$after['contextRevision'],'entry_revisions'=>array_intersect_key($after['entryRevisions'],$entries)];
        $beforeJson=attendanceFormPack($before); $afterJson=attendanceFormPack($after);
        if (strlen($beforeJson)+strlen($afterJson)>8*1024*1024) throw new AttendanceFormException('Riwayat formulir terlalu besar. Hubungi pengelola; perubahan dibatalkan.');
        $pdo->prepare('INSERT INTO presensi_form_operations(operation_id,actor_id,actor_name,halaqoh_id,tanggal,request_hash,before_json,after_json,result_json) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$operation,$form['actor_id'],$actor['nama_lengkap'],$form['group_id'],$form['date'],$requestHash,$beforeJson,$afterJson,json_encode($result,JSON_THROW_ON_ERROR)]);
        $pdo->commit(); return $result;
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    finally { $pdo->prepare('SET SESSION sql_mode=?')->execute([$oldMode]); }
}

function attendanceFormAccept(array $form, array $expected, array $result): void
{
    foreach ($result['entry_revisions'] as $id=>$revision) {
        if (($_SESSION['attendance_forms'][$form['token']]['revisions'][$id]??null)===($expected[$id]??null)) $_SESSION['attendance_forms'][$form['token']]['revisions'][$id]=$revision;
    }
}
