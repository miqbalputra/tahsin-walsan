/* Local browser + HTTP integration. Requires Playwright and a Chromium executable. */
const { chromium } = require('playwright');
const { spawn, spawnSync } = require('node:child_process');
const path=require('node:path'), fs=require('node:fs'), assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..'), php=process.env.TEST_PHP_BINARY||'php';
const phpArgs=/pdo_mysql/i.test(spawnSync(php,['-m'],{encoding:'utf8',windowsHide:true}).stdout||'')?[]:['-d','extension=pdo_mysql'];
function fixture(...args){ const r=spawnSync(php,[...phpArgs,'tests/data-quality-http-fixture.php',...args],{cwd:root,encoding:'utf8',windowsHide:true}); if(r.status!==0)throw Error(r.stderr||r.stdout); return JSON.parse(r.stdout); }
const setup=fixture(), port=18117, base=`http://127.0.0.1:${port}`;
const directory=path.join(root,'.sessions',`quality-browser-${process.pid}`); fs.mkdirSync(directory,{recursive:true});
const server=spawn(php,[...phpArgs,'-d','display_errors=0','-S',`127.0.0.1:${port}`,'dev-router.php'],{cwd:root,windowsHide:true,env:{...process.env,
  DB_HOST:'127.0.0.1',DB_PORT:'13317',DB_DATABASE:setup.database,DB_USERNAME:'root',DB_PASSWORD:'tahsin_local_test',DATA_INDUK_ENABLED:'0',TAHSIN_AUTOSAVE_ENABLED:'1',SESSION_SAVE_PATH:directory,APP_ENV:'testing'},stdio:['ignore','ignore','pipe']});
