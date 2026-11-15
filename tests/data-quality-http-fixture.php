<?php
/** Only generates or inspects synthetic databases on the dedicated test port. */
if (PHP_SAPI!=='cli') exit;
putenv('DATA_INDUK_ENABLED=0');
require_once __DIR__.'/../includes/session_decision_helper.php';
require_once __DIR__.'/../includes/autosave_migration_helper.php';
require_once __DIR__.'/../includes/tahsin_migration_helper.php';
require_once __DIR__.'/../includes/family_migration_helper.php';
$database=$argv[1]??('tahsin_test_quality_http_'.getmypid().'_'.bin2hex(random_bytes(4)));
if (!preg_match('/^tahsin_test_quality_http_[a-z0-9_]+$/D',$database)) throw new RuntimeException('Synthetic quality HTTP database required.');
$pdo=new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4','root','tahsin_local_test',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("SET time_zone='+07:00'");
if (isset($argv[1])) {
    $pdo->exec("USE `$database`");
    if (($argv[2]??'')==='later') $pdo->exec("UPDATE santri_detail SET kelas='3A' WHERE id=21; INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status) VALUES(30,1,20,'2026-11-15','A')");
    echo json_encode(['participants'=>$pdo->query('SELECT id,nama_bapak FROM wali_santri ORDER BY id')->fetchAll(),'children'=>$pdo->query('SELECT * FROM santri_detail ORDER BY id')->fetchAll(),'attendance'=>$pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll(),'undo_receipts'=>(int)$pdo->query('SELECT COUNT(*) FROM participant_merge_undo')->fetchColumn()]); exit;
}
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `$database`");
$pdo->exec(file_get_contents(__DIR__.'/fixtures/tahsin-schema.sql'));
$pdo->exec("CREATE TABLE holidays(id INT PRIMARY KEY,tanggal DATE,keterangan VARCHAR(255)) ENGINE=InnoDB;
CREATE TABLE activity_logs(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,username VARCHAR(50),action VARCHAR(100),description TEXT,ip_address VARCHAR(45),created_at DATETIME) ENGINE=InnoDB;
CREATE TABLE login_attempts(id INT AUTO_INCREMENT PRIMARY KEY,ip_address VARCHAR(45),username VARCHAR(50),attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE pengumuman(id INT PRIMARY KEY,judul VARCHAR(255),isi TEXT,is_aktif TINYINT,created_at DATETIME) ENGINE=InnoDB;");
$q=$pdo->prepare('INSERT INTO users(id,username,password,nama_lengkap,role) VALUES(?,?,?,?,?)');
foreach ([[1,'quality_admin','Admin','admin'],[2,'quality_pj','PJ','pj_tahfidz'],[3,'quality_teacher','Guru H9','ustadz'],[4,'quality_other','Guru H15','ustadz'],[5,'quality_reader','Pembaca','kepsek']] as [$id,$user,$name,$role]) $q->execute([$id,$user,password_hash('QualityTest-2026',PASSWORD_DEFAULT),$name,$role]);
$pdo->exec("INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(1,'H-9',3),(2,'H-15',4);
INSERT INTO wali_santri(id,nama_bapak,no_hp) VALUES(72,'Purwanto','081234567890'),(73,'Purwanto','+6281234567890'),(10,'Wali Kembar','08111111111'),(11,'Wali Kembar','08111111111'),(20,'Wali Undo','08222222222'),(21,'Wali Undo Sumber','08222222222');
INSERT INTO santri_detail(id,wali_santri_id,nama_anak,kelas) VALUES(72,72,'Zaidan Putra Arroyan','Mustawa 5 Ikhwan'),(10,10,'Anak Kembar','1A'),(11,11,'Anak Kembar','2A'),(20,20,'Anak Undo','1A'),(21,21,'Anak Undo Sumber','2A');
INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(1,72),(2,72);
INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status,alasan) VALUES(1,1,72,'2026-11-15','I','Bukan Halaqah 09'),(2,2,72,'2026-11-15','A',NULL);");
tahsinApplyMigration($pdo); autosaveApplyMigration($pdo); familyApplyMigration($pdo); participantMergeApplyMigration($pdo); dataQualityApplyMigration($pdo); attendanceHistoryApplyMigration($pdo);
$merge=bin2hex(random_bytes(16)); participantMergeApply($pdo,1,participantMergePlan($pdo,20,21),['confirmed'=>'1'],$merge);
echo json_encode(['database'=>$database,'merge'=>$merge]),PHP_EOL;
