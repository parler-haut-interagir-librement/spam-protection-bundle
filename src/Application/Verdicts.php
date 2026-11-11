<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Application;

use Phil\BotDetectionBundle\Domain\Verdict\Verdict;
use Phil\SpamProtection\Inspection\Inspection;

/**
 * An inspection in the words `phil/bot-detection-bundle` speaks — robot as soon as one signal is raised, human
 * otherwise —, so that an application counts or reacts to spam as to any robot verdict.
 */
final class Verdicts
{
    public static function of(Inspection $inspection): Verdict
    {
        return $inspection->isSpam() ? Verdict::Robot : Verdict::Human;
    }
}
