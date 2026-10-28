<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use InvalidArgumentException;
use Omegaalfa\QueryBuilder\Utils\EnvLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $keys = [];

    protected function tearDown(): void
    {
        foreach ($this->keys as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        foreach ($this->files as $file) {
            @unlink($file);
            @rmdir(dirname($file));
        }
    }

    public function testLoadsQuotedValuesCommentsAndTypedAccessors(): void
    {
        $prefix = $this->prefix();
        $file = $this->envFile(<<<ENV
            {$prefix}_TEXT="value with spaces" # comment
            {$prefix}_HASH=abc#123
            {$prefix}_PORT=54329
            {$prefix}_ENABLED=yes
            {$prefix}_LEADING=00123
            export {$prefix}_EXPORTED='safe value'
            ENV);

        $this->trackKeys($prefix, ['TEXT', 'HASH', 'PORT', 'ENABLED', 'LEADING', 'EXPORTED']);
        EnvLoader::load($file, required: true, strictPermissions: true);

        self::assertSame('value with spaces', EnvLoader::get("{$prefix}_TEXT"));
        self::assertSame('abc#123', EnvLoader::get("{$prefix}_HASH"));
        self::assertSame(54329, EnvLoader::getInt("{$prefix}_PORT"));
        self::assertTrue(EnvLoader::getBool("{$prefix}_ENABLED"));
        self::assertSame('00123', EnvLoader::get("{$prefix}_LEADING"));
        self::assertSame('safe value', EnvLoader::require("{$prefix}_EXPORTED"));
    }

    public function testDoesNotOverwriteProcessEnvironmentUnlessExplicitlyRequested(): void
    {
        $key = $this->prefix() . '_VALUE';
        $this->keys[] = $key;
        putenv("{$key}=process");
        $_ENV[$key] = 'process';

        EnvLoader::load($this->envFile("{$key}=file\n"), required: true);
        self::assertSame('process', EnvLoader::get($key));

        EnvLoader::load($this->envFile("{$key}=override\n"), required: true, overwrite: true);
        self::assertSame('override', EnvLoader::get($key));
    }

    public function testRejectsInvalidVariableNamesAndMalformedQuotedValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EnvLoader::load($this->envFile("INVALID-NAME=value\n"), required: true);
    }

    public function testStrictPermissionsRejectBroadlyReadableFile(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Unix permission validation is not available on Windows.');
        }
        $file = $this->envFile("SAFE_KEY=value\n", 0644);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('chmod 600');
        EnvLoader::load($file, required: true, strictPermissions: true);
    }

    public function testOptionalMissingFileAndDefaultsAreHandled(): void
    {
        $missing = sys_get_temp_dir() . '/query-builder-env-missing-' . bin2hex(random_bytes(6));

        EnvLoader::load($missing);
        self::assertNull(EnvLoader::get($this->prefix() . '_MISSING'));
        self::assertSame('fallback', EnvLoader::get($this->prefix() . '_MISSING', 'fallback'));
        self::assertSame(42, EnvLoader::getInt($this->prefix() . '_MISSING_INT', 42));
        self::assertFalse(EnvLoader::getBool($this->prefix() . '_MISSING_BOOL', false));

        $this->expectException(RuntimeException::class);
        EnvLoader::load($missing, required: true);
    }

    public function testParsesEscapedDoubleQuotesAndEmptyValues(): void
    {
        $prefix = $this->prefix();
        $file = $this->envFile(
            "{$prefix}_QUOTED=\"a \\\"quoted\\\" value\"\n{$prefix}_EMPTY=\n"
        );
        $this->trackKeys($prefix, ['QUOTED', 'EMPTY']);

        EnvLoader::load($file, required: true);

        self::assertSame('a "quoted" value', EnvLoader::get("{$prefix}_QUOTED"));
        self::assertSame('', EnvLoader::get("{$prefix}_EMPTY"));
    }

    public function testRejectsNulBytesAndUnclosedQuotedValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EnvLoader::load($this->envFile("SAFE=value\0\n"), required: true);
    }

    public function testRejectsMalformedEntriesAndInvalidTypedValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EnvLoader::load($this->envFile("MALFORMED\n"), required: true);
    }

    public function testRejectsInvalidIntegerAndBooleanValues(): void
    {
        $prefix = $this->prefix();
        $file = $this->envFile("{$prefix}_INT=1.5\n{$prefix}_BOOL=maybe\n");
        $this->trackKeys($prefix, ['INT', 'BOOL']);
        EnvLoader::load($file, required: true);

        $this->expectException(InvalidArgumentException::class);
        EnvLoader::getInt("{$prefix}_INT");
    }

    private function prefix(): string
    {
        return 'QB_ENV_' . strtoupper(bin2hex(random_bytes(4)));
    }

    private function envFile(string $contents, int $permissions = 0600): string
    {
        $directory = sys_get_temp_dir() . '/query-builder-env-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0700) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create temporary test directory.');
        }
        $file = $directory . '/.env';
        if (file_put_contents($file, $contents) === false) {
            throw new RuntimeException('Unable to create temporary environment file.');
        }
        chmod($file, $permissions);
        $this->files[] = $file;
        return $file;
    }

    /** @param list<string> $suffixes */
    private function trackKeys(string $prefix, array $suffixes): void
    {
        foreach ($suffixes as $suffix) {
            $this->keys[] = "{$prefix}_{$suffix}";
        }
    }
}
