/** Isolated login recovery checks. Requires Playwright and a localhost fixture DB. */
const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
const appRoot = process.env.TAHSIN_TEST_APP_ROOT ? path.resolve(process.env.TAHSIN_TEST_APP_ROOT) : root;
const php = process.env.TEST_PHP_BINARY || 'php';
const dbArgs = /pdo_mysql/i.test(spawnSync(php, ['-m'], {encoding:'utf8',windowsHide:true}).stdout || '')
  ? [] : ['-d','extension=pdo_mysql'];
const setup = spawnSync(php, [...dbArgs,'tests/security-integration.php'], {cwd:root,encoding:'utf8',windowsHide:true});
assert.equal(setup.status, 0, setup.stderr || setup.stdout);
const database = setup.stdout.match(/STAGING_DATABASE=(tahsin_test_security_\d+_[a-f0-9]{8})/)?.[1];
assert.ok(database, 'Only a newly generated localhost fixture is allowed');
const port = 18089;
const base = `http://127.0.0.1:${port}`;
const sessions = path.join(root,'.sessions',`login-browser-${process.pid}`);
fs.mkdirSync(sessions,{recursive:true});
const env = {...process.env,APP_ENV:'testing',DB_HOST:'127.0.0.1',DB_PORT:'13317',DB_DATABASE:database,
  DB_USERNAME:'root',DB_PASSWORD:'tahsin_local_test',DATA_INDUK_ENABLED:'0',TAHSIN_AUTOSAVE_ENABLED:'0',
  SESSION_SAVE_PATH:sessions,APP_ENV_FILE:path.join(sessions,'absent.env')};
// The release branch expects this optional column; add it only to the fresh fixture.
const archiveColumn = spawnSync(php,[...dbArgs,'-r',
  '$p=new PDO("mysql:host=127.0.0.1;port=13317;dbname='+database+'","root","tahsin_local_test");if(!$p->query("SHOW COLUMNS FROM halaqoh_members LIKE \'archived_at\'")->fetch())$p->exec("ALTER TABLE halaqoh_members ADD COLUMN archived_at DATETIME NULL");'],
  {cwd:root,encoding:'utf8',windowsHide:true});
assert.equal(archiveColumn.status,0,archiveColumn.stderr);
const server = spawn(php,[...dbArgs,'-d','display_errors=0','-S',`127.0.0.1:${port}`,'-t',appRoot],
  {cwd:appRoot,env,windowsHide:true,stdio:['ignore','ignore','pipe']});
