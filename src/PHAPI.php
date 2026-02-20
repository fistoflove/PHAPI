<?php

declare(strict_types=1);

namespace PHAPI;

use PHAPI\Auth\AuthManager;
use PHAPI\Core\AppBootstrapper;
use PHAPI\Core\AuthConfigurator;
use PHAPI\Core\ConfigLoader;
use PHAPI\Core\Container;
use PHAPI\Core\DefaultEndpoints;
use PHAPI\Core\HttpKernelFactory;
use PHAPI\Core\JobsScheduler;
use PHAPI\Core\PHAPIBuilder;
use PHAPI\Core\ProviderLoader;
use PHAPI\Core\RuntimeManager;
use PHAPI\Core\ServiceAccessor;
use PHAPI\Runtime\SwooleDriver;
use PHAPI\Server\ErrorHandler;
use PHAPI\Server\HttpKernel;
use PHAPI\Server\MiddlewareManager;
use PHAPI\Server\Router;
use PHAPI\Services\HttpClient;
use PHAPI\Services\JobsManager;
use PHAPI\Services\DefaultHttpClient;
use PHAPI\Services\DefaultTaskRunner;
use PHAPI\Services\TaskRunner;

final class PHAPI
{
    use Concerns\RoutesRequests;
    use Concerns\ManagesMiddleware;
    use Concerns\ManagesRuntime;
    use Concerns\SchedulesJobs;

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
    private ServiceAccessor $serviceAccessor;
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
     * Create a new PHAPIBuilder for fluent, validated configuration.
     */
    public static function builder(): PHAPIBuilder
    {
        return new PHAPIBuilder();
    }

    /**
     * Create a new PHAPI instance with configuration overrides.
     *
     * @internal Prefer PHAPI::builder() for new code.
     * @param array<string, mixed> $config
     * @return void
     */
    public function __construct(array $config = [])
    {
        $this->configLoader = new ConfigLoader();
        $this->config = $this->configLoader->load($config);

        $this->kernelFactory = new HttpKernelFactory();
        $kernelComponents = $this->kernelFactory->build($this->config);
        $this->router = $kernelComponents['router'];
        $this->middleware = $kernelComponents['middleware'];
        $this->errorHandler = $kernelComponents['errorHandler'];
        $this->kernel = $kernelComponents['kernel'];
        $this->container = $this->kernel->container();
        $this->serviceAccessor = new ServiceAccessor($this->container, $this->config);
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
        $this->providers = $this->providerLoader->register($this->config['providers'] ?? [], $this->container, $this->config);
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
     * Access the error handler for custom exception handling.
     *
     * @return ErrorHandler
     */
    public function errorHandler(): ErrorHandler
    {
        return $this->errorHandler;
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
     * Access the auth manager.
     *
     * @return AuthManager
     */
    public function auth(): AuthManager
    {
        return $this->auth;
    }

    /**
     * Access the ServiceAccessor for typed service resolution.
     *
     * @return ServiceAccessor
     */
    public function services(): ServiceAccessor
    {
        return $this->serviceAccessor;
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
