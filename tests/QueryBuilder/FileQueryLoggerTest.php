<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Logger\FileQueryLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileQueryLoggerTest extends TestCase
{
    private string $directory;
    private string $logPath;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/query-builder-logger-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $this->logPath = $this->directory . '/queries.jsonl';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_file($file) || is_link($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testWritesStructuredQueryWithoutParametersByDefault(): void
    {
        $logger = new FileQueryLogger($this->logPath);
        $logger->logQuery('SELECT * FROM users WHERE password = :password', [':password' => 'secret'], 0.01234, 2);

        $entry = $this->firstEntry();

        self::assertSame('query', $entry['level']);
        self::assertSame(12.34, $entry['duration_ms']);
        self::assertSame(2, $entry['affected_rows']);
        self::assertArrayNotHasKey('params', $entry);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $entry['timestamp']);
        self::assertSame(0600, fileperms($this->logPath) & 0777);
    }

    public function testRedactsSensitiveParametersRecursively(): void
    {
        $logger = new FileQueryLogger($this->logPath, logParameters: true);
        $logger->logQuery('SELECT 1', [
            ':password' => 'visible-no-more',
            'profile' => ['api_token' => 'token-value', 'name' => 'Maria'],
        ], 0.001, 1);

        $params = $this->firstEntry()['params'];

        self::assertSame('[REDACTED]', $params[':password']);
        self::assertSame('[REDACTED]', $params['profile']['api_token']);
        self::assertSame('Maria', $params['profile']['name']);
        self::assertStringNotContainsString('visible-no-more', (string) file_get_contents($this->logPath));
    }

    public function testInvalidUtf8DoesNotBreakLogging(): void
    {
        $logger = new FileQueryLogger($this->logPath, logParameters: true);
        $logger->logQuery('SELECT :value', [':value' => "invalid\xB1"], 0.001, 1);

        self::assertSame(JSON_ERROR_NONE, json_last_error());
        self::assertIsArray($this->firstEntry());
    }

    public function testErrorTraceIsDisabledByDefault(): void
    {
        $logger = new FileQueryLogger($this->logPath);
        $logger->logError('SELECT broken', [], new RuntimeException('failure'));

        $entry = $this->firstEntry();

        self::assertSame('error', $entry['level']);
        self::assertSame(RuntimeException::class, $entry['error']['type']);
        self::assertArrayNotHasKey('trace', $entry['error']);
    }

    public function testRotatesLogAtConfiguredSize(): void
    {
        $logger = new FileQueryLogger($this->logPath, maxFileSize: 180, maxFiles: 2);
        $logger->logQuery('SELECT ' . str_repeat('a', 100), [], 0.001, 1);
        $logger->logQuery('SELECT ' . str_repeat('b', 100), [], 0.001, 1);

        self::assertFileExists($this->logPath);
        self::assertFileExists($this->logPath . '.1');
    }

    public function testDisabledLoggerDoesNotTouchFilesystem(): void
    {
        $logger = new FileQueryLogger($this->directory . '/missing/queries.jsonl', enabled: false);
        $logger->logQuery('SELECT 1', [], 0.001, 1);

        self::assertFileDoesNotExist($this->directory . '/missing/queries.jsonl');
    }

    public function testStrictFailureModeReportsWriteFailure(): void
    {
        $logger = new FileQueryLogger($this->logPath, throwOnFailure: true);
        rmdir($this->directory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to write query log.');
        $logger->logQuery('SELECT 1', [], 0.001, 1);
    }

    /** @return array<string, mixed> */
    private function firstEntry(): array
    {
        $line = strtok((string) file_get_contents($this->logPath), PHP_EOL);
        $entry = json_decode((string) $line, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($entry);

        return $entry;
    }
}
