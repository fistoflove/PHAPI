<?php

declare(strict_types=1);

namespace PHAPI\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\BuildStep;
use PHPat\Test\PHPat;

/**
 * Enforces the PHAPI layer dependency rules.
 *
 * Layer hierarchy (lower layers must not import from higher ones):
 *
 *   Contracts  (pure interfaces, depends on nothing)
 *   Exceptions (value objects, depends on nothing)
 *   HTTP       (request/response DTOs)
 *   Routing    (route value objects)
 *   Auth       (guards, middleware)
 *   Services   (infrastructure adapters)
 *   Server     (kernel, router, middleware manager)
 *   Database   (ORM layer)
 *   Logging    (logger)
 *   Telemetry  (observability decorators)
 *   Providers  (service providers)
 *   Runtime    (Swoole driver)
 *   Core       (orchestration, bootstrapping)
 *   Concerns   (traits composed into PHAPI)
 *   PHAPI      (root facade)
 */
final class LayerDependencyTest
{
    public function testContractsMustNotDependOnAnyConcrete(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('PHAPI\Contracts'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('PHAPI\Auth'),
                Selector::inNamespace('PHAPI\Core'),
                Selector::inNamespace('PHAPI\Concerns'),
                Selector::inNamespace('PHAPI\Database'),
                // Exceptions allowed: interfaces document @throws contracts
                Selector::inNamespace('PHAPI\HTTP'),
                Selector::inNamespace('PHAPI\Logging'),
                Selector::inNamespace('PHAPI\Providers'),
                Selector::inNamespace('PHAPI\Routing'),
                Selector::inNamespace('PHAPI\Runtime'),
                Selector::inNamespace('PHAPI\Server'),
                Selector::inNamespace('PHAPI\Services'),
                Selector::inNamespace('PHAPI\Telemetry'),
                Selector::classname('PHAPI\PHAPI'),
            )
            ->because('Contracts must remain pure interfaces with zero concrete dependencies.');
    }

    public function testExceptionsMustNotDependOnAnythingExceptOtherExceptions(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('PHAPI\Exceptions'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('PHAPI\Auth'),
                Selector::inNamespace('PHAPI\Core'),
                Selector::inNamespace('PHAPI\Concerns'),
                Selector::inNamespace('PHAPI\Contracts'),
                Selector::inNamespace('PHAPI\Database'),
                Selector::inNamespace('PHAPI\HTTP'),
                Selector::inNamespace('PHAPI\Logging'),
                Selector::inNamespace('PHAPI\Providers'),
                Selector::inNamespace('PHAPI\Routing'),
                Selector::inNamespace('PHAPI\Runtime'),
                Selector::inNamespace('PHAPI\Server'),
                Selector::inNamespace('PHAPI\Services'),
                Selector::inNamespace('PHAPI\Telemetry'),
                Selector::classname('PHAPI\PHAPI'),
            )
            ->because('Exceptions are value objects and must not pull in framework layers.');
    }

    public function testHttpMustNotDependOnHigherLayers(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('PHAPI\HTTP'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('PHAPI\Core'),
                Selector::inNamespace('PHAPI\Concerns'),
                Selector::inNamespace('PHAPI\Database'),
                Selector::inNamespace('PHAPI\Logging'),
                Selector::inNamespace('PHAPI\Providers'),
                Selector::inNamespace('PHAPI\Runtime'),
                Selector::inNamespace('PHAPI\Server'),
                Selector::inNamespace('PHAPI\Services'),
                Selector::inNamespace('PHAPI\Telemetry'),
            )
            ->because('HTTP layer (Request/Response/Validator) must not depend on server, runtime, or service layers.');
    }

