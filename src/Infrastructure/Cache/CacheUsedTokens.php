<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Infrastructure\Cache;

use Override;
use Phil\SpamProtectionBundle\Application\UsedTokens;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The used nonces in a PSR-6 cache pool, each kept as long as its token could still be on time. A pool that several
 * web servers share — Redis, a database — makes a token single-use across them; a local pool only on one server.
 *
 * **A PSR-6 pool has no atomic "add if absent"**, so a claim is a read, a write and a read back. The claim writes a
 * marker of its own and is granted only if the marker it reads back is still its own: of two submissions of one
 * token that write one after the other, the one overwritten is refused. Two that each read back before the other
 * writes can still both pass; the window is the time between one write and one read on the pool. A store that must
 * close it implements `UsedTokens` over an atomic primitive (an insert on a unique key, Redis `SET NX`).
 */
final readonly class CacheUsedTokens implements UsedTokens
{
    public function __construct(
        private CacheItemPoolInterface $pool,
    ) {
    }

    #[Override]
    public function claim(string $nonce, int $ttl): bool
    {
        $key = 'phil_spam_protection.'.$nonce;
        $item = $this->pool->getItem($key);

        if ($item->isHit()) {
            return false;
        }

        $marker = sodium_bin2hex(random_bytes(8));
        $this->pool->save($item->set($marker)->expiresAfter($ttl));

        $stored = $this->pool->getItem($key);

        return !$stored->isHit() || $marker === $stored->get();
    }
}
