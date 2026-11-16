<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/csv_helper.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$dangerous = ['=1+1', '+1+1', '-1+1', '@SUM(A1:A2)', '＝1+1', '＋1+1', '－1+1', '＠SUM(A1:A2)'];
foreach ($dangerous as $value) {
    $safe = appSpreadsheetSafeText($value);
    $assert(str_starts_with($safe, "'"), 'Spreadsheet formula prefix is neutralized: ' . $value);
    $cell = appSpreadsheetExcelCell($value);
    $assert(str_starts_with(html_entity_decode($cell, ENT_QUOTES | ENT_HTML5, 'UTF-8'), "'"), 'HTML Excel cell keeps formula value as text: ' . $value);
}

foreach ([" =1+1", "\t=1+1", "\r=1+1", "\n=1+1", "\0=1+1"] as $value) {
    $assert(str_starts_with(appSpreadsheetSafeText($value), "'"), 'Formula preceded by ASCII whitespace/control is neutralized');
}

$html = appSpreadsheetExcelCell(" <script>alert('x')</script>");
$assert(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'HTML Excel cell escapes markup after formula handling');
$assert(appSpreadsheetExcelCell('Nama normal & aman') === 'Nama normal &amp; aman', 'Normal text is preserved and HTML escaped');
$assert(appSpreadsheetExcelCell(null) === '', 'Null cell is exported as an empty cell');

$stream = fopen('php://temp', 'w+b');
if ($stream === false) throw new RuntimeException('Cannot open temporary stream.');
$fields = ['Nama, Wali', 'He said "yes"', "baris satu\nbaris dua", '=1+1', "\t=2+2", null, '2026-10-30'];
$assert(appSpreadsheetCsvRow($stream, $fields), 'CSV row is written completely');
rewind($stream);
$csv = stream_get_contents($stream);
fclose($stream);
$assert(is_string($csv) && str_ends_with($csv, "\r\n"), 'CSV row uses CRLF record terminator');
$assert(is_string($csv) && str_starts_with($csv, '"Nama, Wali","He said ""yes""","baris satu' . "\n"), 'CSV quoting protects delimiters, quotes and embedded newlines');
$readStream = fopen('php://temp', 'w+b');
fwrite($readStream, (string) $csv);
rewind($readStream);
$decoded = iterator_to_array(appCsvRows($readStream), false);
fclose($readStream);
$assert(count($decoded) === 1 && count($decoded[0]) === count($fields), 'CSV row round-trips with the same column count');
$assert($decoded[0][0] === $fields[0] && $decoded[0][1] === $fields[1] && $decoded[0][2] === $fields[2], 'CSV reader preserves ordinary text and quoted content');
$assert(str_starts_with($decoded[0][3], "\t=") && str_starts_with($decoded[0][4], "\t\t="), 'CSV formula prefixes and leading control characters are neutralized');
$assert($decoded[0][5] === '' && $decoded[0][6] === $fields[6], 'CSV null and date cells round-trip');

echo "OK {$checks} spreadsheet export checks\n";
