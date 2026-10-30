/** Isolated localhost fixture only; no production URL, credentials or messages. */
const assert=require('node:assert/strict');
const {spawn,spawnSync}=require('node:child_process');
const path=require('node:path');
const fs=require('node:fs');
const root=path.resolve(__dirname,'..'), php=process.env.TEST_PHP_BINARY||'php';
const args=/pdo_mysql/i.test(spawnSync(php,['-m'],{encoding:'utf8',windowsHide:true}).stdout||'')?[]:['-d','extension=pdo_mysql'];
const setup=spawnSync(php,[...args,'tests/security-integration.php'],{cwd:root,encoding:'utf8',windowsHide:true});
assert.equal(setup.status,0,setup.stderr||setup.stdout);
const database=setup.stdout.match(/STAGING_DATABASE=(tahsin_test_security_\d+_[a-f0-9]{8})/)?.[1];
assert.ok(database,'Only a newly generated test database is allowed');
const prefix='$p=new PDO("mysql:host=127.0.0.1;port=13317;dbname='+database+';charset=utf8mb4","root","tahsin_local_test",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$p->exec("SET time_zone=\'+07:00\'");';
function localPhp(code){const result=spawnSync(php,[...args,'-r',prefix+code],{cwd:root,encoding:'utf8',windowsHide:true});assert.equal(result.status,0,result.stderr||result.stdout);return result.stdout;}
function sql(query){const value='base64_decode("'+Buffer.from(query).toString('base64')+'")';const result=localPhp(/^\s*(SELECT|SHOW)\b/i.test(query)?'echo json_encode($p->query('+value+')->fetchAll(),JSON_THROW_ON_ERROR);':'$p->exec('+value+');');return result?JSON.parse(result):[];}
localPhp('require "includes/tahsin_migration_helper.php";require "includes/autosave_migration_helper.php";require "includes/attendance_form_helper.php";tahsinApplyMigration($p);autosaveApplyMigration($p);attendanceFormApplyMigration($p);');
sql("UPDATE tahsin_tracking SET tracked_from='2026-10-01 00:00:00'; UPDATE tahsin_roster_history SET valid_from='2026-10-01 00:00:00'; UPDATE presensi SET jenis_materi='Iqro',jilid=2,halaman='1-2',hasil_talaqqi='Lulus',alasan='<script>alert(1)</script>' WHERE id=1; INSERT INTO users(id,username,password,nama_lengkap,role) SELECT 100,'other_teacher',password,'Guru Lain','ustadz' FROM users WHERE id=3; INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(2,'Kelompok Lain',100); INSERT INTO wali_santri(id,nama_bapak) VALUES(2,'Wali Lain'); INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status) VALUES(2,2,2,'2026-10-18','A'); INSERT INTO tahsin_autosave_drafts(user_id,halaqoh_id,session_date,wali_id,payload_json,official_revision,context_revision) VALUES(3,1,'2026-10-18',1,'{\"status\":\"I\",\"alasan\":\"Draft lama\"}','old','context');");
const port=18087, base='http://127.0.0.1:'+port;
const sessionPath=path.join(root,'.sessions','audit-20261029','history-http-'+process.pid);
fs.mkdirSync(sessionPath,{recursive:true});
const server=spawn(php,[...args,'-d','display_errors=0','-S','127.0.0.1:'+port,'dev-router.php'],{cwd:root,env:{...process.env,DB_HOST:'127.0.0.1',DB_PORT:'13317',DB_DATABASE:database,DB_USERNAME:'root',DB_PASSWORD:'tahsin_local_test',SESSION_SAVE_PATH:sessionPath,DATA_INDUK_ENABLED:'0',TAHSIN_AUTOSAVE_ENABLED:'1'},windowsHide:true,stdio:['ignore','ignore','pipe']});
let errors='',checks=0;server.stderr.on('data',bytes=>errors+=bytes.toString());
function check(value,message){assert.ok(value,message);checks++;}
async function req(route,jar,body){const response=await fetch(base+route,{redirect:'manual',method:body?'POST':'GET',headers:{...(jar?.cookie?{Cookie:jar.cookie}:{}),...(body?{'Content-Type':'application/x-www-form-urlencoded'}:{})},...(body?{body:new URLSearchParams(body)}:{})});const cookie=response.headers.get('set-cookie')?.match(/PHPSESSID=[^;]+/);if(cookie&&jar)jar.cookie=cookie[0];return{status:response.status,headers:response.headers,body:await response.text()};}
const csrf=body=>body.match(/name="csrf_token" value="([a-f0-9]{64})"/)?.[1];
async function login(username){const jar={};const page=await req('/login.php',jar);check((await req('/login.php',jar,{csrf_token:csrf(page.body),username,password:'AuditTest-2026'})).status===302,username+' can log in');return jar;}
function domain(){return JSON.stringify(['wali_santri','santri_detail','halaqoh','halaqoh_members','presensi','tahsin_autosave_drafts'].map(table=>sql('SELECT * FROM '+table+' ORDER BY 1')));}
function allRecoveryData(){return JSON.stringify([domain(),sql('SELECT * FROM holidays ORDER BY id'),sql('SELECT * FROM presensi_history ORDER BY id'),sql('SELECT * FROM tahsin_autosave_revisions ORDER BY wali_id,session_date')]);}
const hash=id=>localPhp('require "includes/attendance_history_helper.php";echo attendanceHistoryHash(attendanceHistorySelect($p,"'+id+'") ["row"]);');
const latestDelete=id=>Number(sql("SELECT id FROM presensi_history WHERE event='delete' AND presensi_id='"+id+"' ORDER BY id DESC LIMIT 1")[0].id);
const nonce=body=>body.match(/name="restore_token" value="([a-f0-9]{32})"/)?.[1];
(async()=>{
  let ready=false;for(let i=0;i<40;i++){if(server.exitCode!==null)throw Error(errors);try{if((await fetch(base+'/login.php')).status===200){ready=true;break;}}catch{}await new Promise(resolve=>setTimeout(resolve,100));}check(ready,'Local fixture server is reachable');
  const admin=await login('audit_admin'),teacher=await login('audit_teacher'),reader=await login('audit_reader');
  const report=await req('/laporan.php?start_date=2026-10-01&end_date=2026-10-31',admin),token=csrf(report.body);
  const original=domain();
  check(report.status===200&&report.body.includes('name="record_fingerprint"'),'Report includes the exact displayed row fingerprint');
  const originalHash=hash(1);
  check((await req('/laporan.php',admin,{csrf_token:token,action:'delete',id:'1',record_fingerprint:originalHash})).status===200,'Admin deletion runs through the history service');
  const deletion=latestDelete(1);
  check((await req('/presensi-riwayat.php?id='+deletion)).status===302&&(await req('/presensi-riwayat.php?id='+deletion,teacher)).status===302&&(await req('/presensi-riwayat.php?id='+deletion,reader)).status===302,'Recovery requires management access');
  const preview=await req('/presensi-riwayat.php?id='+deletion,admin),restoreToken=nonce(preview.body);
  check(preview.status===200&&restoreToken&&preview.body.includes('&lt;script&gt;alert(1)&lt;/script&gt;')&&!preview.body.includes('<script>alert(1)</script>'),'Recovery preview escapes stored text and offers a session-bound token');
  check(/no-store/.test(preview.headers.get('cache-control')),'Private version snapshots are never cached');
  const deletedState=domain();
  check((await req('/presensi-riwayat.php?id='+deletion,admin,{restore_token:restoreToken,confirmed:'1'})).status===403&&domain()===deletedState,'Recovery enforces CSRF before writes');
  check((await req('/presensi-riwayat.php?id='+deletion,admin,{csrf_token:token,restore_token:restoreToken})).status===422&&domain()===deletedState,'Recovery requires explicit confirmation');
  const otherAdmin=await login('audit_admin');
  check((await req('/presensi-riwayat.php',otherAdmin,{csrf_token:csrf((await req('/laporan.php',otherAdmin)).body),restore_token:restoreToken,confirmed:'1'})).status===422&&domain()===deletedState,'A recovery token cannot be reused from another session');
  const restoreBody={csrf_token:token,restore_token:restoreToken,confirmed:'1'};
  check((await req('/presensi-riwayat.php?id='+deletion,admin,restoreBody)).status===302&&domain()===original,'HTTP recovery restores all original domain rows and drafts');
  const recoveredHistory=JSON.stringify(sql('SELECT * FROM presensi_history ORDER BY id'));
  check((await req('/presensi-riwayat.php',admin,restoreBody)).status===302&&domain()===original&&JSON.stringify(sql('SELECT * FROM presensi_history ORDER BY id'))===recoveredHistory,'Repeated recovery returns its receipt without duplicate rows or audits');
  const download=await req('/presensi-riwayat.php?download='+deletion,admin);
  check(download.status===200&&/attachment/.test(download.headers.get('content-disposition'))&&Buffer.from(JSON.parse(JSON.parse(download.body).before_json).status,'base64').toString()==='H','Version download retains the complete private snapshot');
  check((await req('/presensi-riwayat.php?id[]=1',admin)).status===422,'Array history IDs are rejected without runtime errors');
  await req('/laporan.php',admin,{csrf_token:token,action:'delete',id:'1',record_fingerprint:hash(1)});
  const staleId=latestDelete(1),stalePage=await req('/presensi-riwayat.php?id='+staleId,admin),staleToken=nonce(stalePage.body);
  sql("INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status) VALUES(501,1,1,'2026-10-20','S')");
  const replacementState=domain();
  check((await req('/presensi-riwayat.php?id='+staleId,admin,{csrf_token:token,restore_token:staleToken,confirmed:'1'})).status===422&&domain()===replacementState,'A replacement row blocks stale recovery without overwriting newer attendance');
  sql('DELETE FROM presensi WHERE id=501');
  const retry=await req('/presensi-riwayat.php?id='+staleId,admin);
  await req('/presensi-riwayat.php',admin,{csrf_token:token,restore_token:nonce(retry.body),confirmed:'1'});
  const teacherPage=await req('/ustadz/riwayat.php?start_date=2026-10-01&end_date=2026-10-31',teacher),teacherCsrf=csrf(teacherPage.body);
  const beforeForeign=domain();
  check((await req('/ustadz/riwayat.php',teacher,{csrf_token:teacherCsrf,action:'delete',id:'2',record_fingerprint:hash(2)})).status===422&&domain()===beforeForeign,'Even an exact foreign-row fingerprint cannot bypass teacher ownership');
  const revisionBefore=Number(sql("SELECT revision FROM tahsin_autosave_revisions WHERE wali_id=1 AND session_date='2026-10-18'")[0].revision);
  const draftBefore=JSON.stringify(sql('SELECT * FROM tahsin_autosave_drafts'));
  const legacyReset=await req('/ustadz/form-presensi.php?id=1&tanggal=2026-10-18&legacy=1',teacher);
  const formToken=legacyReset.body.match(/name="attendance_form_token" value="([a-f0-9]{32})"/)?.[1],entryRevision=legacyReset.body.match(/name="entry_revisions\[1\]" value="([a-f0-9]{64})"/)?.[1];
  check((await req('/ustadz/api-save-single.php',teacher,{csrf_token:teacherCsrf,halaqoh_id:'1',wali_santri_id:'1',tanggal:'2026-10-18',intent:'reset',attendance_form_token:formToken,entry_revision:entryRevision,operation_id:'f'.repeat(32)})).status===200,'Legacy per-member reset runs with a source revision and mandatory snapshots');
  check(JSON.stringify(sql('SELECT * FROM tahsin_autosave_drafts'))===draftBefore&&Number(sql("SELECT revision FROM tahsin_autosave_revisions WHERE wali_id=1 AND session_date='2026-10-18'")[0].revision)>revisionBefore&&Number(sql("SELECT actor_id FROM presensi_history WHERE event='delete' AND presensi_id='1' ORDER BY id DESC LIMIT 1")[0].actor_id)===3,'Reset retains server drafts, records the teacher and invalidates stale official revisions');
  await req('/libur.php',admin);
  sql("INSERT INTO wali_santri(id,nama_bapak) VALUES(10,'Alfa Libur'),(11,'Hadir Libur'),(12,'Izin Libur'),(13,'Alfa Biasa'); INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status) VALUES(10,1,10,'2026-10-25','A'),(11,1,11,'2026-10-25','H'),(12,1,12,'2026-10-25','I'),(13,1,13,'2026-10-26','A');");
  check((await req('/libur.php',admin,{csrf_token:token,action:'add',tanggal:'2026-10-25',keterangan:'Libur Uji',clean_alfa:'1'})).status===200,'Holiday save and selected cleanup commit together');
  check(sql('SELECT id FROM presensi WHERE id IN (10,11,12,13) ORDER BY id').map(row=>Number(row.id)).join(',')==='11,12,13'&&latestDelete(10)>0,'Cleanup snapshots only Alfa on the exact holiday and preserves other statuses/dates');
  sql("INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status) VALUES(14,1,10,'2026-10-25','A')");
  const beforeUnconfirmedCleanup=allRecoveryData();
  check((await req('/libur.php',admin,{csrf_token:token,action:'clean_bulk',start_date:'2026-10-25',end_date:'2026-10-25'})).status===422&&allRecoveryData()===beforeUnconfirmedCleanup,'Bulk Alfa cleanup requires explicit confirmation and preserves every row otherwise');
  check((await req('/libur.php',admin,{csrf_token:token,action:'clean_bulk',start_date:'2026-10-25',end_date:'2026-10-25',confirmed:'1'})).status===200&&sql('SELECT id FROM presensi WHERE id=14').length===0&&latestDelete(14)>0,'Confirmed bulk cleanup snapshots the affected Alfa before removing it');
  const beforeBadHoliday=allRecoveryData();
  check((await req('/libur.php',admin,{csrf_token:token,action:'clean_bulk','start_date[]':'bad',end_date:'2026-10-25',confirmed:'1'})).status===422&&allRecoveryData()===beforeBadHoliday,'Malformed cleanup filters cannot remove data');
  sql("CREATE TRIGGER reject_holiday_log BEFORE INSERT ON activity_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic holiday audit failure'");
  const beforeLogFailure=allRecoveryData();
  check((await req('/libur.php',admin,{csrf_token:token,action:'add',tanggal:'2026-10-26',keterangan:'Rollback Uji',clean_alfa:'1'})).status===503&&allRecoveryData()===beforeLogFailure,'Required calendar audit failure rolls back the holiday, cleanup, snapshots and revisions');
  sql('DROP TRIGGER reject_holiday_log');
  const trigger=sql('SELECT delete_name FROM presensi_history_state WHERE id=1')[0].delete_name;
  sql('DROP TRIGGER `'+trigger+'`');
  const missingTriggerState=domain();
  check((await req('/laporan.php',admin,{csrf_token:token,action:'delete',id:'13',record_fingerprint:hash(13)})).status===422&&domain()===missingTriggerState,'Missing snapshot triggers block destructive application operations');
  check((await req('/presensi-riwayat.php?download='+deletion,admin)).status===200,'Saved versions remain downloadable while trigger setup needs repair');
  localPhp('require "includes/attendance_history_helper.php";attendanceHistoryApplyMigration($p);');
  check(!/Fatal error|Uncaught|Warning:/.test(errors),'HTTP recovery flows produce no PHP warnings or uncaught errors');
  fs.writeFileSync(path.join(sessionPath,'results.json'),JSON.stringify({database,checks,passed:true},null,2));
  console.log('PASS '+checks+' attendance history HTTP assertions\nSTAGING_DATABASE='+database);
})().catch(error=>{console.error(error);console.error(errors);process.exitCode=1;}).finally(()=>server.kill());
