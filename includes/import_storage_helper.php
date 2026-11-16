<?php

/** Resolve a canonical temporary directory only when it is outside the webroot. */
function importPrivateTempDirectory(string $candidate, string $applicationRoot): string
{
    $temporaryRoot = realpath($candidate);
    $applicationRoot = realpath($applicationRoot);
    if ($temporaryRoot === false || $applicationRoot === false || !is_dir($temporaryRoot)) return '';

    $temporaryRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $temporaryRoot), DIRECTORY_SEPARATOR);
    $applicationRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $applicationRoot), DIRECTORY_SEPARATOR);
    $candidatePath = DIRECTORY_SEPARATOR === '\\' ? strtolower($temporaryRoot) : $temporaryRoot;
    $applicationPath = DIRECTORY_SEPARATOR === '\\' ? strtolower($applicationRoot) : $applicationRoot;
    $applicationPrefix = rtrim($applicationPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($candidatePath === $applicationPath || str_starts_with($candidatePath, $applicationPrefix)) return '';

    return $temporaryRoot;
}

/** Pick a writable private location; uploaded participant data must never land in the webroot. */
function importSafeTempDirectory(array $candidates, string $applicationRoot): string
{
    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') continue;
        $directory = importPrivateTempDirectory($candidate, $applicationRoot);
        if ($directory !== '' && is_writable($directory)) return $directory;
    }
    return '';
}

function resolveImportTempDir(): string
{
    $applicationRoot = dirname(__DIR__);
    $sessionDirectory = getenv('SESSION_SAVE_PATH') ?: '';
    return importSafeTempDirectory([sys_get_temp_dir(), $sessionDirectory], $applicationRoot);
}
