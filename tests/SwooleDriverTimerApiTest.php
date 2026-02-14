<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Runtime\SwooleDriver;
use PHPUnit\Framework\TestCase;

final class SwooleDriverTimerApiTest extends TestCase
{
    public function testEveryAfterAndClearDelegateToTimerHooks(): void
    {
        $driver = new class () extends SwooleDriver {
            /** @var array<int, array{op: string, value: int}> */
            public array $calls = [];

            protected function timerTick(int $intervalMs, callable $handler)
            {
                $this->calls[] = ['op' => 'tick', 'value' => $intervalMs];
                return 101;
            }

            protected function timerAfter(int $delayMs, callable $handler)
            {
                $this->calls[] = ['op' => 'after', 'value' => $delayMs];
                return 202;
            }

            protected function timerClear(int $timerId): bool
            {
                $this->calls[] = ['op' => 'clear', 'value' => $timerId];
                return $timerId === 101;
            }
        };

        $everyId = $driver->every(1000, static function (): void {
        });
        $afterId = $driver->after(500, static function (): void {
        });
        $cleared = $driver->clearTimer(101);

        $this->assertSame(101, $everyId);
        $this->assertSame(202, $afterId);
        $this->assertTrue($cleared);
        $this->assertSame(
            [
                ['op' => 'tick', 'value' => 1000],
                ['op' => 'after', 'value' => 500],
                ['op' => 'clear', 'value' => 101],
            ],
            $driver->calls
        );
    }

    public function testEveryRejectsInvalidInterval(): void
    {
        $driver = new SwooleDriver();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timer interval must be at least 1ms.');
        $driver->every(0, static function (): void {
        });
    }

    public function testAfterRejectsInvalidDelay(): void
    {
        $driver = new SwooleDriver();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timer delay must be at least 1ms.');
        $driver->after(0, static function (): void {
        });
    }

    public function testWebSocketHelpersReturnFalseWhenServerUnavailable(): void
    {
        $driver = new SwooleDriver();

        $this->assertFalse($driver->isConnectionEstablished(123));
        $this->assertFalse($driver->disconnect(123));
    }
}

