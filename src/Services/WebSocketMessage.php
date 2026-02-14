<?php

declare(strict_types=1);

namespace PHAPI\Services;

final class WebSocketMessage
{
    public function __construct(
        private readonly int $fd,
        private readonly string $data
    ) {
    }

    public function fd(): int
    {
        return $this->fd;
    }

    public function data(): string
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->data, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }
}

