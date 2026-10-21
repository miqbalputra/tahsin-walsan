/* HTTP contract test, synthetic localhost fixture only. No browser data or real credentials. */
const assert=require('node:assert/strict');
const fs=require('node:fs');
const base='http://127.0.0.1:18085';
const fixture=JSON.parse(fs.readFileSync(process.env.TAHSIN_SESSION_EXPECTED_FILE,'utf8'));
const sessionQuery=new URLSearchParams({halaqoh_id:'11',tanggal:fixture.cases.find(c=>c.name==='historical-owner-and-conflicts').date}).toString();
let checks=0;
function check(ok,label){assert(ok,label);checks++;}
async function login(user){
  const page=await fetch(base+'/login.php');const cookie=page.headers.getSetCookie().find(v=>v.startsWith('PHPSESSID=')).split(';')[0];
  const html=await page.text();const token=html.match(/name="csrf_token" value="([^"]+)"/)[1];
  const response=await fetch(base+'/login.php',{method:'POST',redirect:'manual',headers:{cookie,'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({username:user,password:'TahsinTest-2026',csrf_token:token})});
  check(response.status===302,'Synthetic account signs in');
  return response.headers.getSetCookie().find(v=>v.startsWith('PHPSESSID='))?.split(';')[0]||cookie;
}
(async()=>{
 for(const url of [base+'/health.php','http://127.0.0.1:18091/healthz']){
   let ready=false;
   for(let attempt=0;attempt<50;attempt++){try{ready=(await fetch(url)).ok;}catch{}if(ready)break;await new Promise(resolve=>setTimeout(resolve,100));}
   assert(ready,'Owned local service must be ready before HTTP assertions');
 }
 const path='/api/go-vue-teacher-session.php';
 check((await fetch(base+path+'?'+sessionQuery)).status===401,'Anonymous denied');
 const admin=await login('admin_test');check((await fetch(base+path+'?'+sessionQuery,{headers:{cookie:admin}})).status===403,'Admin cannot impersonate teacher');
 const cookie=await login('guru_test');
 for(const c of fixture.cases.filter(c=>c.user===2)){
   const query=new URLSearchParams({halaqoh_id:String(c.group),tanggal:c.date});
   const response=await fetch(base+path+'?'+query,{headers:{cookie}});check(response.status===c.status,`Gateway status ${c.name}: got ${response.status}, expected ${c.status}${response.status===c.status?'':': '+await response.clone().text()}`);
   if(c.status===200){const data=await response.json();const php=await (await fetch(base+'/ustadz/api-autosave.php?'+query,{headers:{cookie}})).json();assert.deepEqual(data,php);checks++;check(response.headers.get('cache-control')==='no-store, private','Session responses cannot be cached');}
 }
 check((await fetch(base+path+'?'+sessionQuery.replace('halaqoh_id=11','halaqoh_id=3'),{headers:{cookie}})).status===404,'Nonpilot group denied');
 check((await fetch(base+path+'?'+sessionQuery,{headers:{cookie,'X-Test-Teacher-Read-Disabled':'1'}})).status===404,'Flag off hides endpoint');
 check((await fetch(base+path+'?'+sessionQuery+'&user_id=4',{headers:{cookie}})).status===422,'Client cannot choose signed identity');
 check((await fetch(base+path,{method:'POST',headers:{cookie,'Content-Type':'application/json'},body:'{}'})).status===405,'No write method on Go read gateway');
 const other=await login('guru_lain');check((await fetch(base+path+'?'+sessionQuery,{headers:{cookie:other}})).status===403,'New owner cannot read historical roster owned by old teacher');
 check((await fetch(base+'/api/go-vue-families.php',{headers:{cookie}})).status===403,'Family API still refuses teachers');
 console.log(`PASS ${checks} teacher session HTTP assertions`);
})().catch(e=>{console.error(e);process.exitCode=1});
