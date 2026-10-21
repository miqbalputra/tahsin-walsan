/* Requires the isolated autosave fixture and localhost router. No production connections. */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const base = process.env.TAHSIN_TEST_URL || 'http://127.0.0.1:18082';
if (new URL(base).hostname !== '127.0.0.1') throw new Error('Only isolated localhost allowed');
const date = process.env.TAHSIN_TEST_DATE;
if (!/^\d{4}-\d{2}-\d{2}$/.test(date || '')) throw new Error('Set TAHSIN_TEST_DATE to the fixture date');
const url = `${base}/ustadz/form-presensi.php?id=3&tanggal=${date}`;
let checks = 0;
const check = (ok, text) => { assert(ok, text); checks++; };
(async () => {
  const browser = await chromium.launch({headless:true, executablePath:process.env.TAHSIN_TEST_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe'});
  const errors = [];
  async function login(username = 'guru_test', options = {}) {
    const context = await browser.newContext({viewport:{width:360,height:800}, serviceWorkers:'block', ...options});
    const page = await context.newPage(); page.on('pageerror', e => errors.push(e.message));
    await page.goto(base + '/login.php');
    await page.locator('[name=username]').fill(username); await page.locator('[name=password]').fill('TahsinTest-2026');
    await Promise.all([page.waitForURL(/hari-ini\.php|dashboard\.php/), page.locator('button[type=submit]').click()]);
    await page.goto(url); return {context,page};
  }
  const ready = page => page.locator('#tahsin-autosave[data-ready=true]').waitFor();
  const row = (page, id) => page.locator(`[data-person="${id}"]`);
  const saved = (page, id, state) => page.locator(`[data-person="${id}"][data-saving-state="${state}"]`).waitFor({timeout:20000});
  try {
    const {context,page} = await login(); await ready(page);
    check(await page.locator('[data-person]').count() === 4, 'Ibu full participant list shown');
    check(await page.locator('h1').innerText() === 'Ibu Uji', 'Program group displayed');
    await row(page,10).locator('[data-status=H]').click();
    await row(page,10).locator('[data-field=jenis_materi]').selectOption('Iqro');
    await row(page,10).locator('[data-field=jilid]').selectOption('3');
    await saved(page,10,'draft');
    check((await row(page,10).locator('[data-save-status]').innerText()).includes('Draft tersimpan'), 'Partial H saved automatically to server');
    const requestState = async p => {
      const response = await p.request.get(`${base}/ustadz/api-autosave.php?halaqoh_id=3&tanggal=${date}`);
      check(response.status() === 200, 'Session API responds'); return (await response.json()).data;
    };
    let state = await requestState(page);
    check(!state.entries.find(e=>e.wali_id===10).official_complete, 'Incomplete H absent from official attendance');
    const other = await login(); await ready(other.page);
    check(await row(other.page,10).locator('[data-field=jilid]').inputValue() === '3', 'Partial draft recovered on a different device');
    await other.context.close();
    await page.reload(); await ready(page);
    await row(page,10).locator('[data-toggle]').click();
    await row(page,10).locator('[data-field=halaman_dari]').fill('9');
    await row(page,10).locator('[data-field=halaman_sampai]').fill('10');
    await row(page,10).locator('[data-result=Lulus]').click();
    await saved(page,10,'official');
    check((await row(page,10).locator('[data-save-status]').innerText()).includes('Tersimpan otomatis'), 'Complete H automatically promoted');
    // Change text while retaining focus: this must use input, not only change/blur.
    await row(page,11).locator('[data-status=I]').click();
    await row(page,11).locator('[data-field=alasan]').fill('Ada keperluan keluarga');
    await saved(page,11,'official');
    state = await requestState(page);
    check(state.entries.find(e=>e.wali_id===11).payload.alasan === 'Ada keperluan keluarga', 'Typing autosaves without leaving the field');
    const typingRequests = [], typingStart = Date.now();
    const noteRequest = request => { if (request.url().includes('/api-autosave.php') && request.method()==='POST') typingRequests.push(Date.now()); };
    page.on('request',noteRequest);
    await row(page,11).locator('[data-field=alasan]').fill('');
    await row(page,11).locator('[data-field=alasan]').pressSequentially('ketikan berlanjut',{delay:180});
    await saved(page,11,'official'); page.off('request',noteRequest);
    check(typingRequests.length>0 && typingRequests[0]-typingStart<2600,'Continuous typing is sent within the two-second scheduling budget');
    state = await requestState(page);
    // Server commits, but the response is lost. A retry must replay the receipt.
    const beforeLost = state.entries.find(e=>e.wali_id===11).draft_revision;
    let lost = false;
    await page.route('**/ustadz/api-autosave.php', async route => {
      if (!lost && route.request().method()==='POST') { lost = true; await route.fetch(); await route.abort(); }
      else await route.continue();
    });
    await row(page,11).locator('[data-field=alasan]').fill('Respons server terputus');
    await saved(page,11,'official');
    await page.unroute('**/ustadz/api-autosave.php');
    state = await requestState(page);
    check(lost && state.entries.find(e=>e.wali_id===11).draft_revision === beforeLost+1,'Lost acknowledgment retry does not duplicate the operation');
    // A delayed old acknowledgment must not overwrite newer typing.
    let release, firstSent;
    const held = new Promise(resolve=>{release=resolve;});
    const sent = new Promise(resolve=>{firstSent=resolve;});
    let hold = true;
    await page.route('**/ustadz/api-autosave.php', async route => {
      if (hold && route.request().method()==='POST') { hold=false; const response=await route.fetch(); firstSent(); await held; await route.fulfill({response}); }
      else await route.continue();
    });
    await row(page,11).locator('[data-field=alasan]').fill('Versi sementara'); await sent;
    await row(page,11).locator('[data-field=alasan]').fill('Versi paling baru'); release();
    await saved(page,11,'official'); await page.unroute('**/ustadz/api-autosave.php');
    check(await row(page,11).locator('[data-field=alasan]').inputValue()==='Versi paling baru','Late acknowledgment preserves newer local text');
    check((await requestState(page)).entries.find(e=>e.wali_id===11).payload.alasan==='Versi paling baru','Newer text reaches server after delayed response');
    await context.setOffline(true);
    await row(page,11).locator('[data-field=alasan]').fill('Keterangan offline terakhir');
    await page.waitForFunction(() => document.querySelector('[data-person="11"] [data-save-status]').textContent.includes('Tersimpan di HP'));
    check(true, 'Offline edit committed to HP');
    await context.setOffline(false);
    // Block delivery while reloading to exercise recovery from IndexedDB.
    await page.route('**/ustadz/api-autosave.php', route => route.request().method()==='POST' ? route.abort() : route.continue());
    await page.reload(); await ready(page);
    check(await row(page,11).locator('[data-field=alasan]').inputValue() === 'Keterangan offline terakhir', 'Latest HP draft restored after reload');
    await page.unroute('**/ustadz/api-autosave.php');
    await row(page,11).locator('[data-retry]').click(); await saved(page,11,'official');
    check((await requestState(page)).entries.find(e=>e.wali_id===11).payload.alasan === 'Keterangan offline terakhir','Recovered edit synchronized');
    // A real logout rotates the login/CSRF state. The same operation survives reauthentication.
    await row(page,11).locator('[data-status=I]').click();
    await context.request.get(base+'/logout.php');
    await row(page,11).locator('[data-field=alasan]').fill('Dipulihkan setelah login berakhir');
    await page.waitForFunction(()=>document.querySelector('[data-banner]').textContent.includes('Login berakhir'));
    check((await row(page,11).locator('[data-save-status]').innerText()).includes('Menunggu login'),'Expired session pauses autosave');
    await page.goto(base+'/login.php'); await page.locator('[name=username]').fill('guru_test'); await page.locator('[name=password]').fill('TahsinTest-2026');
    await Promise.all([page.waitForURL(/hari-ini\.php|dashboard\.php/),page.locator('button[type=submit]').click()]);
    await page.goto(url); await ready(page); await saved(page,11,'official');
    check((await requestState(page)).entries.find(e=>e.wali_id===11).payload.alasan==='Dipulihkan setelah login berakhir','Draft replay succeeds after login and CSRF rotation');
    // An in-progress incomplete edit must retain the previous official version.
    await row(page,10).locator('[data-status=H]').click();
    await row(page,10).locator('[data-field=halaman_sampai]').fill('');
    await saved(page,10,'draft'); state = await requestState(page);
    check(state.entries.find(e=>e.wali_id===10).official_payload.halaman_sampai === '10','Incomplete edit preserves previous official material');
    await row(page,10).locator('[data-field=halaman_sampai]').fill('11'); await saved(page,10,'official');
    // Two independent devices. Keep the second active so focus refresh cannot preempt the edit.
    const device = await login(); await ready(device.page);
    await row(page,11).locator('[data-status=I]').click();
    await row(page,11).locator('[data-field=alasan]').fill('Perubahan perangkat pertama'); await saved(page,11,'official');
    await row(device.page,11).locator('[data-status=I]').click();
    await row(device.page,11).locator('[data-field=alasan]').fill('Perubahan perangkat kedua'); await saved(device.page,11,'conflict');
    check((await row(device.page,11).locator('[data-server-version]').innerText()).includes('perangkat pertama'),'Device conflict shows server version');
    await row(device.page,11).locator('[data-resolve=mine]').click(); await saved(device.page,11,'official');
    check((await requestState(device.page)).entries.find(e=>e.wali_id===11).payload.alasan === 'Perubahan perangkat kedua','Explicit conflict resolution succeeds');
    await device.context.close(); await page.reload(); await ready(page);
    // Pending drafts are also recovered when reopening the app at Hari Ini.
    await row(page,11).locator('[data-status=I]').click();
    await page.route('**/ustadz/api-autosave.php', route => route.request().method()==='POST' && route.request().frame().url().includes('/form-presensi.php') ? route.abort() : route.continue());
    await row(page,11).locator('[data-field=alasan]').fill('Dikirim otomatis dari Hari Ini');
    await page.waitForFunction(()=>document.querySelector('[data-person="11"] [data-save-status]').textContent.includes('Tersimpan di HP'));
    await page.locator('.tas-back').click();
    await page.waitForURL(/hari-ini\.php/);
    await page.waitForFunction(()=>document.querySelector('#tahsin-pending-recovery')?.textContent.includes('Seluruh perubahan tertunda'));
    check((await requestState(page)).entries.find(e=>e.wali_id===11).payload.alasan==='Dikirim otomatis dari Hari Ini','Hari Ini automatically sends a pending local operation');
    await page.unroute('**/ustadz/api-autosave.php'); await page.goto(url); await ready(page);
    // Search/filter must leave data unchanged and only one detail open.
    await row(page,12).locator('[data-status=S]').click();
    await row(page,12).locator('[data-field=alasan]').fill('Sakit'); await saved(page,12,'official');
    await row(page,13).locator('[data-status=A]').click(); await saved(page,13,'official');
    await page.locator('[data-search]').fill('Aminah');
    check(await page.locator('[data-person]:visible').count() === 1,'Search filters existing list');
    await page.locator('[data-search]').fill('');
    await page.locator('[data-show-summary]').click();
    check(await page.locator('[data-finalize]').isEnabled(),'Finalize enabled only for complete acknowledged records');
    await page.locator('[data-finalize]').click();
    await page.waitForFunction(()=>document.querySelector('[data-banner]').textContent.includes('Sesi selesai'));
    check((await requestState(page)).completed_at !== null,'Finalization persisted in server');
    check(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth),'360px layout has no horizontal overflow');
    fs.mkdirSync('.sessions/autosave-ui',{recursive:true});
    await page.locator('[data-summary]').evaluate(el=>{el.hidden=true;});
    await row(page,10).locator('[data-status=H]').click();
    await page.locator('main').evaluate(el=>{el.scrollTop=0;});
    await page.screenshot({path:'.sessions/autosave-ui/teacher-mobile.png',fullPage:true});
    // CSRF and authorization are enforced independently of UI controls.
    const invalid = await context.request.post(base+'/ustadz/api-autosave.php',{data:{halaqoh_id:3,tanggal:date,csrf_token:'wrong'}});
    check(invalid.status()===403,'Invalid CSRF denied');
    const unauthorized = await browser.newContext();
    check((await unauthorized.request.get(`${base}/ustadz/api-autosave.php?halaqoh_id=3&tanggal=${date}`)).status()===401,'Expired login returns JSON 401');
    await unauthorized.close();
    // A fresh browser with unavailable IndexedDB must still save to server honestly.
    const failedStore = await browser.newContext({viewport:{width:390,height:844},serviceWorkers:'block'});
    await failedStore.addInitScript(()=>Object.defineProperty(window,'indexedDB',{get(){throw new Error('Test storage failure');}}));
    const failedPage = await failedStore.newPage(); failedPage.on('pageerror',e=>errors.push(e.message));
    await failedPage.goto(base+'/login.php'); await failedPage.locator('[name=username]').fill('guru_test'); await failedPage.locator('[name=password]').fill('TahsinTest-2026');
    await Promise.all([failedPage.waitForURL(/hari-ini\.php|dashboard\.php/),failedPage.locator('button[type=submit]').click()]);
    await failedPage.goto(url); await ready(failedPage);
    await row(failedPage,11).locator('[data-status=I]').click(); await row(failedPage,11).locator('[data-field=alasan]').fill('Server tetap menyimpan saat penyimpanan HP gagal');
    await saved(failedPage,11,'official');
    check((await row(failedPage,11).locator('[data-save-status]').innerText()).includes('Belum tersimpan di HP'),'Local storage failure is shown honestly');
    await failedStore.close();
    check(errors.length===0,'No browser runtime errors: '+errors.join('; '));
    console.log(`PASS ${checks} autosave browser assertions`);
    await context.close();
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