let serverErrors='',checks=0; server.stderr.on('data',s=>serverErrors+=s.toString());
const check=(ok,message)=>{assert.ok(ok,message);checks++;};
let browser;
(async()=>{
  for(let i=0;i<40;i++){try{if((await fetch(base+'/login.php')).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  browser=await chromium.launch({headless:true,...(process.env.TAHSIN_TEST_CHROME?{executablePath:process.env.TAHSIN_TEST_CHROME}:process.platform==='win32'?{executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'}:{})});
  const errors=[];
  async function login(user){
    const context=await browser.newContext({viewport:{width:360,height:800},serviceWorkers:'block'}),page=await context.newPage();
    page.on('pageerror',e=>errors.push(e.message));
    await page.goto(base+'/login.php'); await page.locator('[name=username]').fill(user); await page.locator('[name=password]').fill('QualityTest-2026');
    await Promise.all([page.waitForURL(/dashboard.php|hari-ini.php/),page.locator('button[type=submit]').click()]);
    return {context,page};
  }
  const {context,page}=await login('quality_admin');
  await page.goto(base+'/periksa-data.php?search=72&status=all');
  check(await page.getByRole('heading',{name:'Keanggotaan aktif ganda',exact:true}).count()===1,'Admin sees Purwanto placement conflict');
  check(await page.getByText('H-9 (#1)',{exact:false}).count()>0,'Both placement names are visible');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Audit fits a 360px mobile viewport');
  await page.screenshot({path:path.join(directory,'audit-mobile.png'),fullPage:true});
  const attendance=page.locator('section').filter({has:page.getByRole('heading',{name:'Presensi berulang / berbeda',exact:true})});
  await attendance.locator('[name=selected_id]').selectOption('1'); await attendance.locator('[name=note]').fill('Konfirmasi pengajar H9');
  await Promise.all([page.waitForURL(/msg=saved/),attendance.getByRole('button',{name:'Simpan pilihan catatan resmi'}).click()]);
  check(await page.getByText('Catatan resmi dipilih',{exact:false}).count()>0,'Official record selection succeeds in browser');
  const selectedCard=page.locator('section').filter({has:page.getByRole('heading',{name:'Presensi berulang / berbeda',exact:true})});
  await selectedCard.locator('[name=note]').fill('Batalkan pilihan uji');
  await Promise.all([page.waitForURL(/msg=saved/),selectedCard.getByRole('button',{name:'Batalkan keputusan terakhir'}).click()]);
  const stats=fixture(setup.database); check(stats.attendance.length===2,'Decision and undo preserve both original attendance records');

  await page.goto(base+'/merge-peserta.php?keep_id=10&delete_id=11');
  await page.locator('[name=confirmed]').check();
  await Promise.all([page.waitForURL(/peserta-riwayat.php\?operation=/),page.getByRole('button',{name:'Gabungkan ke ID 10',exact:true}).click()]);
  check(await page.getByText('Penggabungan tersimpan.',{exact:false}).count()>0,'Merge saves with original snapshots');
  await page.getByRole('link',{name:'Pratinjau pembatalan merge'}).click();
  check(await page.getByText('Tidak ada perubahan lanjutan',{exact:false}).count()>0,'Unchanged merge has simple undo preview');
  const csrf=await page.locator('[name=csrf_token]').inputValue(), token=await page.locator('[name=undo_token]').inputValue();
  const missingCsrf=await context.request.post(base+'/batal-merge.php',{form:{undo_token:token,confirmed:'1'},maxRedirects:0});
  check(missingCsrf.status()===403,'Missing CSRF cannot undo merge');
  const other=await login('quality_admin'); await other.page.goto(base+'/periksa-data.php');
  const otherCsrf=await other.page.locator('[name=csrf_token]').first().inputValue();
  const cross=await other.context.request.post(base+'/batal-merge.php',{form:{csrf_token:otherCsrf,undo_token:token,confirmed:'1'},maxRedirects:0});
  check(cross.status()===422,'Undo token is bound to its original session');
  await page.locator('[name=confirmed]').check();
  await Promise.all([page.waitForURL(/peserta-riwayat.php\?operation=/),page.getByRole('button',{name:'Batalkan merge dan pulihkan identitas asal'}).click()]);
  check(await page.getByText('Merge dibatalkan.',{exact:false}).count()>0,'Undo history renders after successful restoration');
  const replay=await context.request.post(base+'/batal-merge.php',{form:{csrf_token:csrf,undo_token:token,confirmed:'1'},maxRedirects:0});
  check(replay.status()===302,'Repeated undo submission returns existing receipt');

  fixture(setup.database,'later');
  await page.goto(base+'/batal-merge.php?operation='+setup.merge);
  const choices=page.locator('select[name^="choices["]'); check(await choices.count()>=2,'Later child edit and new attendance require explicit placement');
  for(const select of await choices.all())await select.selectOption('source');
  await page.locator('[name=confirmed]').check();
  await page.screenshot({path:path.join(directory,'undo-review-mobile.png'),fullPage:true});
  await Promise.all([page.waitForURL(/peserta-riwayat.php\?operation=/),page.getByRole('button',{name:'Batalkan merge dan pulihkan identitas asal'}).click()]);
  const after=fixture(setup.database); check(after.children.find(c=>Number(c.id)===21).kelas==='3A' && Number(after.attendance.find(r=>Number(r.id)===30).wali_santri_id)===21,'Browser undo preserves later edit and assigns new attendance correctly');
  const teacher=await login('quality_teacher');
  await teacher.page.goto(base+'/ustadz/form-presensi.php?id=1&tanggal=2026-11-15');
  await teacher.page.locator('#tahsin-autosave[data-ready=true]').waitFor();
  const row=teacher.page.locator('[data-person="72"]');
  check(await row.locator('[data-admin-review]').isVisible(),'Blocked teacher sees admin explanation without expanding hidden details');
  check((await teacher.page.locator('[data-bottom-state]').innerText()).includes('perlu ditinjau'),'Footer does not claim all changes saved during a conflict');
  check(await row.locator('[data-resolve="mine"]').isHidden(),'Teacher cannot overwrite a placement conflict');
  await row.locator('[data-admin-review]').scrollIntoViewIfNeeded();
  await teacher.page.screenshot({path:path.join(directory,'teacher-conflict-mobile.png'),fullPage:true});
  const denial=await teacher.context.request.get(base+'/periksa-data.php',{maxRedirects:0}); check(denial.status()===302,'Teacher cannot access admin duplicate audit');
  const pj=await login('quality_pj'); const pjDenied=await pj.context.request.get(base+'/batal-merge.php?operation='+setup.merge,{maxRedirects:0}); check(pjDenied.status()===302,'PJ cannot undo identity merge');
  check(errors.length===0,'Browser has no uncaught JavaScript errors: '+errors.join('; '));
  check(!/Fatal error|Uncaught|PHP Warning/i.test(serverErrors),'Server has no PHP fatal errors or warnings');
  fs.writeFileSync(path.join(directory,'results.json'),JSON.stringify({passed:true,checks,database:setup.database},null,2));
  console.log(`PASS ${checks} quality browser/HTTP assertions\nBROWSER_EVIDENCE=${directory}`);
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(async()=>{if(browser)await browser.close();server.kill();});
