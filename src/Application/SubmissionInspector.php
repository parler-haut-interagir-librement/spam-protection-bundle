<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Application;

use Phil\SpamProtection\Content\ContentRules;
use Phil\SpamProtection\Inspection\Inspection;
use Phil\SpamProtection\Inspection\Signal;
use Phil\SpamProtection\Proof\ProofOfWork;
use Phil\SpamProtection\Timing\TimingPolicy;
use Phil\SpamProtection\Token\SubmissionToken;
use Phil\SpamProtection\Token\TokenCodec;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * Issues the protection of a form when it is rendered, and inspects the submission that comes back.
 *
 * The inspection raises every signal it finds rather than stopping at the first, so that a log tells why a
 * submission was held. The token is claimed — made single-use — only when it is genuine and on time, so that a
 * forged or stale token never fills the registry of used ones; the rate limit counts every submission that carries
 * a client key.
 */
final readonly class SubmissionInspector
{
    public const int MAXIMUM_PROOF_DIFFICULTY = ProofOfWork::MAXIMUM_DIFFICULTY;

    public const int DEFAULT_MAXIMUM_LENGTH = ContentRules::DEFAULT_MAXIMUM_LENGTH;

    public function __construct(
        private TokenCodec $codec,
        private ClockInterface $clock,
        private UsedTokens $usedTokens,
        private TimingPolicy $timing = new TimingPolicy(),
        private ProofOfWork $proof = new ProofOfWork(),
        private ContentRules $content = new ContentRules(),
        private ?SubmissionRateLimiter $rateLimiter = null,
    ) {
    }

    /**
     * The inspector of a configuration, its policies built from plain values.
     *
     * @param list<string> $forbiddenTerms
     */
    public static function configured(
        #[SensitiveParameter]
        string $secret,
        ClockInterface $clock,
        UsedTokens $usedTokens,
        int $minimumSeconds,
        int $maximumSeconds,
        int $proofDifficulty,
        ?int $maximumLinks,
        array $forbiddenTerms,
        bool $detectGibberish,
        ?SubmissionRateLimiter $rateLimiter = null,
        ?int $maximumLength = ContentRules::DEFAULT_MAXIMUM_LENGTH,
    ): self {
        return new self(
            new TokenCodec($secret),
            $clock,
            $usedTokens,
            new TimingPolicy($minimumSeconds, $maximumSeconds),
            new ProofOfWork($proofDifficulty),
            new ContentRules($maximumLinks, $forbiddenTerms, $detectGibberish, $maximumLength),
            $rateLimiter,
        );
    }

    public function protect(string $scope): Protection
    {
        $token = new SubmissionToken($scope, $this->now(), sodium_bin2hex(random_bytes(16)));

        return new Protection($this->codec->encode($token), $token->nonce, $this->proof->difficulty);
    }

    public function inspect(Submission $submission): Inspection
    {
        $inspection = Inspection::clean();

        if (null !== $submission->honeypot && '' !== $submission->honeypot) {
            $inspection = $inspection->with(Signal::HoneypotFilled);
        }

        $inspection = $inspection->with(...$this->tokenSignals($submission));

        foreach ($submission->texts as $text) {
            $inspection = $inspection->with(...$this->content->inspect($text));
        }

        if (null !== $submission->client && $this->rateLimiter instanceof SubmissionRateLimiter && !$this->rateLimiter->consume($submission->scope, $submission->client)) {
            $inspection = $inspection->with(Signal::RateLimited);
        }

        return $inspection;
    }

    /**
     * @return list<Signal>
     */
    private function tokenSignals(Submission $submission): array
    {
        if (null === $submission->token || '' === $submission->token) {
            return [Signal::TokenMissing];
        }

        $token = $this->codec->decode($submission->token);

        if (!$token instanceof SubmissionToken || $token->scope !== $submission->scope) {
            return [Signal::TokenForged];
        }

        $signals = [];

        if (!$this->proof->verify($token->nonce, $submission->solution ?? '')) {
            $signals[] = Signal::ProofInvalid;
        }

        $timing = $this->timing->check($token->issuedAt, $this->now());

        if ($timing instanceof Signal) {
            return [...$signals, $timing];
        }

        if (!$this->usedTokens->claim($token->nonce, $this->timing->maximum)) {
            $signals[] = Signal::TokenReplayed;
        }

        return $signals;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
