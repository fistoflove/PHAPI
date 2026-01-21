<?php

namespace PHAPI;

use PHAPI\Database\AutoloadMiddleware;
use PHAPI\Database\DatabaseFacade;
use PHAPI\Exceptions\ServerNotRunningException;
use PHAPI\HTTP\RouteBuilder;
use PHAPI\Logging\Logger;
use PHAPI\Server\CORSHandler;
use PHAPI\Server\ErrorHandler;
use PHAPI\Server\JobManager;
use PHAPI\Server\MiddlewareManager;
use PHAPI\Server\PerformanceMonitor;
use PHAPI\Server\RequestHandler;
use PHAPI\Server\Router;
use PHAPI\Server\TaskManager;
use Swoole\Http\Server;

/**
 * PHAPI - A lightweight wrapper/facade for Swoole HTTP server
 * 
 * Provides a simple, expressive API for building high-performance PHP applications
 * with Swoole's coroutine support, async tasks, middleware, and request validation.
 */
final class PHAPI
{
    private string $host;
    private int $port;
    private ?Server $server = null;
    private Logger $logger;
    private bool $debug = false;
    private int $workerNum = 0; // 0 = auto (CPU count)
    private int $taskWorkerNum = 4;
    private $pendingStartCallback = null;

    private Router $router;
    private MiddlewareManager $middleware;
    private CORSHandler $cors;
    private ErrorHandler $errorHandler;
    private TaskManager $taskManager;
    private JobManager $jobManager;
    private RequestHandler $requestHandler;
    private PerformanceMonitor $performanceMonitor;

    /**
     * Create a new PHAPI instance
     *
     * @param string $host Host to bind to (default: '0.0.0.0')
     * @param int $port Port to listen on (default: 9501)
     */
    public function __construct(string $host = '0.0.0.0', int $port = 9501)
    {
        $this->host = $host;
        $this->port = $port;
        $this->logger = Logger::getInstance();

        $this->router = new Router();
        $this->middleware = new MiddlewareManager($this->logger);
        $this->cors = new CORSHandler();
        $this->errorHandler = new ErrorHandler($this->logger, $this->debug);
        $this->taskManager = new TaskManager($this->logger, $this->debug);
        $this->jobManager = new JobManager($this->logger, $this->debug);
        $this->performanceMonitor = new PerformanceMonitor($this->logger);
        $this->requestHandler = new RequestHandler(
            $this->router,
            $this->middleware,
            $this->cors,
            $this->errorHandler,
            $this->logger,
            $this->debug
        );
    }

