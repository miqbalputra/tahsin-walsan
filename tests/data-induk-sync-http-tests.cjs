/** Local API stub + isolated MariaDB fixture for Data Induk roster synchronization. */
const assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const http = require('node:http');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const php = process.env.TEST_PHP_BINARY || 'php';
const requested = [];

function dataset(scenario, endpoint) {
  const guardians = [
    {id:'central-unique',fullName:'Bapak Satu',phone:'62-812-3456-789',address:'Alamat Satu'},
    {id:'central-ambiguous',fullName:'Bapak Kembar',phone:'080-0000-0000',address:'Alamat Kembar'},
    {id:'central-mapped',fullName:'Bapak Terpetakan',phone:'081200000000',address:'Alamat Terpetakan'},
    {id:'central-new',fullName:'Bapak Baru',phone:'089900000000',address:'Alamat Baru'},
  ];
  const students = [
    {id:'student-unique',fullName:'Anak, Satu',status:'active',classId:'class-new'},
    {id:'student-ambiguous',fullName:'Anak Kembar',status:'active',classId:'class-new'},
    {id:'student-mapped',fullName:'Anak Terpetakan',status:'active',classId:'class-new',enrollmentId:'enrollment-new'},
    {id:'student-new',fullName:'Anak Baru',status:'active',classId:'class-new',enrollmentId:'enrollment-new-child'},
    {id:'guardian-candidate-unique',fullName:'Anak kandidat wali satu',status:'active',classId:'class-new'},
    {id:'guardian-candidate-ambiguous',fullName:'Anak kandidat wali dua',status:'active',classId:'class-new'},
  ];
  const links = [
    {studentId:'student-unique',guardianId:'central-mapped',relationship:'father',archivedAt:null},
    {studentId:'student-ambiguous',guardianId:'central-mapped',relationship:'father',archivedAt:null},
    {studentId:'student-mapped',guardianId:'central-mapped',relationship:'father',archivedAt:null},
    {studentId:'student-new',guardianId:'central-new',relationship:'father',archivedAt:null},
    {studentId:'guardian-candidate-unique',guardianId:'central-unique',relationship:'father',archivedAt:null},
    {studentId:'guardian-candidate-ambiguous',guardianId:'central-ambiguous',relationship:'father',archivedAt:null},
  ];
  if (scenario === 'invalid-status') students[2].status = 'graduated';
  if (scenario === 'missing-status') delete students[2].status;
  if (scenario === 'unknown-class') students[2].classId = 'class-not-synced';
  if (scenario === 'missing-class-field') delete students[2].classId;
  if (scenario === 'multiple-fathers') {
    guardians.push({id:'central-other',fullName:'Wali Lain',phone:'087700000000',address:'Alamat Lain'});
    links.push({studentId:'student-mapped',guardianId:'central-other',relationship:'father',archivedAt:null});
  }
  if (endpoint === 'guardians') return guardians;
  if (endpoint === 'students') return students;
  if (endpoint === 'family-links') return links;
  return null;
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1');
  const match = url.pathname.match(/^\/([a-z-]+)\/api\/v1\/integrations\/(guardians|students|family-links)$/);
  requested.push({path:url.pathname,key:req.headers['x-api-key'],unit:url.searchParams.get('unitId')});
  if (!match || req.headers['x-api-key'] !== 'fixture-api-key' || url.searchParams.get('unitId') !== 'fixture-unit') {
    res.writeHead(403, {'Content-Type':'application/json'});
    res.end(JSON.stringify({status:'error'}));
    return;
  }
  const items = dataset(match[1], match[2]);
  res.writeHead(200, {'Content-Type':'application/json','Cache-Control':'no-store'});
  res.end(JSON.stringify({data:{items,nextCursor:''}}));
});

function runPhp(env) {
  return new Promise((resolve, reject) => {
    const child = spawn(php, ['-d','memory_limit=256M','tests/data-induk-sync-integration.php'], {
      cwd:root,
      env,
      windowsHide:true,
      stdio:['ignore','pipe','pipe'],
    });
    let stdout='', stderr='';
    child.stdout.on('data', bytes => { stdout += bytes.toString(); });
    child.stderr.on('data', bytes => { stderr += bytes.toString(); });
    child.on('error', reject);
    child.on('exit', code => code === 0 ? resolve({stdout,stderr}) : reject(new Error(stderr || stdout || `PHP exited ${code}`)));
  });
}

server.listen(0, '127.0.0.1', async () => {
  const {port} = server.address();
  try {
    const result = await runPhp({...process.env,TEST_DATA_INDUK_BASE_URL:`http://127.0.0.1:${port}`});
    const summary = JSON.parse(result.stdout.trim().split(/\r?\n/).at(-1));
    assert.equal(summary.ok, true);
    assert.ok(summary.checks >= 20, 'The sync regression suite must cover data preservation cases');
    assert.ok(requested.length > 0, 'PHP sync must call the local Data Induk mock');
    assert.ok(requested.every(row => row.key === 'fixture-api-key' && row.unit === 'fixture-unit'), 'Sync sends credentials and unit only to the loopback fixture');
    assert.equal(result.stderr.trim(), '', 'The sync fixture produces no PHP warnings');
    process.stdout.write(`PASS ${summary.checks} Data Induk sync assertions; ${requested.length} loopback API requests; ${summary.database}\n`);
  } catch (error) {
    console.error(error);
    process.exitCode = 1;
  } finally {
    server.close();
  }
});
