const XLSX = require('xlsx');
const path = require('path');
const fs = require('fs');

const files = ['Mustawa_Ikhwan.xlsx', 'Mustawa_Akhwat.xlsx'];
const fatherMap = new Map(); // normalizedKey -> { id, originalName, phone, address, halaqohRaw }
const santriList = []; // Array of { waliId, childName, kelas }
const ustadzMap = new Map(); // normalizedName -> { id, fullName, username }
const halaqohMap = new Map(); // normalizedHalaqohName -> { id, name, ustadzId }
const memberList = []; // Array of { halaqohId, waliId }

const uniqueSantriCheck = new Set();
const uniqueMemberCheck = new Set();

let waliIdCounter = 1;
let ustadzIdCounter = 10; // Start ustadz IDs from 10 to avoid conflict with admin (usually 1)
let halaqohIdCounter = 1;

function normalize(str) {
    if (!str) return '';
    return str.toString().trim().toLowerCase();
}

function parseHalaqoh(raw) {
    if (!raw) return null;
    // Example: "H-8 Ust Wahyudi" or "H-10 Ust Masyhur"
    // We want: Nama Halaqoh = "H-8", Nama Ustadz = "Ust Wahyudi"
    const parts = raw.split(' ');
    const halaqohName = parts[0] || '';
    const ustadzName = parts.slice(1).join(' ') || 'Ustadz Belum Ditentukan';
    return { halaqohName, ustadzName };
}

function generateUsername(name) {
    return name.toLowerCase().replace(/[^a-z0-9]/g, '').substring(0, 15) + Math.floor(Math.random() * 100);
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

            // 1. Process Wali Santri
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

            // 2. Process Santri
            const santriUniqKey = `${waliId}|${normalize(childName)}|${normalize(kelas)}`;
            if (!uniqueSantriCheck.has(santriUniqKey)) {
                uniqueSantriCheck.add(santriUniqKey);
                santriList.push({ waliId, childName, kelas });
            }

            // 3. Process Halaqoh & Ustadz
            const hInfo = parseHalaqoh(halaqohRaw);
            if (hInfo && hInfo.halaqohName) {
                const normUstadz = normalize(hInfo.ustadzName);
                let uId;
                if (!ustadzMap.has(normUstadz)) {
                    uId = ustadzIdCounter++;
                    ustadzMap.set(normUstadz, {
                        id: uId,
                        fullName: hInfo.ustadzName,
                        username: generateUsername(hInfo.ustadzName)
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

                // 4. Map Member
                const memberKey = `${hId}|${waliId}`;
                if (!uniqueMemberCheck.has(memberKey)) {
                    uniqueMemberCheck.add(memberKey);
                    memberList.push({ halaqohId: hId, waliId: waliId });
                }
            }
        });
    });
});

// Generate SQL Content
let sqlOutput = `-- Complete Data Import: Users (Ustadz), Halaqoh, Wali Santri, Santri, and Members\n`;
sqlOutput += `-- Generated on ${new Date().toLocaleString()}\n`;
sqlOutput += `SET FOREIGN_KEY_CHECKS = 0;\n`;
sqlOutput += `TRUNCATE TABLE halaqoh_members;\n`;
sqlOutput += `TRUNCATE TABLE halaqoh;\n`;
sqlOutput += `TRUNCATE TABLE santri_detail;\n`;
sqlOutput += `TRUNCATE TABLE wali_santri;\n`;
sqlOutput += `DELETE FROM users WHERE role = 'ustadz';\n`;
sqlOutput += `SET FOREIGN_KEY_CHECKS = 1;\n\n`;

// 1. Insert Ustadz (Users)
// Password hash for 'ustadz123'
const defaultPass = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
if (ustadzMap.size > 0) {
    sqlOutput += `-- Create Ustadz Accounts\n`;
    sqlOutput += `INSERT INTO users (id, username, password, nama_lengkap, role) VALUES\n`;
    const ustadzValues = Array.from(ustadzMap.values()).map(u => {
        return `(${u.id}, '${u.username}', '${defaultPass}', '${u.fullName.replace(/'/g, "''")}', 'ustadz')`;
    });
    sqlOutput += ustadzValues.join(',\n') + ';\n\n';
}

// 2. Insert Halaqoh
if (halaqohMap.size > 0) {
    sqlOutput += `-- Create Halaqohs\n`;
    sqlOutput += `INSERT INTO halaqoh (id, nama_halaqoh, ustadz_id) VALUES\n`;
    const hValues = Array.from(halaqohMap.values()).map(h => {
        return `(${h.id}, '${h.name.replace(/'/g, "''")}', ${h.ustadzId})`;
    });
    sqlOutput += hValues.join(',\n') + ';\n\n';
}

// 3. Insert Wali Santri
if (fatherMap.size > 0) {
    sqlOutput += `-- Create Wali Santri\n`;
    sqlOutput += `INSERT INTO wali_santri (id, nama_bapak, no_hp, alamat, kategori, status_aktif) VALUES\n`;
    const waliValues = Array.from(fatherMap.values()).map(data => {
        return `(${data.id}, '${data.originalName.replace(/'/g, "''")}', '${data.phone.replace(/'/g, "''")}', '${data.address.replace(/'/g, "''")}', 'reguler', 1)`;
    });
    sqlOutput += waliValues.join(',\n') + ';\n\n';
}

// 4. Insert Santri Detail
if (santriList.length > 0) {
    sqlOutput += `-- Create Santri Details\n`;
    sqlOutput += `INSERT INTO santri_detail (wali_santri_id, nama_anak, kelas) VALUES\n`;
    const sValues = santriList.map(s => {
        return `(${s.waliId}, '${s.childName.replace(/'/g, "''")}', '${s.kelas.replace(/'/g, "''")}')`;
    });
    sqlOutput += sValues.join(',\n') + ';\n\n';
}

// 5. Insert Halaqoh Members
if (memberList.length > 0) {
    sqlOutput += `-- Map Wali Santri to Halaqoh\n`;
    sqlOutput += `INSERT INTO halaqoh_members (halaqoh_id, wali_santri_id) VALUES\n`;
    const mValues = memberList.map(m => `(${m.halaqohId}, ${m.waliId})`);
    sqlOutput += mValues.join(',\n') + ';\n';
}

const outputPath = path.join(__dirname, '..', 'import_database_lengkap_v2.sql');
fs.writeFileSync(outputPath, sqlOutput);

console.log(`Successfully generated SQL file: ${outputPath}`);
console.log(`- Total Unique Fathers: ${fatherMap.size}`);
console.log(`- Total Children: ${santriList.length}`);
console.log(`- Total Ustadz Created: ${ustadzMap.size}`);
console.log(`- Total Halaqoh Created: ${halaqohMap.size}`);
console.log(`- Default Password for Ustadz: admin123 (Please change after import)`);
