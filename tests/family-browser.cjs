/* Uses only the synthetic autosave fixture on localhost. */
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const base=process.env.TAHSIN_TEST_URL||'http://127.0.0.1:18082';
if(new URL(base).hostname!=='127.0.0.1')throw new Error('Local fixture only');
let checks=0;const check=(ok,msg)=>{assert(ok,msg);checks++;};
const g=(id,name,type)=>({source_id:id,name,type,phone:'',address:'Alamat Uji',active:true,account_active:true});
const s=(id,name)=>({source_id:id,nis:'00123'+id,name,status:'active',class_name:'Mustawa 2 Ikhwan'});
const data={format:'tahsin-family-v1',source:'browser-fixture',snapshot_date:'2026-10-21',guardians:[g('100','Bapak Ahmad','father'),g('101','Ibu Aminah','mother')],students:[s('300','Ahmad'),s('301','Adik Ahmad')],links:[]};
for(const child of data.students)for(const guardian of data.guardians)data.links.push({guardian_id:guardian.source_id,student_id:child.source_id,relationship:guardian.type,primary_contact:guardian.type==='father'});
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.TAHSIN_TEST_CHROME||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 const context=await browser.newContext({viewport:{width:390,height:844},serviceWorkers:'block'});
 const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 try{
  const denied=await page.request.get(base+'/keluarga.php');check(denied.url().includes('login.php'),'Anonymous blocked');
  await page.goto(base+'/login.php');await page.locator('[name=username]').fill('admin_test');await page.locator('[name=password]').fill('TahsinTest-2026');
  await Promise.all([page.waitForURL(/dashboard.php/),page.locator('button[type=submit]').click()]);
  await page.goto(base+'/keluarga.php');check(await page.locator('h1').innerText()==='Keluarga Anak–Bapak–Ibu','Admin family page visible');
  await page.locator('[name=family_file]').setInputFiles({name:'keluarga.json',mimeType:'application/json',buffer:Buffer.from(JSON.stringify(data))});
  await Promise.all([page.waitForLoadState('load'),page.getByRole('button',{name:'Pratinjau Impor',exact:true}).click()]);
  await page.getByRole('heading',{name:/Pratinjau snapshot/}).waitFor();
  check(await page.locator('button[value=apply]').isDisabled(),'Unresolved mapping disables apply');
  await page.locator('select[name="choices[100]"]').selectOption('1');await page.locator('select[name="choices[101]"]').selectOption('10');
  await page.locator('button[value=review]').click();await page.waitForLoadState('load');
  check(await page.locator('button[value=apply]').isEnabled(),'Explicit mapping enables apply');
  await page.locator('button[value=apply]').click();await page.getByRole('status').waitFor();
  check((await page.getByRole('status').innerText()).includes('Impor selesai'),'Atomic import succeeds');
  check(await page.locator('section tbody tr').count()===2,'Two children displayed once each');
  const api=await page.request.get(base+'/api/hermes.php?action=keluarga',{headers:{'X-API-Key':'tahsin-test-api'}});
  check(api.status()===200,'Family API available with existing key');const body=await api.json();
  check(body.meta.total===2 && body.data.every(c=>c.guardians.length===2),'API links both parents without child duplication');
  check(body.data[0].nis.startsWith('00'),'Leading zero in NIS preserved');
  check((await page.request.get(base+'/api/hermes.php?action=keluarga')).status()===401,'Family API requires key');
  await page.locator('[name=q]').fill('Ibu Aminah');await page.getByRole('button',{name:'Cari',exact:true}).click();
  check(await page.locator('section tbody tr').count()===2,'Search by mother finds children');
  await page.goto(base+'/peserta.php?nama_ayah=Ibu%20Aminah');
  await page.getByRole('button',{name:/Edit/i}).first().click();
  check(await page.locator('[name=nama_bapak]').getAttribute('readonly')!==null,'Master identity is read-only');
  check(await page.locator('[name=no_hp]').evaluate(el=>el.checkValidity()),'Missing master phone does not block Tahsin settings form');
  await page.goto(base+'/keluarga.php');await page.waitForFunction(()=>document.querySelector('[data-sidebar]').getBoundingClientRect().right<=1);
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=390),'Family view fits phone');
  fs.mkdirSync('.sessions/family-ui',{recursive:true});await page.screenshot({path:'.sessions/family-ui/mobile.png',fullPage:true});
  check(errors.length===0,'No browser errors: '+errors.join(';'));
  console.log(`PASS ${checks} family browser assertions`);
 } finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
