# Virtuaalihevoset (vrlv3)

## What This Is

Virtuaalihevoset (virtuaalihevoset.net) is a Finnish virtual horse breeding and competition registry built on CodeIgniter 3 + FuelCMS. It tracks horses, breeders (Kasvattajanimi), users, and competition/scoring systems (Kisajarjestelma, Jaos), with a FUEL CMS admin panel for content management.

This milestone is a platform-alignment and framework-audit effort: bring the local Docker development environment in line with the production server (PHP 8.2.23 on Amazon Linux) and produce a clear picture of whether the vendored CodeIgniter 3.1.13 / FuelCMS 1.5.2 frameworks need updating.

## Core Value

The local Docker dev environment must mirror production closely enough that "works locally" reliably means "works in production" — starting with matching PHP version and php.ini configuration.

## Requirements

### Validated

- ✓ Horse/breeder registry with breeder-name management (`Kasvattajanimi_model.php`) — existing
- ✓ User authentication via Ion Auth (`Ion_auth.php`, `Ion_auth_model.php`) — existing
- ✓ Competition scoring / judging system (`Kisajarjestelma.php`, `Jaos.php`, `Porrastetut.php`) — existing
- ✓ FUEL CMS admin panel for page/block/asset management — existing
- ✓ Transactional email via AWS SES (`Vrl_email.php`) — existing
- ✓ Local Docker Compose dev environment (Apache + PHP + MariaDB + phpMyAdmin) — existing

### Active

- [ ] **DOCKER-01**: Dockerfile's PHP base image matches production PHP version (8.2.23, currently pinned to `php:7.4-apache`)
- [ ] **DOCKER-02**: Relevant php.ini directives (memory_limit, upload_max_filesize, opcache settings, timezone, session settings, etc.) in the Docker image match production values captured in `phpinfo.md`
- [ ] **DOCKER-03**: Application runs and functions in the local Docker environment on the updated PHP version without fatal errors, deprecation storms, or broken core flows
- [ ] **FRAMEWORK-01**: Written assessment of whether vendored CodeIgniter 3.1.13 has a newer compatible version worth adopting, and what such an upgrade would involve
- [ ] **FRAMEWORK-02**: Written assessment of whether vendored FuelCMS 1.5.2 has a newer compatible version worth adopting, and what such an upgrade would involve

### Out of Scope

- Actually upgrading CodeIgniter or FuelCMS to a newer version — this milestone only produces the assessment/report; upgrading is a future decision — deferred until the report is reviewed
- Deploying anything to the production server (virtuaalihevoset.net) — this work is scoped to the local Docker dev environment only
- Fixing the pre-existing SQL injection risk in `Kasvattajanimi_model.php` and other CONCERNS.md items — noted but not in scope for this milestone

## Context

- Codebase mapped via `/gsd-map-codebase` on 2026-07-19 — see `.planning/codebase/` (STACK.md, ARCHITECTURE.md, STRUCTURE.md, CONVENTIONS.md, TESTING.md, INTEGRATIONS.md, CONCERNS.md)
- Production PHP config snapshot available at `phpinfo.md` (project root) — PHP 8.2.23, FPM/FastCGI on Amazon Linux, Apache 2.4.62
- Current `Dockerfile` uses `php:7.4-apache`; `fuel/application/composer.json` already declares `"php": ">=8.1.0"` — the Docker image is the outlier, not the app's declared requirement
- CodeIgniter and FuelCMS are vendored directly under `fuel/codeigniter/` and `fuel/modules/fuel/` (not managed via Composer), so "updating" them is a manual file-replacement process, not a `composer update`
- No automated test suite exists for the application — verification of "works without errors" will be manual/exploratory

## Constraints

- **Compatibility**: PHP extensions currently installed in Dockerfile (mysqli, bz2, intl, bcmath, opcache, calendar, pdo_mysql) must remain available/equivalent on PHP 8.2 — some extension install methods may differ between PHP 7.4 and 8.2 base images
- **No production access assumed**: Alignment is based on the static `phpinfo.md` snapshot, not live production access

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| CI3/FuelCMS work is assessment-only this round | User wants to understand upgrade scope before committing to the (likely large) manual upgrade effort | — Pending |
| Docker alignment covers full php.ini, not just PHP version | User wants dev environment as close to production as practically achievable, not just version parity | — Pending |
| Production deployment is explicitly out of scope | User's goal is dev/prod parity for local development confidence, not a live rollout | — Pending |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-07-19 after initialization*
