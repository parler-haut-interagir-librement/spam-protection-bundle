<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Unit\Fixture;

use Override;
use Phil\SpamProtectionBundle\Application\UsedTokens;

/**
 * Used nonces in memory, with the time-to-live each was claimed for.
 */
final class MemoryUsedTokens implements UsedTokens
{
    /** @var array<string, int> */
    public array $claimed = [];

    #[Override]
    public function claim(string $nonce, int $ttl): bool
    {
        if (\array_key_exists($nonce, $this->claimed)) {
            return false;
        }

        $this->claimed[$nonce] = $ttl;

        return true;
    }
}
