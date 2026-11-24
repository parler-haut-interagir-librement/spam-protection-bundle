<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Unit\Fixture;

use Override;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;

/**
 * An array pool that records every key it is asked for.
 */
final class KeyRecordingPool extends ArrayAdapter
{
    /** @var list<string> */
    public array $keys = [];

    #[Override]
    public function getItem(mixed $key): CacheItem
    {
        $this->keys[] = $key;

        return parent::getItem($key);
    }
}
