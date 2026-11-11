<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Unit\Application;

use DateTimeImmutable;
use Phil\SpamProtection\Content\ContentRules;
use Phil\SpamProtection\Inspection\Signal;
use Phil\SpamProtection\Proof\ProofOfWork;
use Phil\SpamProtection\Timing\TimingPolicy;
use Phil\SpamProtection\Token\TokenCodec;
use Phil\SpamProtectionBundle\Application\Protection;
use Phil\SpamProtectionBundle\Application\Submission;
use Phil\SpamProtectionBundle\Application\SubmissionInspector;
use Phil\SpamProtectionBundle\Tests\Unit\Fixture\CountingRateLimiter;
use Phil\SpamProtectionBundle\Tests\Unit\Fixture\MemoryUsedTokens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(SubmissionInspector::class)]
#[CoversClass(Protection::class)]
#[CoversClass(Submission::class)]
final class SubmissionInspectorTest extends TestCase
{
    private const string SECRET = 'a secret of sixteen bytes or more';

    private MockClock $clock;

    private MemoryUsedTokens $used;

    protected function setUp(): void
    {
        $this->clock = new MockClock(new DateTimeImmutable('@1700000000'));
        $this->used = new MemoryUsedTokens();
    }

    public function testAProtectionCarriesASignedTokenAndTheProofsChallenge(): void
    {
        $protection = $this->inspector(proof: 4)->protect('contact');
        $token = new TokenCodec(self::SECRET)->decode($protection->token);

        self::assertNotNull($token);
        self::assertSame('contact', $token->scope);
        self::assertSame(1_700_000_000, $token->issuedAt);
        self::assertSame($token->nonce, $protection->challenge);
        self::assertSame(4, $protection->difficulty);
        self::assertNotSame($protection->challenge, $this->inspector()->protect('contact')->challenge);
    }

    public function testAConfiguredInspectorAppliesItsSettings(): void
    {
        $limiter = new CountingRateLimiter(0);
        $inspector = SubmissionInspector::configured(self::SECRET, $this->clock, $this->used, 0, 10, 2, 0, ['casino'], true, $limiter);
        $protection = $inspector->protect('contact');

        self::assertSame(2, $protection->difficulty);
        self::assertNotNull(new TokenCodec(self::SECRET)->decode($protection->token));
        self::assertSame(
            [Signal::ProofInvalid, Signal::TooManyLinks, Signal::ForbiddenTerm, Signal::Gibberish, Signal::RateLimited],
            $inspector->inspect(new Submission('contact', null, $protection->token, 'x', ['casino www.a.example qwrtzpsdfghjk'], 'c'))->signals,
        );

        $this->clock->sleep(11);

        self::assertSame([Signal::TokenExpired], SubmissionInspector::configured(self::SECRET, $this->clock, $this->used, 0, 10, 0, null, [], false)->inspect(new Submission('contact', null, $protection->token, null))->signals);
        self::assertSame(24, SubmissionInspector::MAXIMUM_PROOF_DIFFICULTY);
    }

    public function testAPersonsSubmissionIsClean(): void
    {
        $inspector = $this->inspector();
        $protection = $inspector->protect('contact');
        $this->clock->sleep(10);

        $inspection = $inspector->inspect(new Submission('contact', '', $protection->token, null, ['Hello, I would like a quote.'], 'client'));

        self::assertFalse($inspection->isSpam());
        self::assertSame([$protection->challenge => 3600], $this->used->claimed);
    }

    public function testEverySignalIsRaisedAtOnce(): void
    {
        $inspector = $this->inspector(proof: 8, content: new ContentRules(0, ['casino']), limit: 0);
        $protection = $inspector->protect('contact');

        $inspection = $inspector->inspect(new Submission('contact', 'https://spam.example', $protection->token, '0', ['casino www.a.example', 'qwrtzpsdfghjkl'], 'client'));

        self::assertSame([
            Signal::HoneypotFilled,
            Signal::ProofInvalid,
            Signal::TooFast,
            Signal::TooManyLinks,
            Signal::ForbiddenTerm,
            Signal::Gibberish,
            Signal::RateLimited,
        ], $inspection->signals);
        self::assertSame([], $this->used->claimed);
    }

