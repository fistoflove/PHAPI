<?php

declare(strict_types=1);

namespace PHAPI\Core;

use PHAPI\Server\ErrorHandler;
use PHAPI\Server\HttpKernel;
use PHAPI\Server\MiddlewareManager;
use PHAPI\Server\Router;

final class HttpKernelFactory
{
    /**
     * @param array<string, mixed> $config
     * @return array{router: Router, middleware: MiddlewareManager, errorHandler: ErrorHandler, kernel: HttpKernel}
     */
    public function build(array $config): array
    {
        $router = new Router();
        $middleware = new MiddlewareManager();
        $errorHandler = new ErrorHandler((bool)($config['debug'] ?? false));
        $container = new Container();
        $kernel = new HttpKernel(
            $router,
            $middleware,
            $errorHandler,
            $container,
            $config['access_logger'] ?? null
        );

        return [
            'router' => $router,
            'middleware' => $middleware,
            'errorHandler' => $errorHandler,
            'kernel' => $kernel,
        ];
    }
}
