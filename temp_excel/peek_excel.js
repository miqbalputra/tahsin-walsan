const XLSX = require('xlsx');
const path = require('path');

const excelFile = path.join(__dirname, '..', 'Mustawa_Ikhwan.xlsx');
const workbook = XLSX.readFile(excelFile);

const sheets = workbook.SheetNames;
console.log('Sheets:', sheets);

sheets.forEach(sheetName => {
    const sheet = workbook.Sheets[sheetName];
    const data = XLSX.utils.sheet_to_json(sheet, { header: 1 });
    console.log(`\nSheet: ${sheetName}`);
    if (data.length > 0) {
        console.log('Columns:', data[0]);
        console.log('First data row:', data[1]);
    }
});
