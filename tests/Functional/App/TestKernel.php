<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Functional\App;

use Override;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * An application as small as the bundle needs: FrameworkBundle with forms, the translator, a cache and a rate
 * limiter, the bundle with a proof of work of 4 bits and no minimum filling time, and one contact form.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    #[Override]
    public function getProjectDir(): string
    {
        return __DIR__;
    }

    #[Override]
    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/phil-spam-protection-bundle-test/'.hash('xxh128', __DIR__).'/cache';
    }

    #[Override]
    public function getShareDir(): string
    {
        // The application's cache pool lives here: outside the package, with the rest of the kernel's files.
        return sys_get_temp_dir().'/phil-spam-protection-bundle-test/'.hash('xxh128', __DIR__).'/share';
    }

    #[Override]
    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/phil-spam-protection-bundle-test/'.hash('xxh128', __DIR__).'/log';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'a test secret of sixteen bytes',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'form' => ['csrf_protection' => false],
            'csrf_protection' => false,
            'translator' => ['default_path' => __DIR__.'/translations', 'fallbacks' => ['en']],
            'default_locale' => 'fr',
            'rate_limiter' => ['contact' => ['policy' => 'fixed_window', 'limit' => 3, 'interval' => '1 hour']],
        ]);
        $container->services()->set('logger', NullLogger::class);
        $container->services()->set(ContactController::class)->args([service('form.factory')])->tag('controller.service_arguments')->public();

        $container->extension('phil_spam_protection', [
            'timing' => ['minimum' => 0, 'maximum' => 600],
            'proof_of_work' => 4,
            'content' => ['forbidden_terms' => ['casino']],
            'rate_limiter' => 'limiter.contact',
        ]);
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('contact', '/contact')->controller(ContactController::class);
    }
}
