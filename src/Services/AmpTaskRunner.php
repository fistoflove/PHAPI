<?php

declare(strict_types=1);

namespace PHAPI\Services;

class AmpTaskRunner implements TaskRunner
{
    /**
     * Run tasks in parallel using AMPHP futures when available.
     *
     * @param array<string, callable(): mixed> $tasks
     * @return array<string, mixed>
     */
    public function parallel(array $tasks): array
    {
        if (!function_exists('Amp\async')) {
            $runner = new SequentialTaskRunner();
            return $runner->parallel($tasks);
        }

        $futures = [];
        foreach ($tasks as $key => $task) {
            $futures[$key] = \Amp\async(\Closure::fromCallable($task));
        }

        $results = [];
        foreach ($futures as $key => $future) {
            $results[$key] = $future->await();
        }

        return $results;
    }
}
