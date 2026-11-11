<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/**
 * The bundle's declared dependencies, checked against the bundle installed on its own. Two exceptions:
 *
 * - `symfony/rate-limiter`, which the production code uses but only requires in development and suggests: the
 *   rate limit is wired only when the application names a limiter;
 * - the `service()` function of the container configurator, which the analyser does not see as declared by
 *   `symfony/dependency-injection` although that package defines it.
 */
return new Configuration()
    ->addPathToScan(__DIR__.'/src', isDev: false)
    ->addPathToScan(__DIR__.'/tests', isDev: true)
    ->ignoreErrorsOnPackage('symfony/rate-limiter', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreUnknownFunctions([
        'Symfony\Component\DependencyInjection\Loader\Configurator\service',
    ]);
