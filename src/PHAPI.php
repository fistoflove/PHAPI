<?php

declare(strict_types=1);

namespace PHAPI;

use PHAPI\Auth\AuthManager;
use PHAPI\Auth\AuthMiddleware;
use PHAPI\Core\AppBootstrapper;
use PHAPI\Core\AuthConfigurator;
use PHAPI\Core\ConfigLoader;
use PHAPI\Core\Container;
use PHAPI\Core\DefaultEndpoints;
use PHAPI\Core\HttpKernelFactory;
use PHAPI\Core\JobsScheduler;
use PHAPI\Core\ProviderLoader;
use PHAPI\Core\RuntimeManager;
use PHAPI\Exceptions\FeatureNotSupportedException;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\RequestContext;
use PHAPI\HTTP\RouteBuilder;
use PHAPI\Runtime\DriverCapabilities;
use PHAPI\Runtime\SwooleDriver;
use PHAPI\Server\ErrorHandler;
use PHAPI\Server\HttpKernel;
use PHAPI\Server\MiddlewareManager;
use PHAPI\Server\Router;
use PHAPI\Services\HttpClient;
use PHAPI\Services\JobsManager;
use PHAPI\Services\Realtime;
use PHAPI\Services\SwooleHttpClient;
use PHAPI\Services\SwooleMySqlClient;
use PHAPI\Services\SwooleRedisClient;
use PHAPI\Services\SwooleTaskRunner;
use PHAPI\Services\TaskRunner;

final class PHAPI
{
    private static ?PHAPI $lastInstance = null;
    /**
     * @var array<string, mixed>
     */
    private array $config;
    private Router $router;
    private MiddlewareManager $middleware;
    private ErrorHandler $errorHandler;
    private Container $container;
    private HttpKernel $kernel;
    private RuntimeManager $runtimeManager;
    private HttpKernelFactory $kernelFactory;
    private JobsManager $jobs;
    private AuthManager $auth;
    private AppBootstrapper $bootstrapper;
    private ConfigLoader $configLoader;
    private AuthConfigurator $authConfigurator;
    private JobsScheduler $jobsScheduler;
    private DefaultEndpoints $defaultEndpoints;
    private ProviderLoader $providerLoader;
    private ?SwooleRedisClient $redisClient = null;
    private ?SwooleMySqlClient $mysqlClient = null;
    /**
     * @var array<int, \PHAPI\Core\ServiceProviderInterface>
     */
    private array $providers = [];
    /**
     * @var callable(string, array<string, mixed>): void|null
     */
    private $realtimeFallback = null;
    /**
     * @var callable(\Swoole\WebSocket\Server, mixed, SwooleDriver): void|null
     */
    private $webSocketHandler = null;

    /**
     * Create a new PHAPI instance with configuration overrides.
     *
     * @param array<string, mixed> $config
     * @return void
     */
    public function __construct(array $config = [])
    {
        self::$lastInstance = $this;
        $this->configLoader = new ConfigLoader();
        $this->config = $this->configLoader->load($config);

        $this->kernelFactory = new HttpKernelFactory();
        $kernelComponents = $this->kernelFactory->build($this->config);
        $this->router = $kernelComponents['router'];
        $this->middleware = $kernelComponents['middleware'];
        $this->errorHandler = $kernelComponents['errorHandler'];
        $this->kernel = $kernelComponents['kernel'];
        $this->container = $this->kernel->container();
        $logDir = $this->config['jobs_log_dir'] ?? (getcwd() . '/var/jobs');
        $logLimit = (int)($this->config['jobs_log_limit'] ?? 200);
        $rotateBytes = (int)($this->config['jobs_log_rotate_bytes'] ?? 1048576);
        $rotateKeep = (int)($this->config['jobs_log_rotate_keep'] ?? 5);
        $this->jobs = new JobsManager($logDir, $logLimit, $rotateBytes, $rotateKeep);
        $this->authConfigurator = new AuthConfigurator();
        $this->auth = $this->authConfigurator->configure($this->config);
        $this->bootstrapper = new AppBootstrapper();
        $this->jobsScheduler = new JobsScheduler();
        $this->defaultEndpoints = new DefaultEndpoints();
        $this->providerLoader = new ProviderLoader();

        $this->runtimeManager = new RuntimeManager($this->config);

        $this->bootstrapper->registerCoreServices(
            $this,
            $this->container,
            $this->middleware,
            $this->jobs,
            $this->auth,
            $this->resolveTaskRunner(),
            $this->resolveHttpClient(),
            $this->runtimeManager->capabilities(),
            $this->runtimeManager->driver() instanceof SwooleDriver ? $this->runtimeManager->driver() : null,
            $this->config['debug'],
            $this->realtimeFallback,
            $this->webSocketHandler
        );
        $this->providers = $this->providerLoader->register($this->config['providers'] ?? [], $this->container, $this);
        $this->providerLoader->boot($this->providers, $this);
        $this->bootstrapper->registerSafetyMiddleware($this->middleware, $this->config);
        $this->defaultEndpoints->register($this, $this->jobs, $this->config);
    }

