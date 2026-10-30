<?php

const IMPORT_BARU_MAX_BYTES = 5 * 1024 * 1024;
const IMPORT_BARU_MAX_ROWS = 5000;

final class ImportSantriCsvException extends RuntimeException {}

function santriCsvReadHeader(string $contents, string $delimiter): array
{
    $stream = fopen('php://temp', 'w+');
    if ($stream === false) throw new ImportSantriCsvException('CSV tidak dapat diperiksa.');
    try {
        if (fwrite($stream, $contents) !== strlen($contents)) throw new ImportSantriCsvException('CSV tidak dapat diperiksa.');
        rewind($stream);
        $header = fgetcsv($stream, 0, $delimiter, '"', '');
        return is_array($header) ? $header : [];
    } finally {
        fclose($stream);
    }
}

/** Parse only the supported wali/child columns and reject shifted or incomplete rows. */
function parseSantriBaruCsvContents(string $contents): array
{
    if (strlen($contents) > IMPORT_BARU_MAX_BYTES) throw new ImportSantriCsvException('Ukuran file melebihi 5 MB. Pecah berkas atau gunakan alur Impor Peserta.');
    if (str_contains($contents, "\0") || !preg_match('//u', $contents)) throw new ImportSantriCsvException('CSV harus berisi teks UTF-8 yang valid.');
    if (str_starts_with($contents, "\xEF\xBB\xBF")) $contents = substr($contents, 3);
    if (trim($contents) === '') return [];

    $required = ['nama_bapak', 'no_hp', 'nama_anak', 'kelas', 'halaqoh'];
    $candidates = [];
    foreach ([',', ';'] as $delimiter) {
        $header = santriCsvReadHeader($contents, $delimiter);
        if (!$header) continue;
        $normalized = array_map(static fn($value): string => strtolower(trim((string) $value)), $header);
        if (in_array('', $normalized, true) || count($normalized) !== count(array_unique($normalized))) continue;
        if (count($normalized) === count($required) && !array_diff($required, $normalized) && !array_diff($normalized, $required)) {
            $candidates[] = ['delimiter' => $delimiter, 'header' => $normalized];
        }
    }
    if (count($candidates) !== 1) {
        throw new ImportSantriCsvException('Header CSV tidak cocok atau pemisah kolom ambigu. Gunakan tepat kolom nama_bapak, no_hp, nama_anak, kelas, halaqoh dengan koma atau titik koma.');
    }

    $delimiter = $candidates[0]['delimiter'];
    $header = $candidates[0]['header'];
    $stream = fopen('php://temp', 'w+');
    if ($stream === false) throw new ImportSantriCsvException('CSV tidak dapat dibaca.');
    try {
        if (fwrite($stream, $contents) !== strlen($contents)) throw new ImportSantriCsvException('CSV tidak dapat dibaca.');
        rewind($stream);
        fgetcsv($stream, 0, $delimiter, '"', '');
        $rows = [];
        $record = 1;
        while (($data = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $record++;
            if (count($data) === 1 && ($data[0] === '' || $data[0] === null)) continue;
            if (count($data) !== count($header)) {
                throw new ImportSantriCsvException("Jumlah kolom pada baris CSV {$record} tidak cocok. Tidak ada data yang diimpor; periksa tanda kutip dan pemisah kolom.");
            }
            $row = [];
            foreach ($header as $index => $column) $row[$column] = trim((string) ($data[$index] ?? ''));
            $rows[] = $row;
            if (count($rows) > IMPORT_BARU_MAX_ROWS) {
                throw new ImportSantriCsvException('Jumlah baris melebihi ' . IMPORT_BARU_MAX_ROWS . '. Pecah berkas atau gunakan alur Impor Peserta.');
            }
        }
        return $rows;
    } finally {
        fclose($stream);
    }
}

function parseCsvFile(string $path): array
{
    if (!is_file($path)) return [];
    $size = filesize($path);
    if ($size === false) throw new ImportSantriCsvException('Ukuran CSV tidak dapat diperiksa.');
    if ($size > IMPORT_BARU_MAX_BYTES) throw new ImportSantriCsvException('Ukuran file melebihi 5 MB. Pecah berkas atau gunakan alur Impor Peserta.');
    $contents = file_get_contents($path);
    if (!is_string($contents)) throw new ImportSantriCsvException('CSV tidak dapat dibaca.');
    return parseSantriBaruCsvContents($contents);
}
