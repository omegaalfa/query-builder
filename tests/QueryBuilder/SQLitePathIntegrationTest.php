<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Exceptions\DatabaseException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SQLitePathIntegrationTest extends TestCase
{
    public function testAbsolutePathOpensSQLiteFile(): void
    {
        $this->withTemporaryDirectory(function (string $directory): void {
            $path = $directory . '/absolute.sqlite';
            $connection = $this->connect($path);

            try {
                self::assertFileExists($path);
                self::assertSame('sqlite:' . $path, $this->settings($path)->toDsn());
            } finally {
                $connection->disconnect();
            }
        });
    }

    public function testRelativePathIsResolvedFromTheCurrentWorkingDirectory(): void
    {
        $this->withTemporaryDirectory(function (string $directory): void {
            $originalDirectory = getcwd();
            self::assertNotFalse($originalDirectory);
            self::assertTrue(chdir($directory));

            $connection = null;
            try {
                $connection = $this->connect('relative.sqlite');
                self::assertFileExists($directory . '/relative.sqlite');
            } finally {
                $connection?->disconnect();
                self::assertTrue(chdir($originalDirectory));
            }
        });
    }

    public function testMemoryDatabaseDoesNotCreateAnOnDiskFile(): void
    {
        $connection = $this->connect(':memory:');

        try {
            $database = $connection->pdo()->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC);
            self::assertSame('', $database['file']);
            self::assertSame('sqlite::memory:', $this->settings(':memory:')->toDsn());
        } finally {
            $connection->disconnect();
        }
    }

    public function testMissingDatabaseFileIsCreatedWhenParentDirectoryExists(): void
    {
        $this->withTemporaryDirectory(function (string $directory): void {
            $path = $directory . '/created.sqlite';
            self::assertFileDoesNotExist($path);

            $connection = $this->connect($path);
            try {
                self::assertFileExists($path);
            } finally {
                $connection->disconnect();
            }
        });
    }

    public function testConnectionFailsWhenParentDirectoryDoesNotExist(): void
    {
        $this->withTemporaryDirectory(function (string $directory): void {
            $path = $directory . '/missing/child.sqlite';

            try {
                $this->connect($path);
                self::fail('SQLite must not create missing parent directories.');
            } catch (DatabaseException $exception) {
                self::assertInstanceOf(\PDOException::class, $exception->getPrevious());
            }

            self::assertDirectoryDoesNotExist($directory . '/missing');
        });
    }

    public function testRecordsPersistAfterClosingAndReopeningConnection(): void
    {
        $this->withTemporaryDirectory(function (string $directory): void {
            $path = $directory . '/persistent.sqlite';

            $firstConnection = $this->connect($path);
            try {
                $firstPdo = $firstConnection->pdo();
                $firstPdo->exec('CREATE TABLE persistence_check (value TEXT NOT NULL)');
                $statement = $firstPdo->prepare('INSERT INTO persistence_check (value) VALUES (:value)');
                $statement->execute(['value' => 'survives reconnect']);
            } finally {
                $firstConnection->disconnect();
                unset($firstPdo, $statement);
            }

            $secondConnection = $this->connect($path);
            try {
                $value = $secondConnection->pdo()
                    ->query('SELECT value FROM persistence_check')
                    ->fetchColumn();
                self::assertSame('survives reconnect', $value);
            } finally {
                $secondConnection->disconnect();
            }
        });
    }

    public function testTemporaryDatabaseFilesAreRemovedSafely(): void
    {
        $directory = $this->createTemporaryDirectory();
        $path = $directory . '/temporary.sqlite';

        try {
            $connection = $this->connect($path);
            try {
                $connection->pdo()->exec('CREATE TABLE cleanup_check (id INTEGER)');
            } finally {
                $connection->disconnect();
            }
        } finally {
            $this->removeTemporaryDatabaseDirectory($directory);
        }

        self::assertDirectoryDoesNotExist($directory);
    }

    private function connect(string $database): PDOConnection
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not installed in this PHP runtime.');
        }

        $connection = new PDOConnection($this->settings($database));
        $connection->pdo();

        return $connection;
    }

    private function settings(string $database): DatabaseSettings
    {
        return new DatabaseSettings(
            driver: 'sqlite',
            host: '',
            database: $database,
            port: 0,
            username: '',
            password: '',
        );
    }

    /** @param callable(string): void $callback */
    private function withTemporaryDirectory(callable $callback): void
    {
        $directory = $this->createTemporaryDirectory();

        try {
            $callback($directory);
        } finally {
            $this->removeTemporaryDatabaseDirectory($directory);
        }

        self::assertDirectoryDoesNotExist($directory);
    }

    private function createTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'query-builder-sqlite-' . bin2hex(random_bytes(8));

        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create a temporary SQLite test directory.');
        }

        return $directory;
    }

    private function removeTemporaryDatabaseDirectory(string $directory): void
    {
        foreach (['absolute.sqlite', 'relative.sqlite', 'created.sqlite', 'persistent.sqlite', 'temporary.sqlite'] as $filename) {
            $this->removeIfPresent($directory . DIRECTORY_SEPARATOR . $filename);
        }

        foreach (['-journal', '-wal', '-shm'] as $suffix) {
            foreach (['absolute.sqlite', 'relative.sqlite', 'created.sqlite', 'persistent.sqlite', 'temporary.sqlite'] as $filename) {
                $this->removeIfPresent($directory . DIRECTORY_SEPARATOR . $filename . $suffix);
            }
        }

        if (is_dir($directory) && !rmdir($directory)) {
            throw new RuntimeException('Unable to remove the temporary SQLite test directory.');
        }
    }

    private function removeIfPresent(string $path): void
    {
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Unable to remove a temporary SQLite test file.');
        }
    }
}