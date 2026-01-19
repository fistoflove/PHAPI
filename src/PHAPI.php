<?php

namespace PHAPI;

use PHAPI\Core\Container;
use PHAPI\Auth\AuthManager;
use PHAPI\Auth\AuthMiddleware;
use PHAPI\Auth\SessionGuard;
use PHAPI\Auth\TokenGuard;
use PHAPI\HTTP\RouteBuilder;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\RequestContext;
use PHAPI\Runtime\DriverCapabilities;
use PHAPI\Runtime\HttpRuntimeDriver;
use PHAPI\Runtime\RuntimeSelector;
use PHAPI\Runtime\SwooleDriver;
use PHAPI\Server\ErrorHandler;
use PHAPI\Server\HttpKernel;
use PHAPI\Server\MiddlewareManager;
use PHAPI\Server\Router;
use PHAPI\Services\AmpHttpClient;
use PHAPI\Services\AmpTaskRunner;
use PHAPI\Services\BlockingHttpClient;
use PHAPI\Services\FallbackRealtime;
use PHAPI\Services\HttpClient;
use PHAPI\Services\JobsManager;
use PHAPI\Services\Realtime;
use PHAPI\Services\RealtimeManager;
use PHAPI\Services\SequentialTaskRunner;
use PHAPI\Services\SwooleHttpClient;
use PHAPI\Services\SwooleTaskRunner;
use PHAPI\Services\TaskRunner;

final class PHAPI
{
    private static ?PHAPI $lastInstance = null;
    private array $config;
    private Router $router;
    private MiddlewareManager $middleware;
    private ErrorHandler $errorHandler;
    private Container $container;
    private HttpKernel $kernel;
    private HttpRuntimeDriver $driver;
    private DriverCapabilities $capabilities;
    private JobsManager $jobs;
    private AuthManager $auth;
    private $realtimeFallback = null;

    public function __construct(array $config = [])
    {
        self::$lastInstance = $this;
        $this->config = array_merge([
            'runtime' => getenv('APP_RUNTIME') ?: 'fpm',
            'debug' => (bool)(getenv('APP_DEBUG') ?: false),
            'host' => '0.0.0.0',
            'port' => 9501,
            'enable_websockets' => false,
        ], $config);

        $this->router = new Router();
        $this->middleware = new MiddlewareManager();
        $this->errorHandler = new ErrorHandler($this->config['debug']);
        $this->container = new Container();
        $this->kernel = new HttpKernel(
            $this->router,
            $this->middleware,
            $this->errorHandler,
            $this->container,
            $this->config['access_logger'] ?? null
        );
        $logDir = $this->config['jobs_log_dir'] ?? (getcwd() . '/var/jobs');
        $logLimit = (int)($this->config['jobs_log_limit'] ?? 200);
        $rotateBytes = (int)($this->config['jobs_log_rotate_bytes'] ?? 1048576);
        $rotateKeep = (int)($this->config['jobs_log_rotate_keep'] ?? 5);
        $this->jobs = new JobsManager($logDir, $logLimit, $rotateBytes, $rotateKeep);
        $this->auth = $this->configureAuth();

        $this->driver = RuntimeSelector::select($this->config);
        $this->capabilities = $this->driver->capabilities();

        $this->registerCoreServices();
        $this->registerSafetyMiddleware();
    }

    public function setDebug(bool $debug): self
    {
        $this->config['debug'] = $debug;
        $this->errorHandler->setDebug($debug);
        $this->registerCoreServices();
        return $this;
    }

    public function setRuntime(string $runtime): self
    {
        $this->config['runtime'] = $runtime;
        $this->driver = RuntimeSelector::select($this->config);
        $this->capabilities = $this->driver->capabilities();
        $this->registerCoreServices();
        return $this;
    }

    public function setRealtimeFallback(callable $fallback): self
    {
        $this->realtimeFallback = $fallback;
        $this->registerCoreServices();
        return $this;
    }