    /**
     * Enable or disable debug mode (detailed error messages)
     *
     * @param bool $debug Enable debug mode
     * @return self
     */
    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;
        $this->errorHandler->setDebug($debug);
        $this->taskManager = new TaskManager($this->logger, $debug);
        $this->jobManager = new JobManager($this->logger, $debug);
        return $this;
    }

    /**
     * Configure Swoole workers
     * 
     * @param int $workerNum Number of HTTP workers (0 = auto/CPU count, default: 0)
     * @param int $taskWorkerNum Number of task workers (default: 4)
     * @return self
     */
    public function setWorkers(int $workerNum = 0, int $taskWorkerNum = 4): self
    {
        $this->workerNum = $workerNum;
        $this->taskWorkerNum = $taskWorkerNum;
        return $this;
    }

    /**
     * Set custom error handler
     *
     * @param callable $handler Handler receives: ($exception, $request, $response, $api)
     * @return self
     */
    public function setErrorHandler(callable $handler): self
    {
        $this->errorHandler->setCustomHandler($handler);
        return $this;
    }

    /**
     * Get the logger instance
     *
     * @return Logger
     */
    public function log(): Logger
    {
        return $this->logger;
    }

    /**
     * Configure logging with sensible defaults
     * 
     * @param string|null $logFile Path to default log file (null = uses default logs/phapi.log)
     * @param bool $stdout Output to console (default: true)
     * @param string $level Minimum log level (default: INFO)
     * @param bool $debug Enable debug mode for detailed errors (default: false)
     * @param array $channels Additional custom logging channels ['channel_name' => 'log_file_path', ...]
     * @return Logger The logger instance for convenience
     */
    public function configureLogging(
        ?string $logFile = null,
        bool $stdout = true,
        string $level = Logger::LEVEL_INFO,
        bool $debug = false,
        array $channels = []
    ): Logger {
        if ($logFile === null) {
            $defaultLog = getcwd() . '/logs/phapi.log';
            $logFile = $defaultLog;
        }
        
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $this->logger->setLogFile($logFile);
        
        $defaultChannels = [
            Logger::CHANNEL_ACCESS => $logDir . '/access.log',
            Logger::CHANNEL_ERROR => $logDir . '/error.log',
            Logger::CHANNEL_TASK => $logDir . '/task.log',
            Logger::CHANNEL_DEBUG => $logDir . '/debug.log',
            Logger::CHANNEL_SYSTEM => $logDir . '/system.log',
        ];
        
        // Add performance channel if monitoring is enabled
        if ($this->performanceMonitor->isEnabled()) {
            $defaultChannels[Logger::CHANNEL_PERFORMANCE] = $logDir . '/performance.log';
        }
        
        $this->logger->setChannels($defaultChannels);
        
        if (!empty($channels)) {
            $this->logger->setChannels($channels);
        }
        
        $this->logger->setLogFile(null);
        $this->logger->setLevel($level)
                     ->setOutputToStdout($stdout)
                     ->setDebugMode($debug);
        
        $this->setDebug($debug);
        
        return $this->logger;
    }

    /**
     * Group routes with a common prefix
     *
     * @param string $prefix Route prefix
     * @param callable $define Function to define routes within the group
     * @return void
     */
    public function group(string $prefix, callable $define): void
    {
        $this->router->pushPrefix($prefix);
        $define($this);
        $this->router->popPrefix();
    }

    /**
     * Register a GET route
     *
     * @param string $path Route path
     * @param callable $handler Route handler
     * @return \PHAPI\HTTP\RouteBuilder
     */
    public function get(string $path, callable $handler): \PHAPI\HTTP\RouteBuilder
    {
        $builder = new \PHAPI\HTTP\RouteBuilder($this, 'GET', $this->router->getFullPath($path), $handler);
        $builder->register();
        return $builder;
    }

    /**
     * Register a POST route
     *
     * @param string $path Route path
     * @param callable $handler Route handler
     * @return \PHAPI\HTTP\RouteBuilder
     */
    public function post(string $path, callable $handler): \PHAPI\HTTP\RouteBuilder
    {
        $builder = new \PHAPI\HTTP\RouteBuilder($this, 'POST', $this->router->getFullPath($path), $handler);
        $builder->register();
        return $builder;
    }

    /**
     * Register a route (called internally by RouteBuilder)
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param callable $handler Route handler
     * @param array $middleware Route middleware definitions
     * @param array|null $validationRules Validation rules
     * @param string $validationType Validation type ('body' or 'query')
     * @return void
     */
    public function registerRoute(
        string $method,
        string $path,
        callable $handler,
        array $middleware = [],
        ?array $validationRules = null,
        string $validationType = 'body'
    ): void {
        $this->router->addRoute($method, $path, $handler, $middleware, $validationRules, $validationType);
    }

    /**
     * Add global middleware or start route builder chain
     *
     * @param callable|string $handler Callable for global middleware, or string for named middleware chaining
     * @return self|RouteBuilder Returns self for global middleware, RouteBuilder for route chaining
     * @throws \InvalidArgumentException
     */
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

    /**
     * Add after middleware (runs after route handler, before response end)
     *
     * @param callable $handler Middleware handler: function($request, $response, $next)
     * @return self
     */
    public function afterMiddleware(callable $handler): self
    {
        $this->middleware->addAfterMiddleware($handler);
        return $this;
    }

    /**
     * Create a route builder with pre-configured middleware for chaining
     *
     * @param string $middlewareName Named middleware name
     * @return \PHAPI\HTTP\RouteBuilder
     */
    private function createRouteBuilderWithMiddleware(string $middlewareName): \PHAPI\HTTP\RouteBuilder
    {
        return new class($this, $middlewareName) extends \PHAPI\HTTP\RouteBuilder {
            private PHAPI $apiInstance;
            private string $preMiddleware;

            public function __construct(PHAPI $api, string $middleware)
            {
                $this->apiInstance = $api;
                $this->preMiddleware = $middleware;
                parent::__construct($api, '', '', function() {});
            }

            public function get(string $path, callable $handler): RouteBuilder
            {
                return parent::get($path, $handler)->middleware($this->preMiddleware);
    }

            public function post(string $path, callable $handler): RouteBuilder
            {
                return parent::post($path, $handler)->middleware($this->preMiddleware);
            }
        };
    }

    /**
     * Add named middleware
     *
     * @param string $name Middleware name
     * @param callable $handler Middleware handler
     * @return self
     */
    public function addMiddleware(string $name, callable $handler): self
    {
        $this->middleware->registerNamed($name, $handler);
        return $this;
    }

    /**
     * Enable CORS support
     *
     * @param array|string|null $origins Allowed origins ('*' for all, array for specific origins)
     * @param array $methods Allowed HTTP methods
     * @param array $headers Allowed headers
     * @param bool $credentials Allow credentials
     * @param int $maxAge Preflight cache time in seconds
     * @return self
     */
    public function enableCORS(
        $origins = '*',
        array $methods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        array $headers = ['Content-Type'],
        bool $credentials = false,
        int $maxAge = 3600
    ): self {
        $this->cors->enable($origins, $methods, $headers, $credentials, $maxAge);
        return $this;
    }

    /**
     * Automatically load middlewares, routes, and tasks from a directory
     *
     * @param string|null $appDir Directory containing middlewares.php, routes.php, tasks.php
     *                            Defaults to calling script's directory + '/app'
     * @return self
     * @throws \RuntimeException
     */
    public function loadApp(?string $appDir = null): self
    {
        if ($appDir === null) {
            $callerFile = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? __FILE__;
            $appDir = dirname($callerFile) . '/app';
        }

        if (!is_dir($appDir)) {
            throw new \RuntimeException("App directory not found: {$appDir}");
        }

        $files = ['middlewares.php', 'routes.php', 'tasks.php', 'jobs.php'];

        foreach ($files as $file) {
            $filePath = rtrim($appDir, '/') . '/' . $file;
            if (file_exists($filePath)) {
                $api = $this;
                require $filePath;
            }
        }

        return $this;
    }

    /**
     * Register a background task handler
     *
     * @param string $name Task name
     * @param callable $handler Task handler receives: ($data, $logger)
     * @return void
     */
    public function task(string $name, callable $handler): void
    {
        $this->taskManager->register($name, $handler);
    }

    /**
     * Dispatch a background task
     *
     * @param string $name Task name
     * @param mixed $data Task data
     * @return bool
     * @throws ServerNotRunningException
     */
    public function dispatchTask(string $name, mixed $data): bool
    {
        if (!$this->server) {
            throw new ServerNotRunningException();
        }
        
        return $this->taskManager->dispatch($this->server, $name, $data);
    }

    /**
     * Get full path with current prefix
     *
     * @param string $path Route path
     * @return string Full path with prefix
     */
    public function fullPath(string $path): string
    {
        return $this->router->getFullPath($path);
    }

    /**
     * Register a scheduled job (recurring task)
     * 
     * Jobs run automatically at specified intervals using Swoole timers.
     * The job handler receives the logger instance.
     * 
     * @param string $name Job name
     * @param int $intervalSeconds Interval in seconds (minimum: 1 second)
     * @param callable $handler Job handler receives: ($logger)
     * @return self
     */
    public function schedule(string $name, int $intervalSeconds, callable $handler): self
    {
        $this->jobManager->register($name, $intervalSeconds, $handler);
        return $this;
    }

    /**
     * Configure database connection
     * 
     * Sets up SQLite database with automatic schema initialization.
     * Database is optional - features work without it.
     * 
     * @param string $dbPath Path to SQLite database file
     * @param array $options Connection options:
     *   - readonly: bool (default: false)
     *   - wal_mode: bool (default: true)
     *   - timeout: int Milliseconds (default: 5000)
     *   - busy_timeout: int Milliseconds (default: 30000)
     *   - autoload: bool Enable autoload middleware (default: true)
     * @return self
     */
    public function configureDatabase(string $dbPath, array $options = []): self
    {
        $config = DatabaseFacade::configure($dbPath, $options);

        // Add autoload middleware if enabled
        if ($config['autoload']) {
            $this->middleware->addGlobalMiddleware(AutoloadMiddleware::create());
        }

        $this->logger->system()->info("Database configured", [
            'path' => $dbPath,
            'autoload' => $config['autoload']
        ]);

        return $this;
    }

    /**
     * Get database connection
     * 
     * Returns PDO instance if database is configured, null otherwise.
     * 
     * @return \PDO|null
     */
    public function db(): ?\PDO
    {
        return DatabaseFacade::getConnection();
    }

    /**
     * Get option value (WordPress-style)
     * 
     * Simple key-value storage for arbitrary data.
     * Returns default value if option doesn't exist.
     * 
     * @param string $key Option key
     * @param mixed $default Default value if option doesn't exist
     * @return mixed
     */
    public function option(string $key, $default = null)
    {
        return DatabaseFacade::option($key, $default);
    }

    /**
     * Set option value (WordPress-style)
     * 
     * Stores arbitrary data with a key.
     * Supports autoload and expiration options.
     * 
     * @param string $key Option key
     * @param mixed $value Option value (any type)
     * @param array $options Additional options:
     *   - autoload: bool Load into memory cache (default: false)
     *   - expires: int|string Seconds until expiration or date string
     * @return bool Success
     */
    public function setOption(string $key, $value, array $options = []): bool
    {
        return DatabaseFacade::setOption($key, $value, $options);
    }

    /**
     * Check if option exists
     * 
     * @param string $key Option key
     * @return bool
     */
    public function hasOption(string $key): bool
    {
        return DatabaseFacade::hasOption($key);
    }

    /**
     * Delete option
     * 
     * @param string $key Option key
     * @return bool Success
     */
    public function deleteOption(string $key): bool
    {
        return DatabaseFacade::deleteOption($key);
    }

    /**
     * Delete all options with given prefix
     * 
     * @param string $prefix Key prefix
     * @return int Number of deleted options
     */
    public function deleteOptionGroup(string $prefix): int
    {
        return DatabaseFacade::deleteOptionGroup($prefix);
    }

    /**
     * Get all options with given prefix
     * 
     * @param string $prefix Key prefix
     * @return array Associative array of key => value
     */
    public function getOptionGroup(string $prefix): array
    {
        return DatabaseFacade::getOptionGroup($prefix);
    }

    /**
     * Set transient (temporary option with expiration)
     * 
     * Transients are temporary key-value pairs that automatically expire.
     * Simple facade: $api->transient('cache_key', $data, 3600);
     * 
     * @param string $key Transient key
     * @param mixed $value Transient value
     * @param int $expires Seconds until expiration (default: 3600 = 1 hour)
     * @return bool Success
     */
    public function transient(string $key, $value, int $expires = 3600): bool
    {
        return DatabaseFacade::transient($key, $value, $expires);
    }

    /**
     * Get transient value
     * 
     * Returns the transient value if it exists and hasn't expired.
     * Returns default value if transient doesn't exist or has expired.
     * 
     * @param string $key Transient key
     * @param mixed $default Default value if transient doesn't exist or expired
     * @return mixed
     */
    public function getTransient(string $key, $default = null)
    {
        return DatabaseFacade::getTransient($key, $default);
    }

    /**
     * Delete transient
     * 
     * @param string $key Transient key
     * @return bool Success
     */
    public function deleteTransient(string $key): bool
    {
        return DatabaseFacade::deleteTransient($key);
    }

    /**
     * Enable performance monitoring
     * 
     * When enabled, automatically processes health check logs every 5 minutes,
     * calculates average response time, and stores metrics in performance.log.
     * Also includes health check logs in performance channel.
     * 
     * When disabled, health check logs are cleaned up every 24 hours.
     * 
     * @param bool $enabled Enable performance monitoring (default: true)
     * @return self
     */
    public function enablePerformanceMonitoring(bool $enabled = true): self
    {
        // Get log directory from system channel, or use default
        $systemLogFile = $this->logger->getChannelFile(Logger::CHANNEL_SYSTEM);
        if ($systemLogFile) {
            $logDir = dirname($systemLogFile);
        } else {
            // Fallback: try to determine log directory from configured channels
            $accessLogFile = $this->logger->getChannelFile(Logger::CHANNEL_ACCESS);
            if ($accessLogFile) {
                $logDir = dirname($accessLogFile);
            } else {
                // Last resort: use current directory
                $logDir = __DIR__ . '/../../logs';
            }
        }

        $this->performanceMonitor->enable($enabled);

        // Ensure performance channel is configured
        if ($enabled) {
            $performanceLogFile = $logDir . '/performance.log';
            
            // Ensure log directory exists
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            
            // Configure performance channel
            $this->logger->setChannel(Logger::CHANNEL_PERFORMANCE, $performanceLogFile);
            
            $this->logger->system()->info("Performance monitoring enabled", [
                'performance_log' => $performanceLogFile
            ]);
            
            // Schedule performance monitoring job (runs every 5 minutes)
            $this->schedule('performanceMonitoring', 300, function($logger) {
                $this->performanceMonitor->processMetrics();
            });
        } else {
            // When disabled, schedule simple cleanup job (runs every 24 hours)
            $this->schedule('cleanHealthCheckLogs', 86400, function($logger) {
                $systemLogFile = $logger->getChannelFile(Logger::CHANNEL_SYSTEM);
                if (!$systemLogFile) {
                    return;
                }

                $cutoffTime = time() - 86400; // 24 hours ago
                $this->performanceMonitor->cleanHealthCheckLogs($systemLogFile, $cutoffTime);
                $logger->system()->info("Cleaned health check logs older than 24 hours");
            });
        }

        return $this;
    }

    /**
     * Register a callback for the 'start' event (convenience method)
     * Called once when the server starts, in the master process
     *
     * @param callable $callback Callback receives: (Server $server)
     * @return self
     */
    public function onStart(callable $callback): self
    {
        // Store callback to register when server is created
        if (!$this->server) {
            // We'll register this in run() when server is created
            // For now, store it temporarily
            $this->pendingStartCallback = $callback;
        } else {
            $this->server->on('start', $callback);
        }
        return $this;
    }

    /**
     * Start the server
     *
     * @return void
     */
    public function run(): void
    {
        $this->server = new Server($this->host, $this->port);
        
        $serverSettings = [
            'enable_coroutine' => true,
            'task_worker_num' => $this->taskWorkerNum,
        ];
        
        // Only set worker_num if explicitly configured (0 = auto/CPU count)
        if ($this->workerNum > 0) {
            $serverSettings['worker_num'] = $this->workerNum;
        }
        
        $this->server->set($serverSettings);

        $this->server->on('request', function ($req, $res) {
            $this->requestHandler->handle($req, $res, fn() => $this);
        });

        $this->taskManager->setupHandlers($this->server);

        // Register start event handler
        $pendingCallback = $this->pendingStartCallback;
        $this->pendingStartCallback = null;
        
        $this->server->on('start', function (Server $server) use ($pendingCallback) {
            // Start all scheduled jobs
            $this->jobManager->start();
            
            // Execute user's start callback if provided
            if ($pendingCallback !== null) {
                call_user_func($pendingCallback, $server);
            }
        });

        $this->logger->system()->info("Server starting", [
            'host' => $this->host,
            'port' => $this->port
        ]);
        
        echo "Listening on http://{$this->host}:{$this->port}\n";
        $this->server->start();
    }
}
