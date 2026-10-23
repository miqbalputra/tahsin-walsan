/** HTTP contracts against a new localhost fixture; no production requests. */
const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const root = path.resolve(__dirname, '..');
const php = process.env.TEST_PHP_BINARY || 'php';
const setup = spawnSync(php, ['-d','extension=pdo_mysql','tests/security-integration.php'], {cwd:root,encoding:'utf8',windowsHide:true});
if (setup.status !== 0) throw Error(setup.stderr || setup.stdout || 'Fixture creation failed');
process.stdout.write(setup.stdout);
const database = setup.stdout.match(/STAGING_DATABASE=(tahsin_test_security_\d+_[a-f0-9]{8})/)?.[1];
assert.ok(database, 'Only a newly generated isolated fixture is allowed');
const port = 18086;
const base = `http://127.0.0.1:${port}`;
const env = {...process.env, DB_HOST:'127.0.0.1', DB_PORT:'13317', DB_DATABASE:database,
  DB_USERNAME:'root', DB_PASSWORD:'tahsin_local_test', DATA_INDUK_ENABLED:'0',
  SESSION_SAVE_PATH:path.join(root,'.sessions','audit-20261023',`http-sessions-${process.pid}`),
  BACKUP_STORAGE_PATH:path.join(root,'.sessions','audit-20261023',`http-backups-${process.pid}`),
  HERMES_API_KEY:'audit-fixture-key', N8N_API_KEY:'audit-fixture-key', TAHSIN_AUTOSAVE_ENABLED:'0'};
fs.mkdirSync(env.SESSION_SAVE_PATH,{recursive:true});
const server = spawn(php, ['-d','extension=pdo_mysql','-d','display_errors=0','-S',`127.0.0.1:${port}`,'dev-router.php'],
  {cwd:root,env,windowsHide:true,stdio:['ignore','ignore','pipe']});
