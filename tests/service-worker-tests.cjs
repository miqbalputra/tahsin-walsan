const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const crypto=require('node:crypto');
const vm=require('node:vm');
const root=path.resolve(__dirname,'..');
const source=fs.readFileSync(path.join(root,'sw.js'),'utf8');
let checks=0;
const check=(condition,message)=>{ assert.ok(condition,message); checks++; };
let offline=false, quotaFull=false, storageDown=false, mode='ok';
const cached=new Map(), puts=[], deleted=[], removed=[], network=[];
const key=request=>typeof request==='string'?request:request.url;
const cache={
  match:async request=>cached.get(key(request))?.clone(),
  delete:async request=>{ removed.push(key(request)); return cached.delete(key(request)); },
  put:async(request,response)=>{ if(quotaFull) throw Error('quota'); puts.push(key(request)); cached.set(key(request),response.clone()); }
};
const initialKeys=['another-app','tahsin-presensi-v6','tahsin-presensi-https%3A%2F%2Fexample.test%2Ftahsin%2F-v7','tahsin-presensi-https%3A%2F%2Fexample.test%2Fother%2F-v7'];
function worker(scope='https://example.test/tahsin/'){
  const handlers={}, context={URL,Response,Set,Promise,Error,
    self:{registration:{scope},location:{origin:'https://example.test'},addEventListener:(name,callback)=>{handlers[name]=callback;}},
    caches:{open:async()=>{if(storageDown)throw Error('cache storage unavailable');return cache;},keys:async()=>initialKeys,delete:async name=>deleted.push(name)},
    fetch:async(input,options={})=>{
      if(offline)throw Error('offline');
      const url=new URL(key(input)); network.push({url:url.href,options});
      const contentType=url.pathname.endsWith('.css')?'text/css':url.pathname.endsWith('.js')?'application/javascript':url.pathname.endsWith('.png')?'image/png':'application/manifest+json';
      const response=new Response(mode==='html'?'<html>private account data</html>':'asset '+url.search,{status:mode==='error'?500:200,headers:{'Content-Type':mode==='html'?'text/html':contentType}});
      if(mode==='redirect')Object.defineProperty(response,'redirected',{value:true});
      if(mode==='opaque')Object.defineProperty(response,'type',{value:'opaque'});
      return response;
    }
  };
  vm.runInNewContext(source+'\nglobalThis.publicAssets=STATIC_ASSETS;globalThis.release=PUBLIC_ASSET_VERSION;',context);
  return {handlers,assets:Array.from(context.publicAssets),release:context.release,scope};
}
const app=worker();
async function request(route,extra={}){
  let response;
  app.handlers.fetch({request:{url:new URL(route,'https://example.test').href,method:'GET',mode:'cors',...extra},respondWith:value=>{response=value;}});
  return response?await response:undefined;
}
const asset=name=>'/tahsin/'+app.assets.find(item=>item.split('?')[0]===name);
(async()=>{
  check(app.assets.length>=12 && new Set(app.assets).size===app.assets.length,'Build enumerates unique public assets');
  for(const entry of app.assets){
    const [file,query]=entry.split('?');
    check(/^(assets\/(css|js)\/[A-Za-z0-9][A-Za-z0-9_.-]*\.(css|js)|manifest\.json|icon-512\.png)$/.test(file),'Only reviewed public asset locations enter precache');
    const hash=crypto.createHash('sha256').update(fs.readFileSync(path.join(root,file))).digest('hex').slice(0,16);
    check(query==='v='+hash,'Generated version matches current contents: '+file);
  }
  check(app.release===crypto.createHash('sha256').update(JSON.stringify(app.assets)).digest('hex').slice(0,16),'Worker cache version tracks the asset collection');
  for(const route of ['/tahsin/login.php','/tahsin/api/private-data','/other-app/assets/css/app.css','https://other.example/assets/css/app.css','/tahsin/assets/private.json']) check(await request(route)===undefined,'Non-allowlisted requests stay outside the asset cache');
  check(await request('/tahsin/ustadz/api-autosave.php',{method:'POST'})===undefined,'Writes are never intercepted');
  check(network.length===0 && puts.length===0,'Private and unrelated requests do not reach cache or fetch handlers');
  const css=asset('assets/css/app.css');
  check((await request(css)).status===200 && puts.length===1,'Canonical versioned CSS loads and is cached');
  const beforeNetwork=network.length;
  check((await request(css)).status===200 && network.length===beforeNetwork,'Exact content version can be read from cache');
  const old=await request('/tahsin/assets/css/app.css?v=obsolete');
  check((await old.text()).includes('obsolete') && network.length===beforeNetwork+1,'Different query versions do not reuse the current cached response');
  check(puts.length===1,'Arbitrary query variants do not fill the cache');
  check((await request('/tahsin/assets/css/app.css')).status===200 && puts.length===1,'Old unversioned links still load but are not precached as the current release');
  const script=asset('assets/js/alpine.min.js');
  quotaFull=true;
  check((await request(script)).status===200 && !cached.has('https://example.test'+script),'A full cache does not block online assets');
  quotaFull=false; storageDown=true;
  check((await request(script)).status===200,'Unavailable Cache Storage falls back to the network');
  storageDown=false;
  for(const invalid of ['html','redirect','opaque','error']){
    mode=invalid; const count=puts.length;
    await request(script);
    check(puts.length===count && !cached.has('https://example.test'+script),'Untrusted asset response is never cached: '+invalid);
  }
  mode='ok';
  cached.set('https://example.test'+script,new Response('<html>private</html>',{headers:{'Content-Type':'text/html'}}));
  check((await request(script)).status===200 && removed.includes('https://example.test'+script),'Previously invalid cached content is discarded before use');
  offline=true;
  check((await request(css)).status===200,'An exact public asset remains available while offline');
  const fallback=await request('/tahsin/users.php',{mode:'navigate'});
  check(fallback.status===503 && /no-store/.test(fallback.headers.get('Cache-Control')) && (await fallback.text()).includes('Draft presensi'),'Offline navigation explains preserved drafts without caching a personal page');
  offline=false;
  let installation;
  mode='html'; const putCount=puts.length;
  app.handlers.install({waitUntil:value=>{installation=value;}});
  await assert.rejects(installation,/Public asset/); checks++;
  check(puts.length===putCount,'Failed MIME validation writes no install responses');
  mode='ok';
  app.handlers.install({waitUntil:value=>{installation=value;}}); await installation;
  check(network.slice(-app.assets.length).every(call=>call.options.cache==='reload'),'Installation bypasses stale HTTP asset caches');
  check(app.assets.every(item=>cached.has(new URL(item,app.scope).href)),'Successful install stores every canonical asset');
  let activated;
  app.handlers.activate({waitUntil:value=>{activated=value;}}); await activated;
  check(deleted.includes('tahsin-presensi-https%3A%2F%2Fexample.test%2Ftahsin%2F-v7'),'Activation removes older caches from this installation');
  check(!deleted.includes('another-app') && !deleted.includes('tahsin-presensi-https%3A%2F%2Fexample.test%2Fother%2F-v7') && !deleted.includes('tahsin-presensi-v6'),'Nested installations preserve unrelated and root legacy caches');
  const primary=worker('https://example.test/');
  primary.handlers.activate({waitUntil:value=>{activated=value;}}); await activated;
  check(deleted.includes('tahsin-presensi-v6'),'Only the root installation retires its old unscoped legacy cache');
  check(!/skipWaiting\(|clients\.claim\(/.test(source),'An update does not replace the worker under an open editor');
  console.log(`PASS ${checks} public asset/service worker assertions`);
})().catch(error=>{console.error(error);process.exitCode=1;});
