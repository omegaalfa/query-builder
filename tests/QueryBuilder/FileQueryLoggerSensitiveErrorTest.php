<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Exceptions\QueryException;
use Omegaalfa\QueryBuilder\Logger\FileQueryLogger;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileQueryLoggerSensitiveErrorTest extends TestCase
{
    private string $directory;
    private string $path;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/safe-logger-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->path = $this->directory . '/queries.jsonl';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public static function drivers(): array
    {
        return [['sqlite'], ['mysql'], ['pgsql']];
    }

    #[DataProvider('drivers')]
    public function testUniqueViolationDoesNotLogSensitiveValue(string $driver): void
    {
        if (!extension_loaded('pdo_' . $driver)) {
            self::markTestSkipped('PDO driver is unavailable.');
        }
        $mysql = $driver === 'mysql';
        if ($driver !== 'sqlite' && !getenv($mysql ? 'DOCKER_MYSQL_HOST' : 'PGVECTOR_HOST')) {
            self::markTestSkipped('Compose database is not configured.');
        }
        $env = $mysql ? 'DOCKER_MYSQL_' : 'PGVECTOR_';
        $connection = new PDOConnection(new DatabaseSettings(
            $driver,
            getenv($env . 'HOST') ?: '',
            $driver === 'sqlite' ? ':memory:' : (string) getenv($env . 'DATABASE'),
            (int) getenv($env . 'PORT'),
            getenv($env . 'USERNAME') ?: '',
            getenv($env . 'PASSWORD') ?: '',
        ));
        try {
            $connection->pdo()->exec('CREATE TEMPORARY TABLE logger_secrets (secret VARCHAR(100) UNIQUE)');
            $marker = 'SENSITIVE_MARKER_913';
            $builder = new QueryBuilder($connection, logger: new FileQueryLogger($this->path, logParameters: false));
            $builder->insert('logger_secrets', ['secret' => $marker])->execute();
            try {
                $builder->insert('logger_secrets', ['secret' => $marker])->execute();
                self::fail('Expected a UNIQUE violation.');
            } catch (QueryException $error) {
                self::assertInstanceOf(PDOException::class, $error->getPrevious());
                if ($driver !== 'sqlite') {
                    self::assertStringContainsString($marker, $error->getMessage());
                }
            }
            $text = (string) file_get_contents($this->path);
            self::assertStringNotContainsString($marker, $text);
            $entries = array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->path, FILE_IGNORE_NEW_LINES));
            self::assertSame('query', $entries[0]['level']);
            self::assertSame(1, $entries[0]['affected_rows']);
            self::assertArrayNotHasKey('params', $entries[0]);
            self::assertSame('error', $entries[1]['level']);
            self::assertSame(PDOException::class, $entries[1]['error']['type']);
            self::assertSame($driver === 'pgsql' ? '23505' : '23000', $entries[1]['error']['sqlstate']);
            self::assertStringContainsString('INSERT INTO', $entries[1]['sql']);
            self::assertArrayNotHasKey('params', $entries[1]);
        } finally {
            $connection->disconnect();
        }
    }

    public function testMessageWithoutSqlStateIsOmittedWithoutFallback(): void
    {
        (new FileQueryLogger($this->path))->logError('SELECT :value', [':value' => 'SENSITIVE'], new RuntimeException('password=SENSITIVE', 7));
        $text = (string) file_get_contents($this->path);
        self::assertStringNotContainsString('SENSITIVE', $text);
        $entry = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(7, $entry['error']['code']);
        self::assertNull($entry['error']['sqlstate']);
        self::assertSame(RuntimeException::class, $entry['error']['type']);
    }

    public function testWrappedPdoErrorUsesStructuredStateOnly(): void
    {
        $cause = new PDOException('SENSITIVE');
        $cause->errorInfo = ['23505', 7, 'SENSITIVE'];
        $error = new RuntimeException('SENSITIVE wrapper', 0, $cause);
        (new FileQueryLogger($this->path))->logError('INSERT INTO items VALUES (:value)', [], $error);
        $text = (string) file_get_contents($this->path);
        self::assertStringNotContainsString('SENSITIVE', $text);
        self::assertSame('23505', json_decode($text, true, flags: JSON_THROW_ON_ERROR)['error']['sqlstate']);
    }

    public function testWriteFailureRemainsSilentByDefault(): void
    {
        $logger = new FileQueryLogger($this->path);
        rmdir($this->directory);
        $logger->logError('SELECT :value', [], new RuntimeException('SENSITIVE'));
        self::assertFileDoesNotExist($this->path);
    }

    public function testStrictWriteFailureKeepsGenericMessage(): void
    {
        $logger = new FileQueryLogger($this->path, throwOnFailure: true);
        rmdir($this->directory);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to write query log.');
        $logger->logError('SELECT :value', [], new RuntimeException('SENSITIVE'));
    }
}