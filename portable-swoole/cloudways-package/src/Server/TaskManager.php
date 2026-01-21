<?php

namespace PHAPI\Server;

use PHAPI\Logging\Logger;
use Swoole\Http\Server;

/**
 * Manages background tasks
 */
class TaskManager
{
    private array $tasks = [];
    private Logger $logger;
    private bool $debug;

    public function __construct(Logger $logger, bool $debug = false)
    {
        $this->logger = $logger;
        $this->debug = $debug;
    }

    /**
     * Register a task handler
     *
     * @param string $name Task name
     * @param callable $handler Task handler receives: ($data, $logger)
     */
    public function register(string $name, callable $handler): void
    {
        $this->tasks[$name] = $handler;
    }

    /**
     * Setup task event handlers on Swoole server
     *
     * @param Server $server Swoole server instance
     */
    public function setupHandlers(Server $server): void
    {
        $server->on('task', function (Server $serv, int $taskId, int $srcWorkerId, mixed $payload) {
            $this->handleTask($serv, $taskId, $payload);
        });

        $server->on('finish', function (Server $serv, int $taskId, string $result) {
            $this->logger->debug()->info("Task finished", [
                'task_id' => $taskId,
                'result' => $result
            ]);
        });
    }

    /**
     * Handle a task execution
     *
     * @param Server $server Swoole server instance
     * @param int $taskId Task ID
     * @param mixed $payload Task payload
     */
    private function handleTask(Server $server, int $taskId, mixed $payload): void
    {
        $name = $payload['name'] ?? '';
        $data = $payload['data'] ?? null;

        if (!isset($this->tasks[$name])) {
            $this->logger->task()->warning("Unknown task", [
                'task_id' => $taskId,
                'name' => $name
            ]);
            $server->finish("unknown:$name");
            return;
        }

        try {
            ($this->tasks[$name])($data, $this->logger);
            $server->finish("done:$name");
            $this->logger->task()->info("Task completed", [
                'task_id' => $taskId,
                'name' => $name
            ]);
        } catch (\Throwable $e) {
            $this->logger->task()->error("Task failed", [
                'task_id' => $taskId,
                'name' => $name,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $this->debug ? $e->getTraceAsString() : null
            ]);
            $server->finish("error:$name:" . $e->getMessage());
        }
    }

    /**
     * Dispatch a task
     *
     * @param Server $server Swoole server instance
     * @param string $name Task name
     * @param mixed $data Task data
     * @return bool
     */
    public function dispatch(Server $server, string $name, mixed $data): bool
    {
        $this->logger->debug()->info("Dispatching task", [
            'name' => $name,
            'has_data' => !is_null($data)
        ]);
        return $server->task(['name' => $name, 'data' => $data]);
    }
}

