<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/legacy_student_import_helper.php';

$checks = 0;
function legacyCsvCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function legacyCsvReject(string $contents, string $message): void
{
    try { parseSantriBaruCsvContents($contents); }
    catch (ImportSantriCsvException) { legacyCsvCheck(true, $message); return; }
    throw new RuntimeException($message);
}

$commaRows = parseSantriBaruCsvContents("\xEF\xBB\xBFnama_bapak,no_hp,nama_anak,kelas,halaqoh\r\n\"Ahmad, Pak\",0812,Ali,1A,Mawar\r\n");
legacyCsvCheck(count($commaRows) === 1 && $commaRows[0]['nama_bapak'] === 'Ahmad, Pak', 'Comma CSV preserves quoted commas in names');

$semicolonRows = parseSantriBaruCsvContents("nama_bapak;no_hp;nama_anak;kelas;halaqoh\n\"Ahmad, Pak\";0812;Ali;1A;Mawar\n");
legacyCsvCheck(count($semicolonRows) === 1 && $semicolonRows[0]['halaqoh'] === 'Mawar', 'Semicolon delimiter is selected from its header despite commas in data');

$reorderedRows = parseSantriBaruCsvContents("nama_anak,nama_bapak,no_hp,kelas,halaqoh\nAli,Ahmad,0812,1A,Mawar\n");
legacyCsvCheck($reorderedRows[0]['nama_bapak'] === 'Ahmad' && $reorderedRows[0]['no_hp'] === '0812', 'Reordered required columns map by their header');

$header = "nama_bapak,no_hp,nama_anak,kelas,halaqoh\n";
legacyCsvReject("nama_bapak,no_hp,nama_anak,kelas\nPak,0812,Ali,1A\n", 'Missing required header is rejected before import');
legacyCsvReject("nama_bapak,no_hp,nama_anak,kelas,halaqoh,kelas\nPak,0812,Ali,1A,Mawar,1B\n", 'Duplicate headers are rejected before import');
legacyCsvReject("nama_bapak,no_hp,nama_anak,kelas,halaqoh,alamat\nPak,0812,Ali,1A,Mawar,Jalan A\n", 'Unsupported columns are rejected instead of being silently discarded');
legacyCsvReject($header . "Pak,0812,Ali,1A\n", 'Short rows are rejected instead of shifting values');
legacyCsvReject($header . "Pak,0812,Ali,1A,Mawar,unexpected\n", 'Extra row fields are rejected instead of being dropped');
legacyCsvReject($header . "Pak,0812,Ali,1A,Mawar\n" . "Pak,0812,\xFF,1A,Mawar\n", 'Invalid UTF-8 is rejected');
legacyCsvReject($header . "Pak,0812,Ali\0,1A,Mawar\n", 'NUL bytes are rejected');
legacyCsvReject($header . str_repeat("Pak,0812,Ali,1A,Mawar\n", IMPORT_BARU_MAX_ROWS + 1), 'Row limit is enforced while parsing');
legacyCsvReject(str_repeat('X', IMPORT_BARU_MAX_BYTES + 1), 'File byte limit is enforced before parsing');
legacyCsvCheck(parseSantriBaruCsvContents("\xEF\xBB\xBF \r\n") === [], 'Empty CSV returns an empty preview');

echo "PASS {$checks} legacy student import assertions\n";
