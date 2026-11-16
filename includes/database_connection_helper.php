<?php

final class AppDatabaseConfigurationException extends RuntimeException {}

/**
 * Resolve one shared connection configuration for the web app, APIs and CLI.
 * Non-local environments must name their database explicitly. The historical
 * localhost defaults are available only when APP_ENV explicitly opts into a
 * local development or test environment.
 */
function appDatabaseConnectionSettings(): array
{
    $read = static function (string $name, string $default): string {
        $value = getenv($name);
        return $value === false || $value === '' ? $default : $value;
    };

    $environment = strtolower(trim((string) (getenv('APP_ENV') ?: '')));
    $allowLocalDefaults = in_array($environment, ['dev', 'development', 'local', 'test', 'testing'], true);
    if (!$allowLocalDefaults) {
        $missing = [];
        foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $name) {
            $value = getenv($name);
            if (!is_string($value) || trim($value) === '') $missing[] = $name;
        }
        if ($missing) {
            throw new AppDatabaseConfigurationException(
                'Konfigurasi database untuk lingkungan ini belum lengkap: ' . implode(', ', $missing) . '.'
            );
        }
    }

    $host = $read('DB_HOST', 'localhost');
    $port = $read('DB_PORT', '3306');
    $database = $read('DB_DATABASE', 'presensi_tahsin');
    $charset = $read('DB_CHARSET', 'utf8mb4');

    return [
        'dsn' => "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
        'username' => $read('DB_USERNAME', 'root'),
        'password' => $read('DB_PASSWORD', ''),
        'timeout' => max(1, (int) $read('DB_CONNECT_TIMEOUT', '5')),
    ];
}
