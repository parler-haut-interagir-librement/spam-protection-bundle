<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Unit;

use Phil\SpamProtectionBundle\Application\SubmissionInspector;
use Phil\SpamProtectionBundle\Infrastructure\Cache\CacheUsedTokens;
use Phil\SpamProtectionBundle\Infrastructure\Form\SpamProtectionTypeExtension;
use Phil\SpamProtectionBundle\Infrastructure\Http\ClientKey;
use Phil\SpamProtectionBundle\Infrastructure\RateLimit\SymfonySubmissionRateLimiter;
use Phil\SpamProtectionBundle\PhilSpamProtectionBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

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
        self::assertSame(CacheUsedTokens::class, (string) $this->reference($inspector->getArgument(2)));
        self::assertSame([3, 3600, 12, 3, [], true, null, 20_000], \array_slice($inspector->getArguments(), 3));
        self::assertSame('cache.app', (string) $this->reference($container->getDefinition(CacheUsedTokens::class)->getArgument(0)));
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
