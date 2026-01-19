<?php

namespace PHAPI\Auth;

interface GuardInterface
{
    public function user(): ?array;
    public function check(): bool;
    public function id(): ?string;
}
