<?php

namespace PHAPI\Services;

class AmpTaskRunner implements TaskRunner
{
    public function parallel(array $tasks): array
    {
        if (!function_exists('Amp\async')) {
            $runner = new SequentialTaskRunner();
            return $runner->parallel($tasks);
        }

        $futures = [];
        foreach ($tasks as $key => $task) {
            $futures[$key] = \Amp\async($task);
        }

        $results = [];
        foreach ($futures as $key => $future) {
            $results[$key] = $future->await();
        }

        return $results;
    }
}
