<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\Internal;

use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
use WeakMap;

/** @internal Tracks cache dependencies for one managed connection transaction. */
final class TransactionalCacheDependencyContext
{
    /** @var array<int, WeakMap<CacheInterface, array<string, true>>> */
    private array $levels = [];

    /** @var WeakMap<CacheInterface, array<string, true>> */
    private WeakMap $committed;

    public function __construct()
    {
        $this->committed = new WeakMap();
    }

    public function beginLevel(int $level): void
    {
        $this->levels[$level] ??= new WeakMap();
    }

    /** @param list<string> $tags */
    public function record(int $level, CacheInterface $cache, array $tags): void
    {
        $this->beginLevel($level);
        $this->levels[$level][$cache] = $this->mergeTags(
            $this->levels[$level][$cache] ?? [],
            $tags,
        );
    }

    public function releaseLevel(int $level, int $parentLevel): void
    {
        $child = $this->levels[$level] ?? null;
        if ($child !== null) {
            $this->beginLevel($parentLevel);
            foreach ($child as $cache => $tags) {
                $this->levels[$parentLevel][$cache] = $this->mergeTags(
                    $this->levels[$parentLevel][$cache] ?? [],
                    array_keys($tags),
                );
            }
        }
        unset($this->levels[$level]);
    }

    public function rollbackLevel(int $level, bool $outermost): void
    {
        if ($outermost) {
            $this->levels = [];
            return;
        }
        unset($this->levels[$level]);
    }

    public function commitOutermost(): void
    {
        foreach ($this->levels as $level) {
            foreach ($level as $cache => $tags) {
                $this->committed[$cache] = $this->mergeTags(
                    $this->committed[$cache] ?? [],
                    array_keys($tags),
                );
            }
        }
        $this->levels = [];
    }

    /** @return array<int, list<string>> */
    public function activeDependencies(CacheInterface $cache): array
    {
        $dependencies = [];
        foreach ($this->levels as $level => $caches) {
            if (isset($caches[$cache])) {
                $dependencies[$level] = array_keys($caches[$cache]);
            }
        }
        return $dependencies;
    }

    /** @return list<string> */
    public function committedDependencies(CacheInterface $cache): array
    {
        return isset($this->committed[$cache])
            ? array_keys($this->committed[$cache])
            : [];
    }

    /** @return list<array{cache: CacheInterface, tags: list<string>}> */
    public function committedDependencyEntries(): array
    {
        $entries = [];
        foreach ($this->committed as $cache => $tags) {
            $entries[] = ['cache' => $cache, 'tags' => array_keys($tags)];
        }
        return $entries;
    }

    public function clearCommittedDependencies(): void
    {
        $this->committed = new WeakMap();
    }

    public function isEmpty(): bool
    {
        foreach ($this->levels as $level => $caches) {
            if (count($caches) === 0) {
                unset($this->levels[$level]);
            }
        }
        return $this->levels === [] && count($this->committed) === 0;
    }

    /** @param array<string, true> $existing
     *  @param list<string> $tags
     *  @return array<string, true>
     */
    private function mergeTags(array $existing, array $tags): array
    {
        foreach ($tags as $tag) {
            if ($tag !== '') {
                $existing[$tag] = true;
            }
        }
        return $existing;
    }
}
