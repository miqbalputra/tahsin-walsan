<?php

/** Parse the small KEY=value subset used by the application's .env.example. */
function appParseEnvironment(string $contents): array
{
    if (strlen($contents) > 65536) {
        throw new RuntimeException('Berkas environment melebihi batas 64 KiB.');
    }
    if (str_starts_with($contents, "\xEF\xBB\xBF")) $contents = substr($contents, 3);

    $values = [];
    foreach (preg_split('/\r\n|\n|\r/', $contents) as $lineNumber => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_starts_with($line, 'export ')) $line = ltrim(substr($line, 7));

        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/D', $line, $match)) {
            throw new RuntimeException('Format environment tidak valid pada baris ' . ($lineNumber + 1) . '.');
        }
        $name = $match[1];
        $raw = trim($match[2]);

        if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
            $quote = $raw[0];
            $end = null;
            $escaped = false;
            for ($i = 1, $length = strlen($raw); $i < $length; $i++) {
                if ($escaped) { $escaped = false; continue; }
                if ($raw[$i] === '\\' && $i + 1 < $length
                    && ($quote === '"' || $raw[$i + 1] === $quote || $raw[$i + 1] === '\\')) {
                    $escaped = true;
                    continue;
                }
                if ($raw[$i] === $quote) { $end = $i; break; }
            }
            if ($end === null) throw new RuntimeException("Nilai environment {$name} tidak memiliki tanda kutip penutup.");
            $tail = trim(substr($raw, $end + 1));
            if ($tail !== '' && !str_starts_with($tail, '#')) {
                throw new RuntimeException("Ada teks setelah nilai environment {$name}.");
            }
            $value = substr($raw, 1, $end - 1);
            if ($quote === '"') {
                $value = preg_replace_callback('/\\\\([nrt"\\\\])/', static fn(array $escape): string => match ($escape[1]) {
                    'n' => "\n", 'r' => "\r", 't' => "\t", default => $escape[1],
                }, $value);
            } else {
                $value = str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
            }
        } else {
            if (preg_match('/\s+#/', $raw, $comment, PREG_OFFSET_CAPTURE)) {
                $raw = substr($raw, 0, $comment[0][1]);
            }
            $value = trim($raw);
        }

        $values[$name] = $value;
    }
    return $values;
}

/** Existing process variables always take precedence over values from .env. */
function appApplyEnvironment(array $values): array
{
    foreach ($values as $name => $value) {
        if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)
            || !is_string($value) || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Nama atau nilai environment tidak valid.');
        }
        if (in_array($name, ['APP_ENV_FILE', 'APP_LOAD_ENV_FILE'], true)) {
            throw new InvalidArgumentException("{$name} harus disediakan oleh environment proses.");
        }
    }
    $loaded = [];
    foreach ($values as $name => $value) {
        if (getenv($name) !== false) continue;
        if (!putenv($name . '=' . $value)) throw new RuntimeException("Environment {$name} tidak dapat disiapkan.");
        $loaded[$name] = true;
    }
    return array_keys($loaded);
}

function appEnvironmentFileEnabled(): bool
{
    return PHP_SAPI !== 'cli' || getenv('APP_LOAD_ENV_FILE') === '1';
}

/**
 * Load .env for web requests. CLI callers must opt in explicitly so audit and
 * test commands cannot silently connect to a developer's production database.
 */
function appLoadEnvironmentFile(): array
{
    if (!appEnvironmentFileEnabled()) return [];
    $path = getenv('APP_ENV_FILE');
    if (!is_string($path) || trim($path) === '') $path = dirname(__DIR__) . '/.env';
    if (!is_file($path) || !is_readable($path)) return [];
    $size = filesize($path);
    if ($size === false || $size > 65536) throw new RuntimeException('Berkas environment tidak valid atau melebihi batas 64 KiB.');
    $contents = file_get_contents($path);
    if (!is_string($contents)) throw new RuntimeException('Berkas environment tidak dapat dibaca.');
    return appApplyEnvironment(appParseEnvironment($contents));
}