let serverErrors = '';
server.stderr.on('data', bytes => { serverErrors += bytes.toString(); });
let checks = 0;
function check(condition, message) { assert.ok(condition,message); checks++; }
async function req(route, jar, body) {
  const response = await fetch(base+route, {redirect:'manual', method:body ? 'POST':'GET',
    headers: {...(jar?.cookie ? {Cookie:jar.cookie}:{}), ...(body ? {'Content-Type':'application/x-www-form-urlencoded'}:{})},
    ...(body ? {body: new URLSearchParams(body)}:{})});
  const cookie = response.headers.get('set-cookie')?.match(/PHPSESSID=[^;]+/);
  if (cookie && jar) jar.cookie = cookie[0];
  return {status:response.status,headers:response.headers,body:await response.text()};
}
const csrf = html => html.match(/name="csrf_token" value="([a-f0-9]{64})"/)?.[1];
async function login(username) {
  const jar = {};
  const page = await req('/login.php',jar);
  const response = await req('/login.php',jar,{csrf_token:csrf(page.body),username,password:'AuditTest-2026'});
  check(response.status===302,`${username} can log in`);
  return jar;
}
function localSql(sql) {
  const program = '$p=new PDO("mysql:host=127.0.0.1;port=13317;dbname='+database+';charset=utf8mb4","root","tahsin_local_test",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$p->exec(base64_decode("'+Buffer.from(sql).toString('base64')+'"));';
  const result = spawnSync(php,['-d','extension=pdo_mysql','-r',program],{cwd:root,encoding:'utf8',windowsHide:true});
  assert.equal(result.status,0,result.stderr);
}
function domainDigest() {
  const code = '$p=new PDO("mysql:host=127.0.0.1;port=13317;dbname='+database+';charset=utf8mb4","root","tahsin_local_test",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$rows=[];foreach(["wali_santri","santri_detail","halaqoh","halaqoh_members","presensi"] as $t){$rows[$t]=$p->query("SELECT * FROM `$t` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);}echo hash("sha256",json_encode($rows));';
  const result = spawnSync(php,['-d','extension=pdo_mysql','-r',code],{cwd:root,encoding:'utf8',windowsHide:true});
  assert.equal(result.status,0,result.stderr);
  return result.stdout;
}
async function previewBackup(jar, token, bytes) {
  const body = new FormData();
  body.set('csrf_token',token); body.set('action','preview');
  body.set('backup_file',new Blob([bytes],{type:'application/zip'}),'fixture.zip');
  const response = await fetch(base+'/backup-restore.php',{method:'POST',redirect:'manual',headers:{Cookie:jar.cookie},body});
  return {status:response.status,body:await response.text()};
}
async function previewParticipants(jar, token, csv) {
  const body=new FormData(); body.set('csrf_token',token); body.set('action','preview');
  body.set('file_csv',new Blob([csv],{type:'text/csv'}),'import-<img src=x onerror=alert(5)>.csv');
  const response=await fetch(base+'/import-peserta.php',{method:'POST',redirect:'manual',headers:{Cookie:jar.cookie},body});
  return {status:response.status,body:await response.text()};
}
const importToken = body => body.match(/name="batch_id" value="([a-f0-9]{32})"/)?.[1];
const importGroup = body => body.match(/name="targets\[([a-f0-9]{64})\]"/)?.[1];
(async()=>{
  let ready = false;
  for(let retry=0;retry<40;retry++) {
    if(server.exitCode!==null) throw Error('Local server stopped: '+serverErrors);
    try { const result=await fetch(base+'/login.php'); if(result.status===200) {ready=true;break;} } catch(_) {}
    await new Promise(resolve=>setTimeout(resolve,100));
  }
  check(ready,'Local fixture server is reachable');
  const anonymous = {}, initial = await req('/login.php',anonymous);
  check(/no-store/.test(initial.headers.get('cache-control')),'Login and session responses are not cached');
  check(initial.body.includes('for="login-username"') && initial.body.includes('aria-controls="login-password"'),'Login controls have accessible names');
  const invalidCsrf = await req('/login.php',anonymous,{'csrf_token[]':'bad',username:'audit_admin',password:'AuditTest-2026'});
  check(invalidCsrf.status===403 && invalidCsrf.body.includes('Buka kembali halaman'),'Malformed CSRF receives an actionable 403');
  const malformed = await req('/login.php',anonymous,{csrf_token:csrf(initial.body),'username[]':'bad',password:'AuditTest-2026'});
  check(malformed.status===200 && malformed.body.includes('Format isian tidak valid'),'Malformed form values do not cause server errors');
  const admin = await login('audit_admin');
  const users = await req('/users.php',admin);
  check(users.status===200 && !/\$2[aby]\$\d\d\$/.test(users.body),'User management HTML contains no password hashes');
  check(users.body.includes('role="dialog"') && users.body.includes('for="user-role"'),'User form has labelled controls and dialog semantics');
  const token = csrf(users.body);
  const groups = await req('/halaqoh.php',admin);
  check(groups.status===200 && groups.body.includes('Kelompok Uji'),'Group list works before optional archive migration');
  const protectedGroup = await req('/halaqoh.php',admin,{csrf_token:token,action:'delete',id:'1'});
  check(protectedGroup.status===200 && protectedGroup.body.includes('data tetap terhubung'),'Deleting a referenced group keeps its history and shows an explanation');
  const invalidTeacher = await req('/halaqoh.php',admin,{csrf_token:token,action:'save',nama_halaqoh:'Uji','ustadz_id':'1'});
  check(invalidTeacher.status===200 && invalidTeacher.body.includes('Pilih akun Ustadz'),'Invalid teacher selection is visible within the group form');
  const escapedGroup = await req('/halaqoh.php',admin,{csrf_token:token,action:'save',nama_halaqoh:"Kelompok 'A' </script>",ustadz_id:'3'});
  check(escapedGroup.status===302,'A valid group name containing punctuation saves normally');
  const escapedGroupPage = await req('/halaqoh.php',admin);
  check(escapedGroupPage.status===200 && escapedGroupPage.body.includes('\\u0027A\\u0027') && !escapedGroupPage.body.includes("Kelompok 'A' </script>"),'Stored group names are encoded safely in Alpine attributes');
  const bound = await req('/users.php',admin,{csrf_token:token,action:'delete',id:'3'});
  check(bound.status===200 && bound.body.includes('Alihkan pengampu'),'Deleting a referenced teacher displays a safe recovery step');
  const invalidRole = await req('/users.php',admin,{csrf_token:token,action:'save',username:'wrongrole',nama_lengkap:'Uji',role:'invalid',password:'LongEnough-2026'});
  check(invalidRole.status===200 && invalidRole.body.includes('Pilih peran pengguna'),'Unknown roles are rejected and the form remains visible');
  const create = await req('/users.php',admin,{csrf_token:token,action:'save',username:'http_created',nama_lengkap:'HTTP Uji',role:'ustadz',password:'AuditTest-2026'});
  check(create.status===302 && create.headers.get('location')==='/users.php','Successful create uses redirect to avoid accidental repeat submissions');
  const duplicate = await req('/users.php',admin,{csrf_token:token,action:'save',username:'http_created',nama_lengkap:'HTTP Uji',role:'ustadz',password:'AuditTest-2026'});
  check(duplicate.status===200 && duplicate.body.includes('Nama pengguna sudah dipakai'),'Duplicate username has a visible error');
  const pj = await login('audit_pj');
  // Report routes share the recap's ownership and canonical-session rules.
  localSql(`CREATE TABLE settings(id INT AUTO_INCREMENT PRIMARY KEY,setting_key VARCHAR(50) UNIQUE NOT NULL,setting_value LONGTEXT);
    INSERT INTO users(id,username,password,nama_lengkap,role) SELECT 100,'other_teacher',password,'Guru Lain','ustadz' FROM users WHERE id=3;
    INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(100,'Kelompok Lain',100);
    INSERT INTO wali_santri(id,nama_bapak) VALUES(100,'RAHASIA_WALI_LAIN');
    INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(100,100);
    UPDATE presensi SET status='S',alasan='<img src=x onerror=alert(1)>' WHERE wali_santri_id=1;
    INSERT INTO presensi(halaqoh_id,wali_santri_id,tanggal,status,alasan) SELECT halaqoh_id,wali_santri_id,tanggal,status,alasan FROM presensi WHERE wali_santri_id=1;
    UPDATE wali_santri SET nama_bapak='Wali <script>alert(1)</script>' WHERE id=1;`);
  const beforeReportData = domainDigest();
  const reportRoute = '/print-rapor.php?wali_santri_id=1&start_date=2026-10-01&end_date=2026-10-31';
  check((await req(reportRoute)).status===302,'Anonymous report request requires login');
  const reportTeacher = await login('audit_teacher');
  const ownReport = await req(reportRoute,reportTeacher);
  check(ownReport.status===200 && ownReport.body.includes('&lt;script&gt;') && ownReport.body.includes('&lt;img src=x onerror=alert(1)&gt;'),'Teacher can print own report with escaped student and attendance data');
  check(ownReport.body.includes('Sakit: <b>1</b>') && !ownReport.body.includes('Sakit: <b>2</b>'),'Identical source records count as one canonical session in the report');
  check(/script-src 'self'/.test(ownReport.headers.get('content-security-policy')) && /no-store/.test(ownReport.headers.get('cache-control')),'Printed report has restrictive CSP and is never cached');
  const otherReport = await req(reportRoute.replace('id=1&','id=100&'),reportTeacher);
  check(otherReport.status===404 && !otherReport.body.includes('RAHASIA_WALI_LAIN'),'Teacher cannot read another group report by changing the participant ID');
  check((await req(reportRoute.replace('id=1&','id=100&'),admin)).body.includes('RAHASIA_WALI_LAIN'),'Admin can still print reports across groups');
  check((await req('/print-rapor.php?wali_santri_id[]=1',admin)).status===422,'Array participant ID receives actionable validation instead of a crash');
  check((await req(reportRoute.replace('2026-10-01','2026-02-30'),admin)).status===422,'Invalid report date is rejected');
  check((await req('/pengaturan-rapor.php',reportTeacher)).status===302 && (await req('/pengaturan-rapor.php',pj)).status===302,'Only admin can edit or download report designs');
  let editor = await req('/pengaturan-rapor.php',admin);
  check(editor.status===200 && editor.body.includes('Desain standar — siap cetak') && editor.body.includes('for="rapor-name"'),'Report editor opens without preseeded settings and offers a labelled standard design');
  const reportToken = csrf(editor.body);
  check((await req('/pengaturan-rapor.php',admin,{'csrf_token[]':'bad'})).status===403,'Malformed CSRF cannot modify a report design');
  const unsafe = "<?php echo 'EXECUTED_PHP_SENTINEL'; ?>";
  localSql("INSERT INTO settings(setting_key,setting_value) VALUES('rapor_template','"+unsafe.replaceAll("'","''")+"')");
  const legacyReport = await req(reportRoute,admin);
  check(legacyReport.status===200 && !legacyReport.body.includes('EXECUTED_PHP_SENTINEL') && legacyReport.body.includes('Desain lama masih tersimpan'),'Stored legacy PHP is never executed and safe fallback is explained');
  const legacyDownload = await req('/pengaturan-rapor.php?download=current',admin);
  check(legacyDownload.status===200 && legacyDownload.body===unsafe && /text\/plain/.test(legacyDownload.headers.get('content-type')),'Original legacy template remains downloadable as inert text');
  const badSave = await req('/pengaturan-rapor.php',admin,{csrf_token:reportToken,design_mode:'custom',template_content:unsafe,pj_tahfidz_name:'PJ Uji',pj_tahfidz_title:'PJ Tahfidz'});
  check(badSave.status===422 && badSave.body.includes('Kode PHP tidak diperbolehkan'),'New PHP template is rejected with a visible error');
  check((await req('/pengaturan-rapor.php?download=current',admin)).body===unsafe,'Rejected template save leaves the previous template byte-for-byte intact');
  const custom = '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Rapor {{nama_wali}}</title></head><body><h1>{{nama_wali}}</h1><p>{{nama_pengesah}} — {{jabatan_pengesah}}</p><p>Sakit {{sakit}}</p>{{riwayat_tabel}}</body></html>';
  const savedDesign = await req('/pengaturan-rapor.php',admin,{csrf_token:reportToken,design_mode:'custom',template_content:custom,pj_tahfidz_name:'Pengesah Baru',pj_tahfidz_title:'Penanggung Jawab'});
  check(savedDesign.status===302,'Valid HTML template saves with redirect');
  editor = await req('/pengaturan-rapor.php',admin);
  const backupKey = editor.body.match(/value="(rapor_backup_\d{14}_[a-f0-9]{8})"/)?.[1];
  check(backupKey && (await req('/pengaturan-rapor.php?download='+backupKey,admin)).body===unsafe,'Replacing a design preserves the exact original in a separate downloadable backup');
  const customReport = await req(reportRoute,admin);
  check(customReport.status===200 && customReport.body.includes('Pengesah Baru') && customReport.body.includes('Penanggung Jawab') && customReport.body.includes('Sakit 1'),'Printed custom design uses saved signer, title and canonical totals');
  check((await req('/pengaturan-rapor.php?download='+backupKey,reportTeacher)).status===302,'Teacher cannot download template backups');
  check(domainDigest()===beforeReportData,'Printing and changing report settings preserve all participant, child, group, membership and attendance rows');
  const backupPage = await req('/backup-restore.php',admin);
  check(backupPage.status===200 && backupPage.body.includes('for="restore-upload"'),'Backup page has a labelled upload and opens normally');
  check((await req('/backup-restore.php',reportTeacher)).status===302,'Teacher cannot export or restore database backups');
  check((await req('/backup-restore.php?download[]=bad',admin)).status===404,'Array download name is safely rejected');
  check((await req('/backup-restore.php?msg[]=bad&err[]=bad',admin)).status===200,'Malformed backup flash fields do not crash the page');
  const backupCsrf = csrf(backupPage.body);
  check((await req('/backup-restore.php',admin,{'csrf_token[]':'invalid',action:'preview'})).status===403,'Malformed CSRF cannot preview or restore an archive');
  const exported = await fetch(base+'/backup-restore.php?export=1',{headers:{Cookie:admin.cookie},redirect:'manual'});
  const backupBytes = Buffer.from(await exported.arrayBuffer());
  check(exported.status===200 && /application\/zip/.test(exported.headers.get('content-type')) && backupBytes.subarray(0,2).toString()==='PK','Authenticated export returns a nonempty ZIP download');
  const backupDirectory = env.BACKUP_STORAGE_PATH;
  const beforeUploads = new Set(fs.readdirSync(backupDirectory));
  const backupPreview = await previewBackup(admin,backupCsrf,backupBytes);
  check(backupPreview.status===200 && backupPreview.body.includes('for="restore-confirm"') && backupPreview.body.includes('Data belum dipulihkan'),'Uploaded ZIP is validated before an accessible inline confirmation is shown');
  const pendingToken = backupPreview.body.match(/name="restore_token" value="([a-f0-9]{64})"/)?.[1];
  check(Boolean(pendingToken),'Preview produces a cryptographically random restore token');
  const wrongConfirmation = await req('/backup-restore.php',admin,{csrf_token:backupCsrf,action:'restore',restore_token:pendingToken,confirm_text:'wrong'});
  check(wrongConfirmation.status===422 && wrongConfirmation.body.includes('for="restore-confirm"'),'A mistyped confirmation keeps the validated preview available for correction');
  const uploadedName = fs.readdirSync(backupDirectory).find(name=>name.startsWith('restore_') && !beforeUploads.has(name));
  check(Boolean(uploadedName),'Uploaded archive has a unique unpredictable filename');
  const uploadedPath = path.join(backupDirectory,uploadedName);
  const secondAdmin = await login('audit_admin');
  const secondBackupPage = await req('/backup-restore.php',secondAdmin);
  check((await req('/backup-restore.php',secondAdmin,{csrf_token:csrf(secondBackupPage.body),action:'restore',restore_token:pendingToken,confirm_text:'RESTORE'})).status===422,'Restore preview is bound to its session and cannot be borrowed by another login');
  fs.appendFileSync(uploadedPath,'tampered-after-preview');
  const dataBeforeRestore = domainDigest();
  const tampered = await req('/backup-restore.php',admin,{csrf_token:backupCsrf,action:'restore',restore_token:pendingToken,confirm_text:'RESTORE'});
  check(tampered.status===422 && tampered.body.includes('Sesi restore tidak valid'),'Archive changed after preview is rejected before restoration');
  check(domainDigest()===dataBeforeRestore,'Rejected changed archive leaves domain data untouched');
  check((await req('/backup-restore.php',admin,{csrf_token:backupCsrf,action:'cancel'})).status===302 && !fs.existsSync(uploadedPath),'Cancel consumes the preview and removes only its uploaded archive');
  const secondPreview = await previewBackup(admin,backupCsrf,backupBytes);
  const freshToken = secondPreview.body.match(/name="restore_token" value="([a-f0-9]{64})"/)?.[1];
  check(secondPreview.status===200 && freshToken && freshToken!==pendingToken,'A new preview uses a distinct token');
  const beforeSafety = new Set(fs.readdirSync(backupDirectory));
  const recovered = await req('/backup-restore.php',admin,{csrf_token:backupCsrf,action:'restore',restore_token:freshToken,confirm_text:'RESTORE'});
  check(recovered.status===302 && /backup-restore\.php$/.test(recovered.headers.get('location')),'Confirmed merge finishes with redirect and a saved outcome');
  check((await req('/backup-restore.php',admin)).body.includes('Pemulihan selesai:'),'Restore outcome is read from the session after redirect');
  check(!(await req('/backup-restore.php?msg=FORGED_SUCCESS',admin)).body.includes('FORGED_SUCCESS'),'Query parameters cannot fabricate a successful restore message');
  check(domainDigest()===dataBeforeRestore,'Restoring the current archive preserves participant, child, group, membership and attendance content');
  const safetyName = fs.readdirSync(backupDirectory).find(name=>name.startsWith('backup_pre_restore_') && !beforeSafety.has(name));
  check(Boolean(safetyName),'A separate safety backup exists before successful merge');
  check((await req('/backup-restore.php?download='+safetyName,admin)).status===200,'Safety backup is downloadable through the authorized controller');
  check((await req('/.sessions/audit-20261023/http-backups-'+process.pid+'/'+safetyName,admin)).status===403,'Backup archive cannot be fetched directly as a public static file');
  check((await req('/backup-restore.php',admin,{csrf_token:backupCsrf,action:'restore',restore_token:freshToken,confirm_text:'RESTORE'})).status===422,'A completed restore token cannot be replayed');
  check((await previewBackup(admin,backupCsrf,Buffer.from('not-a-zip'))).status===422,'Invalid ZIP upload returns a visible validation error');
  check((await req('/backup-restore.php',admin,{csrf_token:backupCsrf,'action[]':'restore'})).status===422,'Malformed restore action does not crash');
  const notReadyImport=await req('/import-peserta.php',admin);
  check(notReadyImport.status===200 && notReadyImport.body.includes('Impor perlu disiapkan'),'Import explains required setup before its additive migration is applied');
  const prepareImport=path.join(env.SESSION_SAVE_PATH,'prepare-import.php');
  fs.writeFileSync(prepareImport,`<?php
$p=new PDO('mysql:host=127.0.0.1;port=13317;dbname=${database};charset=utf8mb4','root','tahsin_local_test',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
require 'includes/participant_import_helper.php'; participantImportApplyMigration($p);
$p->exec('ALTER TABLE halaqoh_members ADD archived_at DATETIME NULL, ADD archive_reason VARCHAR(50) NULL');
`);
  const prepareResult=spawnSync(php,['-d','extension=pdo_mysql',prepareImport],{cwd:root,encoding:'utf8',windowsHide:true,env});
  assert.equal(prepareResult.status,0,prepareResult.stderr);
  const importPage=await req('/import-peserta.php',admin);
  check(importPage.status===200 && importPage.body.includes('for="participant-csv"'),'Import opens with an accessible upload after migration');
  check((await req('/import-peserta.php',reportTeacher)).status===302 && (await req('/import-peserta.php',pj)).status===200,'Import access follows the admin/PJ role boundary');
  const csvToken=csrf(importPage.body);
  check((await req('/import-peserta.php',admin,{'csrf_token[]':'bad',action:'preview'})).status===403,'Malformed import CSRF receives an actionable rejection');
  const csvHeader='nama_bapak,no_hp,alamat,kategori,nama_anak,kelas,tempat_tahsin,ustadz_luar\n';
  const participantCsv=csvHeader+'Wali HTTP <script>alert(2)</script>,08911112222,Alamat HTTP,reguler,Anak </script><script>alert(3)</script>,1A,,\n';
  const beforeImport=domainDigest();
  let importPreview=await previewParticipants(admin,csvToken,participantCsv);
  check(importPreview.status===200 && importPreview.body.includes('Pratinjau — data belum disimpan'),'CSV upload produces a preview before writing domain data');
  check(domainDigest()===beforeImport,'Participant preview leaves all domain tables unchanged');
  check(!importPreview.body.includes('<script>alert(2)') && !importPreview.body.includes('<img src=x onerror=alert(5)>') && importPreview.body.includes('&lt;script&gt;'),'Untrusted CSV names and filenames are escaped in preview');
  let csvBatch=importToken(importPreview.body), csvGroup=importGroup(importPreview.body);
  check(Boolean(csvBatch && csvGroup),'Preview is tied to a server-held batch token and group key');
  const badHistoryRead=await req('/import-peserta.php?batch[]=bad',admin);
  check(badHistoryRead.status===422 && importToken(badHistoryRead.body)===csvBatch,'Malformed read-only history request does not rotate the active preview token');
  const importSecond=await req('/import-peserta.php',secondAdmin);
  check((await req('/import-peserta.php',secondAdmin,{csrf_token:csrf(importSecond.body),action:'apply',batch_id:csvBatch,reviewed:'1',[`targets[${csvGroup}]`]:'new'})).status===422,'Another session cannot apply this participant preview');
  const noReview=await req('/import-peserta.php',admin,{csrf_token:csvToken,action:'apply',batch_id:csvBatch,[`targets[${csvGroup}]`]:'new'});
  check(noReview.status===422 && domainDigest()===beforeImport,'Review confirmation is validated on the server before importing');
  csvBatch=importToken(noReview.body); csvGroup=importGroup(noReview.body);
  const malformedTarget=await req('/import-peserta.php',admin,{csrf_token:csvToken,action:'apply',batch_id:csvBatch,reviewed:'1',[`targets[${csvGroup}][]`]:'new'});
  check(malformedTarget.status===422 && domainDigest()===beforeImport,'Array target cannot bypass participant matching validation');
  csvBatch=importToken(malformedTarget.body); csvGroup=importGroup(malformedTarget.body);
  const importSaved=await req('/import-peserta.php',admin,{csrf_token:csvToken,action:'apply',batch_id:csvBatch,reviewed:'1',[`targets[${csvGroup}]`]:'new',class_policy:'preserve',profile_policy:'preserve'});
  check(importSaved.status===302 && /import-peserta\.php$/.test(importSaved.headers.get('location')),'Confirmed import saves atomically and redirects');
  const importResult=await req('/import-peserta.php',admin);
  check(importResult.status===200 && importResult.body.includes('1 peserta baru') && importResult.body.includes('1 anak ditambahkan'),'Import outcome shows real created and skipped counts');
  const importId=importResult.body.match(/href="\?download=([a-f0-9]{32})"/)?.[1];
  const importDownload=await req('/import-peserta.php?download='+importId,admin);
  const importJournal=JSON.parse(importDownload.body);
  check(importDownload.status===200 && /attachment/.test(importDownload.headers.get('content-disposition')) && importJournal.source_rows[0].nama_bapak==='Wali HTTP <script>alert(2)</script>','Protected journal download retains original CSV values and before/after records');
  check((await req('/import-peserta.php?download='+importId,reportTeacher)).status===302,'Teacher cannot download participant import journals');
  const readableJournal=await req('/import-peserta.php?batch='+importId,admin);
  check(readableJournal.status===200 && readableJournal.body.includes('Sebelum impor') && readableJournal.body.includes('Sesudah impor') && !readableJournal.body.includes('<script>alert(2)'),'Journal has a readable and escaped before/after page');
  check((await req('/import-peserta.php?batch='+importId,reportTeacher)).status===302,'Teacher cannot view participant import snapshots');
  const listing=await req('/peserta.php',admin);
  check(listing.status===200 && listing.body.includes('href="import-peserta.php"') && !listing.body.includes('showImportModal'),'Participant list links to the shared import workflow');
  check(listing.body.includes('\\u003C') && !listing.body.includes('<script>alert(2)') && !listing.body.includes('</script><script>alert(3)'),'Imported text remains inert in participant attributes and embedded child JSON');
  const reflected=await req('/peserta.php?msg='+encodeURIComponent('<script>alert(4)</script>'),admin);
  check(reflected.status===200 && reflected.body.includes('&lt;script&gt;alert(4)&lt;/script&gt;') && !reflected.body.includes('<script>alert(4)'),'Participant flash text cannot execute HTML from query parameters');
  const savedDomain=domainDigest();
  check((await req('/import-peserta.php',admin,{csrf_token:csvToken,action:'apply',batch_id:csvBatch,reviewed:'1',[`targets[${csvGroup}]`]:'new'})).status===422,'Completed participant preview token cannot be replayed');
  check(domainDigest()===savedDomain,'Rejected replay preserves the completed import');
  importPreview=await previewParticipants(admin,csvToken,participantCsv);
  csvBatch=importToken(importPreview.body); csvGroup=importGroup(importPreview.body);
  const importedWali=importJournal.changes[0].wali_id;
  const repeatedCsv=await req('/import-peserta.php',admin,{csrf_token:csvToken,action:'apply',batch_id:csvBatch,reviewed:'1',[`targets[${csvGroup}]`]:String(importedWali),class_policy:'preserve',profile_policy:'preserve'});
  check(repeatedCsv.status===302 && domainDigest()===savedDomain,'Uploading the same CSV again skips existing children and preserves domain contents');
  await req('/import-peserta.php',admin);
  importPreview=await previewParticipants(admin,csvToken,participantCsv);
  csvBatch=importToken(importPreview.body); csvGroup=importGroup(importPreview.body);
  localSql("UPDATE wali_santri SET alamat='Concurrent edit' WHERE id="+Number(importedWali));
  const afterConcurrent=domainDigest();
  const staleImport=await req('/import-peserta.php',admin,{csrf_token:csvToken,action:'apply',batch_id:csvBatch,reviewed:'1',[`targets[${csvGroup}]`]:String(importedWali)});
  check(staleImport.status===422 && staleImport.body.includes('Data peserta berubah') && domainDigest()===afterConcurrent,'A stale import preview cannot overwrite a concurrent edit');
  check((await req('/import-peserta.php',admin,{csrf_token:csvToken,action:'cancel'})).status===302,'Cancel discards participant preview without changing domain data');
  check((await previewParticipants(admin,csvToken,csvHeader+'Bad,08123,,invalid,Child,1A,,\n')).status===422 && domainDigest()===afterConcurrent,'Invalid CSV is rejected before any parent or child insertion');
  for(let index=0;index<12;index++) {
    const historyId=(7000000+index).toString(16).padStart(32,'0');
    localSql("INSERT INTO participant_import_batches SELECT '"+historyId+"',user_id,actor_name,'Older fixture.csv',source_sha256,request_hash,source_rows_json,changes_json,summary_json,created_at - INTERVAL "+(index+1)+" DAY FROM participant_import_batches WHERE id='"+importId+"'");
  }
  const olderImports=await req('/import-peserta.php?page=2',admin);
  check(olderImports.status===200 && olderImports.body.includes('halaman 2 dari') && olderImports.body.includes('Older fixture.csv'),'Older import journals remain reachable through pagination');
  check((await req('/import-peserta.php?page[]=2',admin)).status===422,'Malformed history pagination has an actionable validation error');
  const corruptJournalId='00000000000000000000000000badbad';
  localSql("INSERT INTO participant_import_batches SELECT '"+corruptJournalId+"',user_id,actor_name,source_name,source_sha256,request_hash,source_rows_json,'[\"bad snapshot\"]',summary_json,created_at FROM participant_import_batches WHERE id='"+importId+"'");
  const corruptJournal=await req('/import-peserta.php?batch='+corruptJournalId,admin);
  check(corruptJournal.status===503 && corruptJournal.body.includes('Rincian impor belum dapat dibuka'),'Malformed stored journal shows a clear error instead of PHP warnings or fabricated before/after data');
  localSql("UPDATE users SET role='kepsek' WHERE id=2");
  check((await req('/users.php',pj)).status===302,'Role changes revoke an existing management session');
  const teacher = await login('audit_teacher');
  localSql("UPDATE users SET role='kepsek' WHERE id=3");
  check((await req('/ustadz/api-autosave.php?halaqoh_id=1&tanggal=2026-10-18',teacher)).status===403,'Autosave API rechecks the current role');
  check((await req('/ustadz/api-save-single.php',teacher,{csrf_token:token})).status===403,'Legacy save API rechecks the current role');
  const temporary = await login('http_created');
  localSql("DELETE FROM users WHERE username='http_created'");
  check((await req('/ustadz/api-autosave.php?halaqoh_id=1&tanggal=2026-10-18',temporary)).status===401,'Deleted account is rejected by API despite its old cookie');
  for (const route of ['/config/database.php','/includes/auth_helper.php','/includes/participant_import_helper.php','/.sessions/audit-20261023/test-database.txt','/tests/security-integration.php','/tests/participant-import-integration.php','/n8n_workflow_tahsin.json','/database.sql','/composer.json','/migrate.php','/migrate_participant_import.php']) {
    check((await req(route,admin)).status===403,'Development router denies private path: '+route);
  }
  check((await req('/assets/css/app.css',admin)).status===200,'Public application CSS remains accessible');
  check((await req('/manifest.json',admin)).status===200,'PWA manifest remains accessible');
  const health = await req('/health.php');
  check(health.status===200 && JSON.parse(health.body).ok===true && /no-store/.test(health.headers.get('cache-control')),'Health check is healthy and never cached');
  const apiBad = await req('/api/hermes.php?action=status&api_key[]=bad');
  check(apiBad.status===401,'Array API key is rejected without crashing');
  check(!/Fatal error|Uncaught|Warning:/.test(serverErrors),'HTTP scenarios produce no PHP runtime errors');
  const offlineDatabase = spawnSync(php,['-d','extension=pdo_mysql','-r','require "config/database.php";'], {
    cwd:root,encoding:'utf8',windowsHide:true,env:{...env,DB_PORT:'9',DB_CONNECT_TIMEOUT:'1'}
  });
  check(offlineDatabase.status===1 && offlineDatabase.stderr.includes('Layanan database belum tersedia'),'CLI operations fail clearly when the database cannot be reached');
  console.log(`PASS ${checks} security HTTP assertions`);
  fs.writeFileSync(path.join(root,'.sessions','audit-20261023','http-results.json'),JSON.stringify({database,checks,passed:true},null,2));
})().catch(error=>{console.error(error);console.error(serverErrors);process.exitCode=1;}).finally(()=>server.kill());
