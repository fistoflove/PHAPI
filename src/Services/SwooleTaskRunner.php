<?php

declare(strict_types=1);

namespace PHAPI\Services;

class SwooleTaskRunner implements TaskRunner
{
    private ?float $timeoutSeconds;

    public function __construct(?float $timeoutSeconds = null)
    {
        $this->timeoutSeconds = $timeoutSeconds !== null && $timeoutSeconds > 0
            ? $timeoutSeconds
            : null;
    }

    /**
     * Run tasks in parallel using Swoole coroutines.
     *
     * When $concurrency is set, at most that many tasks run simultaneously
     * using a Channel-based semaphore. When null, all tasks run at once.
     *
     * @param array<string, callable(): mixed> $tasks
     * @param int|null $concurrency Maximum concurrent tasks. Null = unlimited.
     * @return array<string, mixed>
     */
    public function parallel(array $tasks, ?int $concurrency = null): array
    {
        if (!class_exists('Swoole\\Coroutine')) {
            throw new \RuntimeException('Swoole coroutines are not available.');
        }

        if ($tasks === []) {
            return [];
        }

        $results = [];
        $errors = [];

        $runner = function () use ($tasks, $concurrency, &$results, &$errors): void {
            $timeout = $this->timeoutSeconds;

            if (class_exists('Swoole\\Coroutine\\WaitGroup')) {
                $waitGroup = new \Swoole\Coroutine\WaitGroup();
                $semaphore = ($concurrency !== null && $concurrency > 0)
                    ? new \Swoole\Coroutine\Channel($concurrency)
                    : null;

                foreach ($tasks as $key => $task) {
                    // Acquire semaphore slot before spawning — blocks if limit reached
                    if ($semaphore !== null) {
                        $semaphore->push(true);
                    }

                    $waitGroup->add();
                    \Swoole\Coroutine::create(function () use ($task, $key, &$results, &$errors, $waitGroup, $semaphore) {
                        try {
                            $results[$key] = $task();
                        } catch (\Throwable $e) {
                            $errors[$key] = $e;
                        } finally {
                            if ($semaphore !== null) {
                                $semaphore->pop();
                            }
                            $waitGroup->done();
                        }
                    });
                }

                $completed = $waitGroup->wait($timeout ?? -1);
                if ($timeout !== null && $completed === false) {
                    throw new \RuntimeException('Task runner timed out.');
                }
                return;
            }

            // Channel fallback when WaitGroup is unavailable
            if (!class_exists('Swoole\\Coroutine\\Channel')) {
                throw new \RuntimeException('Swoole coroutine channels are not available.');
            }

            $channel = new \Swoole\Coroutine\Channel(count($tasks));
            $semaphore = ($concurrency !== null && $concurrency > 0)
                ? new \Swoole\Coroutine\Channel($concurrency)
                : null;

            foreach ($tasks as $key => $task) {
                if ($semaphore !== null) {
                    $semaphore->push(true);
                }

                \Swoole\Coroutine::create(function () use ($task, $key, $channel, $semaphore) {
                    try {
                        $channel->push(['key' => $key, 'value' => $task()]);
                    } catch (\Throwable $e) {
                        $channel->push(['key' => $key, 'error' => $e]);
                    } finally {
                        if ($semaphore !== null) {
                            $semaphore->pop();
                        }
                    }
                });
            }

            $deadline = $timeout !== null ? microtime(true) + $timeout : null;
            for ($i = 0; $i < count($tasks); $i++) {
                $popTimeout = -1;
                if ($deadline !== null) {
                    $remaining = $deadline - microtime(true);
                    if ($remaining <= 0) {
                        throw new \RuntimeException('Task runner timed out.');
                    }
                    $popTimeout = $remaining;
                }
                $item = $channel->pop($popTimeout);
                if ($item === false) {
                    throw new \RuntimeException('Task runner timed out.');
                }
                if (isset($item['error'])) {
                    $errors[$item['key']] = $item['error'];
                    continue;
                }
                $results[$item['key']] = $item['value'] ?? null;
            }
        };

        if (\Swoole\Coroutine::getCid() < 0) {
            if (!function_exists('Swoole\\Coroutine\\run')) {
                throw new \RuntimeException('Swoole coroutine runner is not available.');
            }
            $error = null;
            \Swoole\Coroutine\run(function () use ($runner, &$error): void {
                try {
                    $runner();
                } catch (\Throwable $e) {
                    $error = $e;
                }
            });
            if ($error !== null) {
                throw $error;
            }
            if ($errors !== []) {
                $first = reset($errors);
                throw $first;
            }
            return $results;
        }

        $runner();

        if ($errors !== []) {
            $first = reset($errors);
            throw $first;
        }

        return $results;
    }
}
