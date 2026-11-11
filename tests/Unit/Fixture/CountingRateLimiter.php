<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Unit\Fixture;

use Override;
use Phil\SpamProtectionBundle\Application\SubmissionRateLimiter;

/**
 * Accepts `limit` submissions per form and client, and records them.
 */
final class CountingRateLimiter implements SubmissionRateLimiter
{
    /** @var array<string, int> */
    public array $counts = [];

    public function __construct(
        private readonly int $limit,
    ) {
    }

    #[Override]
    public function consume(string $scope, string $client): bool
    {
        $key = $scope.'|'.$client;
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

        return $this->counts[$key] <= $this->limit;
    }
}
