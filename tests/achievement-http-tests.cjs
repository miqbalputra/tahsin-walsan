/** New localhost fixture only. Never uses production URLs or app credentials. */
const assert=require('node:assert/strict'),{spawn,spawnSync}=require('node:child_process'),path=require('node:path'),fs=require('node:fs');
const root=path.resolve(__dirname,'..'),php=process.env.TEST_PHP_BINARY||'php';
const args=/pdo_mysql/i.test(spawnSync(php,['-m'],{encoding:'utf8',windowsHide:true}).stdout||'')?[]:['-d','extension=pdo_mysql'];
const setup=spawnSync(php,[...args,'tests/security-integration.php'],{cwd:root,encoding:'utf8',windowsHide:true});assert.equal(setup.status,0,setup.stderr||setup.stdout);
const database=setup.stdout.match(/STAGING_DATABASE=(tahsin_test_security_\d+_[a-f0-9]{8})/)?.[1];assert.ok(database,'A fresh fixture database is required');
const prefix='$p=new PDO("mysql:host=127.0.0.1;port=13317;dbname='+database+';charset=utf8mb4","root","tahsin_local_test",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$p->exec("SET time_zone=\'+07:00\'");';
function localPhp(code){const r=spawnSync(php,[...args,'-r',prefix+code],{cwd:root,encoding:'utf8',windowsHide:true});assert.equal(r.status,0,r.stderr||r.stdout);return r.stdout;}
function sql(query){const q='base64_decode("'+Buffer.from(query).toString('base64')+'")';const r=localPhp(/^\s*(SELECT|SHOW)\b/i.test(query)?'echo json_encode($p->query('+q+')->fetchAll(),JSON_THROW_ON_ERROR);':'$p->exec('+q+');');return r?JSON.parse(r):[];}
localPhp('require "includes/tahsin_migration_helper.php";require "includes/autosave_migration_helper.php";require "includes/participant_helper.php";tahsinApplyMigration($p);autosaveApplyMigration($p);participantApplyMigration($p);');
sql("UPDATE tahsin_tracking SET tracked_from='2026-10-01 00:00:00'; UPDATE tahsin_roster_history SET valid_from='2026-10-01 00:00:00'; UPDATE presensi SET jenis_materi='Iqro',jilid=2,halaman='1-2',hasil_talaqqi='Lulus' WHERE id=1; INSERT INTO users(id,username,password,nama_lengkap,role) SELECT 100,'other_teacher',password,'Guru Lain','ustadz' FROM users WHERE id=3; INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id) VALUES(100,'Kelompok Rahasia',100); INSERT INTO wali_santri(id,nama_bapak) VALUES(100,'PESERTA_RAHASIA'),(200,'Capaian Awal Uji'); INSERT INTO santri_detail(wali_santri_id,nama_anak,kelas) VALUES(200,'Anak Awal Uji','1A'); INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(100,100),(1,200); INSERT INTO presensi(id,halaqoh_id,wali_santri_id,tanggal,status,jenis_materi,nama_surat,halaman,hasil_talaqqi) VALUES(100,100,1,'2026-10-25','H','Al Quran','CAPAIAN_SUMBER_RAHASIA','99-100','Lulus'),(101,100,100,'2026-10-25','H','Al Quran','PRIVAT_LAIN','1-2','Lulus');");
const port=18088,base='http://127.0.0.1:'+port,sessionDir=path.join(root,'.sessions','audit-20261029','achievement-http-'+process.pid);fs.mkdirSync(sessionDir,{recursive:true});
const server=spawn(php,[...args,'-d','display_errors=0','-S','127.0.0.1:'+port,'dev-router.php'],{cwd:root,env:{...process.env,DB_HOST:'127.0.0.1',DB_PORT:'13317',DB_DATABASE:database,DB_USERNAME:'root',DB_PASSWORD:'tahsin_local_test',SESSION_SAVE_PATH:sessionDir,DATA_INDUK_ENABLED:'0',TAHSIN_AUTOSAVE_ENABLED:'1'},windowsHide:true,stdio:['ignore','ignore','pipe']});
let errors='',checks=0;server.stderr.on('data',b=>errors+=b.toString());
function check(v,m){assert.ok(v,m);checks++;}
async function req(route,jar,body){const r=await fetch(base+route,{redirect:'manual',method:body?'POST':'GET',headers:{...(jar?.cookie?{Cookie:jar.cookie}:{}),...(body?{'Content-Type':'application/x-www-form-urlencoded'}:{})},...(body?{body:new URLSearchParams(body)}:{})});const c=r.headers.get('set-cookie')?.match(/PHPSESSID=[^;]+/);if(c&&jar)jar.cookie=c[0];return{status:r.status,headers:r.headers,body:await r.text()};}
const csrf=html=>html.match(/name="csrf_token" value="([a-f0-9]{64})"/)?.[1],nonce=html=>html.match(/name="achievement_token" value="([a-f0-9]{32})"/)?.[1];
async function login(username){const jar={},p=await req('/login.php',jar);check((await req('/login.php',jar,{csrf_token:csrf(p.body),username,password:'AuditTest-2026'})).status===302,username+' can log in');return jar;}
function domain(){return JSON.stringify(['wali_santri','santri_detail','halaqoh','halaqoh_members','presensi','tahsin_capaian_awal','tahsin_autosave_drafts'].map(t=>sql('SELECT * FROM '+t+' ORDER BY 1')));}
(async()=>{
  let ready=false;for(let i=0;i<40;i++){if(server.exitCode!==null)throw Error(errors);try{if((await fetch(base+'/login.php')).status===200){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,100));}check(ready,'Local fixture server is reachable');
  const teacher=await login('audit_teacher'),admin=await login('audit_admin');
  for(const route of ['/capaian.php','/ustadz/capaian.php']){const page=await req(route,teacher);check(page.status===200&&!page.body.includes('CAPAIAN_SUMBER_RAHASIA')&&!page.body.includes('PESERTA_RAHASIA')&&!page.body.includes('PRIVAT_LAIN'),'Teacher achievement route hides foreign-group source data: '+route);}
  if(process.argv.includes('--read-only')){console.log('PASS '+checks+' achievement access assertions');return;}
  const before=domain(),catalog=await req('/capaian.php',admin);check(catalog.status===200&&catalog.body.includes('capaian-form.php')&&catalog.body.includes('CAPAIAN_SUMBER_RAHASIA'),'Admin sees confirmed material with native editor links');
  check((await req('/capaian.php?search[]=x',admin)).status===422&&domain()===before,'Malformed catalog filters fail without database writes');
  check((await req('/capaian-form.php?wali_id=1&presensi_id=1',teacher)).status===302,'Teacher accounts cannot open the administrative editor');
  const edit=await req('/capaian-form.php?wali_id=1&presensi_id=1',admin),token=nonce(edit.body),security=csrf(edit.body);
  check(edit.status===200&&token&&edit.body.includes('2026-10-18'),'Editor binds the exact dated attendance row to a session token');
  const body={csrf_token:security,achievement_token:token,jenis_materi:'Iqro',jilid:'2',nama_surat:'',halaman:'3-4',hasil_talaqqi:'Lulus'};
  check((await req('/capaian-form.php',admin,{...body,csrf_token:'invalid'})).status===403&&domain()===before,'Attendance editing enforces CSRF before writes');
  check((await req('/capaian-form.php',admin,{...body,halaman:'', 'halaman[]':'bad'})).status===422&&domain()===before,'Array material values are rejected without partial writes');
  check((await req('/capaian-form.php',admin,body)).status===302,'A valid attendance edit redirects after commit');
  const after=domain(),journals=JSON.stringify(sql('SELECT * FROM participant_changes ORDER BY operation_id'));
  check(sql('SELECT halaman FROM presensi WHERE id=1')[0].halaman==='3-4'&&sql('SELECT nama_surat FROM presensi WHERE id=100')[0].nama_surat==='CAPAIAN_SUMBER_RAHASIA','Editing the dated source leaves the later foreign-group row intact');
  check((await req('/capaian-form.php',admin,body)).status===302&&domain()===after&&JSON.stringify(sql('SELECT * FROM participant_changes ORDER BY operation_id'))===journals,'Repeated edit returns its durable receipt without writing twice');
  const history=await req('/peserta-riwayat.php?operation='+token,admin);check(history.status===200&&history.body.includes('Capaian sebelum perubahan')&&history.body.includes('1-2')&&history.body.includes('3-4'),'Participant history renders old and new achievement values');
  const stale=await req('/capaian-form.php?wali_id=1&presensi_id=1',admin);sql("UPDATE presensi SET halaman='5-6' WHERE id=1");const concurrent=domain();
  check((await req('/capaian-form.php',admin,{...body,achievement_token:nonce(stale.body)})).status===422&&domain()===concurrent,'An old achievement form cannot overwrite a newer attendance edit');
  const initial=await req('/capaian-form.php?wali_id=200&initial=1',admin),initialToken=nonce(initial.body);
  const initialBody={csrf_token:security,achievement_token:initialToken,jenis_materi:'Iqro',jilid:'3',nama_surat:'',halaman:'7-8',hasil_talaqqi:''};
  const countBefore=Number(sql('SELECT COUNT(*) AS n FROM presensi')[0].n);
  check((await req('/capaian-form.php',admin,initialBody)).status===302&&Number(sql('SELECT COUNT(*) AS n FROM presensi')[0].n)===countBefore,'Initial achievement is saved separately without inventing attendance');
  const baselineAfter=domain();check((await req('/capaian-form.php',admin,initialBody)).status===302&&domain()===baselineAfter,'Initial achievement replay cannot duplicate or re-date the saved baseline');
  const baselineStale=await req('/capaian-form.php?wali_id=200&initial=1',admin);sql("UPDATE tahsin_capaian_awal SET halaman='9-10' WHERE wali_santri_id=200");const baselineConcurrent=domain();
  check((await req('/capaian-form.php',admin,{...initialBody,achievement_token:nonce(baselineStale.body)})).status===422&&domain()===baselineConcurrent,'Concurrent baseline updates reject stale forms without overwriting existing values');
  sql("CREATE TRIGGER reject_achievement_journal BEFORE INSERT ON participant_changes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DO_NOT_EXPOSE_DB_DETAIL'");
  const failing=await req('/capaian-form.php?wali_id=200&initial=1',admin),beforeFailure=domain();
  const failure=await req('/capaian-form.php',admin,{...initialBody,achievement_token:nonce(failing.body),halaman:'11-12'});
  check(failure.status===503&&!failure.body.includes('DO_NOT_EXPOSE_DB_DETAIL')&&domain()===beforeFailure,'Journal failure rolls back the baseline and keeps database diagnostics private');
  sql('DROP TRIGGER reject_achievement_journal');
  const xss='<svg/onload=a()>';sql("UPDATE presensi SET halaman='"+xss+"' WHERE id=1");
  const escaped=await req('/ustadz/capaian.php',teacher);check(escaped.status===200&&!escaped.body.includes(xss)&&escaped.body.includes('&lt;svg/onload=a()&gt;'),'Legacy material text is escaped in teacher output');
  check(!/Fatal error|Uncaught|Warning:/.test(errors),'Achievement flows produce no PHP runtime warnings');
  fs.writeFileSync(path.join(sessionDir,'results.json'),JSON.stringify({database,checks,passed:true},null,2));console.log('PASS '+checks+' achievement HTTP assertions\nSTAGING_DATABASE='+database);
})().catch(e=>{console.error(e);console.error(errors);process.exitCode=1;}).finally(()=>server.kill());
