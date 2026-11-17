<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Unit;

use Phil\RateLimit\RateLimitGuard;
use Phil\SpamProtectionBundle\Application\SubmissionInspector;
use Phil\SpamProtectionBundle\Application\UsedTokens;
use Phil\SpamProtectionBundle\Infrastructure\Cache\CacheUsedTokens;
use Phil\SpamProtectionBundle\Infrastructure\Doctrine\DbalUsedTokens;
use Phil\SpamProtectionBundle\Infrastructure\Doctrine\UsedTokensSchemaListener;
use Phil\SpamProtectionBundle\Infrastructure\Form\SpamProtectionTypeExtension;
use Phil\SpamProtectionBundle\Infrastructure\Http\ClientKey;
use Phil\SpamProtectionBundle\Infrastructure\RateLimit\SymfonySubmissionRateLimiter;
use Phil\SpamProtectionBundle\PhilSpamProtectionBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

#[CoversClass(PhilSpamProtectionBundle::class)]
final class PhilSpamProtectionBundleTest extends TestCase
{
    public function testTheAliasIsPhilSpamProtection(): void
    {
        self::assertSame('phil_spam_protection', new PhilSpamProtectionBundle()->getContainerExtension()?->getAlias());
    }

    public function testTheDefaultsAreWired(): void
    {
        $container = $this->load();
        $inspector = $container->getDefinition(SubmissionInspector::class);

        self::assertSame([SubmissionInspector::class, 'configured'], $inspector->getFactory());
        self::assertSame('%kernel.secret%', $inspector->getArgument(0));
        self::assertSame('clock', (string) $this->reference($inspector->getArgument(1)));
        self::assertSame(UsedTokens::class, (string) $this->reference($inspector->getArgument(2)));
        self::assertSame(CacheUsedTokens::class, (string) $container->getAlias(UsedTokens::class));
        self::assertSame([3, 3600, 12, 3, [], true, null, 20_000], \array_slice($inspector->getArguments(), 3));
        self::assertSame('cache.app', (string) $this->reference($container->getDefinition(CacheUsedTokens::class)->getArgument(0)));
        self::assertNull($container->getDefinition(CacheUsedTokens::class)->getArgument(1), 'Without a lock factory, the pool claims without a lock, as before.');
        self::assertFalse($container->hasDefinition(DbalUsedTokens::class));
        self::assertFalse($container->hasDefinition(SymfonySubmissionRateLimiter::class));
        self::assertSame('request_stack', (string) $this->reference($container->getDefinition(ClientKey::class)->getArgument(0)));
        self::assertSame('%kernel.secret%', $container->getDefinition(ClientKey::class)->getArgument(1));
    }

