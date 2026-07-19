# Technology Stack

**Analysis Date:** 2026-07-19

## Languages

**Primary:**
- PHP >=8.1.0 (per `fuel/application/composer.json`), running in Docker on `php:7.4-apache` base image (`Dockerfile`) — note version mismatch between Composer's declared requirement and the Docker base image.

**Secondary:**
- SQL (MariaDB dialect) - schema and seed data in `database/*.sql`
- JavaScript (jQuery-era, non-bundled) - `assets/js/*.js`
- HTML/CSS (server-rendered views) - `fuel/application/views/`, `assets/css/`

## Runtime

**Environment:**
- PHP 7.4 (Apache module, `mod_php`) inside Docker container (`Dockerfile`), Apache 2 with `mod_rewrite` and `mod_headers` enabled
- MariaDB 11.4.2 (`docker-compose.yml`, service `db`)
- phpMyAdmin 5.1.1 for local DB administration (`docker-compose.yml`, service `phpmyadmin`)

**Package Manager:**
- Composer (PHP), scoped to `fuel/application/` (`fuel/application/composer.json`)
- Lockfile: not found at `fuel/application/composer.lock` in inspected tree; `vendor/` is committed/present under `fuel/application/vendor/`
- No JavaScript package manager (no `package.json`) — JS assets are vendored directly under `assets/js/`

## Frameworks

**Core:**
- FuelCMS running on top of CodeIgniter 3.x — evidenced by `fuel/codeigniter/` (CI core), `fuel/modules/fuel/` (FuelCMS module), and FuelCMS-style config files (`MY_fuel.php`, `MY_fuel_layouts.php`, `MY_fuel_modules.php`) in `fuel/application/config/`
- Application code lives in `fuel/application/` (controllers, models, views, libraries) following CodeIgniter MVC conventions

**Auth:**
- Ion Auth library, `fuel/application/libraries/Ion_auth.php`, `fuel/application/models/Ion_auth_model.php`, config in `fuel/application/config/ion_auth.php` (legacy variant in `ion_auth_old.php`)

**Security/Sanitization:**
- HTML Purifier, config `fuel/application/config/purifier.php`

**Testing:**
- No test framework detected (no PHPUnit config, no `tests/` directory found in the areas explored)

**Build/Dev:**
- Docker Compose for local dev environment (`docker-compose.yml`): apache+php container, MariaDB, phpMyAdmin
- No frontend build tooling (no webpack/vite/gulp) — assets served as static files from `assets/`

## Key Dependencies

**Critical:**
- `aws/aws-sdk-php` ^3.321 - used for AWS SES (Simple Email Service) transactional email sending, `fuel/application/libraries/Vrl_email.php`
- `guzzlehttp/guzzle` (transitive, via AWS SDK) - HTTP client
- CodeIgniter 3 framework core - `fuel/codeigniter/`
- FuelCMS module - `fuel/modules/fuel/`

**Infrastructure:**
- `mysqli` PHP extension - primary DB driver (enabled in `Dockerfile`)
- `pdo_mysql`, `bcmath`, `intl`, `bz2`, `opcache`, `calendar` PHP extensions - enabled in `Dockerfile`
- Memcached support available via CodeIgniter cache driver, config `fuel/application/config/memcached.php` (default `127.0.0.1:11211`) — not wired into `docker-compose.yml`, so likely unused/optional in current deployment

## Configuration

**Environment:**
- Environment detection is host-based (not env-var based), configured in `fuel/application/config/environments.php`: matches `HTTP_HOST` against patterns to select `development` vs `production` (production = `virtuaalihevoset.net`)
- Two required, git-ignored local config files (per `.gitignore` and `README.md`):
  - `fuel/application/config/database.php` (copied from a `database_skeleton.php`, not present in reviewed tree, must be created locally/per-deploy)
  - `fuel/application/config/config.php` (copied from a `config_skeleton.php`, holds e.g. `encryption_key`)
- No `.env` file usage detected — configuration is plain PHP config arrays, git-ignored per environment

**Build:**
- `Dockerfile` — defines PHP 7.4 + Apache image, installs PHP extensions, installs Composer, copies app into `/var/www/html`
- `docker-compose.yml` — orchestrates `apache` (app), `db` (MariaDB), `phpmyadmin`
- `000-default.conf` — Apache vhost config copied into the image

## Platform Requirements

**Development:**
- Docker + Docker Compose (primary documented workflow, see `README.md`)
- Manual DB bootstrap by running SQL files in `database/` in a specific order: `1 fuel_schema.sql` → `2 listat_data_schema.sql` → `3 tunnukset_schema.sql` → `4 from_scratch_schema.sql` → `5 from_scratch_insert.sql`
- Local `database.php` / `config.php` must be created from skeleton files before the app will run

**Production:**
- Apache + PHP (mod_php) + MariaDB/MySQL, matching the Docker image stack
- Production host recognized by `environments.php` as `virtuaalihevoset.net` / `www.virtuaalihevoset.net`
- AWS account with SES access in `eu-north-1` region required for outbound email (`fuel/application/libraries/Vrl_email.php`)

---

*Stack analysis: 2026-07-19*
