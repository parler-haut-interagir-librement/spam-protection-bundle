<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Unit\Fixture;

use Override;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A pool where another submission of the same token writes its own marker right after each save, as a concurrent
 * request would between this request's write and its read back.
 */
final readonly class RacingPool implements CacheItemPoolInterface
{
    private ArrayAdapter $pool;

    public function __construct()
    {
        $this->pool = new ArrayAdapter();
    }

    #[Override]
    public function getItem(string $key): CacheItemInterface
    {
        return $this->pool->getItem($key);
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
        $this->pool->save($item);

        return $this->pool->save($this->pool->getItem($item->getKey())->set('the other request'));
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
