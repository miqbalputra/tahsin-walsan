// Offline evaluation only. This test never calls Telegram, WhatsApp, or an API.
const fs = require('node:fs');
const assert = require('node:assert/strict');
const workflow = JSON.parse(fs.readFileSync('n8n_workflow_laporan_admin_tahsin.template.json', 'utf8'));
const code = workflow.nodes.find(n => n.name === 'Susun Laporan Admin').parameters.jsCode;
const run = body => new Function('$input', code)({first: () => ({json: body})});
const reportText = result => result.map(item => item.json.report).join('\n');
let checks = 0;
function check(name, fn) { fn(); checks++; console.log('PASS ' + name); }
const row = (extra = {}) => ({
  ustadz_id:11, ustadz_name:'Guru Uji', ustadz_phone:'6281234567890', halaqoh_id:1, nama_halaqoh:'Kelompok A',
  tanggal_tahsin:'2026-10-18', tanggal_tahsin_formatted:'Ahad, 18/10/2026', deadline_at:'2026-10-19 10:00:00',
  total_anggota:5, sudah_diisi:3, complete_input:1, belum_diisi:4,
  missing_presensi:2, missing_capaian:1, missing_keterangan:1, conflicts:0,
  has_expected_roster:true, data_verified:false, roster_basis:'current_roster', ...extra,
});
function payload(data = []) {
  const current = data.filter(g => g.tanggal_tahsin === '2026-10-18');
  return {status:'success', day:'Rabu', date_today:'2026-10-21', tanggal_tahsin:'2026-10-18', count:data.length, data,
    inspection_complete:true, summary:{
      checked_participant_sessions:current.length ? current.reduce((s,g)=>s+g.total_anggota,0) : 4,
      complete_participant_sessions:current.length ? current.reduce((s,g)=>s+g.complete_input,0) : 4,
      roster_basis:'current_roster', attendance_counts:{H:1,S:1,I:1,A:1},
    }};
}
function prepare(response, reportStatus = 'pending') {
  const node = workflow.nodes.find(n => n.name === 'Siapkan Pengingat Guru');
  return new Function('$', node.parameters.jsCode)(name => ({first: () => ({json:name === 'Susun Laporan Admin' ? {status:reportStatus} : response})}));
}
check('schedule 10.00 WIB, inactive export and current-week endpoint', () => {
  assert.equal(workflow.active,false);
  assert.equal(workflow.settings.timezone,'Asia/Jakarta');
  assert.equal(workflow.nodes.find(n=>n.type==='n8n-nodes-base.scheduleTrigger').parameters.rule.interval[0].expression,'0 10 * * 1-6');
  assert.equal(new URL(workflow.nodes.find(n=>n.type==='n8n-nodes-base.httpRequest').parameters.url).searchParams.has('all_pending'),false);
});
check('legacy empty API response cannot claim complete', () => {
  const result = run({status:'success',count:0,data:[]});
  assert.equal(result[0].json.status,'error');
  assert(reportText(result).includes('Deploy perbaikan aplikasi'));
});
check('empty roster and missing inspection cannot claim complete', () => {
  for (const p of [{...payload(),inspection_complete:false}, {...payload(),summary:{...payload().summary,checked_participant_sessions:0}}]) assert.equal(run(p)[0].json.status,'error');
});
check('complete current roster includes saved Alfa', () => {
  const result = run(payload());
  assert.equal(result[0].json.status,'complete');
  assert(reportText(result).includes('Alfa 1'));
});
check('first-Sunday/holiday skip remains distinct from complete', () => assert.equal(run({status:'skip',reason:'Ahad pertama libur'})[0].json.status,'skip'));
check('HTTP and network failures become admin alerts', () => {
  for (const statusCode of [401,500,503]) assert.equal(run({statusCode,body:{error:'failure'}})[0].json.status,'error');
  const result = run({error:'secret-key-must-not-leak'});
  assert.equal(result[0].json.status,'error');
  assert(!reportText(result).includes('secret-key-must-not-leak'));
});
check('current roster fallback remains pending without historical tables', () => {
  const result = run(payload([row()]));
  assert.equal(result[0].json.status,'pending');
  assert(reportText(result).includes('roster aktif pekan ini'));
  assert.equal(result[0].json.teacher_count,1);
});
check('unverified older session is rejected', () => assert.equal(run(payload([row({tanggal_tahsin:'2026-10-11'})]))[0].json.status,'error'));
check('verified backlog retains its own session and teacher is unique', () => {
  const result=run(payload([row(),row({tanggal_tahsin:'2026-10-11',tanggal_tahsin_formatted:'Ahad, 11/10/2026',data_verified:true,roster_basis:'session_history'})]));
  assert.equal(result[0].json.teacher_count,1);
  assert(reportText(result).includes('11/10/2026'));
  assert(reportText(result).includes('18/10/2026'));
});
check('empty teacher group is explicitly identified', () => {
  const g=row({sudah_diisi:0,complete_input:0,belum_diisi:5,missing_presensi:5,missing_capaian:0,missing_keterangan:0});
  assert(reportText(run(payload([g]))).includes('Belum mengisi sama sekali'));
});
check('duplicate groups and inconsistent totals become errors', () => {
  assert.equal(run(payload([row(),row()]))[0].json.status,'error');
  assert.equal(run(payload([row({belum_diisi:9})]))[0].json.status,'error');
  assert.equal(run(payload([row({missing_presensi:-1})]))[0].json.status,'error');
  assert.equal(run({...payload([row()]),summary:{...payload([row()]).summary,checked_participant_sessions:20}})[0].json.status,'error');
});
check('HTML escaping, line breaks, and missing phone are safe', () => {
  const text=reportText(run(payload([row({ustadz_name:'Guru <B> & C',ustadz_phone:''})])));
  assert(text.includes('&lt;B&gt; &amp; C'));
  assert(text.includes('\n') && !text.includes('\\n'));
  assert(text.includes('Nomor WhatsApp guru belum valid'));
});
check('100 long group reports fit Telegram and keep all entries', () => {
  const data=Array.from({length:100},(_,i)=>row({ustadz_id:100+i,halaqoh_id:100+i,ustadz_name:'&'.repeat(100),nama_halaqoh:'<'.repeat(100)}));
  const result=run(payload(data));
  assert(result.length>1);
  for(const part of result) assert(part.json.report.length<4096);
  assert.equal((reportText(result).match(/Halaqoh: /g)||[]).length,100);
});
check('admin report precedes sequential teacher delivery with explicit six-second wait', () => {
  const loop=workflow.nodes.find(n=>n.type==='n8n-nodes-base.splitInBatches');
  const wait=workflow.nodes.find(n=>n.type==='n8n-nodes-base.wait');
  const wa=workflow.nodes.find(n=>n.type.includes('gowa'));
  assert.equal(workflow.connections['Kirim Laporan ke Telegram Admin'].main[0][0].node,'Siapkan Pengingat Guru');
  assert.equal(loop.parameters.batchSize,1);
  assert.equal(workflow.connections[loop.name].main[1][0].node,wait.name);
  assert.equal(wait.parameters.unit,'seconds'); assert.equal(wait.parameters.amount,6);
  assert.equal(wa.onError,'continueRegularOutput');
  assert.equal(workflow.connections[wa.name].main[0][0].node,loop.name);
});
check('only pending teachers with valid phones get prepared messages', () => {
  const result=prepare({body:payload([row(),row({halaqoh_id:2,ustadz_phone:''})])});
  assert.equal(result.length,1);
  assert(result[0].json.wa_message.includes('\n'));
  assert(!result[0].json.wa_message.includes('\\n'));
  assert(result[0].json.wa_message.includes('tanggal=2026-10-18'));
  for(const status of ['complete','skip','error']) assert.deepEqual(prepare(payload([row()]),status),[]);
});
check('placement-only conflict is left for admin instead of falsely asking teacher to fill again', () => {
  assert.deepEqual(prepare(payload([row({missing_presensi:0,missing_capaian:0,missing_keterangan:0,conflicts:4})])),[]);
});
check('all nodes compile and every connection targets an existing node', () => {
  const names=new Set(workflow.nodes.map(n=>n.name));
  assert.equal(names.size,workflow.nodes.length);
  for(const n of workflow.nodes.filter(n=>n.type==='n8n-nodes-base.code')) new Function('$input','$',n.parameters.jsCode);
  for(const [source,connection] of Object.entries(workflow.connections)) {
    assert(names.has(source));
    for(const branch of connection.main) for(const target of branch) assert(names.has(target.node));
  }
});
console.log('PASS '+checks+' n8n workflow scenarios; no messages sent');