    public function testAMissingOrForgedTokenRaisesOneSignal(): void
    {
        $inspector = $this->inspector();
        $signup = $inspector->protect('signup');

        self::assertSame([Signal::TokenMissing], $inspector->inspect(new Submission('contact', null, null, null))->signals);
        self::assertSame([Signal::TokenMissing], $inspector->inspect(new Submission('contact', null, '', null))->signals);
        self::assertSame([Signal::TokenForged], $inspector->inspect(new Submission('contact', null, 'forged.token', null))->signals);
        self::assertSame([Signal::TokenForged], $inspector->inspect(new Submission('contact', null, $signup->token, null))->signals);
    }

    public function testAStaleTokenExpiresAndIsNotClaimed(): void
    {
        $inspector = $this->inspector();
        $protection = $inspector->protect('contact');
        $this->clock->sleep(3601);

        self::assertSame([Signal::TokenExpired], $inspector->inspect(new Submission('contact', null, $protection->token, null))->signals);
        self::assertSame([], $this->used->claimed);
    }

    public function testATokenIsUsedOnce(): void
    {
        $inspector = $this->inspector();
        $protection = $inspector->protect('contact');
        $this->clock->sleep(5);
        $submission = new Submission('contact', null, $protection->token, null);

        self::assertFalse($inspector->inspect($submission)->isSpam());
        self::assertSame([Signal::TokenReplayed], $inspector->inspect($submission)->signals);
    }

    public function testASolvedProofIsAccepted(): void
    {
        $inspector = $this->inspector(proof: 6);
        $protection = $inspector->protect('contact');
        $this->clock->sleep(5);

        $solution = 0;

        while (!new ProofOfWork(6)->verify($protection->challenge, (string) $solution)) {
            ++$solution;
        }

        self::assertFalse($inspector->inspect(new Submission('contact', null, $protection->token, (string) $solution))->isSpam());

        $unsolved = $inspector->protect('contact');
        $this->clock->sleep(5);

        self::assertSame([Signal::ProofInvalid], $inspector->inspect(new Submission('contact', null, $unsolved->token, null))->signals);
    }

    public function testAnUnsolvedProofAndAReplayAreBothRaised(): void
    {
        $inspector = $this->inspector(proof: 8);
        $protection = $inspector->protect('contact');
        $this->clock->sleep(5);
        $submission = new Submission('contact', null, $protection->token, 'no');

        self::assertSame([Signal::ProofInvalid], $inspector->inspect($submission)->signals);
        self::assertSame([Signal::ProofInvalid, Signal::TokenReplayed], $inspector->inspect($submission)->signals);
    }

    public function testTheRateLimitCountsOnlySubmissionsWithAClient(): void
    {
        $limiter = new CountingRateLimiter(1);
        $inspector = $this->inspector(limiter: $limiter);
        $this->clock->sleep(0);

        $inspector->inspect(new Submission('contact', null, null, null));
        self::assertSame([], $limiter->counts);

        self::assertFalse($inspector->inspect(new Submission('contact', null, null, null, [], 'a'))->has(Signal::RateLimited));
        self::assertTrue($inspector->inspect(new Submission('contact', null, null, null, [], 'a'))->has(Signal::RateLimited));
        self::assertSame(['contact|a' => 2], $limiter->counts);
    }

    private function inspector(int $proof = 0, ?ContentRules $content = null, ?int $limit = null, ?CountingRateLimiter $limiter = null): SubmissionInspector
    {
        return new SubmissionInspector(
            new TokenCodec(self::SECRET),
            $this->clock,
            $this->used,
            new TimingPolicy(3, 3600),
            new ProofOfWork($proof),
            $content ?? new ContentRules(),
            $limiter ?? (null === $limit ? null : new CountingRateLimiter($limit)),
        );
    }
}
