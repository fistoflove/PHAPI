<?php

namespace PHAPI\Services;

class SequentialTaskRunner implements TaskRunner
{
    public function parallel(array $tasks): array
    {
        $results = [];
        foreach ($tasks as $key => $task) {
            $results[$key] = $task();
        }
        return $results;
    }
}
