<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Application;

/**
 * The nonces of the tokens already submitted, so that each token is used once. The bundle implements it over a
 * PSR-6 cache pool; an application with several web servers gives it a pool they share.
 */
interface UsedTokens
{
    /**
     * Records the nonce as used for `ttl` seconds; `false` when it was already recorded.
     */
    public function claim(string $nonce, int $ttl): bool;
}
