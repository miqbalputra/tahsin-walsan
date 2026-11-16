<?php
/** Return spreadsheet-facing HTML/XLS text as text when whitespace hides a formula. */
function appSpreadsheetSafeText($value): string
{
    $value = (string) ($value ?? '');
    $candidate = ltrim($value, "\x00..\x20");
    if ($candidate !== '' && preg_match('/\A[=+\-@＝＋－＠]/', $candidate)) {
        return "'" . $value;
    }
    return $value;
}

/** Return an HTML cell value that remains text in spreadsheet applications. */
function appSpreadsheetExcelCell($value): string
{
    return htmlspecialchars(appSpreadsheetSafeText($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Write a fully quoted, spreadsheet-safe CSV row for human viewing. */
function appSpreadsheetCsvRow($stream, array $fields): bool
{
    $cells = [];
    foreach ($fields as $value) {
        $value = (string) ($value ?? '');
        $candidate = ltrim($value, "\x00..\x20");
        if (($candidate !== '' && preg_match('/\A[=+\-@＝＋－＠]/', $candidate))
            || ($value !== '' && str_contains("\t\r\n", $value[0]))) {
            // A tab inside a quoted CSV field is the Excel-resistant mitigation.
            $value = "\t" . $value;
        }
        $cells[] = '"' . str_replace('"', '""', $value) . '"';
    }

    $line = implode(',', $cells) . "\r\n";
    $offset = 0;
    $length = strlen($line);
    while ($offset < $length) {
        $written = fwrite($stream, substr($line, $offset));
        if ($written === false || $written === 0) return false;
        $offset += $written;
    }
    return true;
}

/** Streaming CSV reader: bounded logical records, complete quotes, no truncation. */
function appCsvRows($stream, string $delimiter = ',', string $escape = '', int $maxRowBytes = 1048576): Generator
{
    if (!in_array($delimiter, [',', ';', "\t"], true) || !in_array($escape, ['', '\\'], true)) {
        throw new InvalidArgumentException('Pemisah CSV tidak didukung.');
    }
    $raw = '';
    $state = 'start';
    while (($line = fgets($stream, $maxRowBytes + 2)) !== false) {
        $raw .= $line;
        if (strlen($raw) > $maxRowBytes) throw new InvalidArgumentException('Satu baris CSV terlalu panjang.');
        $complete = false;
        for ($index = 0, $length = strlen($line); $index < $length; $index++) {
            $char = $line[$index];
            if ($state === 'quoted') {
                if ($escape !== '' && $char === $escape && ($line[$index + 1] ?? '') === '"') { $index++; continue; }
                if ($char === '"') $state = 'closed';
                continue;
            }
            if ($char === "\r" && ($line[$index + 1] ?? '') === "\n") continue;
            if ($char === "\n") { $complete = true; continue; }
            if ($state === 'closed') {
                if ($char === '"') $state = 'quoted';
                elseif ($char === $delimiter) $state = 'start';
                else throw new InvalidArgumentException('Struktur tanda kutip CSV rusak.');
            } elseif ($char === $delimiter) {
                $state = 'start';
            } elseif ($char === '"') {
                if ($state !== 'start') throw new InvalidArgumentException('Struktur tanda kutip CSV rusak.');
                $state = 'quoted';
            } else {
                if ($char === "\r") throw new InvalidArgumentException('Pemisah baris CSV tidak valid.');
                $state = 'plain';
            }
        }
        if ($complete) {
            $record = str_getcsv(preg_replace('/\r?\n$/D', '', $raw), $delimiter, '"', $escape);
            if ($record !== [null]) yield $record;
            $raw = '';
            $state = 'start';
        }
    }
    if (!feof($stream)) throw new RuntimeException('CSV tidak dapat dibaca hingga selesai.');
    if ($state === 'quoted') throw new InvalidArgumentException('Tanda kutip CSV belum ditutup.');
    if ($raw !== '') yield str_getcsv($raw, $delimiter, '"', $escape);
}
