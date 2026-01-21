# 06 - Tiny Plugin System

## Goal
Add a minimal extension mechanism without a heavy module system.

## Scope
- `PHAPI::extend('cache', fn(Container $c) => new RedisCache(...))`
- Access via `$app->container()->get('cache')` or `$app->resolve('cache')`

## Deliverables
- Extend API backed by container bindings.
- Convenience resolver on `PHAPI`.
- Documentation examples.

## Acceptance Criteria
- Zero overhead when unused.
- Works with DI container bindings.

## Notes
- Keep it minimal: no plugin lifecycle or config required.
- `extend()` is sugar for container bindings.
- Suggested naming: `vendor.feature` or `feature.variant`.
