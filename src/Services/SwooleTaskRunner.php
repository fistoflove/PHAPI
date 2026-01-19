<?php

namespace PHAPI\Services;

class SwooleTaskRunner implements TaskRunner
{
    public function parallel(array $tasks): array
    {
        if (!class_exists('Swoole\\Coroutine\\WaitGroup')) {
            $runner = new SequentialTaskRunner();
            return $runner->parallel($tasks);
        }

        $waitGroup = new \Swoole\Coroutine\WaitGroup();
        $results = [];
        $errors = [];

        foreach ($tasks as $key => $task) {
            $waitGroup->add();
            \Swoole\Coroutine::create(function () use ($task, $key, &$results, &$errors, $waitGroup) {
                try {
                    $results[$key] = $task();
                } catch (\Throwable $e) {
                    $errors[$key] = $e;
                } finally {
                    $waitGroup->done();
                }
            });
        }

        $waitGroup->wait();

        if (!empty($errors)) {
            $first = reset($errors);
            throw $first;
        }

        return $results;
    }
}
