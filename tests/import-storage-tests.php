<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/import_storage_helper.php';

$checks = 0;
function importStorageCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

$applicationRoot = realpath(__DIR__ . '/..');
if ($applicationRoot === false) throw new RuntimeException('Could not resolve application root.');
$temporaryDirectory = resolveImportTempDir();
importStorageCheck($temporaryDirectory !== '', 'A writable temporary directory is available');
importStorageCheck(importPrivateTempDirectory($applicationRoot, $applicationRoot) === '', 'The webroot itself is never an upload directory');
importStorageCheck(importPrivateTempDirectory(__DIR__, $applicationRoot) === '', 'A nested application directory is never an upload directory');
importStorageCheck(importSafeTempDirectory([$applicationRoot, $temporaryDirectory], $applicationRoot) === $temporaryDirectory, 'A private candidate wins over an in-webroot candidate');
importStorageCheck(importSafeTempDirectory([$applicationRoot], $applicationRoot) === '', 'No unsafe in-webroot fallback is returned');

$upload = tempnam($temporaryDirectory, 'tahsin-import-audit-');
if ($upload === false) throw new RuntimeException('Could not create an isolated temporary upload.');
try {
    $realUpload = realpath($upload);
    importStorageCheck($realUpload !== false && importPrivateTempDirectory(dirname($realUpload), $applicationRoot) !== '', 'Temporary upload resolves outside the application webroot');
    if (DIRECTORY_SEPARATOR !== '\\') importStorageCheck((fileperms($upload) & 0077) === 0, 'Temporary upload is not readable by other local users');
} finally {
    @unlink($upload);
}

echo "PASS {$checks} import-storage assertions\n";
