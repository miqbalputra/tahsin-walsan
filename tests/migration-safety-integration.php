<?php
/** Synthetic source on 13319, separate guarded recovery on 13318. Never reads app DB env. */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/migration_safety_helper.php';
require_once __DIR__ . '/../includes/autosave_helper.php';
require_once __DIR__ . '/../includes/autosave_migration_helper.php';
require_once __DIR__ . '/../includes/tahsin_migration_helper.php';
require_once __DIR__ . '/../includes/family_migration_helper.php';
$runtimePath = $argv[1] ?? '';
$runtime = json_decode(file_get_contents($runtimePath), true, 512, JSON_THROW_ON_ERROR);
$root = realpath($runtime['root']);
if (!$root || !str_contains(str_replace('\\', '/', $root), '/tahsin-migration-')) throw new RuntimeException('Owned test runtime required.');
$root .= '/run-' . bin2hex(random_bytes(4)); mkdir($root, 0700);
$pdo = new PDO('mysql:host=127.0.0.1;port=13319;charset=utf8mb4', 'root', 'tahsin_local_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
if (strtolower(rtrim(str_replace('\\', '/', $pdo->query('SELECT @@datadir')->fetchColumn()), '/')) !== strtolower(str_replace('\\', '/', realpath($runtime['source_dir'])))) throw new RuntimeException('Source guard mismatch.');
$database = 'tahsin_test_recovery_' . bin2hex(random_bytes(5));
$pdo->exec('CREATE DATABASE ' . safetyIdentifier($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE ' . safetyIdentifier($database)); $pdo->exec("SET time_zone='+07:00'");
$pdo->exec(file_get_contents(__DIR__ . '/fixtures/tahsin-schema.sql'));
$pdo->exec("CREATE TABLE holidays (id INT AUTO_INCREMENT PRIMARY KEY,tanggal DATE NOT NULL UNIQUE,keterangan VARCHAR(255));
INSERT INTO users(id,username,password,nama_lengkap,role) VALUES(2,'guru_recovery','unused','Guru Pemulihan','ustadz');
INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(1,'Bapak Pemulihan',2);
INSERT INTO wali_santri(id,nama_bapak,no_hp) VALUES(1,'Bapak Pemulihan','0810000001'),(2,'Ibu Pemulihan','0810000002');
INSERT INTO santri_detail(id,wali_santri_id,nama_anak,kelas) VALUES(1,1,'Anak Aktif','2A'),(2,1,'Kakak Lulus','Lulus');
INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(1,1)");
$pdo->exec('SET timestamp=' . (time() - 70 * 86400)); tahsinApplyMigration($pdo); $pdo->exec('SET timestamp=0');
autosaveApplyMigration($pdo); familyApplyMigration($pdo);
$pdo->exec("INSERT INTO family_guardians(id,source_namespace,source_parent_id,wali_santri_id,guardian_type,source_name,source_phone,source_address,source_active,account_active,snapshot_date)
VALUES(1,'fixture','0001',1,'father','Bapak Pemulihan','0810000001','',1,1,'2026-10-15'),(2,'fixture','0002',2,'mother','Ibu Pemulihan','0810000002','',1,1,'2026-10-15');
INSERT INTO family_students(id,source_namespace,source_student_id,nis,nama_anak,kelas,school_status,snapshot_date)
VALUES(1,'fixture','0001','001234','Anak Aktif','2A','active','2026-10-15'),(2,'fixture','0002','001235','Kakak Lulus','','graduated','2026-10-15');
INSERT INTO family_relations(guardian_id,student_id,relationship) VALUES(1,1,'father'),(1,2,'father'),(2,1,'mother'),(2,2,'mother');
INSERT INTO family_legacy_children(santri_detail_id,student_id) VALUES(1,1),(2,2);
CREATE TABLE recovery_edges(id INT PRIMARY KEY,note TEXT NULL,leading_zero VARCHAR(30),raw_bytes BLOB,precise_time DATETIME(6));
CREATE TABLE recovery_audit(id INT AUTO_INCREMENT PRIMARY KEY,edge_id INT,verb VARCHAR(20));
CREATE TRIGGER recovery_edge_insert AFTER INSERT ON recovery_edges FOR EACH ROW INSERT INTO recovery_audit(edge_id,verb) VALUES(NEW.id,'insert');
CREATE TRIGGER recovery_edge_update AFTER UPDATE ON recovery_edges FOR EACH ROW INSERT INTO recovery_audit(edge_id,verb) VALUES(NEW.id,'update');
CREATE FUNCTION recovery_label(v INT) RETURNS VARCHAR(30) DETERMINISTIC NO SQL RETURN CONCAT('hasil-',v);
CREATE PROCEDURE recovery_read() READS SQL DATA SELECT COUNT(*) FROM recovery_edges;
CREATE VIEW recovery_view AS SELECT id,recovery_label(id) AS label FROM recovery_edges;
CREATE EVENT recovery_disabled_event ON SCHEDULE EVERY 1 DAY DISABLE DO UPDATE recovery_edges SET note='must not run';
INSERT INTO recovery_edges VALUES(1,NULL,'000123',X'0001ff80','2026-10-18 08:42:00.123456'),(2,'','000124',X'ff0041','2026-10-18 08:42:00.000001')");
$date = tahsinLatestSession(tahsinNow()->format('Y-m-d'));
$entry = autosaveSessionState($pdo, 2, 1, $date)['entries'][0];
$payload = array_replace($entry['payload'], ['status' => 'H', 'jenis_materi' => 'Iqro', 'jilid' => '3']);
$draftRequest = ['operation_id' => 'recovery_draft_operation_001', 'program' => 'bapak', 'wali_id' => 1, 'payload' => $payload,
    'context_revision' => $entry['context_revision'], 'official_revision' => $entry['official_revision'], 'draft_revision' => $entry['draft_revision']];
$draftResponse = autosaveWrite($pdo, 2, 1, $date, $draftRequest);
$guard = $runtime['recovery_dir'] . '/.tahsin-recovery.json';
if (!file_exists($guard)) safetyJson($guard, ['purpose' => 'tahsin-recovery-only', 'port' => 13318]);
$variables = ['TAHSIN_SAFETY_DB_HOST' => '127.0.0.1', 'TAHSIN_SAFETY_DB_PORT' => '13319', 'TAHSIN_SAFETY_DB_DATABASE' => $database,
    'TAHSIN_SAFETY_DB_USERNAME' => 'root', 'TAHSIN_SAFETY_DB_PASSWORD' => 'tahsin_local_test',
    'TAHSIN_RECOVERY_DB_HOST' => '127.0.0.1', 'TAHSIN_RECOVERY_DB_PORT' => '13318', 'TAHSIN_RECOVERY_DB_USERNAME' => 'root',
    'TAHSIN_RECOVERY_DB_PASSWORD' => 'tahsin_recovery_test', 'TAHSIN_RECOVERY_GUARD_FILE' => $guard,
    'TAHSIN_SAFETY_DUMP_BINARY' => 'C:/Program Files/MariaDB 12.3/bin/mariadb-dump.exe',
    'TAHSIN_SAFETY_CLIENT_BINARY' => 'C:/Program Files/MariaDB 12.3/bin/mariadb.exe',
    'TAHSIN_SAFETY_BINLOG_BINARY' => 'C:/Program Files/MariaDB 12.3/bin/mariadb-binlog.exe'];
foreach ($variables as $key => $value) putenv($key . '=' . $value);
$checks = [];
function recoveryCheck(bool $condition, string $name): void { global $checks; if (!$condition) throw new RuntimeException($name); $checks[] = $name; }
function recoveryCLI(array $args, bool $success = true): array {
    $process = proc_open(array_merge([PHP_BINARY, '-d', 'extension=php_pdo_mysql.dll', __DIR__ . '/../scripts/migration-safety.php'], $args), [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    if ($success && $exit !== 0) throw new RuntimeException($error);
    if (!$success && $exit === 0) throw new RuntimeException('Expected rejection: ' . implode(' ', $args));
    return ['exit' => $exit, 'result' => json_decode($out ?: $error, true)];
}
$before = $pdo->query('SELECT COUNT(*) FROM presensi')->fetchColumn();
recoveryCLI(['backup', '--out=' . $root . '/missing-lock'], false);
recoveryCheck($pdo->query('SELECT COUNT(*) FROM presensi')->fetchColumn() === $before, 'Missing explicit maintenance flag rejects before mutation');
recoveryCLI(['backup', '--lock-writes', '--out=' . $root . '/backup']);
$backup = safetyReadPackage($root . '/backup', 'backup');
recoveryCheck(count($backup['baseline']['tables']) >= 22 && count($backup['baseline']['objects']) >= 12, 'All operational, family and autosave tables and DB objects inventoried');
recoveryCheck($backup['position']['format'] === 'ROW', 'Recoverable binlog position captured under write lock');
recoveryCheck(!glob($root . '/backup/*.cnf'), 'Native client credentials removed after backup');
recoveryCLI(['verify', '--backup=' . $root . '/backup']);
recoveryCLI(['backup', '--lock-writes', '--out=' . $root . '/backup'], false);
recoveryCheck(true, 'Existing backup cannot be overwritten');
$complete = array_replace($payload, ['halaman_dari' => '9', 'halaman_sampai' => '10', 'hasil_talaqqi' => 'Lulus']);
$officialRequest = array_replace($draftRequest, ['operation_id' => 'recovery_official_operation_002', 'payload' => $complete,
    'official_revision' => $draftResponse['official_revision'], 'draft_revision' => $draftResponse['draft_revision']]);
$officialResponse = autosaveWrite($pdo, 2, 1, $date, $officialRequest);
$pdo->exec("UPDATE recovery_edges SET note='Capaian Ibu – sudah diterima' WHERE id=1;
INSERT INTO recovery_edges VALUES(3,'Baru setelah backup','000125',X'0000ff','2026-10-18 08:43:00.987654');
DELETE FROM recovery_edges WHERE id=2");
recoveryCLI(['capture-delta', '--lock-writes', '--backup=' . $root . '/backup', '--out=' . $root . '/delta']);
recoveryCLI(['restore-drill', '--backup=' . $root . '/backup', '--out=' . $root . '/restore']);
$recovered = safetyConnect(array_replace(safetyConfig('TAHSIN_RECOVERY_DB_'), ['database' => $database]));
recoveryCheck(autosaveWrite($recovered, 2, 1, $date, $draftRequest) === $draftResponse, 'Draft and original idempotency receipt restored exactly');
recoveryCheck((int)$recovered->query('SELECT COUNT(*) FROM presensi')->fetchColumn() === 0, 'Restored partial draft remains excluded from reports');
recoveryCheck($recovered->query('SELECT raw_bytes FROM recovery_edges WHERE id=1')->fetchColumn() === "\x00\x01\xff\x80", 'Binary, NULL, empty text and precision pass canonical row hashes');
recoveryCheck((int)$recovered->query('SELECT COUNT(*) FROM recovery_audit')->fetchColumn() === 2, 'Triggers are installed after data loading, no duplicate history');
recoveryCheck((int)$recovered->query("SELECT COUNT(*) FROM family_children WHERE school_status='active'")->fetchColumn() === 2 && (int)$recovered->query("SELECT COUNT(*) FROM family_children WHERE school_status='graduated'")->fetchColumn() === 2, 'Father mother and active graduated siblings preserved');
recoveryCheck($recovered->query('SELECT nis FROM family_students WHERE id=1')->fetchColumn() === '001234', 'Leading zero in student identity preserved');
recoveryCheck($recovered->query('SELECT label FROM recovery_view WHERE id=1')->fetchColumn() === 'hasil-1', 'Views and stored functions restored and executable');
recoveryCLI(['restore-drill', '--backup=' . $root . '/backup', '--out=' . $root . '/restore-again'], false);
recoveryCheck(true, 'Restore refuses a recovery instance with existing application data');
$recovered = null;
recoveryCLI(['replay-drill', '--backup=' . $root . '/backup', '--delta=' . $root . '/delta', '--out=' . $root . '/replay']);
$recovered = safetyConnect(array_replace(safetyConfig('TAHSIN_RECOVERY_DB_'), ['database' => $database]));
recoveryCheck(autosaveWrite($recovered, 2, 1, $date, $officialRequest) === $officialResponse, 'Committed attendance after backup and lost response receipt recovered');
recoveryCheck((int)$recovered->query('SELECT COUNT(*) FROM presensi')->fetchColumn() === 1, 'Retry after replay does not duplicate official attendance');
recoveryCheck((int)$recovered->query('SELECT COUNT(*) FROM recovery_audit')->fetchColumn() === 4, 'Row binlog replay does not fire triggers twice');
recoveryCheck($recovered->query('SELECT note FROM recovery_edges WHERE id=1')->fetchColumn() === 'Capaian Ibu – sudah diterima' && !(int)$recovered->query('SELECT COUNT(*) FROM recovery_edges WHERE id=2')->fetchColumn(), 'Post-backup updates inserts and deletes recovered');
recoveryCLI(['replay-drill', '--backup=' . $root . '/backup', '--delta=' . $root . '/delta', '--out=' . $root . '/replay-again'], false);
recoveryCheck(true, 'Second replay is rejected before duplicating transactions');
mkdir($root . '/tampered', 0700);
foreach (new DirectoryIterator($root . '/backup') as $file) if ($file->isFile()) copy($file->getPathname(), $root . '/tampered/' . $file->getFilename());
file_put_contents($root . '/tampered/data.sql', "\n-- damaged\n", FILE_APPEND);
recoveryCLI(['verify', '--backup=' . $root . '/tampered'], false);
recoveryCheck(true, 'Damaged backup rejected by checksum before restore');
putenv('TAHSIN_RECOVERY_GUARD_FILE=' . $root . '/missing-guard');
recoveryCLI(['restore-drill', '--backup=' . $root . '/backup', '--out=' . $root . '/unsafe-recovery'], false);
recoveryCheck(true, 'Recovery without owned instance guard rejected');
safetyJson($root . '/integration-result.json', ['ok' => true, 'checks' => $checks, 'database' => $database]);
echo json_encode(['ok' => true, 'checks' => count($checks), 'evidence' => $root . '/integration-result.json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