    public function testTheFormExtensionIsTaggedAndLogsOnItsChannel(): void
    {
        $extension = $this->load()->getDefinition(SpamProtectionTypeExtension::class);

        self::assertTrue($extension->hasTag('form.type_extension'));
        self::assertSame([['channel' => 'spam_protection']], $extension->getTag('monolog.logger'));
        self::assertSame(SubmissionInspector::class, (string) $this->reference($extension->getArgument(0)));
        self::assertSame(ClientKey::class, (string) $this->reference($extension->getArgument(1)));

        foreach ([2 => 'translator', 3 => 'logger'] as $index => $id) {
            $reference = $this->reference($extension->getArgument($index));
            self::assertSame($id, (string) $reference);
            self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $reference->getInvalidBehavior());
        }
    }

    public function testTheConfigurationIsWired(): void
    {
        $container = $this->load([
            'secret' => 'a secret of sixteen bytes or more',
            'timing' => ['minimum' => 5, 'maximum' => 600],
            'proof_of_work' => 0,
            'content' => ['maximum_length' => 500, 'maximum_links' => 1, 'forbidden_terms' => ['casino'], 'detect_gibberish' => false],
            'cache_pool' => 'cache.shared',
            'rate_limiter' => 'limiter.contact',
        ]);

        $inspector = $container->getDefinition(SubmissionInspector::class);

        self::assertSame('a secret of sixteen bytes or more', $inspector->getArgument(0));
        self::assertSame([5, 600, 0, 1, ['casino'], false], \array_slice($inspector->getArguments(), 3, 6));
        self::assertSame(SymfonySubmissionRateLimiter::class, (string) $this->reference($inspector->getArgument(9)));
        self::assertSame(500, $inspector->getArgument(10));
        self::assertSame('cache.shared', (string) $this->reference($container->getDefinition(CacheUsedTokens::class)->getArgument(0)));
        self::assertSame('limiter.contact', (string) $this->reference($container->getDefinition(SymfonySubmissionRateLimiter::class)->getArgument(0)));
    }

    public function testTheCachePoolClaimsUnderTheLockFactoryGiven(): void
    {
        $container = $this->load(['cache_pool' => 'cache.shared', 'used_tokens' => ['lock_factory' => 'lock.factory']]);
        $tokens = $container->getDefinition(CacheUsedTokens::class);

        self::assertSame(['cache.shared', 'lock.factory'], [(string) $this->reference($tokens->getArgument(0)), (string) $this->reference($tokens->getArgument(1))]);
        self::assertSame(CacheUsedTokens::class, (string) $container->getAlias(UsedTokens::class));
    }

    public function testAConnectionKeepsTheUsedTokensInItsTable(): void
    {
        $container = $this->load(['used_tokens' => ['connection' => 'default']]);
        $tokens = $container->getDefinition(DbalUsedTokens::class);

        self::assertSame(['doctrine.dbal.default_connection', 'clock'], [(string) $this->reference($tokens->getArgument(0)), (string) $this->reference($tokens->getArgument(1))]);
        self::assertSame(DbalUsedTokens::class, (string) $container->getAlias(UsedTokens::class));
        self::assertFalse($container->hasDefinition(CacheUsedTokens::class));

        $listener = $container->getDefinition(UsedTokensSchemaListener::class);
        self::assertSame('doctrine.dbal.default_connection', (string) $this->reference($listener->getArgument(0)));
        self::assertSame([['event' => 'postGenerateSchema', 'connection' => 'default']], $listener->getTag('doctrine.event_listener'));
    }

    public function testATableAndALockAreNotBothChosen(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('The used tokens are kept in a table of a connection or in the cache pool under a lock, not both');

        $this->load(['used_tokens' => ['connection' => 'default', 'lock_factory' => 'lock.factory']]);
    }

    public function testTheProofDifficultyIsBounded(): void
    {
        $this->load(['proof_of_work' => 24]);

        $this->expectException(InvalidConfigurationException::class);

        $this->load(['proof_of_work' => 25]);
    }

    public function testTheLowerBoundsAreAcceptedAndBelowRefused(): void
    {
        $this->load(['timing' => ['minimum' => 0, 'maximum' => 1], 'proof_of_work' => 0, 'content' => ['maximum_links' => 0, 'maximum_length' => 1]]);

        foreach ([
            ['timing' => ['minimum' => -1]],
            ['timing' => ['maximum' => 0]],
            ['proof_of_work' => -1],
            ['content' => ['maximum_links' => -1]],
            ['content' => ['maximum_links' => '3']],
            ['content' => ['maximum_links' => 1.5]],
            ['content' => ['maximum_length' => 0]],
            ['content' => ['maximum_length' => '100']],
        ] as $config) {
            try {
                $this->load($config);
                self::fail('Accepted: '.json_encode($config, \JSON_THROW_ON_ERROR));
            } catch (InvalidConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * `~` turns the bound off, as the configuration reference says.
     */
    public function testNullSetsNoLimitOnLinksOrLength(): void
    {
        $inspector = $this->load(['content' => ['maximum_links' => null, 'maximum_length' => null]])->getDefinition(SubmissionInspector::class);

        self::assertNull($inspector->getArgument(6));
        self::assertNull($inspector->getArgument(10));
    }

    /**
     * The limiter named is checked as every PHIL rate limit is: a store local to each server would let a client spend
     * the limit once per server, and the container build refuses it in production unless the application runs on one.
     */
    public function testALimiterLocalToEachServerIsRefusedInProduction(): void
    {
        $build = static function (array $config, string $environment): ContainerBuilder {
            $container = new ContainerBuilder();
            $container->setParameter('kernel.environment', $environment);
            $container->setDefinition('cache.local', new Definition(FilesystemAdapter::class));
            $container->setDefinition('cache.shared', new Definition(RedisAdapter::class));
            $container->setDefinition('limiter.storage.contact', new Definition(CacheStorage::class, [new Reference('cache.local')]));
            $container->setDefinition('limiter.storage.shared', new Definition(CacheStorage::class, [new Reference('cache.shared')]));
            $container->setDefinition('limiter.contact', new Definition(RateLimiterFactory::class, [[], new Reference('limiter.storage.contact')]));
            $container->setDefinition('limiter.shared', new Definition(RateLimiterFactory::class, [[], new Reference('limiter.storage.shared')]));

            $bundle = new PhilSpamProtectionBundle();
            $extension = $bundle->getContainerExtension();
            self::assertNotNull($extension);
            $extension->load([$config], $container);
            $bundle->build($container);

            foreach ($container->getCompilerPassConfig()->getBeforeOptimizationPasses() as $pass) {
                if ($pass instanceof RateLimitGuard) {
                    $pass->process($container);
                }
            }

            return $container;
        };

        $build(['rate_limiter' => 'limiter.contact'], 'test');
        $build(['rate_limiter' => 'limiter.shared'], 'prod');
        $build(['rate_limiter' => 'limiter.contact', 'single_server' => true], 'prod');
        $build([], 'prod');

        $this->expectExceptionObject(new InvalidConfigurationException('phil_spam_protection.rate_limiter: the store "limiter.contact" is local to each server, so a client could spend the limit once per server. Name a cache pool on a shared store — Redis, Memcached, a database —, or set "single_server" to true on a single server.'));
        $build(['rate_limiter' => 'limiter.contact'], 'prod');
    }

    public function testAnEmptySecretIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['secret' => '']);
    }

    private function reference(mixed $argument): Reference
    {
        self::assertInstanceOf(Reference::class, $argument);

        return $argument;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $extension = new PhilSpamProtectionBundle()->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $container);

        return $container;
    }
}
