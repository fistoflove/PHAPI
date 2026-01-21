# 05 - Lifecycle Hooks

## Goal
Add minimal lifecycle hooks for boot, worker start, and shutdown.

## Scope
- `onBoot(PHAPI $app)`
- `onWorkerStart(int $workerId)`
- `onShutdown()`

## Deliverables
- Hook registration API in PHAPI.
- Runtime drivers invoke hooks appropriately.

## Acceptance Criteria
- Hooks are no-ops when not set.
- Swoole hooks map to worker lifecycle.
- FPM/AMPHP hooks run per request where appropriate.

## Runtime Semantics

| Hook | FPM | AMPHP | Swoole |
| --- | --- | --- | --- |
| `onBoot` | once per request | once per request | once on server start |
| `onWorkerStart` | once per request | once per request | once per worker |
| `onShutdown` | once per request | once per request | once on server shutdown |

Avoid heavy work in `onWorkerStart()` for FPM/AMPHP because it runs every request.

## Notes
- Keep the API minimal and predictable.
