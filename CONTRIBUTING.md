# Contributing

Thank you for helping. Bug reports, fixes and documentation improvements are all welcome.

## Setup

You need PHP 8.1 or later and Composer.

```sh
git clone https://github.com/IndianOpinion/wp-edge-cache-headers.git
cd wp-edge-cache-headers
composer install
```

No WordPress install is needed: tests run against a small stub layer in `tests/stubs/`.

## Checks

Run all of these before opening a pull request. CI runs them on PHP 8.1, 8.2 and 8.3.

```sh
composer lint   # php -l on every file
composer cs     # phpcs, PSR-12
composer stan   # PHPStan level 8 with WordPress stubs
composer test   # PHPUnit 10
```

`php examples/standalone-engine.php` must also run without Composer's autoloader.

## Where things go

- Decision logic belongs in the pure core (`src/`, outside `src/WordPress/`). It must not call WordPress. Add a unit test in `tests/Unit/`.
- WordPress glue belongs in `src/WordPress/`. Add an integration test in `tests/Integration/` that drives the hooks in the order core fires them. Add any WordPress function you call to `tests/stubs/functions.php`.
- Behaviour changes need a line in `docs/PRD.md` or `docs/ARCHITECTURE.md` and in `CHANGELOG.md` under Unreleased.

## Style

- `declare(strict_types=1);` in every file. PSR-12. PHP 8.1 syntax only (no readonly classes, no DNF types).
- Typed exceptions, never silent failure, never the `@` operator.
- Escape all output in wp-admin. Nonces and capability checks on anything admin-facing.
- Documentation: short sentences, plain words, British spelling.

## Pull requests

- One topic per pull request. Keep `main` green.
- Describe what changed and why, and which PRD cases or tests it touches.
- A sign-off line is not required.
- By contributing you agree that your work is released under the MIT licence.
