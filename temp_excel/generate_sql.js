const XLSX = require('xlsx');
const path = require('path');
const fs = require('fs');

const excelFile = path.join(__dirname, '..', 'Mustawa_Ikhwan.xlsx');
const workbook = XLSX.readFile(excelFile);

const sheets = workbook.SheetNames;
const uniqueEntries = new Set();
const waliSantriMap = new Map(); // fatherName_phone -> id
let waliIdCounter = 1;

let sqlOutput = `-- Data Import from Mustawa_Ikhwan.xlsx\n`;
sqlOutput += `SET FOREIGN_KEY_CHECKS = 0;\n`;
sqlOutput += `TRUNCATE TABLE santri_detail;\n`;
sqlOutput += `TRUNCATE TABLE wali_santri;\n`;
sqlOutput += `SET FOREIGN_KEY_CHECKS = 1;\n\n`;

let waliSantriInserts = [];
let santriDetailInserts = [];

sheets.forEach(sheetName => {
    const sheet = workbook.Sheets[sheetName];
    // Use header: 1 to get raw array to handle potentially messy headers
    const rawData = XLSX.utils.sheet_to_json(sheet);

    rawData.forEach(row => {
        const fatherName = (row['Nama Bapak'] || '').trim();
        const childName = (row['Nama Anak'] || '').trim();
        const phone = (row['Nomor WhatsApp Aktif Bapak\r\nFormat: (Contoh: 08123456789)'] || '').toString().trim();
        const address = (row['Jika saat ini berada di "Halaqoh Luar", mohon isikan alamat lokasi belajar dan nama Ustadz Pengajar\r\n\r\nContoh: Desa A RT 01 RW 01, Kec ... Kab, .... , dengan Ustadz Fulan'] || '').trim();
        const kelas = sheetName;

        if (!fatherName || !childName) return;

        // Unique key to prevent exact duplicates (Father + Child + Class)
        const entryKey = `${fatherName}|${childName}|${kelas}`;
        if (uniqueEntries.has(entryKey)) return;
        uniqueEntries.add(entryKey);

        // Map Wali Santri (Father + Phone)
        const waliKey = `${fatherName}|${phone}`;
        let currentWaliId;
        if (!waliSantriMap.has(waliKey)) {
            currentWaliId = waliIdCounter++;
            waliSantriMap.set(waliKey, currentWaliId);

            // Escape single quotes for SQL
            const escFather = fatherName.replace(/'/g, "''");
            const escPhone = phone.replace(/'/g, "''");
            const escAddress = address.replace(/'/g, "''");

            waliSantriInserts.push(`(${currentWaliId}, '${escFather}', '${escPhone}', '${escAddress}', 'reguler', 1)`);
        } else {
            currentWaliId = waliSantriMap.get(waliKey);
        }

        // Add Santri Detail
        const escChild = childName.replace(/'/g, "''");
        const escKelas = kelas.replace(/'/g, "''");
        santriDetailInserts.push(`(${currentWaliId}, '${escChild}', '${escKelas}')`);
    });
});

if (waliSantriInserts.length > 0) {
    sqlOutput += `INSERT INTO wali_santri (id, nama_bapak, no_hp, alamat, kategori, status_aktif) VALUES\n`;
    sqlOutput += waliSantriInserts.join(',\n') + ';\n\n';
}

if (santriDetailInserts.length > 0) {
    sqlOutput += `INSERT INTO santri_detail (wali_santri_id, nama_anak, kelas) VALUES\n`;
    sqlOutput += santriDetailInserts.join(',\n') + ';\n';
}

const outputPath = path.join(__dirname, '..', 'import_mustawa_ikhwan.sql');
fs.writeFileSync(outputPath, sqlOutput);

console.log(`Successfully generated SQL file: ${outputPath}`);
console.log(`Total Wali Santri: ${waliSantriMap.size}`);
console.log(`Total Santri Detail: ${santriDetailInserts.length}`);
console.log(`Cleaned up duplicates.`);
