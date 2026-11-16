<?php
require_once __DIR__.'/data_quality_helper.php';

/** Append a decision or its inverse; original attendance and decisions stay intact. */
function dataQualityChooseRecord(PDO $pdo,int $actorId,int $waliId,string $session,string $program,string $fingerprint,?int $selectedId,string $note,string $operation,?int $undoId=null): int
{
    if ($pdo->inTransaction() || !dataQualitySchemaReady($pdo)) throw new ParticipantManagementException('Pemeriksaan data belum siap atau transaksi lain masih berjalan.');
    if ($waliId<1 || !tahsinDate($session) || tahsinSessionDate($session)!==$session || !in_array($program,['bapak','ibu','unknown'],true) || !preg_match('/^[a-f0-9]{64}$/D',$fingerprint) || !preg_match('/^[a-f0-9]{32}$/D',$operation) || trim($note)==='' || appTextLength($note)>2000) throw new ParticipantManagementException('Pilihan catatan resmi dan alasan belum valid.');
    $requestHash=hash('sha256',serialize([$actorId,$waliId,$session,$program,$fingerprint,$selectedId,$note,$undoId]));
    $pdo->beginTransaction();
    try {
        attendanceHistoryActor($pdo,$actorId,['admin','pj_tahfidz']);
        $q=$pdo->prepare('SELECT id FROM wali_santri WHERE id=? FOR UPDATE'); $q->execute([$waliId]);
        if (!$q->fetchColumn()) throw new ParticipantManagementException('Peserta tidak ditemukan.');
        $q=$pdo->prepare('SELECT * FROM tahsin_verifications WHERE operation_id=? FOR UPDATE'); $q->execute([$operation]);
        if ($old=$q->fetch(PDO::FETCH_ASSOC)) {
            if (!hash_equals($old['request_hash']??'',$requestHash)) throw new ParticipantManagementException('Operasi telah dipakai untuk pilihan lain.');
            $pdo->commit(); return (int)$old['id'];
        }
        $scope=tahsinScopedSession($pdo,$waliId,$session,$program,true); $records=$scope['records']; $previous=$scope['verification'];
        if (!$records || !hash_equals(tahsinFingerprint($records),$fingerprint)) throw new ParticipantManagementException('Catatan berubah sejak pemeriksaan dibuka. Periksa ulang sumber.');
        $action='select';
        if ($undoId!==null) {
            if (!$previous || (int)$previous['id']!==$undoId || !hash_equals($previous['fingerprint'],$fingerprint)) throw new ParticipantManagementException('Keputusan atau sumber telah berubah. Pembatalan belum dilakukan.');
            $q=$pdo->prepare('SELECT * FROM tahsin_verifications WHERE id=? AND wali_santri_id=? AND session_date=? FOR UPDATE'); $q->execute([$previous['previous_id']??0,$waliId,$session]); $prior=$q->fetch(PDO::FETCH_ASSOC)?:null;
            $selectedId=$prior['selected_presensi_id']??null; $action=$prior['decision_action']??($prior?'select':'reset');
            $fingerprint=$prior['fingerprint']??$fingerprint;
        } elseif ($selectedId!==null) {
            $matches=array_values(array_filter($records,static fn($r)=>(int)$r['id']===$selectedId));
            if (!$matches || $matches[0]['tanggal']>tahsinNow()->format('Y-m-d') || array_key_exists($matches[0]['tanggal'],tahsinHolidayMap($pdo))) throw new ParticipantManagementException('Pilih catatan pada program dan tanggal pelaksanaan yang sah.');
        }
        $q=$pdo->prepare('INSERT INTO tahsin_verifications(wali_santri_id,session_date,fingerprint,selected_presensi_id,decided_by,note,program_code,decision_action,previous_id,operation_id,request_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$waliId,$session,$fingerprint,$selectedId,$actorId,$note,$program,$action,$previous['id']??null,$operation,$requestHash]);
        $id=(int)$pdo->lastInsertId(); $pdo->commit(); return $id;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
