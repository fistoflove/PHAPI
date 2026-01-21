# 01 - Runtime Abstraction Layer

## Goal
Formalize and stabilize the runtime abstraction so apps behave consistently across FPM, AMPHP, and Swoole.

## Scope
- Define a public RuntimeInterface and Capability model.
- Standardize behavior across runtimes for tasks, timers, HTTP client, websockets, jobs, and shutdown.

## Deliverables
- `RuntimeInterface` and `RuntimeCapabilities` interfaces.
- Public contracts under `PHAPI\Contracts`.
- Runtime identity helpers:
  - `name(): string`
  - `supportsWebSockets(): bool`
  - `isLongRunning(): bool`
- Standard runtime hooks:
  - `onBoot(PHAPI $app)`
  - `onWorkerStart(int $workerId)`
  - `onShutdown()`
- Stable abstractions for:
  - Task runner
  - Timers
  - HTTP client
  - WebSocket broadcast
  - Scheduler behavior
  - Graceful shutdown
- Documentation for runtime behavior guarantees.

## Hook Semantics

| Hook | FPM | AMPHP | Swoole |
| --- | --- | --- | --- |
| `onBoot` | once per request | once per request | once on server start |
| `onWorkerStart` | once per request | once per request | once per worker |
| `onShutdown` | once per request | once per request | once on server shutdown |

In FPM/AMPHP, avoid heavy work in `onWorkerStart()` because it runs per request.

## Acceptance Criteria
- Each runtime implements the same interface and capability flags.
- Existing code paths (FPM/AMPHP/Swoole) do not change behavior.
- Public APIs for tasks/jobs/websockets produce consistent results.

## Risks
- Breaking changes in runtime-specific drivers.
- Incomplete feature parity between runtimes.

## Notes
- This is the core PHAPI milestone and should precede other features.
