<?php

declare(strict_types=1);

namespace PHAPI\Services;

class SequentialTaskRunner implements TaskRunner
{
    /**
     * Run tasks sequentially.
     *
     * @param array<string, callable(): mixed> $tasks
     * @return array<string, mixed>
     */
    public function parallel(array $tasks): array
    {
        $results = [];
        foreach ($tasks as $key => $task) {
            $results[$key] = $task();
        }
        return $results;
    }
}
