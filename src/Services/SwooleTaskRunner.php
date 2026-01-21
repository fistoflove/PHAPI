<?php

declare(strict_types=1);

namespace PHAPI\Services;

class SwooleTaskRunner implements TaskRunner
{
    /**
     * Run tasks in parallel using Swoole coroutines when available.
     *
     * @param array<string, callable(): mixed> $tasks
     * @return array<string, mixed>
     */
    public function parallel(array $tasks): array
    {
        if (!class_exists('Swoole\\Coroutine')) {
            $runner = new SequentialTaskRunner();
            return $runner->parallel($tasks);
        }

        $results = [];
        $errors = [];

        $runner = function () use ($tasks, &$results, &$errors): void {
            if (class_exists('Swoole\\Coroutine\\WaitGroup')) {
                $waitGroup = new \Swoole\Coroutine\WaitGroup();
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
                return;
            }

            if (!class_exists('Swoole\\Coroutine\\Channel')) {
                $runner = new SequentialTaskRunner();
                $results = $runner->parallel($tasks);
                return;
            }

            $channel = new \Swoole\Coroutine\Channel(count($tasks));
            foreach ($tasks as $key => $task) {
                \Swoole\Coroutine::create(function () use ($task, $key, $channel) {
                    try {
                        $channel->push(['key' => $key, 'value' => $task()]);
                    } catch (\Throwable $e) {
                        $channel->push(['key' => $key, 'error' => $e]);
                    }
                });
            }

            for ($i = 0; $i < count($tasks); $i++) {
                $item = $channel->pop();
                if (isset($item['error'])) {
                    $errors[$item['key']] = $item['error'];
                    continue;
                }
                $results[$item['key']] = $item['value'] ?? null;
            }
        };

        if (\Swoole\Coroutine::getCid() < 0 && function_exists('Swoole\\Coroutine\\run')) {
            \Swoole\Coroutine\run($runner);
        } else {
            $runner();
        }

        if ($errors !== []) {
            $first = reset($errors);
            throw $first;
        }

        return $results;
    }
}
