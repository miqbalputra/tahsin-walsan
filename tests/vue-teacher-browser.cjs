/* Synthetic migration router only: cross-renderer draft and immutable receipt recovery. */
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const base = process.env.TAHSIN_TEST_URL || 'http://127.0.0.1:18084';
if (new URL(base).hostname !== '127.0.0.1') throw new Error('Local synthetic fixture required');
const date = process.env.TAHSIN_TEST_DATE || '2026-10-18';
const editorURL = `${base}/ustadz/form-presensi.php?id=3&tanggal=${date}`;
let checks = 0;
const check = (ok, name) => { assert(ok, name); checks++; };
(async () => {
  const browser = await chromium.launch({headless:true,executablePath:process.env.TAHSIN_TEST_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe'});
  const context = await browser.newContext({viewport:{width:390,height:844},serviceWorkers:'block'});
  const page = await context.newPage(); const errors = []; page.on('pageerror', e => errors.push(e.message));
  const ready = () => page.locator('#tahsin-autosave[data-ready=true]').waitFor();
  const row = () => page.locator('[data-person="11"]');
  const saved = () => page.locator('[data-person="11"][data-saving-state=official]').waitFor();
  const state = async () => (await (await page.request.get(`${base}/ustadz/api-autosave.php?halaqoh_id=3&tanggal=${date}`)).json()).data;
  const local = () => page.evaluate(() => new Promise((resolve,reject) => {
    const request = indexedDB.open('tahsin-autosave-v1',1);
    request.onerror = () => reject(request.error);
    request.onsuccess = () => { const db=request.result,tx=db.transaction('drafts','readonly'),get=tx.objectStore('drafts').getAll();let rows=[];get.onsuccess=()=>{rows=get.result};tx.oncomplete=()=>{db.close();resolve(rows.filter(r=>r.wali_id===11&&r.dirty))}; };
  }));
  try {
    await page.goto(base+'/login.php'); await page.locator('[name=username]').fill('guru_test'); await page.locator('[name=password]').fill('TahsinTest-2026');
    await Promise.all([page.waitForURL(/hari-ini.php/), page.locator('button[type=submit]').click()]);
    await page.goto(editorURL); await ready();
    check(await page.locator('#vue-teacher-root').count()===1,'Pilot group uses Vue while Go service remains disabled');
    check(await page.locator('#tas-surahs option').count()===114,'All Quran suggestions retained');
    const outside = await page.request.get(`${base}/ustadz/form-presensi.php?id=1&tanggal=${date}`);
    check(!(await outside.text()).includes('id="vue-teacher-root"'),'Bapak outside pilot keeps PHP form');
    const disabled = await page.request.get(editorURL,{headers:{'X-Test-Vue-Disabled':'1'}});
    check(!(await disabled.text()).includes('id="vue-teacher-root"'),'Disabled pilot immediately returns PHP form');
    check((await page.request.get(base+'/api/go-vue-families.php')).status()===404,'Teacher pilot does not enable Go family gateway');
    await row().locator('[data-status=I]').click(); await row().locator('[data-field=alasan]').fill('Awal sebelum pindah tampilan'); await saved();
    await context.setOffline(true); await row().locator('[data-field=alasan]').fill('Draft Vue ketika koneksi putus');
    await page.waitForFunction(()=>document.querySelector('[data-person="11"] [data-save-status]').textContent.includes('Tersimpan di HP'));
    await page.route('**/ustadz/api-autosave.php', route => route.request().method()==='POST' ? route.abort() : route.continue());
    await context.setOffline(false);
    await page.locator('#vue-teacher-fallback').click(); await ready();
    check(await page.locator('#vue-teacher-root').count()===0,'Fallback opens shared PHP autosave, not legacy manual finalize');
    const restored = await row().locator('[data-field=alasan]').inputValue();
    check(restored==='Draft Vue ketika koneksi putus','PHP restores Vue local draft with original scope: '+JSON.stringify({restored,local:await local()}));
    check(await page.locator('[name=renderer][value=php]').count()===1,'Date navigation retains chosen PHP fallback');
    await row().locator('[data-toggle]').click(); await row().locator('[data-field=alasan]').fill('Draft PHP untuk dibuka di Vue');
    await page.waitForFunction(()=>document.querySelector('[data-person="11"] [data-save-status]').textContent.includes('Tersimpan di HP'));
    await page.goto(editorURL); await ready();
    check(await row().locator('[data-field=alasan]').inputValue()==='Draft PHP untuk dibuka di Vue','Vue restores PHP local draft without copying to another database');
    await page.unroute('**/ustadz/api-autosave.php'); await page.evaluate(()=>window.dispatchEvent(new Event('online'))); await saved();
    const before = (await state()).entries.find(e=>e.wali_id===11).draft_revision;
    let acknowledged = false, release; const committed = new Promise(resolve=>{release=resolve});
    await page.route('**/ustadz/api-autosave.php', async route => {
      if (!acknowledged && route.request().method()==='POST') { acknowledged=true; await route.fetch(); await route.abort(); release(); }
      else if (route.request().method()==='POST') await route.abort(); else await route.continue();
    });
    await row().locator('[data-toggle]').click(); await row().locator('[data-field=alasan]').fill('Commit server tetapi respons hilang'); await committed;
    const flights = await local(); check(flights.some(r=>r.flight?.body.operation_id),'Unacknowledged immutable operation remains in IndexedDB');
    await page.locator('#vue-teacher-fallback').click(); await ready();
    await page.unroute('**/ustadz/api-autosave.php'); await page.evaluate(()=>window.dispatchEvent(new Event('online'))); await saved();
    const after = (await state()).entries.find(e=>e.wali_id===11);
    check(after.draft_revision===before+1 && after.payload.alasan==='Commit server tetapi respons hilang','Switching renderers retries original committed operation once');
    check((await local()).length===0,'Recovered sources cleared only after matching server acknowledgment');
    await page.goto(editorURL); await ready();
    check(await row().locator('[data-field=alasan]').inputValue()==='Commit server tetapi respons hilang','Return to Vue shows same official data');
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Vue fits mobile width');
    fs.mkdirSync('.sessions/migration-ui',{recursive:true}); await page.screenshot({path:'.sessions/migration-ui/teacher-vue-mobile.png',fullPage:true});
    check(errors.length===0,'No cross-renderer runtime errors: '+errors.join(';'));
    fs.writeFileSync('.sessions/migration-ui/verification.json',JSON.stringify({ok:true,checks,errors},null,2));
    console.log(`PASS ${checks} Vue/PHP migration browser assertions`);
  } finally { await context.close(); await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
