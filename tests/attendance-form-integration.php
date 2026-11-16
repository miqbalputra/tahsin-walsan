<?php
if (PHP_SAPI!=='cli') exit;
putenv('DATA_INDUK_ENABLED=0');
require_once __DIR__.'/../includes/attendance_form_helper.php';
require_once __DIR__.'/../includes/tahsin_migration_helper.php';
require_once __DIR__.'/../includes/autosave_migration_helper.php';
$database='tahsin_test_forms_'.getmypid().'_'.bin2hex(random_bytes(4));
$pdo=new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4','root','tahsin_local_test',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `$database`; SET time_zone='+07:00'");
$pdo->exec(file_get_contents(__DIR__.'/fixtures/tahsin-schema.sql'));
$pdo->exec("INSERT INTO users(id,username,password,nama_lengkap,role) VALUES(1,'admin','synthetic','Admin Uji','admin'),(2,'teacher','synthetic','Guru Uji','ustadz'),(3,'other','synthetic','Guru Lain','ustadz');
INSERT INTO wali_santri(id,nama_bapak) VALUES(1,'Wali Lama'),(2,'Wali Kedua');
INSERT INTO santri_detail(id,wali_santri_id,nama_anak,kelas) VALUES(1,1,'Anak Lama','Mustawa 2');
INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(1,'Kelompok Lama',2),(2,'Kelompok Lain',3);
INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(1,1),(1,2);
ALTER TABLE presensi ADD extra_bytes VARBINARY(30), ADD extra_number DECIMAL(20,4);
INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status,alasan,jenis_materi,jilid,nama_surat,halaman,hasil_talaqqi,extra_bytes,extra_number) VALUES
(1,1,1,'2026-10-18','H','Sisa lama  ','Iqro',2,'Tidak dipakai  ',' 1-2 ','Lulus',UNHEX('00FF0041'),12345678901234.5678);");
tahsinApplyMigration($pdo); autosaveApplyMigration($pdo); attendanceHistoryApplyMigration($pdo);
$pdo->exec("UPDATE tahsin_tracking SET tracked_from='2026-10-01 00:00:00'; UPDATE tahsin_roster_history SET valid_from='2026-10-01 00:00:00'");
$_SESSION=[]; $checks=0;
function afCheck(bool $value,string $message): void { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
function afDigest(PDO $pdo): string {
    $tables=$pdo->query("SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $rows=[]; foreach ($tables as $table) $rows[$table]=$pdo->query("SELECT * FROM `$table` ORDER BY 1")->fetchAll();
    return hash('sha256',serialize($rows));
}
function afOpen(PDO $pdo): array { return attendanceFormOpen($pdo,2,1,'2026-10-18')['form']; }
function afSave(PDO $pdo,array $form,array $entries,?string $operation=null,bool $full=false): array {
    return attendanceFormSave($pdo,$form,$entries,array_intersect_key($form['revisions'],$entries),$operation??bin2hex(random_bytes(16)),$full);
}
function afReject(PDO $pdo,callable $action,string $message,?int $status=null): void {
    $before=afDigest($pdo);
    try { $action(); } catch (AttendanceFormException|AttendanceValidationException|AttendanceHistoryException|InvalidArgumentException $error) {
        afCheck(afDigest($pdo)===$before && ($status===null || ($error instanceof AttendanceFormException && $error->httpStatus===$status)),$message); return;
    }
    throw new LogicException('Expected rejection: '.$message);
}
$hadir=['status'=>'H','jenis_materi'=>'Iqro','jilid'=>'2','halaman_dari'=>'3','halaman_sampai'=>'4','hasil_talaqqi'=>'Lulus'];
$old=$pdo->query('SELECT * FROM presensi')->fetchAll();
afReject($pdo,fn()=>afSave($pdo,afOpen($pdo),[1=>$hadir]),'Missing receipt schema refuses writes');
attendanceFormApplyMigration($pdo); attendanceFormApplyMigration($pdo);
afCheck($pdo->query('SELECT * FROM presensi')->fetchAll()===$old,'Additive receipt migration and retry preserve original attendance');
$mode=$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
$form=afOpen($pdo); $op=bin2hex(random_bytes(16)); $result=afSave($pdo,$form,[1=>$hadir],$op); attendanceFormAccept($form,[1=>$form['revisions'][1]],$result);
$row=$pdo->query('SELECT * FROM presensi WHERE id=1')->fetch();
$expected=$old[0]; $expected['halaman']='3-4';
afCheck($row===$expected,'Editing changes only requested material; NULL, unused fields, spaces, bytes, decimal, date and ID survive');
$version=$pdo->query('SELECT * FROM presensi_history ORDER BY id DESC LIMIT 1')->fetch();
afCheck(attendanceHistoryDecode($version['before_json'])['halaman']===' 1-2 ' && (int)$version['actor_id']===2,'The complete original row and teacher identity remain in the version journal');
$receipt=$pdo->query('SELECT * FROM presensi_form_operations')->fetch(); $packed=json_decode($receipt['before_json'],true);
afCheck(attendanceHistoryDecode($packed['sources'][1][0])['extra_bytes']===hex2bin('00FF0041'),'Operation journal preserves binary original source copies');
$digest=afDigest($pdo);
afCheck(afSave($pdo,$form,[1=>$hadir],$op)===$result && afDigest($pdo)===$digest,'Lost-response retry is durable and does not mutate revisions/history');
afReject($pdo,fn()=>afSave($pdo,$form,[1=>array_replace($hadir,['halaman_sampai'=>'5'])],$op),'Operation reuse for another payload is rejected',409);
afReject($pdo,fn()=>afSave($pdo,$form,[1=>$hadir]),'An old form cannot overwrite a newer source',409);
$self=attendanceFormGet($form['token'],2);
afCheck($self['revisions'][1]===$result['entry_revisions'][1],'The originating form can acknowledge its own per-member save');
$noOp=afOpen($pdo); $raw=$pdo->query('SELECT * FROM presensi WHERE id=1')->fetch();
afSave($pdo,$noOp,[2=>['status'=>'I','alasan'=>'Keluarga'],1=>$hadir],null,true);
afCheck($pdo->query('SELECT * FROM presensi WHERE id=1')->fetch()===$raw,'Full finalization accepts an arbitrary display order and preserves an unchanged legacy row');
$stale=afOpen($pdo); $pdo->exec("UPDATE presensi SET halaman='5-6' WHERE id=1");
afReject($pdo,fn()=>afSave($pdo,$stale,[1=>$hadir,2=>['status'=>'A']],null,true),'Stale full finalization rejects every entry atomically',409);
afReject($pdo,fn()=>afSave($pdo,$stale,[1=>['intent'=>'reset']]),'A stale reset cannot delete a newer row',409);
$fresh=afOpen($pdo);
foreach ([['status'=>['H']],['status'=>'I','alasan'=>['bad']],array_replace($hadir,['jilid'=>'9']),array_replace($hadir,['halaman_dari'=>'0']),array_replace($hadir,['halaman_dari'=>'9','halaman_sampai'=>'2']),array_replace($hadir,['halaman_dari'=>"1\0"]),['status'=>'I','alasan'=>str_repeat('a',501)],['status'=>'I','alasan'=>"\xff"]] as $invalid) afReject($pdo,fn()=>afSave($pdo,$fresh,[1=>$invalid]),'Malformed/invalid attendance fails without writes');
$draftForm=afOpen($pdo);
$pdo->exec("INSERT INTO tahsin_autosave_drafts(user_id,halaqoh_id,session_date,wali_id,payload_json,official_revision,context_revision) VALUES(2,1,'2026-10-18',2,'{\"status\":\"H\"}','official','context')");
afReject($pdo,fn()=>afSave($pdo,$draftForm,[1=>$hadir,2=>['status'=>'']],null,true),'A new server draft blocks legacy Alfa finalization',409);
$rosterForm=afOpen($pdo); $pdo->exec("UPDATE halaqoh_members SET archived_at=NOW() WHERE wali_santri_id=2");
afCheck(attendanceFormState($pdo,2,1,'2026-10-18')['contextRevision']===$rosterForm['context'],'Archiving a current membership does not rewrite its legitimate historical roster');
$pdo->exec("UPDATE tahsin_roster_history SET valid_until='2026-10-18 00:00:00' WHERE wali_santri_id=2");
afReject($pdo,fn()=>afSave($pdo,$rosterForm,[1=>$hadir]),'A correction to this historical roster invalidates its bound context',409);
$pdo->exec('UPDATE tahsin_roster_history SET valid_until=NOW() WHERE wali_santri_id=2');
$pdo->exec('UPDATE halaqoh_members SET archived_at=NULL WHERE wali_santri_id=2');
$calendar=afOpen($pdo); $pdo->exec("CREATE TABLE holidays(id INT AUTO_INCREMENT PRIMARY KEY,tanggal DATE NOT NULL UNIQUE,keterangan VARCHAR(255)) ENGINE=InnoDB; INSERT INTO holidays(tanggal,keterangan) VALUES('2026-10-18','Libur uji')");
afReject($pdo,fn()=>afSave($pdo,$calendar,[1=>$hadir]),'A calendar change invalidates an existing form',409);
$pdo->exec('DELETE FROM holidays');
$failure=afOpen($pdo); $digest=afDigest($pdo);
$pdo->exec("CREATE TRIGGER reject_form_receipt BEFORE INSERT ON presensi_form_operations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic receipt failure'");
try { afSave($pdo,$failure,[1=>$hadir,2=>['intent'=>'reset']],null,true); throw new LogicException('Receipt failure was ignored'); }
catch (PDOException $error) { afCheck(afDigest($pdo)===$digest,'A mandatory receipt failure rolls back material, reset, history, revisions and all other tables'); }
$pdo->exec('DROP TRIGGER reject_form_receipt');
$reset=afOpen($pdo); $drafts=$pdo->query('SELECT * FROM tahsin_autosave_drafts')->fetchAll(); afSave($pdo,$reset,[1=>['intent'=>'reset']]);
afCheck((int)$pdo->query('SELECT COUNT(*) FROM presensi WHERE wali_santri_id=1')->fetchColumn()===0 && $pdo->query('SELECT * FROM tahsin_autosave_drafts')->fetchAll()===$drafts,'Reviewed reset keeps server drafts and the version journal');
$deleted=$pdo->query("SELECT before_json FROM presensi_history WHERE event='delete' AND presensi_id='1' ORDER BY id DESC LIMIT 1")->fetchColumn();
afCheck(attendanceHistoryDecode($deleted)['extra_bytes']===hex2bin('00FF0041'),'Deletion retains the original binary extension columns');
$revoked=afOpen($pdo); $pdo->exec("UPDATE users SET role='kepsek' WHERE id=2");
afReject($pdo,fn()=>afSave($pdo,$revoked,[2=>['status'=>'A']]),'The actor role is rechecked within the transaction');
afCheck(!$pdo->inTransaction() && $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn()===$mode && (int)$pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn()===1,'Success and failure paths restore SQL mode and preserve transaction/FK protections');
echo "PASS $checks versioned attendance form assertions\nSTAGING_DATABASE=$database\n";