    /**
     * Enable or disable debug mode.
     *
     * @param bool $debug
     * @return self
     */
    public function setDebug(bool $debug): self
    {
        $this->config['debug'] = $debug;
        $this->errorHandler->setDebug($debug);
        $this->bootstrapper->registerCoreServices(
            $this,
            $this->container,
            $this->middleware,
            $this->jobs,
            $this->auth,
            $this->resolveTaskRunner(),
            $this->resolveHttpClient(),
            $this->runtimeManager->capabilities(),
            $this->runtimeManager->driver() instanceof SwooleDriver ? $this->runtimeManager->driver() : null,
            $this->config['debug'],
            $this->realtimeFallback,
            $this->webSocketHandler
        );
        return $this;
    }

    /**
     * Set the runtime driver name.
     *
     * @param string $runtime
     * @return self
     */
    public function setRuntime(string $runtime): self
    {
        $this->config['runtime'] = $runtime;
        $this->runtimeManager->reconfigure($this->config);
        $this->bootstrapper->registerCoreServices(
            $this,
            $this->container,
            $this->middleware,
            $this->jobs,
            $this->auth,
            $this->resolveTaskRunner(),
            $this->resolveHttpClient(),
            $this->runtimeManager->capabilities(),
            $this->runtimeManager->driver() instanceof SwooleDriver ? $this->runtimeManager->driver() : null,
            $this->config['debug'],
            $this->realtimeFallback,
            $this->webSocketHandler
        );
        return $this;
    }

    /**
     * Set a fallback callback for realtime operations when WebSockets are unavailable.
     *
     * @param callable(string, array<string, mixed>): void $fallback
     * @return self
     */
    public function setRealtimeFallback(callable $fallback): self
    {
        $this->realtimeFallback = $fallback;
        $this->bootstrapper->registerCoreServices(
            $this,
            $this->container,
            $this->middleware,
            $this->jobs,
            $this->auth,
            $this->resolveTaskRunner(),
            $this->resolveHttpClient(),
            $this->runtimeManager->capabilities(),
            $this->runtimeManager->driver() instanceof SwooleDriver ? $this->runtimeManager->driver() : null,
            $this->config['debug'],
            $this->realtimeFallback,
            $this->webSocketHandler
        );
        return $this;
    }

    /**
     * Register a WebSocket message handler for Swoole.
     *
     * @param callable(\Swoole\WebSocket\Server, mixed, SwooleDriver): void $handler
     * @return self
     */
    public function setWebSocketHandler(callable $handler): self
    {
        $this->webSocketHandler = $handler;
        $driver = $this->runtimeManager->driver();
        if ($driver instanceof SwooleDriver) {
            $driver->setWebSocketHandler($handler);
        }
        return $this;
    }

    /**
     * Register a boot hook for the active runtime.
     *
     * @param callable(): void $handler
     * @return self
     */
    public function onBoot(callable $handler): self
    {
        $this->runtimeManager->driver()->onBoot($handler);
        return $this;
    }

    /**
     * Register a worker-start hook for the active runtime.
     *
     * @param callable(mixed, int): void $handler
     * @return self
     */
    public function onWorkerStart(callable $handler): self
    {
        $this->runtimeManager->driver()->onWorkerStart($handler);
        return $this;
    }

