<?php
/** Isolated local database only; this test does not read production DB settings. */
if (PHP_SAPI !== 'cli') exit;
putenv('DATA_INDUK_ENABLED=0');
$sessionDirectory = __DIR__ . '/../.sessions/audit-20261023/http-sessions';
if (!is_dir($sessionDirectory)) mkdir($sessionDirectory, 0700, true);
putenv('SESSION_SAVE_PATH=' . $sessionDirectory);
require_once __DIR__ . '/../includes/auth_helper.php';
require_once __DIR__ . '/../includes/user_helper.php';
require_once __DIR__ . '/../includes/halaqoh_helper.php';
require_once __DIR__ . '/../includes/attendance_history_helper.php';
$database = 'tahsin_test_security_' . getmypid() . '_' . bin2hex(random_bytes(4));
$pdo = new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4', 'root', 'tahsin_local_test', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$database`");
$pdo->exec(file_get_contents(__DIR__ . '/fixtures/tahsin-schema.sql'));
$pdo->exec("CREATE TABLE activity_logs (id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,username VARCHAR(50),action VARCHAR(100),description TEXT,ip_address VARCHAR(45),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE login_attempts (id INT AUTO_INCREMENT PRIMARY KEY,ip_address VARCHAR(45),username VARCHAR(50),attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE pengumuman (id INT AUTO_INCREMENT PRIMARY KEY,judul VARCHAR(255),isi TEXT,is_aktif TINYINT DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE holidays(id INT AUTO_INCREMENT PRIMARY KEY,tanggal DATE NOT NULL UNIQUE,keterangan VARCHAR(255),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
$insert = $pdo->prepare('INSERT INTO users(id,username,password,nama_lengkap,role) VALUES(?,?,?,?,?)');
foreach ([[1,'audit_admin','Admin Uji','admin'],[2,'audit_pj','PJ Uji','pj_tahfidz'],[3,'audit_teacher','Guru Uji','ustadz'],[4,'audit_reader','Kepala Sekolah Uji','kepsek']] as [$id,$username,$name,$role]) {
    $insert->execute([$id,$username,password_hash('AuditTest-2026', PASSWORD_DEFAULT),$name,$role]);
}
$pdo->exec("INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(1,'Kelompok Uji',3);
INSERT INTO wali_santri(id,nama_bapak) VALUES(1,'Wali Uji');
INSERT INTO santri_detail(wali_santri_id,nama_anak,kelas) VALUES(1,'Anak Uji','1A');
INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(1,1);
INSERT INTO presensi(halaqoh_id,wali_santri_id,tanggal,status) VALUES(1,1,'2026-10-18','H')");
attendanceHistoryApplyMigration($pdo);
$checks = 0;
function secCheck(bool $condition, string $message): void {
    global $checks; if (!$condition) throw new RuntimeException($message); $checks++;
}
function secReject(callable $operation, string $message): void {
    try { $operation(); } catch (UserManagementException | HalaqohManagementException | InvalidArgumentException $error) { secCheck(true,$message); return; }
    throw new RuntimeException($message);
}
function secUsers(PDO $pdo): string { return json_encode($pdo->query('SELECT * FROM users ORDER BY id')->fetchAll()); }
function secRecords(PDO $pdo): string {
    $records=[]; foreach (['wali_santri','santri_detail','halaqoh','halaqoh_members','presensi'] as $table) {
        $records[$table]=$pdo->query("SELECT * FROM `$table` ORDER BY id")->fetchAll();
    } return json_encode($records);
}
$heldLoginLocks = loginAcquireAttemptLocks($pdo, 'CaseSensitiveUser', '203.0.113.41', 0);
$parallelPdo = new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4', 'root', 'tahsin_local_test', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$sameUserLock = loginAcquireAttemptLocks($parallelPdo, 'casesensitiveuser', '203.0.113.42', 0);
secCheck($heldLoginLocks !== null && $sameUserLock === null, 'Concurrent attempts for one username serialize across different IPs');
loginReleaseAttemptLocks($pdo, $heldLoginLocks ?? []);
$heldIpLocks = loginAcquireAttemptLocks($pdo, 'first_user', '203.0.113.43', 0);
$sameIpLock = loginAcquireAttemptLocks($parallelPdo, 'second_user', '203.0.113.43', 0);
secCheck($heldIpLocks !== null && $sameIpLock === null, 'Concurrent attempts from one IP serialize across different usernames');
loginReleaseAttemptLocks($pdo, $heldIpLocks ?? []);
$rateUser = 'rate_limit_synthetic'; $rateIp = '192.0.2.241';
for ($attempt = 1; $attempt <= MAX_LOGIN_ATTEMPTS; $attempt++) {
    $result = authenticateLoginAttempt($pdo, $rateUser, 'wrong password', $rateIp);
    secCheck($result['state'] === 'failed' && $result['remaining_attempts'] === MAX_LOGIN_ATTEMPTS - $attempt, 'Failed login is counted at attempt ' . $attempt);
}
$lockedLogin = authenticateLoginAttempt($pdo, $rateUser, 'wrong password', $rateIp);
$attemptCount = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE username=? AND ip_address=?');
$attemptCount->execute([$rateUser, $rateIp]);
secCheck($lockedLogin['state'] === 'blocked' && (int)$attemptCount->fetchColumn() === MAX_LOGIN_ATTEMPTS, 'Lockout rejects another attempt at the configured threshold');
$pdo->prepare('DELETE FROM login_attempts WHERE username=? AND ip_address=?')->execute([$rateUser, $rateIp]);
$pdo->prepare('INSERT INTO login_attempts(ip_address,username) VALUES(?,?)')->execute(['192.0.2.242','audit_admin']);
$validLogin = authenticateLoginAttempt($pdo, 'audit_admin', 'AuditTest-2026', '192.0.2.242');
$attemptCount->execute(['audit_admin', '192.0.2.242']);
secCheck($validLogin['state'] === 'success' && (int)$attemptCount->fetchColumn() === 0, 'Successful login clears its attempt history');
$recordsBefore = secRecords($pdo);
$usersBefore = secUsers($pdo);
secReject(fn()=>deleteEmptyHalaqoh($pdo,1),'Referenced group cannot be deleted or detached from attendance');
secCheck((int)$pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn()===1,'Foreign key checks remain enabled');
secReject(fn()=>saveManagedHalaqoh($pdo,['nama_halaqoh'=>'Invalid teacher','ustadz_id'=>'1']),'Only a teaching account can be assigned');
secReject(fn()=>saveManagedHalaqoh($pdo,['nama_halaqoh'=>['invalid'],'ustadz_id'=>'3']),'Malformed group name rejected safely');
$emptyGroup=saveManagedHalaqoh($pdo,['nama_halaqoh'=>"Kelompok 'A'",'ustadz_id'=>'3']);
secCheck($pdo->query('SELECT nama_halaqoh FROM halaqoh WHERE id='.$emptyGroup)->fetchColumn()==="Kelompok 'A'",'Group name punctuation is preserved');
deleteEmptyHalaqoh($pdo,$emptyGroup);
secCheck(!$pdo->query('SELECT id FROM halaqoh WHERE id='.$emptyGroup)->fetchColumn(),'Unreferenced empty group can be deleted normally');
$new = ['username'=>'new_user','nama_lengkap'=>'Pengguna Baru','role'=>'ustadz','no_hp'=>'081234567890','password'=>'NewPassword-2026'];
foreach (['role'=>'superuser','username'=>['array'],'password'=>'short','nama_lengkap'=>'','id'=>'-1'] as $field=>$value) {
    $invalid=$new; $invalid[$field]=$value;
    secReject(fn()=>saveManagedUser($pdo,1,$invalid), 'Invalid request rejected: '.$field);
    secCheck(secUsers($pdo)===$usersBefore,'Rejected request preserves all user rows');
}
secReject(fn()=>saveManagedUser($pdo,4,$new),'Read-only role cannot create accounts');
$escalation=$new; $escalation['role']='admin';
secReject(fn()=>saveManagedUser($pdo,2,$escalation),'PJ cannot create admin accounts');
$selfEscalation=$escalation; $selfEscalation['id']='2'; $selfEscalation['username']='audit_pj';
secReject(fn()=>saveManagedUser($pdo,2,$selfEscalation),'PJ cannot promote itself to admin');
secReject(fn()=>deleteManagedUser($pdo,1,1),'Own account cannot be deleted');
secReject(fn()=>deleteManagedUser($pdo,2,1),'Last admin cannot be deleted by another manager');
$demote=$new; $demote['id']='1'; $demote['username']='audit_admin';
secReject(fn()=>saveManagedUser($pdo,2,$demote),'Last admin cannot be demoted');
secReject(fn()=>deleteManagedUser($pdo,1,3),'Group teacher deletion preserves references');
secCheck(secUsers($pdo)===$usersBefore,'Rejected operations leave users intact');
$newId=saveManagedUser($pdo,1,$new);
$passwordHash=$pdo->query("SELECT password FROM users WHERE id=$newId")->fetchColumn();
secCheck(password_verify($new['password'],$passwordHash),'New password stored as a usable hash');
$edit=$new; $edit['id']=(string)$newId; $edit['password']=''; $edit['nama_lengkap']='Nama Diperbarui';
saveManagedUser($pdo,1,$edit);
secCheck($pdo->query("SELECT password FROM users WHERE id=$newId")->fetchColumn()===$passwordHash,'Empty password preserves existing hash');
$duplicate=$edit; $duplicate['username']='audit_admin';
secReject(fn()=>saveManagedUser($pdo,1,$duplicate),'Duplicate username rejected without partial write');
secCheck($pdo->query("SELECT nama_lengkap FROM users WHERE id=$newId")->fetchColumn()==='Nama Diperbarui','Valid edit persisted');
foreach (listManagedUsers($pdo) as $publicUser) {
    secCheck(!array_key_exists('password',$publicUser),'Public projection contains no password hash');
}
secReject(fn()=>deleteManagedUser($pdo,1,$newId,false),'Account deletion requires explicit confirmation');
secCheck((int)$pdo->query("SELECT COUNT(*) FROM users WHERE id=$newId")->fetchColumn()===1,'Unconfirmed account deletion preserves the existing user row');
$_SESSION=['user_id'=>3,'role'=>'admin','username'=>'forged'];
secCheck(refreshAuthenticatedSession($pdo) && $_SESSION['role']==='ustadz','Role is refreshed from the database');
$pdo->exec("UPDATE users SET role='kepsek' WHERE id=3");
secCheck(refreshAuthenticatedSession($pdo) && $_SESSION['role']==='kepsek','Permission change applies to an existing session');
$pdo->prepare('UPDATE users SET password=? WHERE id=3')->execute([password_hash('ChangedPassword-2026',PASSWORD_DEFAULT)]);
secCheck(!refreshAuthenticatedSession($pdo),'Password change invalidates the old session');
$pdo->exec("UPDATE users SET role='ustadz' WHERE id=3");
$_SESSION=['user_id'=>$newId];
secCheck(refreshAuthenticatedSession($pdo),'Valid legacy session adopts a fingerprint');
deleteManagedUser($pdo,1,$newId,true);
secCheck(!refreshAuthenticatedSession($pdo),'Deleted account cannot keep using its session');
$_SESSION=['user_id'=>['invalid']];
secCheck(!refreshAuthenticatedSession($pdo),'Malformed session identity is rejected');
$_SESSION=['csrf_token'=>['invalid']];
$csrf=generateCsrfToken();
secCheck(strlen($csrf)===64 && verifyCsrfToken($csrf),'Malformed stored CSRF token is replaced safely');
secCheck(!verifyCsrfToken(['invalid']) && !verifyCsrfToken(null),'Malformed CSRF input is denied without TypeError');
secCheck(normalizeDateInput(['invalid'],'fallback')==='fallback','Malformed date returns the defined fallback');
secCheck(secRecords($pdo)===$recordsBefore,'Account changes preserve every participant, child, group, membership and attendance row');
secCheck(!$pdo->inTransaction(),'No transaction is left open after successful or rejected operations');
$pdo->prepare('UPDATE users SET password=? WHERE id=3')->execute([password_hash('AuditTest-2026',PASSWORD_DEFAULT)]);
file_put_contents(__DIR__ . '/../.sessions/audit-20261023/test-database.txt', $database);
echo "PASS $checks security/account assertions\nSTAGING_DATABASE=$database\n";
