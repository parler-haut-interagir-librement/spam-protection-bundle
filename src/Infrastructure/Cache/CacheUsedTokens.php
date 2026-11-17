<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Infrastructure\Cache;

use Override;
use Phil\SpamProtectionBundle\Application\UsedTokens;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The used nonces in a PSR-6 cache pool, each kept as long as its token could still be on time. A pool that several
 * web servers share — Redis, a database — makes a token single-use across them; a local pool only on one server.
 *
 * **A PSR-6 pool has no atomic "add if absent".** Given a lock factory of the Symfony Lock component, a claim takes
 * the lock of its nonce, then reads and writes under it: two submissions of one token never both pass, as long as
 * the lock store is shared by every web server too.
 *
 * Without a lock, a claim is a read, a write and a read back. The claim writes a marker of its own and is granted
 * only if the marker it reads back is still its own: of two submissions of one token that write one after the other,
 * the one overwritten is refused. Two that each read back before the other writes can still both pass; the window is
 * the time between one write and one read on the pool. `DbalUsedTokens`, or this store with a lock, closes it.
 *
 * A claim the pool does not keep is refused: its `save()` answered `false`, or, without a lock, the marker cannot be
 * read back. Accepting it would let the same token through again; a pool that keeps nothing refuses every token.
 */
final readonly class CacheUsedTokens implements UsedTokens
{
    /** The longest a claim holds the lock of its nonce, in seconds. */
    private const float LOCK_TTL = 10.0;

    public function __construct(
        private CacheItemPoolInterface $pool,
        private ?LockFactory $locks = null,
    ) {
    }

    #[Override]
    public function claim(string $nonce, int $ttl): bool
    {
        $key = 'phil_spam_protection.'.$nonce;

        if (!$this->locks instanceof LockFactory) {
            return $this->claimWithoutLock($key, $ttl);
        }

        $lock = $this->locks->createLock($key, self::LOCK_TTL);
        $lock->acquire(true);

        try {
            $item = $this->pool->getItem($key);

            if ($item->isHit()) {
                return false;
            }

            return $this->pool->save($item->set($this->marker())->expiresAfter($ttl));
        } finally {
            $lock->release();
        }
    }

    private function claimWithoutLock(string $key, int $ttl): bool
    {
        $item = $this->pool->getItem($key);

        if ($item->isHit()) {
            return false;
        }

        $marker = $this->marker();

        if (!$this->pool->save($item->set($marker)->expiresAfter($ttl))) {
            return false;
        }

        $stored = $this->pool->getItem($key);

        return $stored->isHit() && $marker === $stored->get();
    }

    private function marker(): string
    {
        return sodium_bin2hex(random_bytes(8));
    }
}
