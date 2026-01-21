<?php

declare(strict_types=1);

namespace PHAPI\Core;

use PHAPI\HTTP\Response;
use PHAPI\PHAPI;
use PHAPI\Services\JobsManager;

final class DefaultEndpoints
{
    /**
     * Register built-in endpoints based on configuration.
     *
     * @param PHAPI $app
     * @param JobsManager $jobs
     * @param array<string, mixed> $config
     * @return void
     */
    public function register(PHAPI $app, JobsManager $jobs, array $config): void
    {
        if ($this->isEnabled($config, 'monitor')) {
            $app->get('/monitor', static function () use ($jobs): Response {
                $appInstance = PHAPI::app();
                $logs = $jobs->logs();

                return Response::json([
                    'ok' => true,
                    'time' => date('c'),
                    'runtime' => $appInstance?->runtimeName(),
                    'jobs' => $logs,
                    'jobs_count' => count($logs),
                    'memory_bytes' => memory_get_usage(true),
                ]);
            });
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param string $name
     * @return bool
     */
    private function isEnabled(array $config, string $name): bool
    {
        $defaults = $config['default_endpoints'] ?? [
            'monitor' => true,
        ];

        if (is_bool($defaults)) {
            return $defaults;
        }

        if (is_array($defaults)) {
            return (bool)($defaults[$name] ?? true);
        }

        return true;
    }
}
