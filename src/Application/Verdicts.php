<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Application;

use Phil\BotDetectionBundle\Domain\Verdict\Verdict;
use Phil\SpamProtection\Inspection\Inspection;

/**
 * An inspection in the words `phil/bot-detection-bundle` speaks — robot when the refusal policy refuses it, which by
 * default is as soon as one signal is raised, human otherwise —, so that an application counts or reacts to spam as to
 * any robot verdict.
 */
final class Verdicts
{
    public static function of(Inspection $inspection, RefusalPolicy $policy = new RefusalPolicy()): Verdict
    {
        return $policy->refuses($inspection) ? Verdict::Robot : Verdict::Human;
    }
}
