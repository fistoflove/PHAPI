<?php

declare(strict_types=1);

namespace PHAPI\HTTP;

class RequestContext
{
    /**
     * @var array<int, Request>
     */
    private static array $byCoroutine = [];
    private static ?Request $current = null;

    /**
     * Store the current request in the context.
     *
     * @param Request $request
     * @return void
     */
    public static function set(Request $request): void
    {
        $cid = self::coroutineId();
        if ($cid === null) {
            self::$current = $request;
            return;
        }

        self::$byCoroutine[$cid] = $request;
    }

    /**
     * Get the current request from the context.
     *
     * @return Request|null
     */
    public static function get(): ?Request
    {
        $cid = self::coroutineId();
        if ($cid === null) {
            return self::$current;
        }

        return self::$byCoroutine[$cid] ?? null;
    }

    /**
     * Clear the current request from the context.
     *
     * @return void
     */
    public static function clear(): void
    {
        $cid = self::coroutineId();
        if ($cid === null) {
            self::$current = null;
            return;
        }

        unset(self::$byCoroutine[$cid]);
    }

    private static function coroutineId(): ?int
    {
        if (!class_exists('Swoole\\Coroutine')) {
            return null;
        }

        $cid = \Swoole\Coroutine::getCid();
        return $cid >= 0 ? $cid : null;
    }
}
