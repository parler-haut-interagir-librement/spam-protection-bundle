<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Application;

/**
 * How often one client may submit one form. The client is a key the infrastructure derives from the request — a
 * keyed hash of its address, never the address itself.
 */
interface SubmissionRateLimiter
{
    /**
     * Counts one submission of the form by the client; `false` when it goes over the limit.
     */
    public function consume(string $scope, string $client): bool;
}
