<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\Logger;

use DateTimeImmutable;
use DateTimeInterface;
use Omegaalfa\QueryBuilder\Interfaces\DatabaseValueInterface;
use Omegaalfa\QueryBuilder\Interfaces\QueryLoggerInterface;
use RuntimeException;
use Stringable;
use Throwable;

class FileQueryLogger implements QueryLoggerInterface
{
    /**
     *
     */
    private const DEFAULT_SENSITIVE_KEYS = [
        'authorization',
        'cookie',
        'credit_card',
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
    ];

    /** @var list<string> */
    private readonly array $sensitiveKeys;

    /**
     * @param list<string> $sensitiveKeys Chaves de parâmetros substituídas por "[REDACTED]".
     */
    public function __construct(
        private readonly string $logPath,
        private readonly bool   $enabled = true,
        private readonly bool   $logParameters = false,
        private readonly bool   $includeStackTrace = false,
        array                   $sensitiveKeys = self::DEFAULT_SENSITIVE_KEYS,
        private readonly int    $maxFileSize = 10_485_760,
        private readonly int    $maxFiles = 5,
        private readonly int    $filePermissions = 0600,
        private readonly bool   $throwOnFailure = false,
        private readonly int    $maxValueLength = 2_048,
        private readonly int    $maxCollectionItems = 100,
    )
    {
        $this->sensitiveKeys = array_values(array_unique(array_map(
            static fn(string $key): string => strtolower(trim($key)),
            $sensitiveKeys,
        )));

        $this->validateConfiguration();
    }

    /**
     * @param string $sql
     * @param array $params
     * @param float $duration
     * @param int $rowCount
     * @return void
     */
    public function logQuery(string $sql, array $params, float $duration, int $rowCount): void
    {
        if (!$this->enabled) {
            return;
        }

        $entry = [
            'timestamp' => (new DateTimeImmutable())->format(DATE_RFC3339_EXTENDED),
            'level' => 'query',
            'duration_ms' => round($duration * 1_000, 3),
            'affected_rows' => $rowCount,
            'sql' => $sql,
        ];

        if ($this->logParameters) {
            $entry['params'] = $this->normalizeCollection($params);
        }

        $this->writeEntry($entry);
    }

    /**
     * @param string $sql
     * @param array $params
     * @param Throwable $error
     * @return void
     */
    public function logError(string $sql, array $params, Throwable $error): void
    {
        if (!$this->enabled) {
            return;
        }

        $entry = [
            'timestamp' => (new DateTimeImmutable())->format(DATE_RFC3339_EXTENDED),
            'level' => 'error',
            'sql' => $sql,
            'error' => [
                'type' => $error::class,
                'code' => $error->getCode(),
                'message' => $this->truncate($error->getMessage()),
            ],
        ];

        if ($this->logParameters) {
            $entry['params'] = $this->normalizeCollection($params);
        }
        if ($this->includeStackTrace) {
            $entry['error']['trace'] = $this->truncate($error->getTraceAsString());
        }

        $this->writeEntry($entry);
    }

