<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle;

use Override;
use Phil\RateLimit\RateLimitGuard;
use Phil\SpamProtectionBundle\Application\SubmissionInspector;
use Phil\SpamProtectionBundle\Application\UsedTokens;
use Phil\SpamProtectionBundle\Infrastructure\Cache\CacheUsedTokens;
use Phil\SpamProtectionBundle\Infrastructure\Doctrine\DbalUsedTokens;
use Phil\SpamProtectionBundle\Infrastructure\Doctrine\UsedTokensSchemaListener;
use Phil\SpamProtectionBundle\Infrastructure\Form\SpamProtectionTypeExtension;
use Phil\SpamProtectionBundle\Infrastructure\Http\ClientKey;
use Phil\SpamProtectionBundle\Infrastructure\RateLimit\SymfonySubmissionRateLimiter;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\AbstractBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Spam protection for Symfony forms, written for people rather than against them: a honeypot, a signed single-use
 * token, a minimum and maximum filling time, a proof of work the browser solves, rules on the text, and a rate limit
 * — no captcha, no third party, no identifier kept.
 *
 * ```yaml
 * phil_spam_protection:
 *     secret: '%kernel.secret%'
 *     timing: { minimum: 3, maximum: 3600 }
 *     proof_of_work: 12
 *     content: { maximum_length: 20000, maximum_links: 3, forbidden_terms: [], detect_gibberish: true }
 *     cache_pool: cache.app
 *     used_tokens: { connection: null, lock_factory: null }   # an atomic store of used tokens, for production
 *     rate_limiter: null      # the service id of a Symfony rate limiter factory
 * ```
 */
final class PhilSpamProtectionBundle extends AbstractBundle
{
    #[Override]
    protected string $extensionAlias = 'phil_spam_protection';

