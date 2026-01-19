<?php

namespace PHAPI\HTTP;

class RequestContext
{
    private static array $byCoroutine = [];
    private static ?Request $current = null;

    public static function set(Request $request): void
    {
        $cid = self::coroutineId();
        if ($cid === null) {
            self::$current = $request;
            return;
        }

        self::$byCoroutine[$cid] = $request;
    }

    public static function get(): ?Request
    {
        $cid = self::coroutineId();
        if ($cid === null) {
            return self::$current;
        }

        return self::$byCoroutine[$cid] ?? null;
    }

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
