# 02 - Class-Based Handlers (Optional)

## Goal
Allow controller-style handlers without adding framework bloat.

## Scope
- Support callable arrays: `[Controller::class, 'method']`.
- Resolve controller instances via the DI container.

## Deliverables
- Router/handler resolution accepts class-string callables.
- Documentation example showing controller usage.

## Acceptance Criteria
- Controllers resolve with constructor injection.
- No change to existing function/closure handlers.

## Notes
- This complements DI and keeps PHAPI minimal.
