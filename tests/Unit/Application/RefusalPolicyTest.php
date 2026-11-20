<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Unit\Application;

use InvalidArgumentException;
use Phil\SpamProtection\Inspection\Inspection;
use Phil\SpamProtection\Inspection\Signal;
use Phil\SpamProtectionBundle\Application\RefusalPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(RefusalPolicy::class)]
final class RefusalPolicyTest extends TestCase
{
    public function testByDefaultOneSignalRefusesAlone(): void
    {
        $policy = new RefusalPolicy();

        self::assertSame(100, $policy->threshold);
        self::assertSame(0, $policy->score(Inspection::clean()));
        self::assertFalse($policy->refuses(Inspection::clean()));

        foreach (Signal::cases() as $signal) {
            self::assertSame(100, $policy->weightOf($signal), $signal->value);
            self::assertTrue($policy->refuses(Inspection::clean()->with($signal)), $signal->value);
        }
    }

    public function testTheWeightsAddUpToTheThreshold(): void
    {
        $policy = new RefusalPolicy(10, ['too_fast' => 4, 'too_many_links' => 5, 'gibberish' => 0]);

        self::assertSame(4, $policy->weightOf(Signal::TooFast));
        self::assertSame(10, $policy->weightOf(Signal::HoneypotFilled), 'A signal not listed weighs the threshold.');
        self::assertSame(9, $policy->score(Inspection::clean()->with(Signal::TooFast, Signal::TooManyLinks, Signal::Gibberish)));
        self::assertFalse($policy->refuses(Inspection::clean()->with(Signal::TooFast, Signal::TooManyLinks, Signal::Gibberish)));
        self::assertTrue($policy->refuses(Inspection::clean()->with(Signal::TooFast, Signal::TooManyLinks, Signal::RateLimited)));
        self::assertSame(4, $policy->score(Inspection::clean()->with(Signal::TooFast, Signal::TooFast)->with(Signal::TooFast, Signal::TooFast)), 'A signal is counted once, however often it is raised.');
    }

    public function testAScoreJustAtTheThresholdRefusesAndJustBelowDoesNot(): void
    {
        $policy = new RefusalPolicy(9, ['too_fast' => 4, 'too_many_links' => 5, 'gibberish' => 4]);

        self::assertTrue($policy->refuses(Inspection::clean()->with(Signal::TooFast, Signal::TooManyLinks)));
        self::assertFalse($policy->refuses(Inspection::clean()->with(Signal::TooFast, Signal::Gibberish)));
    }

    #[TestWith([Signal::TokenMissing])]
    #[TestWith([Signal::TokenForged])]
    #[TestWith([Signal::TokenReplayed])]
    public function testTheCertainSignalsAlwaysWeighTheThreshold(Signal $signal): void
    {
        self::assertContains($signal, RefusalPolicy::ALWAYS_REFUSED);
        self::assertSame(1_000, new RefusalPolicy(1_000)->weightOf($signal));
        self::assertTrue(new RefusalPolicy(1_000, ['too_fast' => 0])->refuses(Inspection::clean()->with($signal)));
        self::assertCount(3, RefusalPolicy::ALWAYS_REFUSED);
    }

    public function testACertainSignalTakesNoWeight(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The signal "token_replayed" always refuses: its weight is not configured.');

        new RefusalPolicy(100, ['token_replayed' => 0]);
    }

    public function testAnUnknownSignalIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"too_slow" is not a signal: honeypot_filled, token_missing,');

        new RefusalPolicy(100, ['too_slow' => 10]);
    }

    #[TestWith([0, 'The threshold of a refusal is from 1 to 1000; 0 given.'])]
    #[TestWith([1_001, 'The threshold of a refusal is from 1 to 1000; 1001 given.'])]
    public function testTheThresholdIsBounded(int $threshold, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);

        new RefusalPolicy($threshold);
    }

    #[TestWith([-1])]
    #[TestWith([1_001])]
    public function testAWeightIsBounded(int $weight): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('The weight of the signal "too_fast" is from 0 to 1000; %d given.', $weight));

        new RefusalPolicy(100, ['too_fast' => $weight]);
    }

    public function testTheBoundsThemselvesAreAccepted(): void
    {
        self::assertSame(1, new RefusalPolicy(1, ['too_fast' => 0])->threshold);
        self::assertSame(1_000, new RefusalPolicy(1_000, ['too_fast' => 1_000])->weightOf(Signal::TooFast));
    }
}
