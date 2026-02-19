<?php

declare(strict_types=1);

namespace PHAPI;

use PHAPI\Auth\AuthManager;
use PHAPI\Contracts\DatabaseInterface;
use PHAPI\Core\AppBootstrapper;
use PHAPI\Core\AuthConfigurator;
use PHAPI\Core\ConfigLoader;
use PHAPI\Core\Container;
use PHAPI\Core\DefaultEndpoints;
use PHAPI\Core\HttpKernelFactory;
use PHAPI\Core\JobsScheduler;
use PHAPI\Core\ProviderLoader;
use PHAPI\Core\RuntimeManager;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\RequestContext;
use PHAPI\Runtime\SwooleDriver;
use PHAPI\Server\ErrorHandler;
use PHAPI\Server\HttpKernel;
use PHAPI\Server\MiddlewareManager;
use PHAPI\Server\Router;
use PHAPI\Services\HttpClient;
use PHAPI\Services\JobsManager;
use PHAPI\Services\MySqlPool;
use PHAPI\Services\DefaultHttpClient;
use PHAPI\Services\DefaultTaskRunner;
use PHAPI\Services\OpenFgaClient;
use PHAPI\Services\OpenFgaHttpClient;
use PHAPI\Services\RedisClient;
use PHAPI\Services\Realtime;
use PHAPI\Services\TaskRunner;

final class PHAPI
{
    use Concerns\RoutesRequests;
    use Concerns\ManagesMiddleware;
    use Concerns\ManagesRuntime;
    use Concerns\SchedulesJobs;

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
    private ?RedisClient $redisClient = null;
    private ?MySqlPool $mysqlPool = null;
    private ?OpenFgaClient $openFgaClient = null;
    /**
     * @var array<int, \PHAPI\Core\ServiceProviderInterface>
     */
    private array $providers = [];
    /**
     * @var callable(\Swoole\WebSocket\Server, mixed, SwooleDriver): void|null
     */
    private $webSocketHandler = null;
    private bool $deferGroupPop = false;
    private int $deferredGroupCount = 0;
    /**
     * @var array<int, int>
     */
    private array $deferredGroupMarkers = [];
    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $groupMiddlewareStack = [[]];

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
            $this->swooleDriver(),
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
            $this->swooleDriver(),
            $this->webSocketHandler
        );
        return $this;
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
     * Access the loaded configuration.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
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
        $this->enableCoroutineHooks();
        $driver = $this->swooleDriver();
        $this->jobsScheduler->registerSwooleJobs(
            $this->jobs,
            $driver,
            function (callable $handler): array {
                return $this->executeJobHandler($handler);
            }
        );
        $driver->start($this->kernel);
    }

    private function enableCoroutineHooks(): void
    {
        if (!class_exists('Swoole\\Runtime')) {
            return;
        }

        $enabled = $this->config['enable_coroutine_hooks'] ?? true;
        if ($enabled === false) {
            return;
        }

        $flags = defined('SWOOLE_HOOK_ALL') ? SWOOLE_HOOK_ALL : 0;

        try {
            \Swoole\Runtime::enableCoroutine($flags);
        } catch (\Throwable $e) {
            error_log('PHAPI: failed to enable coroutine hooks: ' . $e->getMessage());
        }
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
     * Get the task runner service.
     *
     * @return TaskRunner
     */
    public function tasks(): TaskRunner
    {
        return $this->container->get(TaskRunner::class);
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
     * Get the HTTP client service.
     *
     * @return HttpClient
     */
    public function http(): HttpClient
    {
        return $this->container->get(HttpClient::class);
    }

    /**
     * Get the Redis client service.
     *
     * @return RedisClient
     */
    public function redis(): RedisClient
    {
        if ($this->redisClient === null) {
            $config = $this->config['redis'] ?? [];
            $this->redisClient = new RedisClient([
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
     * Get the OpenFGA authorization client.
     *
     * @return OpenFgaClient
     */
    public function openfga(): OpenFgaClient
    {
        if ($this->openFgaClient === null) {
            $config = $this->config['openfga'] ?? [];
            $this->openFgaClient = new OpenFgaHttpClient($config, $this->http());
        }

        return $this->openFgaClient;
    }

    /**
     * Get the ORM database service.
     *
     * @return DatabaseInterface
     */
    public function database(): DatabaseInterface
    {
        return $this->container->get(DatabaseInterface::class);
    }

    /**
     * Access the PHAPI database service, if registered.
     *
     * @return DatabaseInterface|null
     */
    public static function db(): ?DatabaseInterface
    {
        return static::app()?->database();
    }

    /**
     * Get the MySQL connection pool.
     *
     * @return MySqlPool
     */
    public function mysql(): MySqlPool
    {
        if ($this->mysqlPool === null) {
            $config = $this->config['mysql'] ?? [];
            $dsnParts = self::parseMySqlDsn(isset($config['dsn']) ? (string) $config['dsn'] : '');
            $this->mysqlPool = new MySqlPool([
                'host' => (string)($dsnParts['host'] ?? $config['host'] ?? '127.0.0.1'),
                'port' => (int)($dsnParts['port'] ?? $config['port'] ?? 3306),
                'user' => (string)($config['user'] ?? 'root'),
                'password' => (string)($config['password'] ?? ''),
                'database' => (string)($dsnParts['database'] ?? $config['database'] ?? ''),
                'charset' => (string)($dsnParts['charset'] ?? $config['charset'] ?? 'utf8mb4'),
                'timeout' => isset($config['timeout']) ? (float)$config['timeout'] : 1.0,
                'pool_size' => (int)($config['pool_size'] ?? 5),
                'pool_timeout' => (float)($config['pool_timeout'] ?? 1.0),
            ]);
        }

        return $this->mysqlPool;
    }

    /**
     * @return array{host?: string, port?: int, database?: string, charset?: string}
     */
    private static function parseMySqlDsn(string $dsn): array
    {
        $dsn = trim($dsn);
        if (!str_starts_with(strtolower($dsn), 'mysql:')) {
            return [];
        }

        $parts = [];
        foreach (explode(';', substr($dsn, 6)) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $pair = explode('=', $segment, 2);
            if (count($pair) !== 2) {
                continue;
            }

            $key = strtolower(trim($pair[0]));
            $value = trim($pair[1]);
            if ($key === '') {
                continue;
            }

            $parts[$key] = $value;
        }

        $parsed = [];
        if (isset($parts['host']) && $parts['host'] !== '') {
            $parsed['host'] = $parts['host'];
        }
        if (isset($parts['port']) && is_numeric($parts['port'])) {
            $parsed['port'] = max(1, (int) $parts['port']);
        }
        if (isset($parts['dbname']) && $parts['dbname'] !== '') {
            $parsed['database'] = $parts['dbname'];
        }
        if (isset($parts['charset']) && $parts['charset'] !== '') {
            $parsed['charset'] = $parts['charset'];
        }

        return $parsed;
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



    private function resolveTaskRunner(): TaskRunner
    {
        $timeout = $this->config['task_timeout'] ?? null;
        $timeoutValue = $timeout === null ? null : (float)$timeout;
        return new DefaultTaskRunner($timeoutValue);
    }

    private function resolveHttpClient(): HttpClient
    {
        return new DefaultHttpClient();
    }

}
