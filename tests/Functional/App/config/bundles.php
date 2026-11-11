<?php

declare(strict_types=1);

use Phil\SpamProtectionBundle\PhilSpamProtectionBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;

return [
    FrameworkBundle::class => ['all' => true],
    PhilSpamProtectionBundle::class => ['all' => true],
];
