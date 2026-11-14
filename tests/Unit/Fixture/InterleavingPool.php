<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Unit\Fixture;

use Closure;
use Override;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A pool that runs another request once, right after the first read: a concurrent submission that reads, writes and
 * reads back while this one is between its read and its write.
 */
final class InterleavingPool implements CacheItemPoolInterface
{
    public readonly ArrayAdapter $pool;

    private ?Closure $between;

    /**
     * @param Closure(self): mixed $between
     */
    public function __construct(Closure $between)
    {
        $this->pool = new ArrayAdapter();
        $this->between = $between;
    }

    #[Override]
    public function getItem(string $key): CacheItemInterface
    {
        $item = $this->pool->getItem($key);
        $between = $this->between;
        $this->between = null;

        if ($between instanceof Closure) {
            $between($this);
        }

        return $item;
    }

    /**
     * @return iterable<string, CacheItemInterface>
     */
    #[Override]
    public function getItems(array $keys = []): iterable
    {
        return $this->pool->getItems($keys);
    }

    #[Override]
    public function hasItem(string $key): bool
    {
        return $this->pool->hasItem($key);
    }

    #[Override]
    public function clear(): bool
    {
        return $this->pool->clear();
    }

    #[Override]
    public function deleteItem(string $key): bool
    {
        return $this->pool->deleteItem($key);
    }

    #[Override]
    public function deleteItems(array $keys): bool
    {
        return $this->pool->deleteItems($keys);
    }

    #[Override]
    public function save(CacheItemInterface $item): bool
    {
        return $this->pool->save($item);
    }

    #[Override]
    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->pool->saveDeferred($item);
    }

    #[Override]
    public function commit(): bool
    {
        return $this->pool->commit();
    }
}
