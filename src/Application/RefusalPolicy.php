<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Application;

use InvalidArgumentException;
use Phil\SpamProtection\Inspection\Inspection;
use Phil\SpamProtection\Inspection\Signal;

/**
 * When an inspection refuses a submission: each signal it raised adds its weight, and a score that reaches the
 * threshold refuses. By default every signal weighs the threshold, so that one signal refuses on its own; an
 * application that finds a signal too strict alone — a fast typist, a message with many links — lowers its weight, and
 * the submission is refused only when other signals join it.
 *
 * **Three signals always weigh the threshold**: a missing token, a forged one and a replayed one. They are certain —
 * the form was not rendered by this server, or its token was already spent — and a lighter replay would let the same
 * token pass twice.
 */
final readonly class RefusalPolicy
{
    public const int DEFAULT_THRESHOLD = 100;

    public const int MAXIMUM_WEIGHT = 1000;

    /** The signals that always weigh the threshold. */
    public const array ALWAYS_REFUSED = [Signal::TokenMissing, Signal::TokenForged, Signal::TokenReplayed];

    /**
     * @param int                $threshold the score that refuses, from 1 to `MAXIMUM_WEIGHT`
     * @param array<string, int> $weights   signal value → weight, from 0 to `MAXIMUM_WEIGHT`; the threshold for a signal not listed
     *
     * @throws InvalidArgumentException for a threshold or a weight out of bounds, an unknown signal, or one of `ALWAYS_REFUSED`
     */
    public function __construct(
        public int $threshold = self::DEFAULT_THRESHOLD,
        private array $weights = [],
    ) {
        if ($threshold < 1 || $threshold > self::MAXIMUM_WEIGHT) {
            throw new InvalidArgumentException(\sprintf('The threshold of a refusal is from 1 to %d; %d given.', self::MAXIMUM_WEIGHT, $threshold));
        }

        foreach ($weights as $signal => $weight) {
            $known = Signal::tryFrom($signal) ?? throw new InvalidArgumentException(\sprintf('"%s" is not a signal: %s.', $signal, implode(', ', array_map(static fn (Signal $signal): string => $signal->value, Signal::cases()))));

            if (\in_array($known, self::ALWAYS_REFUSED, true)) {
                throw new InvalidArgumentException(\sprintf('The signal "%s" always refuses: its weight is not configured.', $signal));
            }

            if ($weight < 0 || $weight > self::MAXIMUM_WEIGHT) {
                throw new InvalidArgumentException(\sprintf('The weight of the signal "%s" is from 0 to %d; %d given.', $signal, self::MAXIMUM_WEIGHT, $weight));
            }
        }
    }

    public function weightOf(Signal $signal): int
    {
        // A certain signal never takes a weight (the constructor refuses one): it weighs the threshold, as any signal not listed.
        return $this->weights[$signal->value] ?? $this->threshold;
    }

    /**
     * The sum of the weights of the signals the inspection raised, each once.
     */
    public function score(Inspection $inspection): int
    {
        return array_sum(array_map($this->weightOf(...), $inspection->signals));
    }

    public function refuses(Inspection $inspection): bool
    {
        return $this->score($inspection) >= $this->threshold;
    }
}
