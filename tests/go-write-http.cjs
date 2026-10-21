/* Owned local synthetic staging only. Exercises the existing browser API through PHP CSRF/login. */
const assert=require('node:assert/strict');const fs=require('node:fs');const {spawnSync}=require('node:child_process');
const base='http://127.0.0.1:18086';const fixture=JSON.parse(fs.readFileSync(process.env.TAHSIN_WRITE_FIXTURE_FILE,'utf8'));if(!/^tahsin_test_autosave_[a-z0-9_]+$/.test(fixture.database))throw new Error('Synthetic fixture required');
const run=Date.now().toString(36);let checks=0;function check(ok,label){assert(ok,label);checks++;}
function bridge(input){const r=spawnSync(process.env.TAHSIN_TEST_PHP_BINARY,['-d','extension=php_pdo_mysql.dll',process.env.TAHSIN_WRITE_BRIDGE_FILE,fixture.database],{input:JSON.stringify(input),encoding:'utf8'});assert.equal(r.status,0,r.stderr);const result=JSON.parse(r.stdout);assert.equal(result.status,200,JSON.stringify(result));return result.data;}
async function login(){const p=await fetch(base+'/login.php');const cookie=p.headers.getSetCookie().find(v=>v.startsWith('PHPSESSID=')).split(';')[0];const html=await p.text();const token=html.match(/name="csrf_token" value="([^"]+)"/)[1];const r=await fetch(base+'/login.php',{method:'POST',redirect:'manual',headers:{cookie,'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({username:'guru_test',password:'TahsinTest-2026',csrf_token:token})});check(r.status===302,'Teacher login succeeds');return r.headers.getSetCookie().filter(v=>v.startsWith('PHPSESSID=')).at(-1)?.split(';')[0]||cookie;}
(async()=>{
 for(let i=0;i<50;i++){try{if((await fetch(base+'/health.php')).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
 const cookie=await login();const page=await (await fetch(base+'/ustadz/form-presensi.php?id=3&tanggal='+fixture.date,{headers:{cookie}})).text();
 const match=page.match(/<script id="tahsin-autosave-data" type="application\/json">([\s\S]*?)<\/script>/);assert(match,'Autosave boot data exists');const boot=JSON.parse(match[1]);
 const url=base+'/ustadz/api-autosave.php';const query=new URLSearchParams({halaqoh_id:'3',tanggal:fixture.date});
 const read=async(group=3)=>(await (await fetch(url+'?'+new URLSearchParams({halaqoh_id:String(group),tanggal:fixture.date}),{headers:{cookie}})).json()).data;
 const post=body=>fetch(url,{method:'POST',headers:{cookie,'Content-Type':'application/json'},body:JSON.stringify(body)});
 bridge({action:'transfer',date:fixture.date,owner:'go'});
 const state=await read();const entry=state.entries.find(e=>e.wali_id===10);
 const body={action:'save',operation_id:'http_go_operation_'+run,halaqoh_id:3,tanggal:fixture.date,program:state.program,wali_id:10,payload:{...entry.payload,status:'S',alasan:'Keterangan <ibu> / keluarga 😀'},context_revision:state.context_revision,official_revision:entry.official_revision,draft_revision:entry.draft_revision,csrf_token:boot.csrf_token};
 check((await post({...body,csrf_token:'invalid'})).status===403,'CSRF is checked before Go forwarding');
 const first=await post(body);check(first.status===200&&first.headers.get('x-tahsin-writer')==='go','Existing PHP endpoint forwards to sole Go writer');const saved=await first.json();
 const retry=await post(body);check(retry.status===200,'Lost response retry accepted');assert.deepEqual(await retry.json(),saved);checks++;
 check((await read()).entries.find(e=>e.wali_id===10).official_revision===saved.data.official_revision,'PHP GET reads the Go official revision');
 const old=(await read(1)).entries[0];const bapak=await read(1);const other={...body,operation_id:'http_php_other_'+run,halaqoh_id:1,program:bapak.program,wali_id:old.wali_id,payload:{...old.payload,status:'A'},context_revision:bapak.context_revision,official_revision:old.official_revision,draft_revision:old.draft_revision};
 check((await post(other)).status===200,'Other PHP group remains writable during Go pilot');
 const latest=(await read()).entries.find(e=>e.wali_id===10);
 const disabled=await fetch(url,{method:'POST',headers:{cookie,'Content-Type':'application/json','X-Test-Writer-Disabled':'1'},body:JSON.stringify({...body,operation_id:'old_php_tab_'+run,official_revision:latest.official_revision,draft_revision:latest.draft_revision})});check(disabled.status===503,'Old PHP-only sender cannot bypass Go fence with a current revision');
 bridge({action:'transfer',date:fixture.date,owner:'paused'});check((await post({...body,operation_id:'paused_http_retry_0001'})).status===503,'Pause is retryable and does not become validation conflict');
 bridge({action:'transfer',date:fixture.date,owner:'php'});const replay=await post(body);check(replay.status===200,'PHP accepts Go receipt after handback');assert.deepEqual(await replay.json(),saved);checks++;
 console.log(`PASS ${checks} writer HTTP assertions`);
})().catch(e=>{console.error(e);process.exitCode=1});
