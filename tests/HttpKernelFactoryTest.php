<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Core\HttpKernelFactory;
use PHAPI\Server\ErrorHandler;
use PHAPI\Server\HttpKernel;
use PHAPI\Server\MiddlewareManager;
use PHAPI\Server\Router;
use PHPUnit\Framework\TestCase;

final class HttpKernelFactoryTest extends TestCase
{
    public function testBuildsKernelComponents(): void
    {
        $factory = new HttpKernelFactory();
        $components = $factory->build(['debug' => true]);

        $this->assertInstanceOf(Router::class, $components['router']);
        $this->assertInstanceOf(MiddlewareManager::class, $components['middleware']);
        $this->assertInstanceOf(ErrorHandler::class, $components['errorHandler']);
        $this->assertInstanceOf(HttpKernel::class, $components['kernel']);
    }
}