let errors = '', browser, checks = 0;
const failures = [];
server.stderr.on('data',bytes=>{errors+=bytes.toString();});
function check(ok, message) { checks++; if (!ok) failures.push(message); }
function snapshot() {
  const code = '$p=new PDO("mysql:host=127.0.0.1;port=13317;dbname='+database+';charset=utf8mb4","root","tahsin_local_test");$rows=[];foreach(["users","wali_santri","santri_detail","halaqoh","halaqoh_members","presensi"] as $t){$rows[$t]=$p->query("SELECT * FROM `$t` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);}echo hash("sha256",json_encode($rows));';
  const result = spawnSync(php,[...dbArgs,'-r',code],{cwd:root,encoding:'utf8',windowsHide:true});
  assert.equal(result.status,0,result.stderr); return result.stdout;
}
function attemptsSnapshot() {
  const code = '$p=new PDO("mysql:host=127.0.0.1;port=13317;dbname='+database+';charset=utf8mb4","root","tahsin_local_test");echo json_encode($p->query("SELECT * FROM login_attempts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));';
  const result=spawnSync(php,[...dbArgs,'-r',code],{cwd:root,encoding:'utf8',windowsHide:true});
  assert.equal(result.status,0,result.stderr);return result.stdout;
}
(async()=>{
  let ready=false;
  for (let retry=0;retry<50;retry++) {
    if (server.exitCode!==null) throw Error('Local server stopped: '+errors);
    try { if ((await fetch(base+'/login.php')).status===200) {ready=true;break;} } catch {}
    await new Promise(resolve=>setTimeout(resolve,100));
  }
  assert.ok(ready,'Local fixture server is reachable');
  const before=snapshot();
  browser=await chromium.launch({headless:true,...(process.env.TAHSIN_TEST_CHROME
    ? {executablePath:process.env.TAHSIN_TEST_CHROME} : {})});
  const runtimeErrors=[];
  const context=await browser.newContext({viewport:{width:390,height:844},serviceWorkers:'block'});
  const page=await context.newPage(); page.on('pageerror',e=>runtimeErrors.push(e.message));
  await page.goto(base+'/login.php');
  await page.waitForFunction(()=>!!window.Alpine);
  const rateToken=await page.locator('[name=csrf_token]').inputValue();
  for(let attempt=1;attempt<=5;attempt++) {
    const failed=await context.request.post(base+'/login.php',{form:{csrf_token:rateToken,username:'login_lockout_probe',password:'wrong'}});
    check(failed.status()===200&&(await failed.text()).includes('Username atau password salah!'),
      'Password failure is counted before the limit at attempt '+attempt);
  }
  const blocked=await context.request.post(base+'/login.php',{form:{csrf_token:rateToken,username:'login_lockout_probe',password:'wrong'}});
  check((await blocked.text()).includes('Terlalu banyak percobaan login gagal'),'The five-attempt account lockout remains enforced');
  const lockedAttempts=attemptsSnapshot();
  await page.locator('[name=username]').fill('audit_teacher');
  await page.locator('[name=password]').fill('AuditTest-2026');
  // A browser history restore preserves the DOM's pending-submit state.
  // Prevent navigation so the real submit handler can enter that state.
  await page.locator('form').evaluate(form=>form.addEventListener('submit',event=>event.preventDefault(),{once:true}));
  await page.locator('button[type=submit]').click();
  check(await page.locator('button[type=submit]').isDisabled(),'Submitting prevents duplicate login requests');
  await page.evaluate(()=>window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true})));
  await page.waitForFunction(()=>!document.querySelector('button[type=submit]').disabled,{},{timeout:2000}).catch(()=>{});
  check(await page.locator('button[type=submit]').isEnabled(),'Restoring the login page re-enables its submit button');
  check(await page.locator('[name=username]').inputValue()==='audit_teacher','History recovery preserves the entered username');
  if (await page.locator('button[type=submit]').isDisabled()) {
    await page.reload();
    await page.locator('[name=username]').fill('audit_teacher');
    await page.locator('[name=password]').fill('AuditTest-2026');
  }
  await Promise.all([page.waitForURL('**/ustadz/dashboard.php'),page.locator('button[type=submit]').click()]);
  check((await page.locator('body').innerText()).includes('Guru'),'Mobile teacher login reaches the dashboard');
  check((await page.request.get(base+'/ustadz/dashboard.php')).status()===200,'The regenerated login session persists on the next request');
  await context.close();
  // Failed or blocked JavaScript must preserve ordinary HTML login behavior.
  const fallback=await browser.newContext({javaScriptEnabled:false,serviceWorkers:'block'});
  const fallbackPage=await fallback.newPage();
  await fallbackPage.goto(base+'/login.php');
  check(await fallbackPage.locator('[name=password]').getAttribute('type')==='password','The password is masked even when JavaScript fails to load');
  await fallbackPage.locator('[name=username]').fill('audit_teacher');
  await fallbackPage.locator('[name=password]').fill('AuditTest-2026');
  await Promise.all([fallbackPage.waitForURL('**/ustadz/dashboard.php'),fallbackPage.locator('button[type=submit]').click()]);
  check((await fallbackPage.locator('body').innerText()).includes('Guru'),'Native HTML login works without JavaScript');
  check((await fallbackPage.request.get(base+'/ustadz/dashboard.php')).status()===200,'Native HTML login retains a valid session');
  await fallback.close();
  const expiredContext=await browser.newContext({serviceWorkers:'block'});
  const expiredPage=await expiredContext.newPage();
  expiredPage.on('pageerror',e=>runtimeErrors.push(e.message));
  await expiredPage.goto(base+'/login.php');
  await expiredPage.locator('[name=username]').fill('audit_teacher');
  await expiredPage.locator('[name=password]').fill('AuditTest-2026');
  const oldToken=await expiredPage.locator('[name=csrf_token]').inputValue();
  await expiredContext.clearCookies();
  const [expiredResponse]=await Promise.all([
    expiredPage.waitForResponse(response=>response.url()===base+'/login.php'&&response.request().method()==='POST'),
    expiredPage.locator('button[type=submit]').click(),
  ]);
  await expiredPage.locator('form [name=csrf_token]').waitFor({state:'attached'});
  check(expiredResponse.status()===403,'An expired login form is rejected before authenticating');
  check((await expiredPage.locator('body').innerText()).includes('Sesi keamanan formulir')
    && /^[a-f0-9]{64}$/.test(await expiredPage.locator('[name=csrf_token]').inputValue())
    && await expiredPage.locator('[name=csrf_token]').inputValue()!==oldToken,
    'Expired login form displays a useful error and a usable security token');
  check(await expiredPage.locator('[name=password]').inputValue()==='', 'The server never echoes the submitted password');
  await expiredPage.locator('[name=username]').fill('audit_teacher');
  await expiredPage.locator('[name=password]').fill('AuditTest-2026');
  await Promise.all([expiredPage.waitForURL('**/ustadz/dashboard.php'),expiredPage.locator('button[type=submit]').click()]);
  check((await expiredPage.request.get(base+'/ustadz/dashboard.php')).status()===200,'A teacher can retry login directly after a stale form error');
  await expiredContext.close();
  check(attemptsSnapshot()===lockedAttempts,'Other-account logins and stale CSRF preserve the locked account history exactly');
  check(runtimeErrors.length===0,'Login produces no browser JavaScript errors');
  check(snapshot()===before,'Browser login preserves every account hash, participant, group, membership and attendance row');
  assert.deepEqual(failures,[]);
  console.log(`PASS ${checks} login browser assertions`);
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{
  await browser?.close();server.kill();
});
