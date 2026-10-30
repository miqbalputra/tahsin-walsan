'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const safety = require('../temp_excel/destructive-import-safety.cjs');

const root = path.resolve(__dirname, '..');
const scripts = [
  'generate_sql.js',
  'generate_final_sql.js',
  'generate_complete_db.js',
  'generate_final_v3.js',
  'fix_sql_error.js'
];
let checks = 0;
function check(condition, message) {
  assert.ok(condition, message);
  checks++;
}

const dockerIgnore = fs.readFileSync(path.join(root, '.dockerignore'), 'utf8')
  .split(/\r?\n/).map(line => line.trim());
for (const artifact of [
  '/DAFTAR_AKUN_USTADZ.md',
  '/teacher-credentials.json',
  '/n8n_workflow_laporan_admin_tahsin.json',
  '/scripts/database-snapshot.php',
  '/scripts/production-baseline.php',
  '/docs/',
  '/tests/'
]) {
  check(dockerIgnore.includes(artifact), artifact + ' is excluded from Docker image context');
}

check(!safety.isFullReplacementApproved([], {}), 'No arguments cannot approve a full replacement');
check(!safety.isFullReplacementApproved([safety.REPLACEMENT_FLAG], {}), 'CLI flag alone cannot approve a full replacement');
check(!safety.isFullReplacementApproved([], { TAHSIN_FULL_REPLACEMENT_CONFIRMATION: safety.REPLACEMENT_CONFIRMATION }), 'Environment confirmation alone cannot approve a full replacement');
const approvalWithoutTarget = { TAHSIN_FULL_REPLACEMENT_CONFIRMATION: safety.REPLACEMENT_CONFIRMATION };
check(!safety.isFullReplacementApproved([safety.REPLACEMENT_FLAG], approvalWithoutTarget), 'Explicit confirmations without a sandbox target are rejected');
check(!safety.isFullReplacementApproved([safety.REPLACEMENT_FLAG], {
  ...approvalWithoutTarget,
  TAHSIN_IMPORT_TARGET_DATABASE: 'production'
}), 'A non-sandbox database target is rejected');
const approvedSandbox = { ...approvalWithoutTarget, TAHSIN_IMPORT_TARGET_DATABASE: 'tahsin_test_audit' };
check(safety.isFullReplacementApproved([safety.REPLACEMENT_FLAG], approvedSandbox), 'Both confirmations and an explicit sandbox database approve the guarded generator');

const childEnv = { ...process.env };
delete childEnv.TAHSIN_FULL_REPLACEMENT_CONFIRMATION;
delete childEnv.TAHSIN_IMPORT_TARGET_DATABASE;
for (const script of scripts) {
  const scriptPath = path.join(root, 'temp_excel', script);
  const source = fs.readFileSync(scriptPath, 'utf8');
  const gatePosition = source.indexOf('requireFullReplacementApproval');
  const workbookPosition = source.indexOf("require('xlsx')");
  check(gatePosition >= 0 && gatePosition < workbookPosition,
    script + ' checks explicit approval before loading user workbooks');
  check(source.includes('createPrivateOutputDirectory') && source.includes('writePrivateArtifact'),
    script + ' writes generated SQL and personal data through the private output helper');
  check(!source.includes('admin123') && !source.includes('defaultPass')
    && !source.includes('tahsin${hInfo.halaqohNum}') && !source.includes('DAFTAR_AKUN_USTADZ.md'),
    script + ' no longer creates predictable teacher passwords or a webroot password list');
  const result = spawnSync(process.execPath, [scriptPath], {
    cwd: root,
    env: childEnv,
    encoding: 'utf8',
    windowsHide: true
  });
  check(result.status === 2 && result.stderr.includes('Dibatalkan') && !result.stderr.includes('Cannot find module'),
    script + ' refuses to read workbooks or generate destructive SQL without both approvals');
}

const blockedBase = path.join(root, 'temp_excel', 'audit-output-' + process.pid);
assert.throws(() => safety.createPrivateOutputDirectory(root, blockedBase), /di luar webroot/);
check(!fs.existsSync(blockedBase), 'A rejected in-webroot output path is not created');

const outputDirectory = safety.createPrivateOutputDirectory(root, os.tmpdir());
try {
  const realRoot = fs.realpathSync(root);
  const realOutputDirectory = fs.realpathSync(outputDirectory);
  check(!safety.isInside(realRoot, realOutputDirectory), 'Generated SQL and credentials are directed outside the webroot');
  if (process.platform !== 'win32') {
    check((fs.statSync(outputDirectory).mode & 0o777) === 0o700, 'Private output directory has owner-only permissions');
  }
  const artifact = safety.writePrivateArtifact(outputDirectory, 'audit-check.sql', 'SELECT 1;\n');
  check(fs.readFileSync(artifact, 'utf8') === 'SELECT 1;\n', 'Private output helper writes the requested artifact');
  if (process.platform !== 'win32') {
    check((fs.statSync(artifact).mode & 0o777) === 0o600, 'Private artifact has owner-only permissions');
  }
  assert.throws(() => safety.writePrivateArtifact(outputDirectory, '../unsafe.sql', 'SELECT 1;'), /Nama file/);
  const firstPassword = safety.createTemporaryPassword();
  const secondPassword = safety.createTemporaryPassword();
  check(/^[A-Za-z0-9_-]{32}$/.test(firstPassword) && /^[A-Za-z0-9_-]{32}$/.test(secondPassword) && firstPassword !== secondPassword,
    'Temporary teacher passwords are 192-bit base64url values generated independently');
  const warning = safety.destructiveSqlWarning(['wali_santri'], approvedSandbox);
  check(warning.includes('permanently removed') && warning.includes('USE `tahsin_test_audit`;'),
    'Generated replacement SQL is visibly destructive and bound to the explicit sandbox database');
} finally {
  const realOutputDirectory = fs.realpathSync(outputDirectory);
  const realTempDirectory = fs.realpathSync(os.tmpdir());
  assert.equal(path.dirname(realOutputDirectory), realTempDirectory);
  assert.ok(path.basename(realOutputDirectory).startsWith('tahsin-full-replacement-'));
  fs.rmSync(realOutputDirectory, { recursive: true, force: true });
}

console.log('PASS ' + checks + ' legacy generator safety assertions');
