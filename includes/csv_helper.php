<?php
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
