<?php
/** Isolated localhost database only; never reads app production credentials. */
if (PHP_SAPI !== 'cli') exit;
putenv('DATA_INDUK_ENABLED=0');
foreach (['family_helper','family_migration_helper','alumni_archive_helper','attendance_helper','report_helper'] as $file) require_once __DIR__ . '/../includes/' . $file . '.php';
$db='tahsin_test_family_'.getmypid();
$pdo=new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4','root','tahsin_local_test',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("SET time_zone = '+07:00'");
$pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $pdo->exec("USE `$db`");
$pdo->exec(file_get_contents(__DIR__.'/fixtures/tahsin-schema.sql'));
$pdo->exec('ALTER TABLE halaqoh_members ADD archived_at DATETIME NULL, ADD archive_reason VARCHAR(100) NULL');
$pdo->exec("INSERT INTO users(id,username,password,nama_lengkap,role) VALUES(1,'guru_test','unused','Guru Uji','ustadz')");
$pdo->exec("INSERT INTO wali_santri(id,nama_bapak,no_hp,alamat,kategori) VALUES(7,'Bapak Ahmad','08123456789','Alamat lama','askar'),(8,'Bapak Kembar','08000000000','Tidak diubah','reguler'),(9,'Bapak Kembar','08000000000','Tidak diubah','reguler')");
$pdo->exec("INSERT INTO santri_detail(id,wali_santri_id,nama_anak,kelas) VALUES(1,7,'Ahmad','1A'),(2,7,'Hasan','2A'),(3,7,'Anak Lokal Lain','3A')");
$pdo->exec("INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(1,'Bapak Uji',1),(2,'Ibu Uji',1)");
$pdo->exec('INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(1,7)');
$pdo->exec("INSERT INTO presensi(wali_santri_id,halaqoh_id,tanggal,status,jenis_materi,jilid,halaman,hasil_talaqqi) VALUES(7,1,'2026-10-18','H','Iqro',2,'3-4','Lulus')");
$checks=0;
function fmCheck(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function fmReject(callable $fn,string $message): void { try { $fn(); } catch (Throwable $e) { fmCheck(true,$message); return; } throw new RuntimeException($message); }
function fmLegacy(PDO $p): string { $r=[]; foreach(['wali_santri','santri_detail','halaqoh','halaqoh_members','presensi'] as $t) $r[$t]=$p->query("SELECT * FROM `$t` ORDER BY id")->fetchAll(); return json_encode($r); }
$before=fmLegacy($pdo);
fmCheck(familyChildrenTable($pdo)==='santri_detail','Legacy fallback');
familyApplyMigration($pdo); familyApplyMigration($pdo);
fmCheck($before===fmLegacy($pdo),'Migration preserves every existing row');
fmCheck(familyChildrenTable($pdo)==='family_children','Read view ready');
$g=fn($id,$name,$type,$phone='')=>['source_id'=>$id,'name'=>$name,'type'=>$type,'phone'=>$phone,'address'=>'Sumber','active'=>true,'account_active'=>true];
$s=fn($id,$name,$status='active')=>['source_id'=>$id,'nis'=>'00123'.$id,'name'=>$name,'status'=>$status,'class_name'=>'Mustawa 2 Ikhwan'];
$l=fn($gid,$sid,$role)=>['guardian_id'=>$gid,'student_id'=>$sid,'relationship'=>$role,'primary_contact'=>$role==='father'];
$package=['format'=>'tahsin-family-v1','source'=>'school-export','snapshot_date'=>'2026-10-15',
 'guardians'=>[$g('100','Bapak Ahmad','father','+628123456789'),$g('101','Ibu Aminah','mother'),$g('102','Bapak Kembar','father','08000000000')],
 'students'=>[$s('200','Ahmad'),$s('201','Hasan'),$s('202','Alumni','graduated'),$s('203','Anak Tanpa Relasi')],
 'links'=>[$l('100','200','father'),$l('101','200','mother'),$l('100','201','father'),$l('101','201','mother'),$l('101','202','mother')]];
$plan=familyImportPlan($pdo,$package);
fmCheck($plan['guardians'][100]['target_id']===7,'Strong match reuses old father ID');
fmCheck($plan['conflicts']===1,'Ambiguous identity needs a choice');
$familyRows=$plan['families'];
$ahmadFamily=array_values(array_filter($familyRows,fn($f)=>in_array('200',array_column($f['students'],'source_id'),true)))[0];
fmCheck(count($familyRows)===3,'Preview groups exact sibling parent sets into families');
fmCheck(array_column($ahmadFamily['students'],'source_id')===['200','201'],'Siblings appear together in one family row');
fmCheck($ahmadFamily['father_ids']===['100'] && $ahmadFamily['mother_ids']===['101'],'Family preview shows father and mother source identities');
fmCheck(count(array_filter($familyRows,fn($f)=>$f['students'][0]['source_id']==='203' && !$f['father_ids'] && !$f['mother_ids']))===1,'Child without source parent links remains visible as its own family row');
$twoOrphans=$package; $twoOrphans['students'][]=$s('204','Anak Tanpa Relasi Kedua');
$orphanRows=array_values(array_filter(familyImportPreviewRows(familyValidatePackage($twoOrphans)),fn($f)=>!$f['father_ids'] && !$f['mother_ids'] && !$f['guardian_ids']));
fmCheck(count($orphanRows)===2,'Unlinked children are never assumed to be siblings');
fmCheck($before===fmLegacy($pdo),'Preview is read-only');
fmReject(fn()=>familyImportApply($pdo,$package),'Conflict rejects entire import');
fmCheck((int)$pdo->query('SELECT COUNT(*) FROM family_students')->fetchColumn()===0,'No partial write');
$result=familyImportApply($pdo,$package,['102'=>'8']);
fmCheck($result['created']===1 && $result['reused']===2,'Only mother created; historical fathers reused');
$official=$pdo->query('SELECT * FROM presensi')->fetchAll();
fmCheck($official[0]['wali_santri_id']===7 && $official[0]['halaman']==='3-4','Father achievement intact');
fmCheck((int)$pdo->query('SELECT COUNT(*) FROM family_students')->fetchColumn()===4,'One identity per student including orphan');
fmCheck((int)$pdo->query('SELECT COUNT(*) FROM santri_detail')->fetchColumn()===3,'Legacy child rows intact');
fmCheck((int)$pdo->query('SELECT COUNT(*) FROM family_children WHERE wali_santri_id=7')->fetchColumn()===3,'Shared children deduplicated and unmatched local child retained');
$mother=(int)$pdo->query("SELECT wali_santri_id FROM family_guardians WHERE guardian_type='mother'")->fetchColumn();
fmCheck($mother!==7,'Parents are distinct participants');
fmCheck((int)$pdo->query("SELECT COUNT(*) FROM family_children WHERE wali_santri_id=$mother")->fetchColumn()===3,'Mother displays siblings and alumni');
fmCheck(waliHasActiveSchoolChild($pdo,$mother),'Mother eligible via active child');
fmCheck((int)$pdo->query("SELECT COUNT(*) FROM halaqoh_members WHERE wali_santri_id=$mother")->fetchColumn()===0,'No invented halaqoh membership');
fmCheck(familyIsManaged($pdo,7) && familyIsManaged($pdo,$mother),'Family identity protected from local edits');
fmReject(fn()=>familyAssertLocalChildrenWritable($pdo,[1]),'Local class edits cannot override master');
putenv('DATA_INDUK_ENABLED=1');
fmReject(fn()=>familyImportApply($pdo,$package),'Two identity writers cannot overwrite each other');
putenv('DATA_INDUK_ENABLED=0');
fmCheck($pdo->query('SELECT kategori FROM wali_santri WHERE id=7')->fetchColumn()==='askar','Father category retained');
fmCheck($pdo->query('SELECT alamat FROM wali_santri WHERE id=7')->fetchColumn()==='Alamat lama','Father biodata retained');
$once=fmLegacy($pdo); $again=familyImportApply($pdo,$package);
fmCheck($again['created']===0 && $once===fmLegacy($pdo),'Retry idempotent');
$family=familyFetchStudents($pdo,50,0,'Ahmad');
$ahmad=array_values(array_filter($family['students'],fn($s)=>$s['source_student_id']==='200'))[0];
fmCheck(count($ahmad['guardians'])===2,'API returns two parents for one child');
fmCheck(familyFetchStudents($pdo,50,0,'Ibu Aminah')['total']===3,'Search by parent');
fmCheck(familyFetchStudents($pdo,1,0)['total']===4 && count(familyFetchStudents($pdo,1,0)['students'])===1,'Pagination');
$pdo->exec("INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(2,$mother)");
$roster=attendanceFetchActiveMembers($pdo,2,1);
fmCheck(count($roster)===1 && str_contains($roster[0]['info_anak'],'Ahmad'),'Teacher sees mother children');
$filters=['start_date'=>'2026-10-01','end_date'=>'2026-10-31','kelas'=>'Mustawa 2 Ikhwan','search'=>'Ahmad'];
fmCheck(count(fetchPresensiReport($pdo,$filters))===1,'Child relation never multiplies official records');
foreach(['duplicate','orphan','type','older','same-date','claim','partial'] as $case) {
 $bad=$package;
 if($case==='duplicate') $bad['students'][]=$bad['students'][0];
 if($case==='orphan') $bad['links'][0]['student_id']='missing';
 if($case==='type') $bad['links'][0]['relationship']='mother';
 if($case==='older') $bad['snapshot_date']='2026-10-14';
 if($case==='same-date') $bad['students'][0]['name']='Changed';
 if($case==='claim') { $bad['snapshot_date']='2026-10-16'; $bad['guardians'][]=$g('103','Other Person','mother'); }
 if($case==='partial') { $bad['snapshot_date']='2026-10-16'; array_pop($bad['students']); }
 fmReject(fn()=>familyImportApply($pdo,$bad,$case==='claim'?['103'=>'7']:[]),'Reject '.$case);
}
$next=$package; $next['snapshot_date']='2026-10-16'; $next['students'][2]['status']='withdrawn';
$next['links']=array_values(array_filter($next['links'],fn($l)=>!($l['guardian_id']==='100' && $l['student_id']==='201')));
familyImportApply($pdo,$next);
fmCheck((int)$pdo->query("SELECT COUNT(*) FROM family_children WHERE wali_santri_id=7 AND nama_anak='Hasan'")->fetchColumn()===0,'Removed source relation cannot reappear through legacy row');
fmCheck($pdo->query("SELECT school_status FROM family_students WHERE source_student_id='202'")->fetchColumn()==='withdrawn','School status preserved explicitly');
fmCheck($official===$pdo->query('SELECT * FROM presensi')->fetchAll(),'All imports leave attendance byte-identical');
echo "PASS $checks family integration assertions\nSTAGING_DATABASE=$db\n";
