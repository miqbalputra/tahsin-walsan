/** Fresh loopback fixture only; never reads production credentials or URLs. */
const assert=require('node:assert/strict'),{spawn,spawnSync}=require('node:child_process'),path=require('node:path'),fs=require('node:fs');
const root=path.resolve(__dirname,'..'),php=process.env.TEST_PHP_BINARY||'php';
const args=/pdo_mysql/i.test(spawnSync(php,['-m'],{encoding:'utf8',windowsHide:true}).stdout||'')?[]:['-d','extension=pdo_mysql'];
const setup=spawnSync(php,[...args,'tests/security-integration.php'],{cwd:root,encoding:'utf8',windowsHide:true});assert.equal(setup.status,0,setup.stderr||setup.stdout);
const database=setup.stdout.match(/STAGING_DATABASE=(tahsin_test_security_\d+_[a-f0-9]{8})/)?.[1];assert.ok(database,'Fresh fixture required');
const prefix='$p=new PDO("mysql:host=127.0.0.1;port=13317;dbname='+database+';charset=utf8mb4","root","tahsin_local_test",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$p->exec("SET time_zone=\'+07:00\'");';
function localPhp(code){const r=spawnSync(php,[...args,'-r',prefix+code],{cwd:root,encoding:'utf8',windowsHide:true});assert.equal(r.status,0,r.stderr||r.stdout);return r.stdout;}
function sql(query){const q='base64_decode("'+Buffer.from(query).toString('base64')+'")';const r=localPhp(/^\s*(SELECT|SHOW)\b/i.test(query)?'echo json_encode($p->query('+q+')->fetchAll(),JSON_THROW_ON_ERROR);':'$p->exec('+q+');');return r?JSON.parse(r):[];}
localPhp('require "includes/tahsin_migration_helper.php";require "includes/autosave_migration_helper.php";tahsinApplyMigration($p);autosaveApplyMigration($p);');
if(fs.existsSync(path.join(root,'includes/attendance_form_helper.php')))localPhp('require "includes/attendance_form_helper.php";attendanceFormApplyMigration($p);');
sql("UPDATE tahsin_tracking SET tracked_from='2026-10-01 00:00:00'; UPDATE tahsin_roster_history SET valid_from='2026-10-01 00:00:00'; UPDATE presensi SET jenis_materi='Iqro',jilid=2,halaman=' 1-2 ',hasil_talaqqi='Lulus',alasan='Tidak terpakai  ' WHERE id=1; INSERT INTO wali_santri(id,nama_bapak) VALUES(2,'Wali Kedua'); INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(1,2); UPDATE tahsin_roster_history SET valid_from='2026-10-01 00:00:00'; UPDATE santri_detail SET nama_anak='<svg/onload=a()>' WHERE wali_santri_id=1;");
const port=18090,base='http://127.0.0.1:'+port,sessionDir=path.join(root,'.sessions','audit-20261030','legacy-http-'+process.pid);fs.mkdirSync(sessionDir,{recursive:true});
const server=spawn(php,[...args,'-d','display_errors=0','-S','127.0.0.1:'+port,'dev-router.php'],{cwd:root,env:{...process.env,DB_HOST:'127.0.0.1',DB_PORT:'13317',DB_DATABASE:database,DB_USERNAME:'root',DB_PASSWORD:'tahsin_local_test',SESSION_SAVE_PATH:sessionDir,DATA_INDUK_ENABLED:'0',TAHSIN_AUTOSAVE_ENABLED:'0'},windowsHide:true,stdio:['ignore','ignore','pipe']});
let errors='',checks=0;server.stderr.on('data',b=>errors+=b.toString());
function check(v,m){assert.ok(v,m);checks++;}
async function req(route,jar,body){const r=await fetch(base+route,{redirect:'manual',method:body?'POST':'GET',headers:{...(jar?.cookie?{Cookie:jar.cookie}:{}),...(body?{'Content-Type':'application/x-www-form-urlencoded'}:{})},...(body?{body:new URLSearchParams(body)}:{})});const c=r.headers.get('set-cookie')?.match(/PHPSESSID=[^;]+/);if(c&&jar)jar.cookie=c[0];return{status:r.status,headers:r.headers,body:await r.text()};}
const value=(html,name)=>html.match(new RegExp('name="'+name.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+'" value="([a-f0-9]+)"'))?.[1];
const form=html=>({csrf_token:value(html,'csrf_token'),attendance_form_token:value(html,'attendance_form_token'),attendance_operation_id:value(html,'attendance_operation_id'),'entry_revisions[1]':value(html,'entry_revisions[1]'),'entry_revisions[2]':value(html,'entry_revisions[2]')});
const route='/ustadz/form-presensi.php?id=1&tanggal=2026-10-18&legacy=1';
const hadir={status:'H',jenis_materi:'Iqro',jilid:'2',halaman_dari:'3',halaman_sampai:'4',hasil_talaqqi:'Lulus'};
function single(f,id,payload,operation='a'.repeat(32)){return{csrf_token:f.csrf_token,attendance_form_token:f.attendance_form_token,entry_revision:f['entry_revisions['+id+']'],operation_id:operation,halaqoh_id:'1',wali_santri_id:String(id),tanggal:'2026-10-18',...payload};}
function full(f,entries){const b={...f,tanggal:'2026-10-18'};for(const[id,p]of Object.entries(entries))for(const[k,v]of Object.entries(p))b['presensi['+id+']['+k+']']=v;return b;}
function domain(){return JSON.stringify(['wali_santri','santri_detail','halaqoh','halaqoh_members','presensi','tahsin_autosave_drafts','presensi_history','tahsin_autosave_revisions'].map(t=>sql('SELECT * FROM '+t+' ORDER BY 1')));}
(async()=>{
  let ready=false;for(let i=0;i<40;i++){if(server.exitCode!==null)throw Error(errors);try{if((await fetch(base+'/login.php')).status===200){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,100));}check(ready,'Local fixture reachable');
  async function login(){const j={},p=await req('/login.php',j);check((await req('/login.php',j,{csrf_token:value(p.body,'csrf_token'),username:'audit_teacher',password:'AuditTest-2026'})).status===302,'Teacher fixture logs in');return j;}
  const teacher=await login(),other=await login();
  const page=await req(route,teacher),old=form(page.body);
  sql("UPDATE presensi SET halaman='9-10' WHERE id=1");const concurrent=domain();
  const stale=await req('/ustadz/api-save-single.php',teacher,single(old,1,hadir));
  check(stale.status===409&&domain()===concurrent,'A legacy per-member save cannot overwrite a source changed since the form was opened');
  if(process.argv.includes('--probe')){console.log('PASS '+checks+' stale legacy regression checks');return;}
  check(page.status===200&&old.attendance_form_token&&old['entry_revisions[1]']&&old['entry_revisions[2]'],'Legacy page binds source revisions and operation to a session form');
  check(!page.body.includes('<svg/onload=a()>')&&page.body.includes('&lt;svg/onload=a()&gt;'),'Legacy child names are escaped');
  check((await req(route,teacher,full(old,{1:hadir,2:{status:''}}))).status===409&&domain()===concurrent,'Stale full finalization cannot partly write or invent Alfa for another participant');
  const fresh=form((await req(route,teacher)).body),before=domain();
  check((await req('/ustadz/api-save-single.php',teacher,single({...fresh,attendance_form_token:''},1,hadir))).status===422&&domain()===before,'Old unversioned clients cannot bypass the source guard');
  check((await req('/ustadz/api-save-single.php',teacher,single({...fresh,csrf_token:'bad'},1,hadir))).status===403&&domain()===before,'Legacy API enforces CSRF');
  check((await req('/ustadz/api-save-single.php',other,single(fresh,1,hadir))).status===403&&domain()===before,'Another login session cannot use the bound form or CSRF');
  check((await req('/ustadz/api-save-single.php',teacher,{...single(fresh,1,hadir),halaman_dari:'','halaman_dari[]':'bad'})).status===422&&domain()===before,'Nested material input cannot reach persistence');
  const invalidBody=full(fresh,{1:hadir,2:{status:'I',alasan:'Isian <img src=x> belum lengkap'}}); invalidBody['presensi[2][halaman_dari][]']='bad';
  const invalid=await req(route,teacher,invalidBody);
  check(invalid.status===422&&invalid.body.includes('Isian &lt;img src=x&gt; belum lengkap')&&domain()===before,'Failed full-form input remains escaped and available without partial writes');
  const perBody=single(fresh,2,{status:'I',alasan:'Izin keluarga'},'b'.repeat(32)),saved=await req('/ustadz/api-save-single.php',teacher,perBody),result=JSON.parse(saved.body);
  check(saved.status===200&&result.entry_revisions?.['2'],'A per-member save returns its new source revision');
  const after=domain(),receipts=JSON.stringify(sql('SELECT * FROM presensi_form_operations ORDER BY operation_id'));
  check((await req('/ustadz/api-save-single.php',teacher,perBody)).status===200&&domain()===after&&JSON.stringify(sql('SELECT * FROM presensi_form_operations ORDER BY operation_id'))===receipts,'A lost-response retry returns its receipt without changing data or revisions');
  check((await req('/ustadz/api-save-single.php',teacher,{...perBody,alasan:'Berbeda'})).status===409&&domain()===after,'An operation cannot be reused with a different payload');
  fresh['entry_revisions[2]']=result.entry_revisions['2'];
  const original=sql('SELECT * FROM presensi WHERE id=1')[0];
  const finalBody=full(fresh,{1:{...hadir,halaman_dari:'9',halaman_sampai:'10'},2:{status:'I',alasan:'Izin keluarga'}}),final=await req(route,teacher,finalBody);
  check(final.status===302&&JSON.stringify(sql('SELECT * FROM presensi WHERE id=1')[0])===JSON.stringify(original),'Finalization accepts its own autosave and preserves unchanged raw/unused original values');
  const finalState=domain(),finalReceipts=JSON.stringify(sql('SELECT * FROM presensi_form_operations ORDER BY operation_id'));
  check((await req(route,teacher,finalBody)).status===302&&domain()===finalState&&JSON.stringify(sql('SELECT * FROM presensi_form_operations ORDER BY operation_id'))===finalReceipts,'Full finalization also has a durable replay receipt');
  const resetForm=form((await req(route,teacher)).body);sql("UPDATE presensi SET halaman='11-12' WHERE id=1");const resetBefore=domain();
  check((await req('/ustadz/api-save-single.php',teacher,single(resetForm,1,{intent:'reset'},'c'.repeat(32)))).status===409&&domain()===resetBefore,'A stale reset cannot delete newer data');
  const failForm=form((await req(route,teacher)).body);sql("CREATE TRIGGER reject_form_receipt BEFORE INSERT ON presensi_form_operations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PRIVATE_DATABASE_CAUSE'");const failedBefore=domain();
  const failure=await req('/ustadz/api-save-single.php',teacher,single(failForm,1,hadir,'d'.repeat(32)));
  check(failure.status===503&&!failure.body.includes('PRIVATE_DATABASE_CAUSE')&&domain()===failedBefore,'Receipt failure rolls back attendance, versions and revisions while keeping SQL errors private');sql('DROP TRIGGER reject_form_receipt');
  check((await req('/ustadz/form-presensi.php?id[]=1&legacy=1',teacher)).status===422&&(await req('/ustadz/form-presensi.php?id=1&tanggal[]=bad&legacy=1',teacher)).status===422,'Malformed page identifiers and dates fail before output');
  const resetFresh=form((await req(route,teacher)).body),beforeReset=sql('SELECT * FROM presensi WHERE id=1')[0];
  const reset=await req('/ustadz/api-save-single.php',teacher,single(resetFresh,1,{intent:'reset'},'e'.repeat(32)));
  check(reset.status===200&&sql('SELECT * FROM presensi WHERE id=1').length===0&&sql("SELECT * FROM presensi_history WHERE event='delete' AND presensi_id='1'").length>0&&beforeReset.halaman==='11-12','A reviewed reset stores a full deletion version and preserves other participants');
  check(!/Fatal error|Uncaught|Warning:/.test(errors),'Legacy paths produce no runtime warnings');
  fs.writeFileSync(path.join(sessionDir,'results.json'),JSON.stringify({database,checks,passed:true},null,2));console.log('PASS '+checks+' legacy attendance HTTP assertions\nSTAGING_DATABASE='+database);
})().catch(e=>{console.error(e);console.error(errors);process.exitCode=1;}).finally(()=>server.kill());
