<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Unit\Application;

use Phil\BotDetectionBundle\Domain\Verdict\Verdict;
use Phil\SpamProtection\Inspection\Inspection;
use Phil\SpamProtection\Inspection\Signal;
use Phil\SpamProtectionBundle\Application\Verdicts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Verdicts::class)]
final class VerdictsTest extends TestCase
{
    public function testSpamIsARobot(): void
    {
        self::assertSame(Verdict::Human, Verdicts::of(Inspection::clean()));
        self::assertSame(Verdict::Robot, Verdicts::of(Inspection::clean()->with(Signal::TooFast)));
    }
}
