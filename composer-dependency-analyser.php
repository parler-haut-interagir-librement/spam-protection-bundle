<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/**
 * The bundle's declared dependencies, checked against the bundle installed on its own. Two exceptions:
 *
 * - `symfony/rate-limiter`, `doctrine/dbal`, `doctrine/orm` and `symfony/lock`, which the production code uses but
 *   only requires in development and suggests: the rate limit is wired only when the application names a limiter,
 *   the table of used tokens only when it names a connection, and the lock only when it names a lock factory;
 * - the `service()` function of the container configurator, which the analyser does not see as declared by
 *   `symfony/dependency-injection` although that package defines it.
 */
return new Configuration()
    ->addPathToScan(__DIR__.'/src', isDev: false)
    ->addPathToScan(__DIR__.'/tests', isDev: true)
    ->ignoreErrorsOnPackages(['symfony/rate-limiter', 'doctrine/dbal', 'doctrine/orm', 'symfony/lock'], [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreUnknownFunctions([
        'Symfony\Component\DependencyInjection\Loader\Configurator\service',
    ]);
