<?php

namespace PHAPI\Services;

interface TaskRunner
{
    public function parallel(array $tasks): array;
}