    /**
     * @return void
     */
    private function validateConfiguration(): void
    {
        if (!$this->enabled) {
            return;
        }
        if (trim($this->logPath) === '') {
            throw new RuntimeException('Log path cannot be empty.');
        }
        if ($this->maxFileSize < 0 || $this->maxFiles < 1) {
            throw new RuntimeException('Log rotation configuration is invalid.');
        }
        if ($this->filePermissions < 0 || $this->filePermissions > 0777) {
            throw new RuntimeException('Log file permissions must be between 0000 and 0777.');
        }
        if ($this->maxValueLength < 1 || $this->maxCollectionItems < 1) {
            throw new RuntimeException('Log value limits must be greater than zero.');
        }

        $directory = dirname($this->logPath);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException("Log directory does not exist or is not writable: {$directory}");
        }
        if (is_dir($this->logPath) || is_link($this->logPath)) {
            throw new RuntimeException('Log path must be a regular file and cannot be a symbolic link.');
        }
        if (is_file($this->logPath) && !is_writable($this->logPath)) {
            throw new RuntimeException("Log file is not writable: {$this->logPath}");
        }
    }

    /** @param array<string, mixed> $entry */
    private function writeEntry(array $entry): void
    {
        try {
            $json = json_encode(
                $entry,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
            );
            $this->writeLine($json . PHP_EOL);
        } catch (Throwable $error) {
            if ($this->throwOnFailure) {
                throw new RuntimeException('Failed to write query log.', 0, $error);
            }
        }
    }

    /**
     * @param string $line
     * @return void
     */
    private function writeLine(string $line): void
    {
        $lockPath = $this->logPath . '.lock';
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Unable to open the query log lock file.');
        }

        try {
            if (!@chmod($lockPath, $this->filePermissions)) {
                throw new RuntimeException('Unable to secure the query log lock file.');
            }
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to lock the query log.');
            }

            if (is_link($this->logPath)) {
                throw new RuntimeException('Query log path became a symbolic link.');
            }

            clearstatcache(true, $this->logPath);
            $currentSize = is_file($this->logPath) ? filesize($this->logPath) : 0;
            if ($this->maxFileSize > 0 && is_int($currentSize) && $currentSize + strlen($line) > $this->maxFileSize) {
                $this->rotateFiles();
            }

            $isNewFile = !is_file($this->logPath);
            $stream = @fopen($this->logPath, 'ab');
            if ($stream === false) {
                throw new RuntimeException('Unable to open the query log file.');
            }

            try {
                if ($isNewFile && !@chmod($this->logPath, $this->filePermissions)) {
                    throw new RuntimeException('Unable to secure the query log file.');
                }
                $remaining = $line;
                while ($remaining !== '') {
                    $written = fwrite($stream, $remaining);
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Unable to write the complete query log entry.');
                    }
                    $remaining = substr($remaining, $written);
                }
                if (!fflush($stream)) {
                    throw new RuntimeException('Unable to flush the query log entry.');
                }
            } finally {
                fclose($stream);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return void
     */
    private function rotateFiles(): void
    {
        $oldest = $this->logPath . '.' . $this->maxFiles;
        if (is_file($oldest) && !@unlink($oldest)) {
            throw new RuntimeException('Unable to remove the oldest query log.');
        }

        for ($index = $this->maxFiles - 1; $index >= 1; --$index) {
            $source = $this->logPath . '.' . $index;
            if (is_file($source) && !@rename($source, $this->logPath . '.' . ($index + 1))) {
                throw new RuntimeException('Unable to rotate query log files.');
            }
        }

        if (is_file($this->logPath) && !@rename($this->logPath, $this->logPath . '.1')) {
            throw new RuntimeException('Unable to rotate the active query log.');
        }
    }

    /** @return array<int|string, mixed> */
    private function normalizeCollection(array $values, int $depth = 0): array
    {
        if ($depth >= 8) {
            return ['[MAX_DEPTH]'];
        }

        $normalized = [];
        $count = 0;
        foreach ($values as $key => $value) {
            if (++$count > $this->maxCollectionItems) {
                $normalized['__truncated__'] = count($values) - $this->maxCollectionItems;
                break;
            }

            $normalized[$key] = is_string($key) && $this->isSensitiveKey($key)
                ? '[REDACTED]'
                : $this->normalizeValue($value, $depth + 1);
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     * @param int $depth
     * @return mixed
     */
    private function normalizeValue(mixed $value, int $depth): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            is_string($value) => $this->truncate($value),
            is_array($value) => $this->normalizeCollection($value, $depth),
            $value instanceof DateTimeInterface => $value->format(DATE_RFC3339_EXTENDED),
            $value instanceof DatabaseValueInterface => $this->normalizeValue($value->value(), $depth + 1),
            $value instanceof Stringable => $this->truncate((string)$value),
            is_resource($value) => '[RESOURCE:' . get_resource_type($value) . ']',
            is_object($value) => '[OBJECT:' . $value::class . ']',
            default => '[' . strtoupper(get_debug_type($value)) . ']',
        };
    }

    /**
     * @param string $key
     * @return bool
     */
    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(ltrim($key, ':'));
        foreach ($this->sensitiveKeys as $sensitiveKey) {
            if ($normalized === $sensitiveKey || str_contains($normalized, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $value
     * @return string
     */
    private function truncate(string $value): string
    {
        if (strlen($value) <= $this->maxValueLength) {
            return $value;
        }

        return substr($value, 0, $this->maxValueLength) . sprintf(
                '[TRUNCATED:%d_BYTES]',
                strlen($value) - $this->maxValueLength,
            );
    }
}
