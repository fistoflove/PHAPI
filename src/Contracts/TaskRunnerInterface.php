<?php

declare(strict_types=1);

namespace PHAPI\Contracts;

interface TaskRunnerInterface
{
    /**
     * Run tasks in parallel when supported.
     *
     * @param array<string, callable(): mixed> $tasks
     * @param int|null $concurrency Maximum number of tasks running at once. Null = unlimited.
     * @return array<string, mixed>
     */
    public function parallel(array $tasks, ?int $concurrency = null): array;
}
