<?php

declare(strict_types=1);

namespace PHAPI\Core;

use PHAPI\Runtime\SwooleDriver;
use PHAPI\Services\JobsManager;

final class JobsScheduler
{
    /**
     * Register Swoole timers for scheduled jobs.
     *
     * @param JobsManager $jobs
     * @param SwooleDriver|null $driver
     * @param callable(callable(mixed ...$args): mixed): array{result: mixed, output: string} $executor
     * @return void
     */
    public function registerSwooleJobs(JobsManager $jobs, ?SwooleDriver $driver, callable $executor): void
    {
        if ($driver === null) {
            return;
        }

        $registered = $jobs->jobs();
        if ($registered === []) {
            return;
        }

        $driver->onWorkerStart(function ($server, int $workerId) use ($registered, $jobs, $executor) {
            if ($workerId !== 0) {
                return;
            }

            foreach ($registered as $name => $job) {
                $intervalMs = $job['interval'] * 1000;
                \Swoole\Timer::tick($intervalMs, function () use ($jobs, $executor, $name) {
                    $jobs->runScheduled($name, function (callable $handler) use ($executor) {
                        return $executor($handler);
                    });
                });
            }
        });
    }
}
