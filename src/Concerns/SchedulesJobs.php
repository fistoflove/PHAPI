<?php

declare(strict_types=1);

namespace PHAPI\Concerns;

use PHAPI\Core\Container;

/**
 * Provides job scheduling, execution, and log access.
 *
 * This trait is used by PHAPI and accesses the following properties via $this:
 * - JobsManager $jobs
 * - Container $container
 */
trait SchedulesJobs
{
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
}