    #[Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('secret')
                    ->info('The secret that signs the tokens and keys the client hash; 16 bytes or more.')
                    ->defaultValue('%kernel.secret%')
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('timing')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('minimum')->info('The seconds a person takes at least to fill a form.')->defaultValue(3)->min(0)->end()
                        ->integerNode('maximum')->info('The seconds after which a rendered form is stale.')->defaultValue(3600)->min(1)->end()
                    ->end()
                ->end()
                ->integerNode('proof_of_work')
                    ->info('The difficulty, in bits, of the proof of work the browser solves; 0 turns it off.')
                    ->defaultValue(12)
                    ->min(0)
                    ->max(SubmissionInspector::MAXIMUM_PROOF_DIFFICULTY)
                ->end()
                ->arrayNode('content')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('maximum_length')
                            ->info('The characters a text may hold, and the most that are read; null for no bound.')
                            ->defaultValue(SubmissionInspector::DEFAULT_MAXIMUM_LENGTH)
                            ->validate()
                                ->ifTrue(static fn (mixed $length): bool => null !== $length && (!\is_int($length) || $length < 1))
                                ->thenInvalid('The maximum length of a text is an integer of 1 or more, or null for no bound; %s given.')
                            ->end()
                        ->end()
                        ->scalarNode('maximum_links')
                            ->info('The links a text may hold; null for no limit.')
                            ->defaultValue(3)
                            ->validate()
                                ->ifTrue(static fn (mixed $links): bool => null !== $links && (!\is_int($links) || $links < 0))
                                ->thenInvalid('The maximum links of a text are an integer of 0 or more, or null for no limit; %s given.')
                            ->end()
                        ->end()
                        ->arrayNode('forbidden_terms')->info('Whole words, or sequences of words, a text may not hold.')->scalarPrototype()->end()->end()
                        ->booleanNode('detect_gibberish')->defaultTrue()->end()
                    ->end()
                ->end()
                ->scalarNode('cache_pool')
                    ->info('The PSR-6 pool that keeps the used tokens; shared by every web server.')
                    ->defaultValue('cache.app')
                ->end()
                ->arrayNode('used_tokens')
                    ->info('How a token is made single-use atomically: a table of a Doctrine connection, or the cache pool under a lock. Without either, two submissions of one token at the same moment can both pass.')
                    ->addDefaultsIfNotSet()
                    ->validate()
                        ->ifTrue(static fn (array $tokens): bool => null !== $tokens['connection'] && null !== $tokens['lock_factory'])
                        ->thenInvalid('The used tokens are kept in a table of a connection or in the cache pool under a lock, not both: %s given.')
                    ->end()
                    ->children()
                        ->scalarNode('connection')
                            ->info('The Doctrine connection whose table keeps the used tokens: "default".')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('lock_factory')
                            ->info('The lock factory, on a store shared by every web server, under which the cache pool claims a token: "lock.factory".')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('rate_limiter')
                    ->info('The service id of a Symfony rate limiter factory counting submissions per form and client; in the prod environment, its storage must be shared by every server.')
                    ->defaultNull()
                ->end()
                ->booleanNode('single_server')
                    ->info('Accepts a rate limiter whose storage is local to the server in the prod environment, for an application on one server.')
                    ->defaultFalse()
                ->end()
            ->end();
    }

    /**
     * The guard of the rate limits, which refuses in the prod environment a limiter counting on each server apart.
     */
    #[Override]
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new RateLimitGuard());
    }

    /**
     * @param array<array-key, mixed> $config
     */
    #[Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{secret: string, timing: array{minimum: int, maximum: int}, proof_of_work: int, content: array{maximum_length: ?int, maximum_links: ?int, forbidden_terms: list<string>, detect_gibberish: bool}, cache_pool: string, used_tokens: array{connection: ?string, lock_factory: ?string}, rate_limiter: ?string, single_server: bool} $config */
        $services = $container->services();
        $connection = $config['used_tokens']['connection'];

        if (null !== $connection) {
            $reference = service(\sprintf('doctrine.dbal.%s_connection', $connection));
            $services->set(DbalUsedTokens::class)->args([$reference, service('clock')]);
            $services->alias(UsedTokens::class, DbalUsedTokens::class);
            $services->set(UsedTokensSchemaListener::class)
                ->args([$reference])
                ->tag('doctrine.event_listener', ['event' => 'postGenerateSchema', 'connection' => $connection]);
        } else {
            $lockFactory = $config['used_tokens']['lock_factory'];
            $services->set(CacheUsedTokens::class)->args([service($config['cache_pool']), null === $lockFactory ? null : service($lockFactory)]);
            $services->alias(UsedTokens::class, CacheUsedTokens::class);
        }

        $rateLimiter = null;

        if (null !== $config['rate_limiter']) {
            $services->set(SymfonySubmissionRateLimiter::class)->args([service($config['rate_limiter'])]);
            RateLimitGuard::watch($builder, $config['rate_limiter'], 'phil_spam_protection.rate_limiter', $config['single_server']);
            $rateLimiter = service(SymfonySubmissionRateLimiter::class);
        }

        $services->set(SubmissionInspector::class)
            ->factory([SubmissionInspector::class, 'configured'])
            ->args([
                $config['secret'],
                service('clock'),
                service(UsedTokens::class),
                $config['timing']['minimum'],
                $config['timing']['maximum'],
                $config['proof_of_work'],
                $config['content']['maximum_links'],
                $config['content']['forbidden_terms'],
                $config['content']['detect_gibberish'],
                $rateLimiter,
                $config['content']['maximum_length'],
            ]);

        $services->set(ClientKey::class)->args([service('request_stack'), $config['secret']]);

        $services->set(SpamProtectionTypeExtension::class)
            ->args([
                service(SubmissionInspector::class),
                service(ClientKey::class),
                service('translator')->nullOnInvalid(),
                service('logger')->nullOnInvalid(),
            ])
            ->tag('form.type_extension')
            ->tag('monolog.logger', ['channel' => 'spam_protection']);
    }
}
