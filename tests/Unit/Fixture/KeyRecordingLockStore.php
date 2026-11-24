<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Unit\Fixture;

use Override;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * An in-memory lock store that records the resource of every key it is given.
 */
final class KeyRecordingLockStore implements PersistingStoreInterface
{
    /** @var list<string> */
    public array $keys = [];

    private readonly InMemoryStore $store;

    public function __construct()
    {
        $this->store = new InMemoryStore();
    }

    #[Override]
    public function save(Key $key): void
    {
        $this->keys[] = (string) $key;
        $this->store->save($key);
    }

    #[Override]
    public function delete(Key $key): void
    {
        $this->keys[] = (string) $key;
        $this->store->delete($key);
    }

    #[Override]
    public function exists(Key $key): bool
    {
        $this->keys[] = (string) $key;

        return $this->store->exists($key);
    }

    #[Override]
    public function putOffExpiration(Key $key, float $ttl): void
    {
        $this->keys[] = (string) $key;
        $this->store->putOffExpiration($key, $ttl);
    }
}
