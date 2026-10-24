/* Deterministic storage protocol tests; this does not run a browser engine. */
const assert=require('node:assert/strict');
const {DraftStore,AutosaveApp,pendingRecord,requiresExitWarning,exportDrafts}=require('../assets/js/tahsin-autosave.js');
const copy=value=>structuredClone(value);
let checks=0;
const check=(condition,message)=>{ assert.ok(condition,message); checks++; };
function fakeDatabase(initial=[],failAt=0){
  const data=new Map(initial.map(row=>[row.key,copy(row)])); let writeCount=0;
  return {data,transaction(){
    const staged=new Map([...data].map(([key,row])=>[key,copy(row)]));
    let active=0,done=false;
    const tx={error:null,abort(){if(done)return;done=true;tx.error=new Error('Synthetic abort');queueMicrotask(()=>tx.onabort?.());},objectStore(){return store;}};
    const request=(kind,value)=>{
      const req={}; active++;
      queueMicrotask(()=>{
        if(done)return;
        try{
          if(kind==='get')req.result=copy(staged.get(value));
          else {
            writeCount++;
            if(failAt && writeCount===failAt)throw Error('Synthetic quota failure');
            if(kind==='add' && staged.has(value.key))throw Error('Duplicate key');
            staged.set(value.key,copy(value));
          }
          req.onsuccess?.();
        }catch(error){tx.error=error;done=true;tx.onabort?.();return;}
        active--;
        queueMicrotask(()=>{if(!done && active===0){done=true;data.clear();for(const [key,row] of staged)data.set(key,copy(row));tx.oncomplete?.();}});
      });
      return req;
    };
    const store={get:key=>request('get',key),put:value=>request('put',value),add:value=>request('add',value)};
    return tx;
  }};
}
const source={key:'1:bapak:3:2026-10-18:7:original-tab',scope:'1:bapak:3:2026-10-18',wali_id:7,sequence:4,dirty:true,
  payload:{status:'I',alasan:'Perangkat <script>أحمد'},entry:{draft_revision:2},flight:{sequence:4,body:{operation_id:'immutable-operation-001',csrf_token:'private-csrf',payload:{status:'I'}}}};