    public function testServicesMustNotDependOnHigherLayers(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('PHAPI\Services'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('PHAPI\Core'),
                Selector::inNamespace('PHAPI\Concerns'),
                Selector::inNamespace('PHAPI\Database'),
                Selector::inNamespace('PHAPI\Logging'),
                Selector::inNamespace('PHAPI\Providers'),
                Selector::inNamespace('PHAPI\Server'),
                Selector::inNamespace('PHAPI\Telemetry'),
                Selector::classname('PHAPI\PHAPI'),
            )
            ->because('Services must depend on Contracts/Exceptions/HTTP/Auth/Runtime, not on Core/Server/Telemetry.');
    }

    public function testAuthMustNotDependOnHigherLayers(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('PHAPI\Auth'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('PHAPI\Core'),
                Selector::inNamespace('PHAPI\Concerns'),
                Selector::inNamespace('PHAPI\Database'),
                Selector::inNamespace('PHAPI\Logging'),
                Selector::inNamespace('PHAPI\Providers'),
                Selector::inNamespace('PHAPI\Runtime'),
                Selector::inNamespace('PHAPI\Server'),
                Selector::inNamespace('PHAPI\Telemetry'),
                Selector::classname('PHAPI\PHAPI'),
            )
            ->because('Auth must only depend on HTTP, Contracts, Exceptions, and Services.');
    }

    public function testServerMustNotDependOnHigherLayers(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('PHAPI\Server'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('PHAPI\Core'),
                Selector::inNamespace('PHAPI\Concerns'),
                Selector::inNamespace('PHAPI\Database'),
                Selector::inNamespace('PHAPI\Providers'),
                Selector::inNamespace('PHAPI\Runtime'),
                Selector::inNamespace('PHAPI\Telemetry'),
            )
            ->excluding(
                // HttpKernel needs the DI Container to resolve handlers/middleware
                Selector::classname('PHAPI\Core\Container'),
            )
            ->because('Server layer may use Core\\Container for DI but must not depend on other Core classes, Runtime, Providers, or Telemetry.');
    }

    public function testTelemetryDecoratorsNotDependOnHigherLayers(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('PHAPI\Telemetry'))
            ->excluding(
                // TracingServiceProvider is a provider — it wires things together
                // and legitimately needs Core\Container + PHAPI
                Selector::classname('PHAPI\Telemetry\TracingServiceProvider'),
            )
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('PHAPI\Core'),
                Selector::inNamespace('PHAPI\Concerns'),
                Selector::inNamespace('PHAPI\Database'),
                Selector::inNamespace('PHAPI\Providers'),
                Selector::classname('PHAPI\PHAPI'),
            )
            ->because('Telemetry decorators must not depend on Core, Providers, or the root PHAPI class.');
    }

    public function testProvidersMustNotDependOnConcerns(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('PHAPI\Providers'))
            ->shouldNotDependOn()
            ->classes(
                // PHAPI\PHAPI allowed: ServiceProviderInterface::boot() takes PHAPI
                Selector::inNamespace('PHAPI\Concerns'),
            )
            ->because('Providers must not depend on PHAPI concern traits.');
    }

    public function testDatabaseMustNotDependOnHigherLayers(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('PHAPI\Database'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('PHAPI\Core'),
                Selector::inNamespace('PHAPI\Concerns'),
                Selector::inNamespace('PHAPI\Providers'),
                Selector::inNamespace('PHAPI\Runtime'),
                Selector::inNamespace('PHAPI\Server'),
                Selector::inNamespace('PHAPI\Telemetry'),
                Selector::classname('PHAPI\PHAPI'),
            )
            ->because('Database/ORM layer must not depend on Core, Server, Runtime, or Telemetry.');
    }

    public function testLoggingMustNotDependOnHigherLayers(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('PHAPI\Logging'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('PHAPI\Core'),
                Selector::inNamespace('PHAPI\Concerns'),
                Selector::inNamespace('PHAPI\Database'),
                Selector::inNamespace('PHAPI\Providers'),
                Selector::inNamespace('PHAPI\Runtime'),
                Selector::inNamespace('PHAPI\Telemetry'),
                Selector::classname('PHAPI\PHAPI'),
            )
            ->because('Logging must not depend on higher-level orchestration layers.');
    }
}
