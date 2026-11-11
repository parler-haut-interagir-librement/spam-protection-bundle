<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Application;

/**
 * What a rendered form carries: the signed token, and the challenge and difficulty of the proof of work the
 * browser solves before it submits — a difficulty of 0 when the proof is off.
 */
final readonly class Protection
{
    public function __construct(
        public string $token,
        public string $challenge,
        public int $difficulty,
    ) {
    }
}
