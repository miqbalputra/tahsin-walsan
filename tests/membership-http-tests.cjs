/** Synthetic, newly created loopback database only. No production configuration. */
const assert=require('node:assert/strict'),{spawn,spawnSync}=require('node:child_process'),path=require('node:path'),fs=require('node:fs');
const root=path.resolve(__dirname,'..'),php=process.env.TEST_PHP_BINARY||'php';
const args=/pdo_mysql/i.test(spawnSync(php,['-m'],{encoding:'utf8',windowsHide:true}).stdout||'')?[]:['-d','extension=pdo_mysql'];
const setup=spawnSync(php,[...args,'tests/security-integration.php'],{cwd:root,encoding:'utf8',windowsHide:true});assert.equal(setup.status,0,setup.stderr||setup.stdout);
const database=setup.stdout.match(/STAGING_DATABASE=(tahsin_test_security_\d+_[a-f0-9]{8})/)?.[1];assert.ok(database,'Fresh fixture required');
const prefix='$p=new PDO("mysql:host=127.0.0.1;port=13317;dbname='+database+';charset=utf8mb4","root","tahsin_local_test",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$p->exec("SET time_zone=\'+07:00\'");';
function localPhp(code){const r=spawnSync(php,[...args,'-r',prefix+code],{cwd:root,encoding:'utf8',windowsHide:true});assert.equal(r.status,0,r.stderr||r.stdout);return r.stdout;}
function sql(query){const q='base64_decode("'+Buffer.from(query).toString('base64')+'")';const r=localPhp(/^\s*(SELECT|SHOW)\b/i.test(query)?'echo json_encode($p->query('+q+')->fetchAll(),JSON_THROW_ON_ERROR);':'$p->exec('+q+');');return r?JSON.parse(r):[];}
localPhp('require "includes/tahsin_migration_helper.php";require "includes/autosave_migration_helper.php";require "includes/family_migration_helper.php";require "includes/participant_helper.php";tahsinApplyMigration($p);autosaveApplyMigration($p);familyApplyMigration($p);participantApplyMigration($p);');
sql("INSERT INTO halaqoh(id,nama_halaqoh,ustadz_id,program_code) VALUES(2,'Ibu Uji',3,'ibu'),(3,'Bapak Lain',3,'bapak'); INSERT INTO wali_santri(id,nama_bapak,status_aktif,kategori) VALUES(2,'Peserta Baru',1,'reguler'),(3,'Peserta Ibu',1,'reguler'),(4,'Alumni',1,'reguler'),(5,'Tanpa Anak',1,'reguler'),(6,'Peserta Luar',1,'tahsin_luar'),(7,'Peserta Arsip',0,'reguler'); INSERT INTO santri_detail(wali_santri_id,nama_anak,kelas) VALUES(2,'Anak <img src=x>','2A'),(3,'Anak Ibu','3A'),(4,'Lulusan','Lulus'),(6,'Anak Luar','2A'),(7,'Anak Arsip','2A'); INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(2,3); INSERT INTO tahsin_autosave_drafts(user_id,halaqoh_id,session_date,wali_id,payload_json,official_revision,context_revision) VALUES(3,1,'2026-10-18',1,'{\"status\":\"H\"}','old','context');");
const port=18092,base='http://127.0.0.1:'+port,sessionDir=path.join(root,'.sessions','audit-20261030','membership-http-'+process.pid);fs.mkdirSync(sessionDir,{recursive:true});
const server=spawn(php,[...args,'-d','display_errors=0','-S','127.0.0.1:'+port,'dev-router.php'],{cwd:root,env:{...process.env,DB_HOST:'127.0.0.1',DB_PORT:'13317',DB_DATABASE:database,DB_USERNAME:'root',DB_PASSWORD:'tahsin_local_test',SESSION_SAVE_PATH:sessionDir,DATA_INDUK_ENABLED:'0'},windowsHide:true,stdio:['ignore','ignore','pipe']});
let errors='',checks=0;server.stderr.on('data',b=>errors+=b.toString());
function check(v,m){assert.ok(v,m);checks++;}
async function req(route,jar,body){const r=await fetch(base+route,{redirect:'manual',method:body?'POST':'GET',headers:{...(jar?.cookie?{Cookie:jar.cookie}:{}),...(body?{'Content-Type':'application/x-www-form-urlencoded'}:{})},...(body?{body:new URLSearchParams(body)}:{})});const c=r.headers.get('set-cookie')?.match(/PHPSESSID=[^;]+/);if(c&&jar)jar.cookie=c[0];return{status:r.status,headers:r.headers,body:await r.text()};}
const csrf=html=>html.match(/name="csrf_token" value="([a-f0-9]{64})"/)?.[1],token=html=>html.match(/name="membership_token" value="([a-f0-9]{32})"/)?.[1];
const details=(group,action,wali)=>'/halaqoh-members.php?id='+group+'&action='+action+'&wali_id='+wali;
function domain(){return JSON.stringify(['wali_santri','santri_detail','halaqoh','halaqoh_members','presensi','tahsin_roster_history','tahsin_autosave_drafts','participant_changes'].map(t=>sql('SELECT * FROM '+t+' ORDER BY 1')));}
(async()=>{
  let ready=false;for(let i=0;i<40;i++){if(server.exitCode!==null)throw Error(errors);try{if((await fetch(base+'/login.php')).status===200){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,100));}check(ready,'Local fixture reachable');
  async function login(username){const jar={},p=await req('/login.php',jar);check((await req('/login.php',jar,{csrf_token:csrf(p.body),username,password:'AuditTest-2026'})).status===302,'Fixture logs in: '+username);return jar;}
  const admin=await login('audit_admin'),teacher=await login('audit_teacher');
  const ibu=await req('/halaqoh-members.php?id=2',admin);
  check(ibu.status===200&&/<option value="1">/.test(ibu.body),'A participant in Bapak remains available for Ibu');
  if(process.argv.includes('--probe')){console.log('PASS '+checks+' membership program regression checks');return;}
  const bapak=await req('/halaqoh-members.php?id=1',admin);
  check(/<option value="3">/.test(bapak.body)&&!/<option value="[4567]">/.test(bapak.body),'Candidates are scoped by program, school status, category and participant archive');
  check((await req('/halaqoh-members.php?id=1',teacher)).status===302,'Teachers cannot manage memberships');
  const before=domain();
  check((await req('/halaqoh-members.php?id=1',admin,{csrf_token:csrf((await req(details(1,'add',2),admin)).body),action:'add',wali_santri_id:'2'})).status===422&&domain()===before,'Unversioned legacy POST cannot bypass the confirmation form');
  check((await req(details(3,'add',1),admin)).status===422&&domain()===before,'A forged add cannot create a second group in the same program');
  const add=await req(details(2,'add',1),admin),nonce=token(add.body),security=csrf(add.body);
  check(add.status===200&&nonce&&add.body.includes('Program Ibu'),'Confirmation names the exact participant and program');
  check((await req('/halaqoh-members.php?id=2',admin,{membership_token:nonce,csrf_token:'bad'})).status===403&&domain()===before,'Membership mutation enforces CSRF');
  check((await req('/halaqoh-members.php?id=3',admin,{membership_token:nonce,csrf_token:security})).status===422&&domain()===before,'A bound membership token cannot target another group');
  const body={membership_token:nonce,csrf_token:security};
  check((await req('/halaqoh-members.php?id=2',admin,body)).status===302,'Adding Ibu commits through the dedicated membership service');
  check(sql('SELECT halaqoh_id FROM halaqoh_members WHERE wali_santri_id=1 AND archived_at IS NULL ORDER BY halaqoh_id').map(r=>Number(r.halaqoh_id)).join(',')==='1,2','Adding one program preserves the other active placement');
  const after=domain();check((await req('/halaqoh-members.php?id=2',admin,body)).status===302&&domain()===after,'A repeated membership submission returns its durable receipt without changes');
  const journal=await req('/peserta-riwayat.php?operation='+nonce,admin);check(journal.status===200&&journal.body.includes('Tambahkan keanggotaan')&&journal.body.includes('Ibu Uji')&&journal.body.includes('Sumber sekolah sebelum perubahan'),'Before/after membership and school-source state is visible in participant history');
  const stale=await req(details(1,'remove',1),admin);sql("UPDATE halaqoh_members SET archived_at=NOW() WHERE wali_santri_id=1 AND halaqoh_id=1; UPDATE halaqoh_members SET archived_at=NULL WHERE wali_santri_id=1 AND halaqoh_id=1");const concurrent=domain();
  check((await req('/halaqoh-members.php?id=1',admin,{csrf_token:security,membership_token:token(stale.body)})).status===409&&domain()===concurrent,'An old remove form cannot undo an intervening removal/reactivation');
  const remove=await req(details(1,'remove',1),admin);check((await req('/halaqoh-members.php?id=1',admin,{csrf_token:security,membership_token:token(remove.body)})).status===302,'Reviewed removal archives the chosen relationship');
  check(sql('SELECT halaqoh_id FROM halaqoh_members WHERE wali_santri_id=1 AND archived_at IS NULL').map(r=>Number(r.halaqoh_id)).join(',')==='2'&&sql('SELECT * FROM presensi WHERE wali_santri_id=1').length===1&&sql('SELECT * FROM tahsin_autosave_drafts').length===1,'Removing Bapak retains Ibu, original attendance and drafts');
  const failing=await req(details(1,'add',2),admin),failureBody={csrf_token:security,membership_token:token(failing.body)};
  sql("CREATE TRIGGER reject_membership_journal BEFORE INSERT ON participant_changes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PRIVATE_MEMBERSHIP_CAUSE'");const failedBefore=domain();
  const failure=await req('/halaqoh-members.php?id=1',admin,failureBody);check(failure.status===503&&!failure.body.includes('PRIVATE_MEMBERSHIP_CAUSE')&&domain()===failedBefore,'Required journal failure rolls back membership and roster changes without exposing SQL');sql('DROP TRIGGER reject_membership_journal');
  check((await req('/halaqoh-members.php?id=1',admin,failureBody)).status===302,'The same token safely retries after a rolled-back journal failure');
  const escaped=await req('/halaqoh-members.php?id=1',admin);check(escaped.body.includes('&lt;img src=x&gt;')&&!escaped.body.includes('<img src=x>'),'Child names are escaped in the native member list');
  check((await req('/halaqoh-members.php?id[]=1',admin)).status===422&&(await req('/halaqoh-members.php?id=1&search[]=bad',admin)).status===422,'Malformed IDs and search filters fail before layout output');
  sql('DROP TRIGGER tahsin_autosave_au');
  check((await req('/halaqoh.php',admin)).body.includes('name="program_code"'),'Program controls remain visible when an optional autosave trigger is missing');
  const ibuWithoutAutosave=await req('/halaqoh.php',admin,{csrf_token:security,action:'save',nama_halaqoh:'Ibu tanpa autosave',ustadz_id:'3',program_code:'ibu'});
  check(ibuWithoutAutosave.status===302&&sql("SELECT program_code FROM halaqoh WHERE nama_halaqoh='Ibu tanpa autosave'")[0].program_code==='ibu','The selected Ibu program is actually stored through the administrative route');
  check(!/Fatal error|Uncaught|Warning:|Deprecated:/.test(errors),'Membership flows produce no PHP runtime warnings');
  fs.writeFileSync(path.join(sessionDir,'results.json'),JSON.stringify({database,checks,passed:true},null,2));console.log('PASS '+checks+' membership HTTP assertions\nSTAGING_DATABASE='+database);
})().catch(e=>{console.error(e);console.error(errors);process.exitCode=1;}).finally(()=>server.kill());
