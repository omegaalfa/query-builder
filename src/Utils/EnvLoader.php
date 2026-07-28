<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\Utils;

use InvalidArgumentException;
use RuntimeException;

/**
 * Loads trusted environment configuration without overwriting process values by default.
 *
 * In production, prefer variables injected by the process manager or a secrets provider.
 * When a .env file is used, enable strictPermissions and protect it with mode 0600.
 */
final class EnvLoader
{

    private const int MAX_FILE_SIZE = 1_048_576;

    private const string KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    /** @var array<string, true> */
    private static array $loadedFiles = [];

    /**
     * @param string|null $path
     * @param bool $required
     * @param bool $overwrite
     * @param bool $strictPermissions
     * @return void
     */
    public static function load(
        ?string $path = null,
        bool    $required = false,
        bool    $overwrite = false,
        bool    $strictPermissions = false,
    ): void
    {
        $envPath = self::resolvePath($path);

        if (!file_exists($envPath)) {
            if ($required) {
                throw new RuntimeException("Environment file not found: {$envPath}");
            }
            return;
        }
        if (!is_file($envPath) || !is_readable($envPath)) {
            throw new RuntimeException("Environment path must be a readable regular file: {$envPath}");
        }

        $canonicalPath = realpath($envPath);
        if ($canonicalPath === false) {
            throw new RuntimeException("Unable to resolve environment file: {$envPath}");
        }
        if (isset(self::$loadedFiles[$canonicalPath])) {
            return;
        }

        self::assertSafeFile($canonicalPath, $strictPermissions);
        $contents = self::readFile($canonicalPath);
        $variables = self::parse($contents, $canonicalPath);

        foreach ($variables as $key => $value) {
            if (!$overwrite && self::has($key)) {
                continue;
            }
            if (!putenv($key . '=' . $value)) {
                throw new RuntimeException("Unable to set environment variable: {$key}");
            }
            $_ENV[$key] = $value;
        }

        self::$loadedFiles[$canonicalPath] = true;
    }

    /**
     * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        self::assertValidKey($key);
        return array_key_exists($key, $_ENV)
            || array_key_exists($key, $_SERVER)
            || getenv($key) !== false;
    }

    /**
     * @param string $key
     * @param string|null $default
     * @return string|null
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        self::assertValidKey($key);
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            $value = $_SERVER[$key] ?? $default;
        }
        return $value === null ? null : (string)$value;
    }

    /**
     * @param string $key
     * @return string
     */
    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new RuntimeException("Required environment variable is missing or empty: {$key}");
        }
        return $value;
    }

    /**
     * @param string $key
     * @param int|null $default
     * @return int|null
     */
    public static function getInt(string $key, ?int $default = null): ?int
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        if (!preg_match('/^-?\d+$/D', $value)) {
            throw new InvalidArgumentException("Environment variable {$key} must be an integer.");
        }
        return (int)$value;
    }

    /**
     * @param string $key
     * @param bool|null $default
     * @return bool|null
     */
    public static function getBool(string $key, ?bool $default = null): ?bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new InvalidArgumentException(
                "Environment variable {$key} must be a boolean."
            ),
        };
    }

    /**
     * @param string|null $path
     * @return string
     */
    private static function resolvePath(?string $path): string
    {
        $envPath = $path ?? dirname(__DIR__, 2) . '/.env';
        return is_dir($envPath)
            ? rtrim($envPath, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . '.env'
            : $envPath;
    }

    /**
     * @param string $path
     * @param bool $strictPermissions
     * @return void
     */
    private static function assertSafeFile(string $path, bool $strictPermissions): void
    {
        $size = filesize($path);
        if ($size === false || $size > self::MAX_FILE_SIZE) {
            throw new RuntimeException('Environment file exceeds the 1 MiB safety limit.');
        }
        if (!$strictPermissions || PHP_OS_FAMILY === 'Windows') {
            return;
        }
        $permissions = fileperms($path);
        if ($permissions === false || ($permissions & 0o077) !== 0) {
            throw new RuntimeException(
                'Environment file permissions are too broad; use chmod 600: ' . $path
            );
        }
    }

    /**
     * @param string $path
     * @return string
     */
    private static function readFile(string $path): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open environment file: {$path}");
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException("Unable to lock environment file: {$path}");
            }
            $contents = stream_get_contents($handle, self::MAX_FILE_SIZE + 1);
            flock($handle, LOCK_UN);
            if ($contents === false || strlen($contents) > self::MAX_FILE_SIZE) {
                throw new RuntimeException("Unable to safely read environment file: {$path}");
            }
            return $contents;
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string, string> */
    private static function parse(string $contents, string $path): array
    {
        if (str_contains($contents, "\0")) {
            throw new InvalidArgumentException("NUL byte found in environment file: {$path}");
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $lines = preg_split('/\R/', $contents);
        if ($lines === false) {
            throw new RuntimeException("Unable to parse environment file: {$path}");
        }

        $variables = [];
        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (str_starts_with($trimmed, 'export ')) {
                $trimmed = ltrim(substr($trimmed, 7));
            }
            if (!str_contains($trimmed, '=')) {
                throw new InvalidArgumentException("Invalid .env entry at {$path}:{$lineNumber}");
            }

            [$key, $rawValue] = explode('=', $trimmed, 2);
            $key = trim($key);
            self::assertValidKey($key, "{$path}:{$lineNumber}");
            $variables[$key] = self::parseValue($rawValue, $path, $lineNumber);
        }
        return $variables;
    }

    /**
     * @param string $rawValue
     * @param string $path
     * @param int $lineNumber
     * @return string
     */
    private static function parseValue(string $rawValue, string $path, int $lineNumber): string
    {
        $value = ltrim($rawValue);
        if ($value === '') {
            return '';
        }
        $quote = $value[0];
        if ($quote !== '"' && $quote !== "'") {
            return trim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
        }

        $length = strlen($value);
        $escaped = false;
        for ($i = 1; $i < $length; $i++) {
            if ($quote === '"' && !$escaped && $value[$i] === '\\') {
                $escaped = true;
                continue;
            }
            if (!$escaped && $value[$i] === $quote) {
                $remainder = trim(substr($value, $i + 1));
                if ($remainder !== '' && !str_starts_with($remainder, '#')) {
                    throw new InvalidArgumentException(
                        "Unexpected content after quoted value at {$path}:{$lineNumber}"
                    );
                }
                $parsed = substr($value, 1, $i - 1);
                return $quote === '"'
                    ? str_replace(['\\"', '\\\\'], ['"', '\\'], $parsed)
                    : $parsed;
            }
            $escaped = false;
        }
        throw new InvalidArgumentException("Unclosed quoted value at {$path}:{$lineNumber}");
    }

    /**
     * @param string $key
     * @param string|null $location
     * @return void
     */
    private static function assertValidKey(string $key, ?string $location = null): void
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            $suffix = $location === null ? '' : " at {$location}";
            throw new InvalidArgumentException("Invalid environment variable name: {$key}{$suffix}");
        }
    }
}
