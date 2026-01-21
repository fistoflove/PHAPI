<?php

declare(strict_types=1);

namespace PHAPI\Contracts;

interface TaskRunnerInterface
{
    /**
     * Run tasks in parallel when supported.
     *
     * @param array<string, callable(): mixed> $tasks
     * @return array<string, mixed>
     */
    public function parallel(array $tasks): array;
}
