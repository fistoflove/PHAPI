<?php

declare(strict_types=1);

namespace PHAPI\Concerns;

use PHAPI\Contracts\DatabaseInterface;
use PHAPI\Services\HttpClient;
use PHAPI\Services\MySqlPool;
use PHAPI\Services\OpenFgaClient;
use PHAPI\Services\Realtime;
use PHAPI\Services\RedisClient;
use PHAPI\Services\TaskRunner;

/**
 * Provides service accessor forwarding methods on PHAPI.
 *
 * Each method delegates to $this->serviceAccessor for lazy service resolution.
 * This trait is used by PHAPI and accesses the following property via $this:
 * - ServiceAccessor $serviceAccessor
 */
trait HasServiceAccessors
{
    /**
     * Get the MySQL connection pool.
     *
     * @return MySqlPool
     */
    public function mysql(): MySqlPool
    {
        return $this->serviceAccessor->mysql();
    }

    /**
     * Get the Redis client service.
     *
     * @return RedisClient
     */
    public function redis(): RedisClient
    {
        return $this->serviceAccessor->redis();
    }

    /**
     * Get the OpenFGA authorization client.
     *
     * @return OpenFgaClient
     */
    public function openfga(): OpenFgaClient
    {
        return $this->serviceAccessor->openfga();
    }

    /**
     * Get the HTTP client service.
     *
     * @return HttpClient
     */
    public function http(): HttpClient
    {
        return $this->serviceAccessor->http();
    }

    /**
     * Get the ORM database service.
     *
     * @return DatabaseInterface
     */
    public function database(): DatabaseInterface
    {
        return $this->serviceAccessor->database();
    }

    /**
     * Get the task runner service.
     *
     * @return TaskRunner
     */
    public function tasks(): TaskRunner
    {
        return $this->serviceAccessor->tasks();
    }

    /**
     * Get the realtime service.
     *
     * @return Realtime
     */
    public function realtime(): Realtime
    {
        return $this->serviceAccessor->realtime();
    }
}
