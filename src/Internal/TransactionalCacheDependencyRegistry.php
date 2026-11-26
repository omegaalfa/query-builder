<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\Internal;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
use WeakMap;

/** @internal Coordinates dependency contexts without extending ConnectionInterface. */
final class TransactionalCacheDependencyRegistry
{
    /** @var WeakMap<PDOConnection, TransactionalCacheDependencyContext>|null */
    private static ?WeakMap $contexts = null;

    public static function beginLevel(PDOConnection $connection, int $level): void
    {
        self::context($connection)->beginLevel($level);
    }

    /** @param list<string> $tags */
    public static function record(PDOConnection $connection, int $level, CacheInterface $cache, array $tags): void
    {
        self::context($connection)->record($level, $cache, $tags);
    }

    public static function releaseLevel(PDOConnection $connection, int $level, int $parentLevel): void
    {
        $context = self::existingContext($connection);
        $context?->releaseLevel($level, $parentLevel);
        self::removeIfEmpty($connection, $context);
    }

    public static function rollbackLevel(PDOConnection $connection, int $level, bool $outermost): void
    {
        $context = self::existingContext($connection);
        $context?->rollbackLevel($level, $outermost);
        self::removeIfEmpty($connection, $context);
    }

    public static function commitOutermost(PDOConnection $connection): void
    {
        $context = self::existingContext($connection);
        if ($context === null) {
            return;
        }

        $context->commitOutermost();
        $pending = $context->committedDependencyEntries();
        try {
            foreach ($pending as $entry) {
                try {
                    if (call_user_func([$entry['cache'], 'flushTags'], $entry['tags']) === false) {
                        error_log('[QueryBuilderCache] Post-commit invalidation failed: flushTags() returned false.');
                    }
                } catch (\Throwable $exception) {
                    error_log('[QueryBuilderCache] Post-commit invalidation failed: ' . $exception->getMessage());
                }
            }
        } finally {
            $context->clearCommittedDependencies();
            self::removeIfEmpty($connection, $context);
        }
    }

    /** @return array<int, list<string>> */
    public static function activeDependencies(PDOConnection $connection, CacheInterface $cache): array
    {
        return self::existingContext($connection)?->activeDependencies($cache) ?? [];
    }

    /** @return list<string> */
    public static function committedDependencies(PDOConnection $connection, CacheInterface $cache): array
    {
        return self::existingContext($connection)?->committedDependencies($cache) ?? [];
    }

    public static function reset(PDOConnection $connection): void
    {
        if (self::$contexts !== null && isset(self::$contexts[$connection])) {
            unset(self::$contexts[$connection]);
        }
    }

    private static function context(PDOConnection $connection): TransactionalCacheDependencyContext
    {
        self::$contexts ??= new WeakMap();
        return self::$contexts[$connection] ??= new TransactionalCacheDependencyContext();
    }

    private static function existingContext(PDOConnection $connection): ?TransactionalCacheDependencyContext
    {
        return self::$contexts !== null ? (self::$contexts[$connection] ?? null) : null;
    }

    private static function removeIfEmpty(PDOConnection $connection, ?TransactionalCacheDependencyContext $context): void
    {
        if ($context?->isEmpty() && self::$contexts !== null) {
            unset(self::$contexts[$connection]);
        }
    }
}
