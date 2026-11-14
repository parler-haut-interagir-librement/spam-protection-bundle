<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Integration;

use Composer\Autoload\ClassLoader;
use Phil\SpamProtectionBundle\Infrastructure\Cache\CacheUsedTokens;
use Phil\SpamProtectionBundle\Infrastructure\Doctrine\DbalUsedTokens;
use Phil\SpamProtectionBundle\Tests\Unit\Infrastructure\DbalUsedTokensTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Eight processes submit the same token at the same moment: exactly one claim passes — through a SQLite file, and
 * through a filesystem cache under file locks.
 */
#[CoversClass(DbalUsedTokens::class)]
#[CoversClass(CacheUsedTokens::class)]
final class ConcurrentClaimTest extends TestCase
{
    private const int CONTENDERS = 8;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/phil-spam-protection-race-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    #[TestWith(['dbal'])]
    #[TestWith(['lock'])]
    public function testOneSubmissionOfATokenPasses(string $store): void
    {
        $path = $this->directory.'/store';

        if ('dbal' === $store) {
            DbalUsedTokensTest::connection($path)->close();
        }

        $signal = $this->directory.'/go';
        $autoload = \dirname((string) new ReflectionClass(ClassLoader::class)->getFileName(), 2).'/autoload.php';
        $processes = [];

        for ($i = 0; $i < self::CONTENDERS; ++$i) {
            $process = new Process([\PHP_BINARY, __DIR__.'/claim.php', $autoload, $store, $path, $signal]);
            $process->start();
            $processes[] = $process;
        }

        usleep(300_000);
        touch($signal);

        $outcomes = [];

        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $outcomes[] = $process->getOutput();
        }

        sort($outcomes);

        self::assertSame(['claimed', ...array_fill(0, self::CONTENDERS - 1, 'refused')], $outcomes);
    }
}
