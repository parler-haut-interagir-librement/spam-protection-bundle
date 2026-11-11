<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Unit\Fixture;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * The records logged, as level, message and context.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{mixed, string, array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }
}
