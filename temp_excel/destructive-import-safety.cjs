'use strict';

const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');

const REPLACEMENT_FLAG = '--replace-all-existing-data';
const REPLACEMENT_CONFIRMATION = 'I VERIFIED A BACKUP AND ACCEPT DELETING ALL EXISTING DATA';
const TEST_DATABASE_PATTERN = /^tahsin_test_[a-z0-9_]{1,52}$/;

function approvedTargetDatabase(env = process.env) {
    const name = typeof env.TAHSIN_IMPORT_TARGET_DATABASE === 'string'
        ? env.TAHSIN_IMPORT_TARGET_DATABASE
        : '';
    return TEST_DATABASE_PATTERN.test(name) ? name : '';
}

function isFullReplacementApproved(args = process.argv.slice(2), env = process.env) {
    return Array.isArray(args)
        && args.includes(REPLACEMENT_FLAG)
        && env.TAHSIN_FULL_REPLACEMENT_CONFIRMATION === REPLACEMENT_CONFIRMATION
        && approvedTargetDatabase(env) !== '';
}

function requireFullReplacementApproval(scriptName) {
    if (isFullReplacementApproved()) return;
    console.error('Dibatalkan: ' + scriptName + ' menghasilkan SQL yang menghapus atau mengganti data lama.');
    console.error('Generator ini hanya boleh menarget database sandbox bernama tahsin_test_*. Siapkan dan verifikasi backup di database terpisah.');
    console.error('Persetujuan wajib: argumen ' + REPLACEMENT_FLAG + ', environment TAHSIN_FULL_REPLACEMENT_CONFIRMATION dengan nilai persis, dan TAHSIN_IMPORT_TARGET_DATABASE berawalan tahsin_test_:');
    console.error(REPLACEMENT_CONFIRMATION);
    process.exit(2);
}

function isInside(parent, candidate) {
    const relative = path.relative(path.resolve(parent), path.resolve(candidate));
    return relative === '' || (relative !== '..' && !relative.startsWith('..' + path.sep) && !path.isAbsolute(relative));
}

function createPrivateOutputDirectory(applicationRoot, requestedBase = os.tmpdir()) {
    const realRoot = fs.realpathSync(applicationRoot);
    const requestedPath = path.resolve(requestedBase);
    if (isInside(realRoot, requestedPath)) {
        throw new Error('Direktori hasil impor harus berada di luar webroot aplikasi.');
    }
    const realBase = fs.realpathSync(requestedBase);
    if (isInside(realRoot, realBase)) {
        throw new Error('Direktori hasil impor harus berada di luar webroot aplikasi.');
    }

    const directory = fs.mkdtempSync(path.join(realBase, 'tahsin-full-replacement-'));
    try {
        fs.chmodSync(directory, 0o700);
    } catch (error) {
        if (process.platform !== 'win32') {
            fs.rmSync(directory, { recursive: true, force: true });
            throw error;
        }
    }
    return directory;
}

function writePrivateArtifact(directory, filename, contents) {
    if (path.basename(filename) !== filename || !/^[a-z0-9][a-z0-9._-]{0,100}$/i.test(filename)) {
        throw new Error('Nama file hasil impor tidak valid.');
    }
    const target = path.join(directory, filename);
    fs.writeFileSync(target, contents, { encoding: 'utf8', flag: 'wx', mode: 0o600 });
    if (process.platform !== 'win32') fs.chmodSync(target, 0o600);
    return target;
}

function createTemporaryPassword() {
    return crypto.randomBytes(24).toString('base64url');
}

function destructiveSqlWarning(tables, env = process.env) {
    const target = approvedTargetDatabase(env);
    if (!target) throw new Error('Nama target SQL harus database sandbox tahsin_test_*.');
    return [
        '-- DESTRUCTIVE FULL REPLACEMENT: existing records will be permanently removed.',
        '-- Affected tables: ' + tables.join(', ') + '.',
        '-- Target is constrained to a disposable tahsin_test_* database after confirming a separate backup.',
        '-- This file was created only after explicit CLI and environment confirmation.',
        'USE `' + target + '`;',
        ''
    ].join('\n');
}

module.exports = {
    REPLACEMENT_FLAG,
    REPLACEMENT_CONFIRMATION,
    approvedTargetDatabase,
    isFullReplacementApproved,
    requireFullReplacementApproval,
    isInside,
    createPrivateOutputDirectory,
    writePrivateArtifact,
    createTemporaryPassword,
    destructiveSqlWarning
};
