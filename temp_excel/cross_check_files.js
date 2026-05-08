const XLSX = require('xlsx');
const path = require('path');

const files = ['Mustawa_Ikhwan.xlsx', 'Mustawa_Akhwat.xlsx'];
const fatherMap = new Map();

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

            const fatherName = (row[fatherKey] || '').trim();
            const childName = (row[childKey] || '').trim();
            const phone = (row[phoneKey] || '').toString().trim();
            const gender = fileName.includes('Ikhwan') ? 'Ikhwan' : 'Akhwat';

            if (!fatherName || !childName) return;

            const normName = normalize(fatherName);
            const normPhone = normalize(phone);
            const key = `${normName}|${normPhone}`;

            if (!fatherMap.has(key)) {
                fatherMap.set(key, {
                    originalName: fatherName,
                    phone: phone,
                    children: new Map()
                });
            }

            const childUniqKey = normalize(`${childName}|${sheetName}|${gender}`);
            fatherMap.get(key).children.set(childUniqKey, `${childName} (${sheetName} - ${gender})`);
        });
    });
});

const report = [];
for (const [key, data] of fatherMap.entries()) {
    if (data.children.size > 1) {
        report.push({
            fatherName: data.originalName,
            phone: data.phone,
            children: Array.from(data.children.values())
        });
    }
}

console.log('--- HASIL CROSS-CHECK TOTAL (IKHWAN & AKHWAT) ---');
console.log(JSON.stringify(report, null, 2));
console.log(`\nTotal Wali Santri Unik: ${fatherMap.size}`);
console.log(`Total Wali Santri dengan > 1 Anak: ${report.length}`);
