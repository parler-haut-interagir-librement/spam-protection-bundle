<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Application;

/**
 * What a submitted form brings back for inspection: its scope, the honeypot's value, the token, the proof's
 * solution, the text a person wrote, and the client key the rate limit counts by — `null` when there is no request
 * to derive it from.
 */
final readonly class Submission
{
    /**
     * @param array<string> $texts
     */
    public function __construct(
        public string $scope,
        public ?string $honeypot,
        public ?string $token,
        public ?string $solution,
        public array $texts = [],
        public ?string $client = null,
    ) {
    }
}
