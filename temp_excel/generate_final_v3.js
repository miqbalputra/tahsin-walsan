const XLSX = require('xlsx');
const path = require('path');
const fs = require('fs');
const bcrypt = require('bcryptjs');

const files = ['Mustawa_Ikhwan.xlsx', 'Mustawa_Akhwat.xlsx'];
const fatherMap = new Map();
const santriList = [];
const ustadzMap = new Map();
const halaqohMap = new Map();
const memberList = [];

const uniqueSantriCheck = new Set();
const uniqueMemberCheck = new Set();

let waliIdCounter = 1;
let ustadzIdCounter = 10;
let halaqohIdCounter = 1;

function normalize(str) {
    if (!str) return '';
    return str.toString().trim().toLowerCase();
}

function parseHalaqoh(raw) {
    if (!raw) return null;
    const parts = raw.split(' ');
    const halaqohName = parts[0] || '';
    const ustadzName = parts.slice(1).join(' ') || 'Ustadz Belum Ditentukan';

    // Extract number from halaqoh name (extract all digits)
    const halaqohNum = halaqohName.match(/\d+/g)?.join('') || '';

    return { halaqohName, ustadzName, halaqohNum };
}

function generateCleanUsername(fullName) {
    // Remove "ust", "ustadz", "ust.", etc from name
    let clean = fullName.toLowerCase()
        .replace(/\bustadz\b/g, '')
        .replace(/\bust\b/g, '')
        .replace(/\./g, '')
        .trim();

    // Take the first word and clean it
    let firstWord = clean.split(' ')[0] || 'user';
    return firstWord.replace(/[^a-z0-9]/g, '');
}

files.forEach(fileName => {
    const filePath = path.join(__dirname, '..', fileName);
    const workbook = XLSX.readFile(filePath);

    workbook.SheetNames.forEach(sheetName => {
        const sheet = workbook.Sheets[sheetName];
        const data = XLSX.utils.sheet_to_json(sheet);

        data.forEach(row => {
            const keys = Object.keys(row);
            const fatherKey = keys.find(k => k.toLowerCase().includes('bapak'));
            const childKey = keys.find(k => k.toLowerCase().includes('anak') || k.toLowerCase().includes('ananda'));
            const phoneKey = keys.find(k => k.toLowerCase().includes('whatsapp'));
            const addressKey = keys.find(k => k.toLowerCase().includes('luar'));
            const halaqohKey = keys.find(k => k.toLowerCase().includes('halaqoh saat ini'));

            const fatherName = (row[fatherKey] || '').trim();
            const childName = (row[childKey] || '').trim();
            const phone = (row[phoneKey] || '').toString().trim();
            const address = (row[addressKey] || '').trim();
            const halaqohRaw = (row[halaqohKey] || '').trim();
            const kelas = sheetName;

            if (!fatherName || !childName) return;
            if (normalize(fatherName) === 'sudah dijapri') return;

            const normName = normalize(fatherName);
            const normPhone = normalize(phone);
            const fatherKeyId = `${normName}|${normPhone}`;

            let waliId;
            if (!fatherMap.has(fatherKeyId)) {
                waliId = waliIdCounter++;
                fatherMap.set(fatherKeyId, {
                    id: waliId,
                    originalName: fatherName,
                    phone: phone,
                    address: address
                });
            } else {
                waliId = fatherMap.get(fatherKeyId).id;
            }

            const santriUniqKey = `${waliId}|${normalize(childName)}|${normalize(kelas)}`;
            if (!uniqueSantriCheck.has(santriUniqKey)) {
                uniqueSantriCheck.add(santriUniqKey);
                santriList.push({ waliId, childName, kelas });
            }

            const hInfo = parseHalaqoh(halaqohRaw);
            if (hInfo && hInfo.halaqohName) {
                const normUstadz = normalize(hInfo.ustadzName);
                let uId;

                // Track password based on halaqoh number
                const rawPassword = `tahsin${hInfo.halaqohNum}`;
                const hashedPassword = bcrypt.hashSync(rawPassword, 10);

                if (!ustadzMap.has(normUstadz)) {
                    uId = ustadzIdCounter++;
                    ustadzMap.set(normUstadz, {
                        id: uId,
                        fullName: hInfo.ustadzName,
                        username: generateCleanUsername(hInfo.ustadzName),
                        rawPassword: rawPassword,
                        hashedPassword: hashedPassword
                    });
                } else {
                    uId = ustadzMap.get(normUstadz).id;
                }

                const normHalaqoh = normalize(hInfo.halaqohName);
                let hId;
                if (!halaqohMap.has(normHalaqoh)) {
                    hId = halaqohIdCounter++;
                    halaqohMap.set(normHalaqoh, {
                        id: hId,
                        name: hInfo.halaqohName,
                        ustadzId: uId
                    });
                } else {
                    hId = halaqohMap.get(normHalaqoh).id;
                }

                const memberKey = `${hId}|${waliId}`;
                if (!uniqueMemberCheck.has(memberKey)) {
                    uniqueMemberCheck.add(memberKey);
                    memberList.push({ halaqohId: hId, waliId: waliId });
                }
            }
        });
    });
});