    /**
     * Register a shutdown hook for the active runtime.
     *
     * @param callable(): void $handler
     * @return self
     */
    public function onShutdown(callable $handler): self
    {
        $this->runtimeManager->driver()->onShutdown($handler);
        return $this;
    }

    /**
     * Register a request-start hook for the active runtime.
     *
     * @param callable(\PHAPI\HTTP\Request): void $handler
     * @return self
     */
    public function onRequestStart(callable $handler): self
    {
        $this->runtimeManager->driver()->onRequestStart($handler);
        return $this;
    }

    /**
     * Register a request-end hook for the active runtime.
     *
     * @param callable(\PHAPI\HTTP\Request, \PHAPI\HTTP\Response): void $handler
     * @return self
     */
    public function onRequestEnd(callable $handler): self
    {
        $this->runtimeManager->driver()->onRequestEnd($handler);
        return $this;
    }

    /**
     * Return the active runtime capabilities.
     *
     * @return DriverCapabilities
     */
    public function capabilities(): DriverCapabilities
    {
        return $this->runtimeManager->capabilities();
    }

    /**
     * Return the active runtime driver.
     *
     * @return \PHAPI\Runtime\RuntimeInterface
     */
    public function runtime(): \PHAPI\Runtime\RuntimeInterface
    {
        return $this->runtimeManager->driver();
    }

    /**
     * Register a background process factory (Swoole only).
     *
     * @param callable(): mixed $factory
     * @param (callable(\Swoole\Process): void)|null $onStart
     * @param int $workerId
     * @return self
     *
     * @throws FeatureNotSupportedException
     */
    public function spawnProcess(callable $factory, ?callable $onStart = null, int $workerId = 0): self
    {
        $driver = $this->runtimeManager->driver();
        if (!$driver instanceof SwooleDriver) {
            throw new FeatureNotSupportedException('Background processes are supported only in Swoole runtime.');
        }

        $driver->spawnProcess($factory, $onStart, $workerId);
        return $this;
    }

    /**
     * Return the effective runtime name.
     *
     * @return string
     */
    public function runtimeName(): string
    {
        return $this->runtimeManager->driver()->name();
    }

    /**
     * Access the DI container.
     *
     * @return Container
     */
    public function container(): Container
    {
        return $this->container;
    }

    /**
     * Access the HTTP kernel for in-memory testing.
     *
     * @return HttpKernel
     */
    public function kernel(): HttpKernel
    {
        return $this->kernel;
    }

    /**
     * Register a lightweight extension backed by the container.
     *
     * @param string $id
     * @param callable(Container): mixed $factory
     * @param bool $singleton
     * @return self
     */
    public function extend(string $id, callable $factory, bool $singleton = true): self
    {
        if ($singleton) {
            $this->container->singleton($id, $factory);
        } else {
            $this->container->bind($id, $factory, false);
        }

        return $this;
    }

    /**
     * Resolve an entry from the container.
     *
     * @param string $id
     * @return mixed
     */
    public function resolve(string $id)
    {
        return $this->container->get($id);
    }

    /**
     * Start the configured runtime server.
     *
     * @return void
     */
    public function run(): void
    {
        $runMode = getenv('PHAPI_RUN_MODE');
        if ($runMode === 'jobs') {
            return;
        }
        $driver = $this->runtimeManager->driver();
        $this->jobsScheduler->registerSwooleJobs(
            $this->jobs,
            $driver instanceof SwooleDriver ? $driver : null,
            function (callable $handler): array {
                return $this->executeJobHandler($handler);
            }
        );
        $driver->start($this->kernel);
    }

    /**
     * Get the last constructed PHAPI instance.
     *
     * @return self|null
     */
    public static function lastInstance(): ?self
    {
        return self::$lastInstance;
    }

    /**
     * Alias for lastInstance().
     *
     * @return self|null
     */
    public static function app(): ?self
    {
        return self::$lastInstance;
    }

    /**
     * Get the current request from the request context.
     *
     * @return Request|null
     */
    public static function request(): ?Request
    {
        return RequestContext::get();
    }

