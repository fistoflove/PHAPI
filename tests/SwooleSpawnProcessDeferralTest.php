<?php

namespace PHAPI\Tests;

use PHAPI\Runtime\SwooleDriver;

final class SwooleSpawnProcessDeferralTest extends SwooleTestCase
{
    public function testDefersUntilOutsideCoroutine(): void
    {
        $driver = new class([1, 1, -1]) extends SwooleDriver {
            public array $calls = [];
            private array $cidSequence;

            public function __construct(array $cidSequence)
            {
                parent::__construct();
                $this->cidSequence = $cidSequence;
            }

            public function startForWorker(int $workerId): void
            {
                $this->startProcessesForWorker($workerId);
            }

            protected function coroutineId(): int
            {
                return array_shift($this->cidSequence) ?? -1;
            }

            protected function deferTimer(callable $callback): void
            {
                $this->calls[] = 'timer';
                $callback();
            }

            protected function deferEvent(callable $callback): void
            {
                $this->calls[] = 'event';
                $callback();
            }

            protected function startProcessesForWorkerOutsideCoroutine(int $workerId): void
            {
                $this->calls[] = 'start:' . $workerId;
            }
        };

        $driver->startForWorker(0);

        $this->assertSame(['timer', 'event', 'start:0'], $driver->calls);
    }
}
