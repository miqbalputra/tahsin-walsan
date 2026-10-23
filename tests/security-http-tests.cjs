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
  const program = '$p=new PDO("mysql:host=127.0.0.1;port=13317;dbname='+database+';charset=utf8mb4","root","tahsin_local_test",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$p->exec('+JSON.stringify(sql)+');';
  const result = spawnSync(php,['-d','extension=pdo_mysql','-r',program],{cwd:root,encoding:'utf8',windowsHide:true});
  assert.equal(result.status,0,result.stderr);
}
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
  localSql("UPDATE users SET role='kepsek' WHERE id=2");
  check((await req('/users.php',pj)).status===302,'Role changes revoke an existing management session');
  const teacher = await login('audit_teacher');
  localSql("UPDATE users SET role='kepsek' WHERE id=3");
  check((await req('/ustadz/api-autosave.php?halaqoh_id=1&tanggal=2026-10-18',teacher)).status===403,'Autosave API rechecks the current role');
  check((await req('/ustadz/api-save-single.php',teacher,{csrf_token:token})).status===403,'Legacy save API rechecks the current role');
  const temporary = await login('http_created');
  localSql("DELETE FROM users WHERE username='http_created'");
  check((await req('/ustadz/api-autosave.php?halaqoh_id=1&tanggal=2026-10-18',temporary)).status===401,'Deleted account is rejected by API despite its old cookie');
  for (const route of ['/config/database.php','/includes/auth_helper.php','/.sessions/audit-20261023/test-database.txt','/tests/security-integration.php','/n8n_workflow_tahsin.json','/database.sql','/composer.json','/migrate.php']) {
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
