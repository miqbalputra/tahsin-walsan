<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/env_loader.php';

$checks = 0;
function envCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function envReject(callable $call, string $message): void
{
    try { $call(); } catch (RuntimeException) { envCheck(true, $message); return; }
    throw new RuntimeException($message);
}

$values = appParseEnvironment(<<<'ENV'
# comments and blank lines are ignored
APP_NAME=Presensi Tahsin
TOKEN=abc#literal
TOKEN_WITH_COMMENT=abc # comment
QUOTED="value = # still part of the value" # trailing comment
SINGLE='don\'t parse # or = here'
ESCAPED="line\nnext \"quoted\""
export EXPORTED=yes
DUPLICATE=first
DUPLICATE=last
ENV);
$exampleContents = file_get_contents(__DIR__ . '/../.env.example');
if (!is_string($exampleContents)) throw new RuntimeException('Could not read the tracked environment example.');
$example = appParseEnvironment($exampleContents);
envCheck(isset($example['DB_HOST'], $example['DB_DATABASE'], $example['DB_USERNAME'], $example['DB_PASSWORD']), 'The tracked environment example parses with database settings');
envCheck($values['APP_NAME'] === 'Presensi Tahsin', 'Unquoted values retain spaces');
envCheck($values['TOKEN'] === 'abc#literal', 'Hash without preceding whitespace remains part of a secret');
envCheck($values['TOKEN_WITH_COMMENT'] === 'abc', 'Whitespace-prefixed inline comments are removed');
envCheck($values['QUOTED'] === 'value = # still part of the value', 'Quoted value retains spaces, equals and hash');
envCheck($values['SINGLE'] === "don't parse # or = here", 'Single-quoted values retain punctuation');
envCheck($values['ESCAPED'] === "line\nnext \"quoted\"", 'Double-quoted escapes are decoded');
envCheck($values['EXPORTED'] === 'yes', 'export prefix is accepted');
envCheck($values['DUPLICATE'] === 'last', 'Last duplicate key wins within the file');
envReject(fn() => appParseEnvironment("INVALID LINE\n"), 'Malformed lines fail closed');
envReject(fn() => appParseEnvironment("BROKEN=\"unterminated\n"), 'Unterminated quotes fail closed');
envReject(fn() => appParseEnvironment(str_repeat('X', 65537)), 'Oversized files fail closed');

$suffix = strtoupper(bin2hex(random_bytes(6)));
$existingName = 'TAHSIN_ENV_EXISTING_' . $suffix;
$loadedName = 'TAHSIN_ENV_LOADED_' . $suffix;
putenv($existingName . '=process-value');
$loaded = appApplyEnvironment([$existingName => 'file-value', $loadedName => 'file-value']);
envCheck(getenv($existingName) === 'process-value', 'Process environment takes precedence over file values');
envCheck(getenv($loadedName) === 'file-value' && $loaded === [$loadedName], 'Unset process values are loaded from the file');

$path = tempnam(sys_get_temp_dir(), 'tahsin-env-');
if ($path === false) throw new RuntimeException('Could not create isolated environment fixture.');
$fileName = 'TAHSIN_ENV_FILE_' . $suffix;
$previousFile = getenv('APP_ENV_FILE');
$previousOptIn = getenv('APP_LOAD_ENV_FILE');
try {
    if (file_put_contents($path, $fileName . "=loaded\n") === false) throw new RuntimeException('Could not write isolated environment fixture.');
    putenv('APP_ENV_FILE=' . $path);
    putenv('APP_LOAD_ENV_FILE');
    envCheck(!appEnvironmentFileEnabled() && appLoadEnvironmentFile() === [], 'CLI does not read environment files by default');
    envCheck(getenv($fileName) === false, 'CLI default does not apply values from a selected file');
    putenv('APP_LOAD_ENV_FILE=1');
    appLoadEnvironmentFile();
    envCheck(getenv($fileName) === 'loaded', 'CLI loads a selected environment file only after explicit opt-in');
} finally {
    putenv($existingName);
    putenv($loadedName);
    putenv($fileName);
    if ($previousFile === false) putenv('APP_ENV_FILE'); else putenv('APP_ENV_FILE=' . $previousFile);
    if ($previousOptIn === false) putenv('APP_LOAD_ENV_FILE'); else putenv('APP_LOAD_ENV_FILE=' . $previousOptIn);
    @unlink($path);
}

echo "PASS {$checks} environment-loader assertions\n";