(async()=>{
  check(pendingRecord(source),'An active unsent record can be recovered');
  check(!pendingRecord({...source,kind:'archive'}) && !pendingRecord({...source,dirty:false}),'Archived or delivered copies are not sent automatically');
  check(requiresExitWarning([{dirty:true,sequence:3,persistedSequence:2,localError:false}]),'An uncommitted device write requires an exit warning');
  check(requiresExitWarning([{dirty:true,sequence:3,persistedSequence:3,localError:true}]),'A storage error requires an exit warning');
  check(!requiresExitWarning([{dirty:true,sequence:3,persistedSequence:3,localError:false,inFlight:true}]),'A durable local draft can survive leaving while its request is in flight');
  check(!requiresExitWarning([{dirty:false,sequence:3,persistedSequence:-1,localError:true}]),'Confirmed server data does not produce a needless exit warning');
  const exported=JSON.parse(exportDrafts([source],1));
  check(exported.user_id===1 && exported.records[0].payload.alasan===source.payload.alasan,'Device backup retains original Unicode and inert text');
  check(!('csrf_token' in exported.records[0].flight.body) && source.flight.body.csrf_token==='private-csrf','Backup omits session CSRF without changing the original immutable request');
  const db=fakeDatabase([source]), store=new DraftStore(); store.db=db;
  const archived=await store.archive(source,'before_conflict_resolution');
  check(db.data.has(source.key) && db.data.has(archived.key) && archived.key!==source.key,'Conflict backup adds a new key without replacing the active draft');
  check(archived.kind==='archive' && archived.dirty===false && archived.flight.body.operation_id===source.flight.body.operation_id,'Archive preserves the original request while excluding it from automatic send');
  check(await store.archiveRecovered(source.key,source.sequence),'Matching delivered source can be archived atomically');
  check(db.data.get(source.key).kind==='archive' && [...db.data.values()].some(row=>row.source_key===source.key && row.key!==source.key),'Delivered recovery keeps an independent original copy');
  check(db.data.get(source.key).payload.alasan===source.payload.alasan && !pendingRecord(db.data.get(source.key)),'Archiving delivery retains fields without leaving a duplicate send queue');
  check(await store.archiveRecovered('missing',undefined)===false,'A missing recovery source is a safe no-op');
  const changed=fakeDatabase([{...source,sequence:5}]), changedStore=new DraftStore(); changedStore.db=changed;
  check(await changedStore.archiveRecovered(source.key,4)===false && changed.data.get(source.key).dirty,'A newer source version is never cleared by an older acknowledgement');
  const failing=fakeDatabase([source],2), failingStore=new DraftStore(); failingStore.db=failing;
  await assert.rejects(failingStore.archiveRecovered(source.key,4),/quota/); checks++;
  check(failing.data.size===1 && failing.data.get(source.key).dirty && failing.data.get(source.key).sequence===4,'Failure on the second write rolls back both archive and source changes');
  const noRoom=new DraftStore(); noRoom.db=fakeDatabase([source],1);
  await assert.rejects(noRoom.archive(source,'test'),/quota/); checks++;
  check(noRoom.db.data.size===1,'A failed conflict backup leaves the active draft untouched');
  const row={id:7,payload:{status:'I',alasan:'local'},sequence:4,entry:{draft_revision:2},flight:copy(source.flight),serverEntry:{payload:{status:'A'},blocked:false}};
  let message='',schedules=0;
  const conflictNoRoom=new DraftStore(); conflictNoRoom.db=fakeDatabase([source],1);
  const app={store:conflictNoRoom,snapshot:r=>({...source,payload:copy(r.payload)}),banner:value=>{message=value;},hydrate(){},persist:async()=>{},render(){},schedule(){schedules++;}};
  await AutosaveApp.prototype.resolve.call(app,row,'server');
  check(row.payload.alasan==='local' && row.flight.body.operation_id===source.flight.body.operation_id && schedules===0 && message.includes('belum tersimpan'),'Failed backup prevents replacing local fields or immutable flight data');
  let finish; app.store={archive:()=>new Promise(resolve=>{finish=resolve;})};
  const resolving=AutosaveApp.prototype.resolve.call(app,row,'server');
  row.sequence=5; row.payload.alasan='newer typing'; finish(); await resolving;
  check(row.payload.alasan==='newer typing' && schedules===0,'Typing during conflict archiving is not overwritten by the old choice');
  app.store=store;
  await AutosaveApp.prototype.resolve.call(app,row,'server');
  check(row.payload.status==='A' && row.sequence===6 && row.dirty && schedules===1,'A committed conflict backup allows an explicit version choice to continue');
  const previousStorage=global.sessionStorage;
  global.sessionStorage={getItem:()=> 'duplicated-tab-client',setItem(){}};
  try{
    const boot={user_id:1,halaqoh_id:3,state:{program:'bapak',session_date:'2026-10-18',entries:[]}};
    const first=new AutosaveApp({},boot), second=new AutosaveApp({},boot);
    check(first.client!==second.client,'Duplicated tabs get separate editor keys even when sessionStorage was copied');
    let prevented=0; first.rows.set(7,{dirty:true,sequence:1,persistedSequence:0});
    const event={preventDefault(){prevented++;}}; first.beforeUnload(event);
    check(prevented===1 && event.returnValue===true,'Risky exit asks the browser for its standard confirmation');
    first.rows.clear(); first.beforeUnload({preventDefault(){prevented++;}});
    check(prevented===1,'An empty/durable editor does not cancel navigation');
  }finally{if(previousStorage===undefined)delete global.sessionStorage;else global.sessionStorage=previousStorage;}
  console.log(`PASS ${checks} autosave storage/protocol assertions`);
})().catch(error=>{console.error(error);process.exitCode=1;});