    public function capabilities(): DriverCapabilities
    {
        return $this->capabilities;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function run(): void
    {
        if (getenv('PHAPI_RUN_MODE') === 'jobs') {
            return;
        }
        if ($this->driver instanceof SwooleDriver) {
            $this->registerSwooleJobs();
        }
        $this->driver->start($this->kernel);
    }

    public static function lastInstance(): ?self
    {
        return self::$lastInstance;
    }

    public static function app(): ?self
    {
        return self::$lastInstance;
    }

    public static function request(): ?Request
    {
        return RequestContext::get();
    }

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

    public function group(string $prefix, callable $define): void
    {
        $this->router->pushPrefix($prefix);
        $define($this);
        $this->router->popPrefix();
    }

    public function get(string $path, $handler): RouteBuilder
    {
        return $this->registerBuilder('GET', $path, $handler);
    }

    public function post(string $path, $handler): RouteBuilder
    {
        return $this->registerBuilder('POST', $path, $handler);
    }

    public function put(string $path, $handler): RouteBuilder
    {
        return $this->registerBuilder('PUT', $path, $handler);
    }

    public function patch(string $path, $handler): RouteBuilder
    {
        return $this->registerBuilder('PATCH', $path, $handler);
    }

    public function delete(string $path, $handler): RouteBuilder
    {
        return $this->registerBuilder('DELETE', $path, $handler);
    }

    public function options(string $path, $handler): RouteBuilder
    {
        return $this->registerBuilder('OPTIONS', $path, $handler);
    }

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

    public function updateRoute(int $index, array $route): void
    {
        $this->router->updateRoute($index, $route);
    }

    public function middleware($handler)
    {
        if (is_string($handler)) {
            return $this->createRouteBuilderWithMiddleware($handler);
        }

        if (is_callable($handler)) {
            $this->middleware->addGlobalMiddleware($handler);
            return $this;
        }

        throw new \InvalidArgumentException('middleware() expects a callable (global middleware) or string (named middleware)');
    }

    public function afterMiddleware(callable $handler): self
    {
        $this->middleware->addAfterMiddleware($handler);
        return $this;
    }

    public function addMiddleware(string $name, callable $handler): self
    {
        $this->middleware->registerNamed($name, $handler);
        return $this;
    }

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

    public function tasks(): TaskRunner
    {
        return $this->container->get(TaskRunner::class);
    }

    public function schedule(string $name, int $intervalSeconds, callable $handler, array $options = []): self
    {
        $this->jobs->register($name, $intervalSeconds, $handler, $options);
        return $this;
    }

    public function runJobs(): array
    {
        return $this->jobs->runDue(function (callable $handler, string $name) {
            return $this->executeJobHandler($handler);
        });
    }

    public function jobLogs(?string $name = null): array
    {
        return $this->jobs->logs($name);
    }

    public function auth(): AuthManager
    {
        return $this->auth;
    }

    public function requireAuth(?string $guard = null): callable
    {
        return AuthMiddleware::require($this->auth, $guard);
    }

    public function requireRole($roles, ?string $guard = null): callable
    {
        return AuthMiddleware::requireRole($this->auth, $roles, $guard);
    }

    public function requireAllRoles(array $roles, ?string $guard = null): callable
    {
        return AuthMiddleware::requireAllRoles($this->auth, $roles, $guard);
    }

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

    public function http(): HttpClient
    {
        return $this->container->get(HttpClient::class);
    }

    public function url(string $name, array $params = [], array $query = []): string
    {
        return $this->router->urlFor($name, $params, $query);
    }

    public function realtime(): Realtime
    {
        return $this->container->get(Realtime::class);
    }

    private function registerBuilder(string $method, string $path, $handler): RouteBuilder
    {
        $builder = new RouteBuilder($this, $method, $path, $handler);
        $builder->register();
        return $builder;
    }

    private function createRouteBuilderWithMiddleware(string $middlewareName): RouteBuilder
    {
        return new class($this, $middlewareName) extends RouteBuilder {
            private PHAPI $apiInstance;
            private string $preMiddleware;

            public function __construct(PHAPI $api, string $middleware)
            {
                $this->apiInstance = $api;
                $this->preMiddleware = $middleware;
                parent::__construct($api, '', '', function () {
                });
            }

            public function get(string $path, $handler): RouteBuilder
            {
                return parent::get($path, $handler)->middleware($this->preMiddleware);
            }

            public function post(string $path, $handler): RouteBuilder
            {
                return parent::post($path, $handler)->middleware($this->preMiddleware);
            }

            public function put(string $path, $handler): RouteBuilder
            {
                return parent::put($path, $handler)->middleware($this->preMiddleware);
            }

            public function patch(string $path, $handler): RouteBuilder
            {
                return parent::patch($path, $handler)->middleware($this->preMiddleware);
            }

            public function delete(string $path, $handler): RouteBuilder
            {
                return parent::delete($path, $handler)->middleware($this->preMiddleware);
            }
        };
    }

    private function resolveOrigin($origins, ?string $requestOrigin, bool $credentials): string
    {
        if ($origins === '*') {
            return $credentials && $requestOrigin ? $requestOrigin : '*';
        }

        if (is_array($origins)) {
            if ($requestOrigin !== null && in_array($requestOrigin, $origins, true)) {
                return $requestOrigin;
            }
            return $origins[0] ?? '*';
        }

        return (string)$origins;
    }

    private function registerCoreServices(): void
    {
        $this->container->set(self::class, $this);
        $this->container->set(TaskRunner::class, $this->resolveTaskRunner());
        $this->container->set(HttpClient::class, $this->resolveHttpClient());
        $this->container->set(AuthManager::class, $this->auth);
        $this->container->set('auth', $this->auth);

        $this->middleware->registerNamed('auth', AuthMiddleware::require($this->auth));
        $this->middleware->registerNamed('role', function ($request, $next, array $args = []) {
            if (empty($args)) {
                return $next($request);
            }
            return AuthMiddleware::requireRole($this->auth, $args)($request, $next);
        });

        $this->middleware->registerNamed('role_all', function ($request, $next, array $args = []) {
            if (empty($args)) {
                return $next($request);
            }
            return AuthMiddleware::requireAllRoles($this->auth, $args)($request, $next);
        });

        $fallback = new FallbackRealtime($this->config['debug'], $this->realtimeFallback);
        $this->container->set(Realtime::class, new RealtimeManager(
            $this->capabilities,
            $this->driver instanceof SwooleDriver ? $this->driver : null,
            $fallback
        ));
    }

    private function registerSafetyMiddleware(): void
    {
        $maxBody = $this->config['max_body_bytes'] ?? null;
        if ($maxBody !== null) {
            $limit = (int)$maxBody;
            $this->middleware->addGlobalMiddleware(function ($request, $next) use ($limit) {
                $length = $request->contentLength();
                if ($length !== null && $length > $limit) {
                    return \PHAPI\HTTP\Response::error('Payload too large', 413, [
                        'max_bytes' => $limit,
                        'received_bytes' => $length,
                    ]);
                }
                return $next($request);
            });
        }
    }

    private function registerSwooleJobs(): void
    {
        $jobs = $this->jobs->jobs();
        if (empty($jobs)) {
            return;
        }

        $self = $this;
        $this->driver->onWorkerStart(function ($server, int $workerId) use ($jobs, $self) {
            if ($workerId !== 0) {
                return;
            }

            foreach ($jobs as $name => $job) {
                $intervalMs = (int)$job['interval'] * 1000;
                \Swoole\Timer::tick($intervalMs, function () use ($job, $self, $name) {
                    $self->jobs->runScheduled($name, function (callable $handler, string $jobName) use ($self) {
                        return $self->executeJobHandler($handler);
                    });
                });
            }
        });
    }

    private function executeJobHandler(callable $handler): array
    {
        $ref = new \ReflectionFunction(\Closure::fromCallable($handler));
        $params = [];

        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();
            if ($type !== null && !$type->isBuiltin()) {
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
        if ($this->driver instanceof SwooleDriver) {
            return new SwooleTaskRunner();
        }

        if ($this->capabilities->supportsAsyncIo()) {
            return new AmpTaskRunner();
        }

        return new SequentialTaskRunner();
    }

    private function resolveHttpClient(): HttpClient
    {
        if ($this->driver instanceof SwooleDriver) {
            return new SwooleHttpClient();
        }

        if ($this->capabilities->supportsAsyncIo()) {
            return new AmpHttpClient();
        }

        return new BlockingHttpClient();
    }

    private function configureAuth(): AuthManager
    {
        $authConfig = $this->config['auth'] ?? [];
        $default = $authConfig['default'] ?? 'token';
        $manager = new AuthManager($default);

        $tokenResolver = $authConfig['token_resolver'] ?? function () {
            return null;
        };
        $sessionKey = $authConfig['session_key'] ?? 'user';
        $sessionAllowInSwoole = (bool)($authConfig['session_allow_in_swoole'] ?? false);

        $manager->addGuard('token', new TokenGuard($tokenResolver));
        $manager->addGuard('session', new SessionGuard($sessionKey, $sessionAllowInSwoole));

        return $manager;
    }
}