// Fix potential username conflicts
const finalUstadzList = Array.from(ustadzMap.values());
const usernameCount = new Map();
finalUstadzList.forEach(u => {
    if (usernameCount.has(u.username)) {
        usernameCount.set(u.username, usernameCount.get(u.username) + 1);
        u.username = u.username + usernameCount.get(u.username);
    } else {
        usernameCount.set(u.username, 0);
    }
});

// Generate SQL
let sqlOutput = `-- Final Data Import: Users (Ustadz), Halaqoh, Wali Santri, Santri, and Members\n`;
sqlOutput += `-- Generated on ${new Date().toLocaleString()}\n`;
sqlOutput += `SET FOREIGN_KEY_CHECKS = 0;\n`;
sqlOutput += `TRUNCATE TABLE halaqoh_members;\n`;
sqlOutput += `TRUNCATE TABLE halaqoh;\n`;
sqlOutput += `TRUNCATE TABLE santri_detail;\n`;
sqlOutput += `TRUNCATE TABLE wali_santri;\n`;
sqlOutput += `DELETE FROM users WHERE role = 'ustadz';\n`;
sqlOutput += `SET FOREIGN_KEY_CHECKS = 1;\n\n`;

sqlOutput += `-- Create Ustadz Accounts\n`;
sqlOutput += `INSERT INTO users (id, username, password, nama_lengkap, role) VALUES\n`;
const ustadzValues = finalUstadzList.map(u => {
    return `(${u.id}, '${u.username}', '${u.hashedPassword}', '${u.fullName.replace(/'/g, "''")}', 'ustadz')`;
});
sqlOutput += ustadzValues.join(',\n') + ';\n\n';

sqlOutput += `-- Create Halaqohs\n`;
sqlOutput += `INSERT INTO halaqoh (id, nama_halaqoh, ustadz_id) VALUES\n`;
const hValues = Array.from(halaqohMap.values()).map(h => {
    return `(${h.id}, '${h.name.replace(/'/g, "''")}', ${h.ustadzId})`;
});
sqlOutput += hValues.join(',\n') + ';\n\n';

sqlOutput += `-- Create Wali Santri\n`;
sqlOutput += `INSERT INTO wali_santri (id, nama_bapak, no_hp, alamat, kategori, status_aktif) VALUES\n`;
const waliValues = Array.from(fatherMap.values()).map(data => {
    return `(${data.id}, '${data.originalName.replace(/'/g, "''")}', '${data.phone.replace(/'/g, "''")}', '${data.address.replace(/'/g, "''")}', 'reguler', 1)`;
});
sqlOutput += waliValues.join(',\n') + ';\n\n';

sqlOutput += `-- Create Santri Details\n`;
sqlOutput += `INSERT INTO santri_detail (wali_santri_id, nama_anak, kelas) VALUES\n`;
const sValues = santriList.map(s => {
    return `(${s.waliId}, '${s.childName.replace(/'/g, "''")}', '${s.kelas.replace(/'/g, "''")}')`;
});
sqlOutput += sValues.join(',\n') + ';\n\n';

sqlOutput += `-- Map Wali Santri to Halaqoh\n`;
sqlOutput += `INSERT INTO halaqoh_members (halaqoh_id, wali_santri_id) VALUES\n`;
const mValues = memberList.map(m => `(${m.halaqohId}, ${m.waliId})`);
sqlOutput += mValues.join(',\n') + ';\n';

fs.writeFileSync(path.join(__dirname, '..', 'import_database_final.sql'), sqlOutput);

// Generate Account List MD
let accountList = `# Daftar Akun Ustadz Tahsin\n\n`;
accountList += `| No | Nama Ustadz | Username | Password | Halaqoh |\n`;
accountList += `|---|---|---|---|---|\n`;

// Match back halaqoh name to ustadz
const hEntries = Array.from(halaqohMap.values());
finalUstadzList.forEach((u, index) => {
    const halaqohs = hEntries.filter(h => h.ustadzId === u.id).map(h => h.name).join(', ');
    accountList += `| ${index + 1} | ${u.fullName} | \`${u.username}\` | \`${u.rawPassword}\` | ${halaqohs} |\n`;
});

fs.writeFileSync(path.join(__dirname, '..', 'DAFTAR_AKUN_USTADZ.md'), accountList);

console.log('Successfully generated:');
console.log('- import_database_final.sql');
console.log('- DAFTAR_AKUN_USTADZ.md');
