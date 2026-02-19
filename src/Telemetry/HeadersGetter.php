<?php

declare(strict_types=1);

namespace PHAPI\Telemetry;

use OpenTelemetry\Context\Propagation\PropagationGetterInterface;

/**
 * Propagation getter for PHAPI's header array format.
 *
 * PHAPI normalizes all header keys to lowercase, so a direct array
 * key lookup is sufficient — no case-folding required.
 */
final class HeadersGetter implements PropagationGetterInterface
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** @return list<string> */
    public function keys(mixed $carrier): array
    {
        \assert(\is_array($carrier));

        return array_keys($carrier);
    }

    public function get(mixed $carrier, string $key): ?string
    {
        \assert(\is_array($carrier));

        $lower = strtolower($key);
        if (!array_key_exists($lower, $carrier)) {
            return null;
        }

        $value = $carrier[$lower];
        if (\is_array($value)) {
            return $value[0] ?? null;
        }

        return $value;
    }
}
