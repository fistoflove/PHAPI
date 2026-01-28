# Repository Guidelines

## Project Structure & Module Organization
- `src/` contains the framework runtime, HTTP stack, services, and contracts.
- `tests/` contains PHPUnit tests (unit and integration).
- `examples/` includes runnable examples (single-file, multi-file, example app).
- `config/` holds default configuration (`config/phapi.php`).
- `docs/` stores staged implementation notes and supporting docs.
- `bin/` provides CLI helpers (`phapi-run`, `phapi-jobs`).

## Build, Test, and Development Commands
- `composer test`: Run the PHPUnit suite with testdox output.
- `composer test:integration`: Run integration tests only.
- `composer phpstan`: Run PHPStan static analysis (strict rules).
- `composer lint`: Run PHP-CS-Fixer in dry-run mode.
- `composer lint:fix`: Apply PHP-CS-Fixer formatting.

Examples:
- `APP_RUNTIME=swoole php example.php`
- `APP_RUNTIME=portable_swoole php bin/phapi-run example.php`

## Coding Style & Naming Conventions
- PHP 8.0+, PSR-12 style, `declare(strict_types=1);` everywhere in `src/`.
- Use meaningful, descriptive class names; prefer `PascalCase` for classes and `camelCase` for methods.
- Formatting is enforced via PHP-CS-Fixer (`.php-cs-fixer.php`).
- Static analysis via PHPStan (`phpstan.neon`, strict rules).

## Testing Guidelines
- Framework: PHPUnit (`phpunit.xml`).
- Tests live in `tests/` and follow `*Test.php` naming.
- Add tests for new public APIs, runtime behavior, and examples where feasible.
- Use `PHAPI::kernel()` to exercise routes in memory when unit testing.

## Commit & Pull Request Guidelines
- Recent commits use Conventional Commits (e.g., `feat:`) and simple version tags (e.g., `0.1`).
- Keep commits focused and descriptive; prefer small, reviewable changes.
- PRs should include: summary, rationale, and relevant command output (`composer test`, `composer phpstan`, `composer lint`).
- Update `README.md` whenever a change affects usage, configuration, public APIs, or examples.

## Security & Configuration Tips
- Runtime selection uses `APP_RUNTIME` (`swoole`, `portable_swoole`).
- Use `PHAPI_PORTABLE_SWOOLE_DIR` or `PHAPI_PORTABLE_SWOOLE_EXT` when testing portable Swoole.
