<?php

declare(strict_types=1);

namespace PHAPI\Supabase\Realtime;

/**
 * Tracks presence state for a Supabase Realtime channel.
 *
 * Uses the same CRDT-based sync model as supabase-js: full state snapshots
 * via syncState() and incremental diffs via syncDiff().
 *
 * @api
 */
final class RealtimePresence
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $state = [];

    /**
     * Replace the entire presence state (received on join or full sync).
     *
     * @param array<string, mixed> $state
     */
    public function syncState(array $state): void
    {
        $normalized = [];

        foreach ($state as $key => $value) {
            if (is_array($value) && isset($value['metas']) && is_array($value['metas'])) {
                /** @var array<int, array<string, mixed>> $metas */
                $metas = $value['metas'];
                $normalized[$key] = $metas;
            } elseif (is_array($value)) {
                /** @var array<int, array<string, mixed>> $value */
                $normalized[$key] = $value;
            }
        }

        $this->state = $normalized;
    }

    /**
     * Apply an incremental presence diff (joins and leaves).
     *
     * @param array<string, mixed> $diff
     */
    public function syncDiff(array $diff): void
    {
        /** @var array<string, mixed> $joins */
        $joins = $diff['joins'] ?? [];
        /** @var array<string, mixed> $leaves */
        $leaves = $diff['leaves'] ?? [];

        foreach ($joins as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            /** @var array<int, array<string, mixed>> $metas */
            $metas = $value['metas'] ?? [$value];
            $existing = $this->state[$key] ?? [];
            $this->state[$key] = array_merge($existing, $metas);
        }

        foreach ($leaves as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            $currentMetas = $this->state[$key] ?? [];
            if ($currentMetas === []) {
                continue;
            }

            /** @var array<int, array<string, mixed>> $leaveMetas */
            $leaveMetas = $value['metas'] ?? [$value];
            $leaveRefs = [];
            foreach ($leaveMetas as $meta) {
                if (isset($meta['phx_ref'])) {
                    $leaveRefs[(string) $meta['phx_ref']] = true;
                }
            }

            if ($leaveRefs !== []) {
                $remaining = array_filter($currentMetas, static function (array $meta) use ($leaveRefs): bool {
                    return !isset($leaveRefs[(string) ($meta['phx_ref'] ?? '')]);
                });
            } else {
                // No phx_ref — remove all metas for this key
                $remaining = [];
            }

            if ($remaining === []) {
                unset($this->state[$key]);
            } else {
                $this->state[$key] = array_values($remaining);
            }
        }
    }

    /**
     * Get the full presence state.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * Get all presence keys (typically user/session identifiers).
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->state);
    }
}
