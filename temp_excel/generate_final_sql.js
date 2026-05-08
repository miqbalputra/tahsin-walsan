const XLSX = require('xlsx');
const path = require('path');
const fs = require('fs');

const files = ['Mustawa_Ikhwan.xlsx', 'Mustawa_Akhwat.xlsx'];
const fatherMap = new Map(); // normalizedKey -> { id, originalName, phone, address }
const santriList = []; // Array of { waliId, childName, kelas }
const uniqueSantriCheck = new Set(); // To prevent exact duplicates

let waliIdCounter = 1;

function normalize(str) {
    if (!str) return '';
    return str.toString().trim().toLowerCase();
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

            const fatherName = (row[fatherKey] || '').trim();
            const childName = (row[childKey] || '').trim();
            const phone = (row[phoneKey] || '').toString().trim();
            const address = (row[addressKey] || '').trim();
            const kelas = sheetName;

            // Validation & Skip Rules
            if (!fatherName || !childName) return;
            if (normalize(fatherName) === 'sudah dijapri') return; // Skip instructed data

            const normName = normalize(fatherName);
            const normPhone = normalize(phone);
            const fatherKeyId = `${normName}|${normPhone}`;

            // Handle Wali Santri (Father)
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

            // Handle Santri (Child) - Clean exact duplicates (same father, same child name, same class)
            const santriUniqKey = `${waliId}|${normalize(childName)}|${normalize(kelas)}`;
            if (!uniqueSantriCheck.has(santriUniqKey)) {
                uniqueSantriCheck.add(santriUniqKey);
                santriList.push({
                    waliId: waliId,
                    childName: childName,
                    kelas: kelas
                });
            }
        });
    });
});

// Generate SQL
let sqlOutput = `-- Data Import from Mustawa_Ikhwan.xlsx and Mustawa_Akhwat.xlsx\n`;
sqlOutput += `-- Generated on ${new Date().toLocaleString()}\n`;
sqlOutput += `SET FOREIGN_KEY_CHECKS = 0;\n`;
sqlOutput += `TRUNCATE TABLE santri_detail;\n`;
sqlOutput += `TRUNCATE TABLE wali_santri;\n`;
sqlOutput += `SET FOREIGN_KEY_CHECKS = 1;\n\n`;

// Insert Wali Santri
if (fatherMap.size > 0) {
    const waliValues = [];
    for (const data of fatherMap.values()) {
        const escName = data.originalName.replace(/'/g, "''");
        const escPhone = data.phone.replace(/'/g, "''");
        const escAddress = data.address.replace(/'/g, "''");
        waliValues.push(`(${data.id}, '${escName}', '${escPhone}', '${escAddress}', 'reguler', 1)`);
    }
    sqlOutput += `INSERT INTO wali_santri (id, nama_bapak, no_hp, alamat, kategori, status_aktif) VALUES\n`;
    sqlOutput += waliValues.join(',\n') + ';\n\n';
}

// Insert Santri Detail
if (santriList.length > 0) {
    const santriValues = santriList.map(s => {
        const escChild = s.childName.replace(/'/g, "''");
        const escKelas = s.kelas.replace(/'/g, "''");
        return `(${s.waliId}, '${escChild}', '${escKelas}')`;
    });
    sqlOutput += `INSERT INTO santri_detail (wali_santri_id, nama_anak, kelas) VALUES\n`;
    sqlOutput += santriValues.join(',\n') + ';\n';
}

const outputPath = path.join(__dirname, '..', 'import_mustawa_lengkap.sql');
fs.writeFileSync(outputPath, sqlOutput);

console.log(`Successfully generated SQL file: ${outputPath}`);
console.log(`Total Unique Fathers (Wali Santri): ${fatherMap.size}`);
console.log(`Total Children (Santri Detail): ${santriList.length}`);
console.log(`Data 'sudah dijapri' has been skipped.`);
