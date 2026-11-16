/** Exercise actual client code with controlled delayed/lost HTTP responses. */
const assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs'),crypto=require('node:crypto').webcrypto;
const source=fs.readFileSync(require('node:path').join(__dirname,'../assets/js/tahsin-legacy.js'),'utf8');
let checks=0;const check=(value,message)=>{assert.ok(value,message);checks++;};
const tick=()=>new Promise(resolve=>setImmediate(resolve));
function harness(){
  const requests=[],listeners={},stats={submits:0,confirms:0},window={addEventListener:(name,listener)=>listeners[name]=listener,confirm:()=>{stats.confirms++;return false;}},version={value:'a'.repeat(64)},error={hidden:true};
  let card;
  const form={elements:{csrf_token:{value:'csrf'},attendance_form_token:{value:'form'}},reportValidity:()=>true,
    querySelector:selector=>selector==='[data-legacy-error]'?error:card.blocked?{}:null};
  const el={dataset:{},closest:()=>form,querySelector:selector=>selector==='[data-entry-revision]'?version:selector.includes('hasil_talaqqi')?{value:'Lulus'}:selector.includes('jilid')?{value:'2'}:selector.includes('alasan')?{value:''}:null};
  vm.runInNewContext(source,{window,document:{querySelector:()=>el.dataset.legacyUnsaved==='1'?{}:null,addEventListener:(name,listener)=>listeners['document:'+name]=listener},crypto,FormData,HTMLFormElement:{prototype:{submit(){stats.submits++;}}},fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,body:options.body,resolve,reject})),Promise,Uint8Array,Set,Array,Object,Error});
  card=window.legacyAttendanceCard({waliId:1,groupId:1,date:'2026-10-18',status:'H',materi:'Iqro',halamanDari:'1',halamanSampai:'2',suratDari:'',suratSampai:'',intent:'save',wasSaved:false},(_title,_message,callback)=>callback());card.$el=el;
  const reply=(request,revision,status=200)=>request.resolve({ok:status===200,status,json:async()=>status===200?{status:'success',entry_revisions:{1:revision}}:{status:'error',message:'Versi berubah'}});
  return{window,card,requests,version,error,form,stats,reply,listeners,el};
}
(async()=>{
  const h=harness();h.card.saveDraft();await tick();
  h.card.halamanDari='3';h.card.halamanSampai='4';h.card.saveDraft();await tick();
  check(h.requests.length===1,'A second edit waits for the first network request');
  check(h.requests[0].body.get('halaman_dari')==='1','Each queued request retains its own input snapshot');
  h.reply(h.requests[0],'b'.repeat(64));await tick();
  check(h.requests.length===2&&h.requests[1].body.get('entry_revision')==='b'.repeat(64),'The next edit uses the acknowledged revision');
  check(h.requests[1].body.get('halaman_dari')==='3','The newer edit is delivered after the earlier one');
  h.reply(h.requests[1],'c'.repeat(64));await h.card.queue;
  check(h.version.value==='c'.repeat(64)&&!h.card.isSaving,'Final revision and pending state reflect the latest acknowledgement');
  check(h.el.dataset.legacyUnsaved==='0','The acknowledged current fields no longer claim unsaved work');

  const lost=harness();lost.card.saveDraft();await tick();lost.requests[0].reject(new Error('Response lost after commit'));await lost.card.queue;
  check(lost.card.blocked&&lost.card.retryRequest&&lost.version.value==='a'.repeat(64),'An uncertain response keeps its input and blocks further overwrites');
  const original=Array.from(lost.requests[0].body.entries());lost.card.retrySend();await tick();
  check(JSON.stringify(Array.from(lost.requests[1].body.entries()))===JSON.stringify(original),'Retry preserves the exact payload, operation ID and original revision');
  lost.reply(lost.requests[1],'d'.repeat(64));await lost.card.queue;
  check(!lost.card.blocked&&!lost.card.retryRequest&&lost.version.value==='d'.repeat(64),'A recovered receipt releases the form after acknowledgement');

  const conflict=harness();conflict.card.saveDraft();await tick();conflict.reply(conflict.requests[0],null,409);await conflict.card.queue;
  check(conflict.card.blocked&&!conflict.card.retryRequest,'A stale response requires review and never silently retries');
  await conflict.window.legacyAttendanceFinalize({target:conflict.form},{isFinalizing:false,$nextTick:async()=>{}});
  check(conflict.stats.submits===0&&!conflict.error.hidden,'Full finalization cannot bypass a blocked participant');

  const wait=harness();wait.card.saveDraft();await tick();const final=wait.window.legacyAttendanceFinalize({target:wait.form},{isFinalizing:false,$nextTick:async()=>{}});await tick();
  check(wait.stats.submits===0,'Finalization waits for all in-flight per-member requests');
  wait.reply(wait.requests[0],'e'.repeat(64));await final;
  check(wait.stats.submits===1&&wait.version.value==='e'.repeat(64),'Finalization sends the current revisions after pending writes settle');
  const partial=harness();partial.card.markEdited();const event={preventDefault(){this.prevented=true;}};partial.listeners.beforeunload(event);
  check(event.prevented&&event.returnValue==='','Incomplete unsaved fields receive a native leave-page warning');
  const allowed={preventDefault(){this.prevented=true;}};wait.listeners.beforeunload(allowed);
  check(!allowed.prevented,'An authorized final form submission is not interrupted by a leave-page warning');
  const leave={target:{closest:()=>({target:'',hasAttribute:()=>false,getAttribute:()=>'/next.php'})},button:0,preventDefault(){this.prevented=true;}};
  partial.listeners['document:click'](leave);
  check(leave.prevented&&partial.stats.confirms===1,'Normal links preserve incomplete fields when leaving is declined');
  check(!partial.window.legacyAttendanceCanLeave(),'Date navigation also requires a choice before discarding incomplete fields');
  console.log('PASS '+checks+' legacy attendance request-order assertions');
})().catch(error=>{console.error(error);process.exitCode=1;});
