<?php

declare(strict_types=1);

namespace PHAPI\Services;

use PHAPI\Contracts\TaskRunnerInterface;

interface TaskRunner extends TaskRunnerInterface
{
    /**
     * Run tasks in parallel when supported.
     *
     * @param array<string, callable(): mixed> $tasks
     * @return array<string, mixed>
     */
    public function parallel(array $tasks): array;
}
