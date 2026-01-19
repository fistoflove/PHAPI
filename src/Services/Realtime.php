<?php

namespace PHAPI\Services;

interface Realtime
{
    public function broadcast(string $channel, array $message): void;
}
