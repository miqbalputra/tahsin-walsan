/* Local synthetic fixture only. Never points at production. */
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const base=process.env.TAHSIN_TEST_URL||'http://127.0.0.1:18083';
if(new URL(base).hostname!=='127.0.0.1')throw new Error('Local fixture required');
let checks=0;const check=(ok,text)=>{assert(ok,text);checks++};
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.TAHSIN_TEST_CHROME||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 const errors=[];
 async function login(name,viewport={width:390,height:844}){
  const context=await browser.newContext({viewport,serviceWorkers:'block'});const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/login.php');await page.locator('[name=username]').fill(name);await page.locator('[name=password]').fill('TahsinTest-2026');
  await Promise.all([page.waitForURL(/dashboard.php|hari-ini.php/),page.locator('button[type=submit]').click()]);return{context,page};
 }
 try{
  const anon=await browser.newContext();
  for(const path of ['/go-vue.php','/api/go-vue-families.php'])check((await anon.request.get(base+path,{headers:{'X-Test-Next-Disabled':'1'}})).status()===404,'Disabled preview hidden');
  check((await anon.request.get(base+'/api/go-vue-families.php')).status()===401,'Anonymous API denied');await anon.close();
  const teacher=await login('guru_test');check((await teacher.page.request.get(base+'/api/go-vue-families.php')).status()===403,'Teacher denied admin preview');await teacher.context.close();
  const {context,page}=await login('admin_test');
  const ready=()=>page.locator('[data-student="1"]').waitFor();
  const html=await page.goto(base+'/go-vue.php');await ready();
  check(html.status()===200,'Preview page available after opt in');
  check(await page.locator('[data-student]').count()===1,'Default lists only active children');
  check((await page.locator('[data-student="1"]').innerText()).includes('Ibu Aminah'),'Mother linked through same student');
  check((await page.locator('[data-student="1"]').innerText()).includes('Bapak Ahmad'),'Father linked through same student');
  check((await page.locator('[data-student="1"]').innerText()).includes('0000500'),'NIS retains leading zeroes');
  check(!(await html.text()).includes('0123456789abcdef'),'Server signing secret never sent in page');
  const api=await page.request.get(base+'/api/go-vue-families.php?scope=all');check(api.status()===200 && (await api.json()).meta.read_only===true,'Authenticated PHP-Go gateway succeeds');
  check(api.headers()['cache-control'].includes('no-store'),'Family data is not cacheable');
  const go=await page.request.get('http://127.0.0.1:18090/api/v1/families');check(go.status()===401,'Go API rejects unsigned direct access');
  for(const method of ['POST','PUT','DELETE'])check((await page.request.fetch(base+'/api/go-vue-families.php',{method})).status()===405,method+' cannot write through gateway');
  await page.getByRole('button',{name:'Semua anak',exact:true}).click();await page.locator('[data-student="2"]').waitFor();
  check(await page.locator('[data-student]').count()===2,'Graduated sibling retained in all children view');
  await page.locator('#nxt-search').fill('Ibu Aminah');await page.getByRole('button',{name:'Cari',exact:true}).click();await page.locator('.nxt-results[aria-busy=false]').waitFor();
  check(await page.locator('[data-student]').count()===2,'Parent search finds siblings once each');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=390),'Fits phone width');
  check(await page.locator('#go-vue-preview input:not([type=search]),#go-vue-preview textarea,#go-vue-preview [contenteditable]').count()===0,'Read-only view exposes no record editor');
  fs.mkdirSync('.sessions/next-ui',{recursive:true});await page.screenshot({path:'.sessions/next-ui/family-mobile.png',fullPage:true});
  await page.setViewportSize({width:1440,height:1000});await page.screenshot({path:'.sessions/next-ui/family-desktop.png',fullPage:true});
  await page.route('**/api/go-vue-families.php*',r=>r.fulfill({status:503,contentType:'application/json',body:'{"status":"error","message":"unavailable"}'}));
  await page.getByRole('button',{name:'Anak aktif',exact:true}).click();await page.getByRole('alert').waitFor();
  check((await page.getByRole('alert').innerText()).includes('aplikasi utama'),'Backend failure offers existing app');
  check((await page.request.get(base+'/keluarga.php')).status()===200,'PHP family page still works during Go failure');
  await page.unroute('**/api/go-vue-families.php*');await page.getByRole('button',{name:'Coba lagi',exact:true}).click();await ready();
  check(await page.locator('[data-student]').count()===1,'Retry recovers read view');
  const expected=JSON.parse(fs.readFileSync('.sessions/go-vue-expected.json','utf8'));
  const live=await (await page.request.get(base+'/api/go-vue-families.php?scope=all&limit=100')).json();
  for(const child of live.data){const old=expected.students.find(c=>Number(c.id)===child.id);check(Boolean(old)&&old.nis===child.nis&&old.source_student_id===child.source_student_id&&old.nama_anak===child.nama_anak&&old.kelas===child.kelas&&old.school_status===child.school_status,'PHP and Go student identities match');check(JSON.stringify(old.guardians.map(g=>Number(g.wali_santri_id)).sort())===JSON.stringify(child.guardians.map(g=>g.wali_santri_id).sort()),'PHP and Go parent IDs match');}
  await page.request.get(base+'/logout.php');check((await page.request.get(base+'/api/go-vue-families.php')).status()===401,'Logout revokes gateway access immediately');
  check(errors.length===0,'No JavaScript errors: '+errors.join(';'));
  fs.writeFileSync('.sessions/next-ui/verification.json',JSON.stringify({checks,errors,result:'PASS'},null,2));console.log(`PASS ${checks} Go/Vue browser assertions`);await context.close();
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
