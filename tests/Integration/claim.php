<?php

declare(strict_types=1);

/*
 * One contender of ConcurrentClaimTest: waits for the start signal, then claims the nonce once and prints whether
 * the claim passed.
 *
 *     php claim.php <autoload> <dbal|lock> <path> <start-signal>
 */

use Doctrine\DBAL\DriverManager;
use Phil\SpamProtectionBundle\Infrastructure\Cache\CacheUsedTokens;
use Phil\SpamProtectionBundle\Infrastructure\Doctrine\DbalUsedTokens;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

[, $autoload, $store, $path, $signal] = $argv;

require $autoload;

$tokens = 'dbal' === $store
    ? new DbalUsedTokens(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path, 'driverOptions' => [PDO::ATTR_TIMEOUT => 10]]), new NativeClock())
    : new CacheUsedTokens(new FilesystemAdapter('race', 0, $path), new LockFactory(new FlockStore($path)));

// Every contender is connected and ready before the first one claims.
$deadline = microtime(true) + 10;

while (!is_file($signal) && microtime(true) < $deadline) {
    usleep(500);
}

echo $tokens->claim(str_repeat('a', 32), 600) ? 'claimed' : 'refused';
