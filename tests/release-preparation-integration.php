<?php
/** Synthetic localhost-only rollout and backup recovery drill. */
if (PHP_SAPI!=='cli') exit;
putenv('DATA_INDUK_ENABLED=0');
require_once __DIR__.'/../includes/release_preparation_helper.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4','root','tahsin_local_test',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$suffix=getmypid().'_'.bin2hex(random_bytes(4)); $source='tahsin_test_release_'.$suffix; $target='tahsin_release_stage_'.$suffix;
$checks=0;
function rc(bool $ok,string $message):void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
try {
    $pdo->exec("CREATE DATABASE `$source`; USE `$source`; SET time_zone='+07:00'");
    $pdo->exec(file_get_contents(__DIR__.'/fixtures/tahsin-schema.sql'));
    $pdo->exec("CREATE TABLE holidays(id INT PRIMARY KEY,tanggal DATE,keterangan VARCHAR(255)) ENGINE=InnoDB;
        CREATE TABLE activity_logs(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,username VARCHAR(50),action VARCHAR(100),description TEXT,created_at DATETIME) ENGINE=InnoDB;
        INSERT INTO users(id,username,password,nama_lengkap,role) VALUES(1,'release_teacher','synthetic','Guru','ustadz');
        INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(1,'H9',1);
        INSERT INTO wali_santri(id,nama_bapak) VALUES(1,'Synthetic');
        INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(1,1);
        INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status,alasan) VALUES(1,1,1,'2026-11-15','H','Original');");
    autosaveApplyMigration($pdo); familyApplyMigration($pdo);
    $pdo->exec("INSERT INTO tahsin_autosave_revisions VALUES(1,'2026-11-15',7)");
    $original=releaseDatabaseSnapshot($pdo);
    $pdo->exec("CREATE DATABASE `$target`");
    $stage=new PDO('mysql:host=127.0.0.1;port=13317;dbname='.$target.';charset=utf8mb4','root','tahsin_local_test',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $proof=releaseVerifyClone($pdo,$stage,sys_get_temp_dir().'/tahsin-release-test-'.$suffix);
    rc($proof['verified'] && is_file($proof['backup']),'Backup restored and migrations verified on separate schema');
    rc(releaseDatabaseSnapshot($pdo)===$original,'Verification never changes source');
    rc(releaseDatabaseSnapshot($stage,$original)===$original,'Clone migrations preserve every original row and column');
    rc(dataQualitySchemaReady($stage),'Clone ready for quality and safe undo');
    tahsinApplyMigration($pdo);
    $records=$pdo->query('SELECT * FROM presensi ORDER BY id')->fetchAll();
    $q=$pdo->prepare("INSERT INTO tahsin_verifications(wali_santri_id,session_date,fingerprint,selected_presensi_id,decided_by,note) VALUES(1,'2026-11-15',?,1,1,'Legacy decision')");
    $q->execute([tahsinFingerprint($records)]);
    $legacy=autosaveSessionState($pdo,1,1,'2026-11-15')['entries'][0];
    releaseInstallJournals($pdo);
    $current=autosaveSessionState($pdo,1,1,'2026-11-15')['entries'][0];
    rc($legacy['official_revision']===$current['official_revision'],'Legacy verification and revision keep the same draft receipt after rollout');
    rc(releaseDatabaseSnapshot($pdo,$original)===$original,'Apply preserves source original values');
    rc((int)$pdo->query('SELECT revision FROM tahsin_program_revisions')->fetchColumn()===7,'Legacy global revision seeds program counter');
    $pdo->exec("UPDATE presensi SET alasan='New edit' WHERE id=1");
    $advanced=(int)$pdo->query('SELECT revision FROM tahsin_program_revisions')->fetchColumn();
    dataQualityApplyMigration($pdo);
    rc((int)$pdo->query('SELECT revision FROM tahsin_program_revisions')->fetchColumn()===$advanced && $advanced>7,'Rerun never lowers live revision');
    try { releaseVerifyClone($pdo,$pdo,sys_get_temp_dir()); throw new LogicException('Unsafe clone accepted'); }
    catch (RuntimeException $e) { if ($e instanceof LogicException) throw $e; $checks++; }
    echo "PASS: $checks release preparation assertions\n";
} finally {
    // These names are generated here, restricted to disposable test schemas.
    foreach ([$target,$source] as $name) if (preg_match('/^(tahsin_release_stage_|tahsin_test_release_)[0-9]+_[a-f0-9]+$/D',$name)) $pdo->exec("DROP DATABASE IF EXISTS `$name`");
}
