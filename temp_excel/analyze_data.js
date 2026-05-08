const XLSX = require('xlsx');
const path = require('path');

const excelFile = path.join(__dirname, '..', 'Mustawa_Ikhwan.xlsx');
const workbook = XLSX.readFile(excelFile);

const sheets = workbook.SheetNames;
const allData = [];
const fatherMap = new Map(); // Nama Bapak -> Array of child data

sheets.forEach(sheetName => {
    const sheet = workbook.Sheets[sheetName];
    const data = XLSX.utils.sheet_to_json(sheet);

    data.forEach(row => {
        const fatherName = row['Nama Bapak'] ? row['Nama Bapak'].trim() : 'Unknown';
        const childName = row['Nama Anak'] ? row['Nama Anak'].trim() : 'Unknown';
        const phone = row['Nomor WhatsApp Aktif Bapak\r\nFormat: (Contoh: 08123456789)'] || '';
        const halaqohLuar = row['Jika saat ini berada di "Halaqoh Luar", mohon isikan alamat lokasi belajar dan nama Ustadz Pengajar\r\n\r\nContoh: Desa A RT 01 RW 01, Kec ... Kab, .... , dengan Ustadz Fulan'] || '';
        const kelas = sheetName; // Use sheet name as class

        const item = {
            fatherName,
            childName,
            phone,
            kelas,
            address: halaqohLuar,
            pencapaian: row['Pencapaian Saat ini'] || '',
            halaqoh: row['Halaqoh saat ini'] || ''
        };

        if (!fatherMap.has(fatherName)) {
            fatherMap.set(fatherName, []);
        }
        fatherMap.get(fatherName).push(item);
        allData.push(item);
    });
});

const duplicates = [];
for (const [fatherName, children] of fatherMap.entries()) {
    if (children.length > 1) {
        duplicates.push({
            fatherName,
            children: children.map(c => `${c.childName} (${c.kelas})`)
        });
    }
}

console.log('Duplicate check results:');
if (duplicates.length > 0) {
    console.log(JSON.stringify(duplicates, null, 2));
} else {
    console.log('No duplicate father names found.');
}

console.log('\nTotal records processed:', allData.length);
console.log('Total unique fathers:', fatherMap.size);
