# PHAPI Versioning Policy

## Semantic Versioning

All releases are semver-tagged as `vMAJOR.MINOR.PATCH`:

- **MAJOR** — Breaking changes to public API (method signatures, removed classes, changed behavior)
- **MINOR** — New features that are backward-compatible
- **PATCH** — Bug fixes and non-functional changes

## Consumer Constraints

- Consumers reference tags via `^MAJOR.MINOR`, never commit hashes
- Example: `"phapi/phapi": "^1.0"` accepts `v1.0.0`, `v1.1.0`, `v1.2.3`, but not `v2.0.0`

## Lock Files

- `composer.lock` must be committed in every project that consumes PHAPI, no exceptions
- Run `composer update phapi/phapi` to upgrade within the semver constraint
- Run `composer install` for reproducible builds from the lock file

## Release Process

1. Ensure all tests pass: `composer test`
2. Ensure static analysis passes: `composer phpstan`
3. Tag the release: `git tag vX.Y.Z`
4. Update `CHANGELOG.md` with the release notes
5. Consumers run `composer update phapi/phapi` to pick up the new version