    /**
     * Load app bootstrap files from the given base directory.
     *
     * @param string|null $baseDir
     * @return void
     */
    public function loadApp(?string $baseDir = null): void
    {
        $baseDir = $baseDir ?? getcwd();
        $api = $this;
        $paths = [
            $baseDir . '/app/middlewares.php',
            $baseDir . '/app/routes.php',
            $baseDir . '/app/tasks.php',
            $baseDir . '/app/jobs.php',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                require $path;
            }
        }
    }

    /**
     * Group routes under a prefix.
     *
     * @param string $prefix
     * @param callable(self): void $define
     * @return void
     */
    public function group(string $prefix, callable $define): void
    {
        $this->router->pushPrefix($prefix);
        $define($this);
        $this->router->popPrefix();
    }

    /**
     * Register a GET route.
     *
     * @param string $path
     * @param mixed $handler
     * @return RouteBuilder
     */
    public function get(string $path, $handler): RouteBuilder
    {
        return $this->registerBuilder('GET', $path, $handler);
    }

    /**
     * Register a POST route.
     *
     * @param string $path
     * @param mixed $handler
     * @return RouteBuilder
     */
    public function post(string $path, $handler): RouteBuilder
    {
        return $this->registerBuilder('POST', $path, $handler);
    }

    /**
     * Register a PUT route.
     *
     * @param string $path
     * @param mixed $handler
     * @return RouteBuilder
     */
    public function put(string $path, $handler): RouteBuilder
    {
        return $this->registerBuilder('PUT', $path, $handler);
    }

    /**
     * Register a PATCH route.
     *
     * @param string $path
     * @param mixed $handler
     * @return RouteBuilder
     */
    public function patch(string $path, $handler): RouteBuilder
    {
        return $this->registerBuilder('PATCH', $path, $handler);
    }

    /**
     * Register a DELETE route.
     *
     * @param string $path
     * @param mixed $handler
     * @return RouteBuilder
     */
    public function delete(string $path, $handler): RouteBuilder
    {
        return $this->registerBuilder('DELETE', $path, $handler);
    }

    /**
     * Register an OPTIONS route.
     *
     * @param string $path
     * @param mixed $handler
     * @return RouteBuilder
     */
    public function options(string $path, $handler): RouteBuilder
    {
        return $this->registerBuilder('OPTIONS', $path, $handler);
    }

    /**
     * Register a route directly with the router.
     *
     * @param string $method
     * @param string $path
     * @param mixed $handler
     * @param array<int, array<string, mixed>> $middleware
     * @param array<string, string>|null $validationRules
     * @param string $validationType
     * @param string|null $name
     * @param mixed $host
     * @return int Route index.
     */
    public function registerRoute(
        string $method,
        string $path,
        $handler,
        array $middleware = [],
        ?array $validationRules = null,
        string $validationType = 'body',
        ?string $name = null,
        $host = null
    ): int {
        return $this->router->addRoute($method, $path, $handler, $middleware, $validationRules, $validationType, $name, $host);
    }

    /**
     * Update a registered route by index.
     *
     * @param int $index
     * @param array<string, mixed> $route
     * @return void
     */
    public function updateRoute(int $index, array $route): void
    {
        $this->router->updateRoute($index, $route);
    }

    /**
     * Register global middleware or return a route builder for named middleware.
     *
     * @param mixed $handler
     * @return self|RouteBuilder
     *
     * @throws \InvalidArgumentException
     */
    public function middleware($handler)
    {
        if (is_string($handler) && class_exists($handler)) {
            $this->middleware->addGlobalMiddleware($this->classMiddleware($handler));
            return $this;
        }

        if (is_string($handler)) {
            return $this->createRouteBuilderWithMiddleware($handler);
        }

        if (is_callable($handler)) {
            $this->middleware->addGlobalMiddleware($handler);
            return $this;
        }

        throw new \InvalidArgumentException('middleware() expects a callable (global middleware) or string (named middleware)');
    }

    /**
     * Build a middleware callable from an invokable class.
     *
     * @param class-string $class
     * @return callable(Request): mixed|callable(Request, callable(Request): \PHAPI\HTTP\Response): mixed
     */
    public function classMiddleware(string $class): callable
    {
        return function (Request $request, callable $next) use ($class) {
            $instance = $this->container->get($class);
            if (!is_callable($instance)) {
                throw new \RuntimeException("Middleware class '{$class}' is not invokable.");
            }
            $callable = \Closure::fromCallable($instance);
            $paramCount = (new \ReflectionFunction($callable))->getNumberOfParameters();
            if ($paramCount <= 1) {
                return $instance($request);
            }
            return $instance($request, $next);
        };
    }

    /**
     * Register after-middleware to run after the handler.
     *
     * @param callable(Request, \PHAPI\HTTP\Response): (\PHAPI\HTTP\Response|void) $handler
     * @return self
     */
    public function afterMiddleware(callable $handler): self
    {
        $this->middleware->addAfterMiddleware($handler);
        return $this;
    }

    /**
     * Register a named middleware handler.
     *
     * @param string $name
     * @param callable(\PHAPI\HTTP\Request, callable(\PHAPI\HTTP\Request): \PHAPI\HTTP\Response, array<string, mixed>=): mixed $handler
     * @return self
     */
    public function addMiddleware(string $name, callable $handler): self
    {
        $this->middleware->registerNamed($name, $handler);
        return $this;
    }

    /**
     * Enable CORS handling using a global middleware.
     *
     * @param mixed $origins
     * @param array<int, string> $methods
     * @param array<int, string> $headers
     * @param bool $credentials
     * @param int $maxAge
     * @return self
     */
    public function enableCORS(
        $origins = '*',
        array $methods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        array $headers = ['Content-Type'],
        bool $credentials = false,
        int $maxAge = 3600
    ): self {
        $this->middleware->addGlobalMiddleware(function ($request, $next) use ($origins, $methods, $headers, $credentials, $maxAge) {
            $origin = $request->header('origin');
            $allowedOrigin = $this->resolveOrigin($origins, $origin, $credentials);

            if ($request->method() === 'OPTIONS') {
                return \PHAPI\HTTP\Response::empty(204)
                    ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
                    ->withHeader('Access-Control-Allow-Methods', implode(', ', $methods))
                    ->withHeader('Access-Control-Allow-Headers', implode(', ', $headers))
                    ->withHeader('Access-Control-Max-Age', (string)$maxAge)
                    ->withHeader('Access-Control-Allow-Credentials', $credentials ? 'true' : 'false');
            }

            $response = $next($request);
            return $response
                ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
                ->withHeader('Access-Control-Allow-Methods', implode(', ', $methods))
                ->withHeader('Access-Control-Allow-Headers', implode(', ', $headers))
                ->withHeader('Access-Control-Max-Age', (string)$maxAge)
                ->withHeader('Access-Control-Allow-Credentials', $credentials ? 'true' : 'false');
        });

        return $this;
    }

    /**
     * Get the task runner service.
     *
     * @return TaskRunner
     */
    public function tasks(): TaskRunner
    {
        return $this->container->get(TaskRunner::class);
    }

    /**
     * Schedule a recurring job.
     *
     * @param string $name
     * @param int $intervalSeconds
     * @param callable(mixed ...$args): mixed $handler
     * @param array<string, mixed> $options
     * @return self
     */
    public function schedule(string $name, int $intervalSeconds, callable $handler, array $options = []): self
    {
        $this->jobs->register($name, $intervalSeconds, $handler, $options);
        return $this;
    }

    /**
     * Run any due jobs and return their results.
     *
     * @return array<int, array<string, mixed>>
     */
    public function runJobs(): array
    {
        return $this->jobs->runDue(function (callable $handler, string $name) {
            return $this->executeJobHandler($handler);
        });
    }

    /**
     * Return job logs, optionally filtered by job name.
     *
     * @param string|null $name
     * @return array<int, array<string, mixed>>
     */
    public function jobLogs(?string $name = null): array
    {
        return $this->jobs->logs($name);
    }

    /**
     * Access the auth manager.
     *
     * @return AuthManager
     */
    public function auth(): AuthManager
    {
        return $this->auth;
    }

    /**
     * Return auth-required middleware for the given guard.
     *
     * @param string|null $guard
     * @return callable(\PHAPI\HTTP\Request, callable(\PHAPI\HTTP\Request): \PHAPI\HTTP\Response): \PHAPI\HTTP\Response
     */
    public function requireAuth(?string $guard = null): callable
    {
        return AuthMiddleware::require($this->auth, $guard);
    }

    /**
     * Return role-required middleware for the given guard.
     *
     * @param string|array<int, string> $roles
     * @param string|null $guard
     * @return callable(\PHAPI\HTTP\Request, callable(\PHAPI\HTTP\Request): \PHAPI\HTTP\Response): \PHAPI\HTTP\Response
     */
    public function requireRole($roles, ?string $guard = null): callable
    {
        return AuthMiddleware::requireRole($this->auth, $roles, $guard);
    }

    /**
     * Return middleware requiring all roles.
     *
     * @param array<int, string> $roles
     * @param string|null $guard
     * @return callable(\PHAPI\HTTP\Request, callable(\PHAPI\HTTP\Request): \PHAPI\HTTP\Response): \PHAPI\HTTP\Response
     */
    public function requireAllRoles(array $roles, ?string $guard = null): callable
    {
        return AuthMiddleware::requireAllRoles($this->auth, $roles, $guard);
    }

    /**
     * Enable default security headers with optional overrides.
     *
     * @param array<string, string> $headers
     * @return self
     */
    public function enableSecurityHeaders(array $headers = []): self
    {
        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'X-XSS-Protection' => '0',
        ];

        $final = array_merge($defaults, $headers);

        $this->middleware->addGlobalMiddleware(function ($request, $next) use ($final) {
            $response = $next($request);
            foreach ($final as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
            return $response;
        });

        return $this;
    }

    /**
     * Get the HTTP client service.
     *
     * @return HttpClient
     */
    public function http(): HttpClient
    {
        return $this->container->get(HttpClient::class);
    }

    /**
     * Get the Swoole coroutine Redis client.
     *
     * @return SwooleRedisClient
     */
    public function redis(): SwooleRedisClient
    {
        if ($this->redisClient === null) {
            $config = $this->config['redis'] ?? [];
            $this->redisClient = new SwooleRedisClient([
                'host' => (string)($config['host'] ?? '127.0.0.1'),
                'port' => (int)($config['port'] ?? 6379),
                'auth' => isset($config['auth']) && $config['auth'] !== '' ? (string)$config['auth'] : null,
                'db' => isset($config['db']) ? (int)$config['db'] : null,
                'timeout' => isset($config['timeout']) ? (float)$config['timeout'] : 1.0,
            ]);
        }

        return $this->redisClient;
    }

    /**
     * Get the Swoole coroutine MySQL client.
     *
     * @return SwooleMySqlClient
     */
    public function mysql(): SwooleMySqlClient
    {
        if ($this->mysqlClient === null) {
            $config = $this->config['mysql'] ?? [];
            $this->mysqlClient = new SwooleMySqlClient([
                'host' => (string)($config['host'] ?? '127.0.0.1'),
                'port' => (int)($config['port'] ?? 3306),
                'user' => (string)($config['user'] ?? 'root'),
                'password' => (string)($config['password'] ?? ''),
                'database' => (string)($config['database'] ?? ''),
                'charset' => (string)($config['charset'] ?? 'utf8mb4'),
                'timeout' => isset($config['timeout']) ? (float)$config['timeout'] : 1.0,
            ]);
        }

        return $this->mysqlClient;
    }

    /**
     * Generate a URL for a named route.
     *
     * @param string $name
     * @param array<string, string> $params
     * @param array<string, string> $query
     * @return string
     */
    public function url(string $name, array $params = [], array $query = []): string
    {
        return $this->router->urlFor($name, $params, $query);
    }

    /**
     * Get the realtime service.
     *
     * @return Realtime
     */
    public function realtime(): Realtime
    {
        return $this->container->get(Realtime::class);
    }

    /**
     * @param string $method
     * @param string $path
     * @param mixed $handler
     * @return RouteBuilder
     */
    private function registerBuilder(string $method, string $path, $handler): RouteBuilder
    {
        $builder = new RouteBuilder($this, $method, $path, $handler);
        $builder->register();
        return $builder;
    }

    private function createRouteBuilderWithMiddleware(string $middlewareName): RouteBuilder
    {
        return new class ($this, $middlewareName) extends RouteBuilder {
            private string $preMiddleware;

            /**
             * Create a middleware-prefixed route builder.
             *
             * @param PHAPI $api
             * @param string $middleware
             * @return void
             */
            public function __construct(PHAPI $api, string $middleware)
            {
                $this->preMiddleware = $middleware;
                parent::__construct($api, '', '', function () {
                });
            }

            /**
             * Register a GET route with the predefined middleware.
             *
             * @param string $path
             * @param mixed $handler
             * @return RouteBuilder
             */
            public function get(string $path, $handler): RouteBuilder
            {
                return parent::get($path, $handler)->middleware($this->preMiddleware);
            }

            /**
             * Register a POST route with the predefined middleware.
             *
             * @param string $path
             * @param mixed $handler
             * @return RouteBuilder
             */
            public function post(string $path, $handler): RouteBuilder
            {
                return parent::post($path, $handler)->middleware($this->preMiddleware);
            }

            /**
             * Register a PUT route with the predefined middleware.
             *
             * @param string $path
             * @param mixed $handler
             * @return RouteBuilder
             */
            public function put(string $path, $handler): RouteBuilder
            {
                return parent::put($path, $handler)->middleware($this->preMiddleware);
            }

            /**
             * Register a PATCH route with the predefined middleware.
             *
             * @param string $path
             * @param mixed $handler
             * @return RouteBuilder
             */
            public function patch(string $path, $handler): RouteBuilder
            {
                return parent::patch($path, $handler)->middleware($this->preMiddleware);
            }

            /**
             * Register a DELETE route with the predefined middleware.
             *
             * @param string $path
             * @param mixed $handler
             * @return RouteBuilder
             */
            public function delete(string $path, $handler): RouteBuilder
            {
                return parent::delete($path, $handler)->middleware($this->preMiddleware);
            }
        };
    }

    /**
     * @param array<int, string>|string $origins
     * @param string|null $requestOrigin
     * @param bool $credentials
     * @return string
     */
    private function resolveOrigin($origins, ?string $requestOrigin, bool $credentials): string
    {
        if ($origins === '*') {
            return ($credentials && $requestOrigin !== null && $requestOrigin !== '') ? $requestOrigin : '*';
        }

        if (is_array($origins)) {
            if ($requestOrigin !== null && in_array($requestOrigin, $origins, true)) {
                return $requestOrigin;
            }
            return $origins[0] ?? '*';
        }

        return $origins;
    }

    /**
     * @param callable(mixed ...$args): mixed $handler
     * @return array{result: mixed, output: string}
     */
    private function executeJobHandler(callable $handler): array
    {
        $ref = new \ReflectionFunction(\Closure::fromCallable($handler));
        $params = [];

        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if ($typeName === Container::class) {
                    $params[] = $this->container;
                    continue;
                }
                if ($typeName === self::class) {
                    $params[] = $this;
                    continue;
                }
                $params[] = $this->container->get($typeName);
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $params[] = $param->getDefaultValue();
                continue;
            }
        }

        ob_start();
        $result = $handler(...$params);
        $output = ob_get_clean();

        return [
            'result' => $result,
            'output' => $output === false ? '' : $output,
        ];
    }

    private function resolveTaskRunner(): TaskRunner
    {
        $driver = $this->runtimeManager->driver();
        if ($driver instanceof SwooleDriver) {
            $timeout = $this->config['task_timeout'] ?? null;
            $timeoutValue = $timeout === null ? null : (float)$timeout;
            return new SwooleTaskRunner($timeoutValue);
        }
        throw new FeatureNotSupportedException('Task runner requires Swoole runtime.');
    }

    private function resolveHttpClient(): HttpClient
    {
        $driver = $this->runtimeManager->driver();
        if ($driver instanceof SwooleDriver) {
            return new SwooleHttpClient();
        }
        throw new FeatureNotSupportedException('HTTP client requires Swoole runtime.');
    }

}
