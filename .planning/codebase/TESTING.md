# Testing Patterns

**Analysis Date:** 2026-07-19

## Test Framework

**Runner:**
- Not detected for application code. No test runner configuration exists at the repository root or under `fuel/application/` (no `phpunit.xml`, no `tests/` directory in the application tree).
- The only test suite present belongs to the vendored FUEL CMS framework module: `fuel/modules/fuel/tests/` (e.g. `fuel/modules/fuel/tests/Fuel_login_test.php`, `fuel/modules/fuel/tests/Asset_test.php`, `fuel/modules/fuel/tests/Menu_test.php`) and CodeIgniter's own `Unit_test` library at `fuel/codeigniter/libraries/Unit_test.php`. These are third-party/framework tests, not application tests, and should not be treated as examples of this project's testing conventions.
- No `composer.json` `require-dev` entry for PHPUnit was found in `fuel/application/composer.json`.

**Assertion Library:** Not applicable — no application test suite exists.

**Run Commands:** Not applicable — no test script is defined; there is no `package.json`/Makefile test target at the repo root.

## Test File Organization

**Location:** Not applicable — no `tests/` directory exists for `fuel/application/` code (controllers, models, libraries, helpers).

**Naming:** Not applicable.

**Structure:** Not applicable.

## Test Structure

Not applicable. No unit, integration, or end-to-end tests cover the application's controllers (`fuel/application/controllers/`), models (`fuel/application/models/`), or libraries (`fuel/application/libraries/`).

## Mocking

Not applicable — no mocking framework or test doubles are used anywhere in the application code.

## Fixtures and Factories

Not applicable — no fixture/factory files exist for application code. Database schema seed data exists only as raw SQL dumps under `database/` (e.g. `database/5 from_scratch_insert.sql`), which are database bootstrap scripts, not test fixtures.

## Coverage

**Requirements:** None enforced. No coverage tooling configured.

**View Coverage:** Not applicable.

## Test Types

**Unit Tests:** None for application code.

**Integration Tests:** None for application code.

**E2E Tests:** Not used.

## Manual/Ad-hoc Verification Patterns Observed

In lieu of automated tests, some admin/CLI controller actions are written as one-off diagnostic scripts that `echo` progress and results directly, intended to be run manually via CLI or as a logged-in admin HTTP request — see `fuel/application/controllers/Update_stats.php` (`update_stats()` method echoes step-by-step output such as "Käynnistetään sijoitusstatistiikan korjaus..." and per-record results rather than asserting against expected values).

## Recommendations for New Work

- If tests are introduced, PHPUnit is the natural fit given the CodeIgniter/PHP 8.1 stack (`fuel/application/composer.json` requires `php: >=8.1.0`); add it under `require-dev` and create an application-level `tests/` directory (e.g. `fuel/application/tests/`) mirroring `controllers/`, `models/`, `libraries/` structure, separate from vendored `fuel/modules/fuel/tests/`.
- Given heavy CodeIgniter Query Builder usage in models (see `fuel/application/models/Hevonen_model.php`), a database test bootstrap (test DB config in `fuel/application/config/database.php` or an environment override) would be required before integration tests are feasible.
- Prioritize coverage for money/scoring logic such as the VRL placement-scoring formula in `fuel/application/controllers/Update_stats.php:85-101`, and for raw-SQL query construction paths (same file, lines 38-43 and 110-112) given the SQL-injection risk noted in CONVENTIONS.md.

---

*Testing analysis: 2026-07-19*
