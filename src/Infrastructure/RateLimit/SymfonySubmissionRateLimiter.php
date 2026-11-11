<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Infrastructure\RateLimit;

use Override;
use Phil\SpamProtectionBundle\Application\SubmissionRateLimiter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The rate limit of a Symfony rate limiter the application configures, counted per form and per client key.
 */
final readonly class SymfonySubmissionRateLimiter implements SubmissionRateLimiter
{
    public function __construct(
        private RateLimiterFactoryInterface $factory,
    ) {
    }

    #[Override]
    public function consume(string $scope, string $client): bool
    {
        return $this->factory->create($scope.'|'.$client)->consume()->isAccepted();
    }
}
