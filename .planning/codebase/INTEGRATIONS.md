# External Integrations

**Analysis Date:** 2026-07-19

## APIs & External Services

**Email:**
- Amazon SES (Simple Email Service) - transactional email sending for the application
  - SDK/Client: `aws/aws-sdk-php` (`Aws\Ses\SesClient`), wired in `fuel/application/libraries/Vrl_email.php`
  - Region: `eu-north-1` (hardcoded in `Vrl_email::aws_send()`)
  - Sender address: hardcoded to `vrlvirallinen@gmail.com` in `Vrl_email.php` (line ~76) — this must be a verified SES sender identity
  - Auth: relies on AWS SDK default credential provider chain (env vars / instance profile / credentials file); no explicit key configuration found in application code — likely supplied via server/IAM environment, not tracked in this repo
  - A legacy, commented-out fallback path exists in the same file using CodeIgniter's native `email` library + Ion Auth email config (`fuel/application/config/ion_auth.php`), currently dead code (`return` before it executes)

**Analytics:**
- Google Analytics (optional) - config `fuel/application/config/google.php`, `$config['google_uacct']` currently set to empty string (not active); consumed via a `google_helper`

## Data Storage

**Databases:**
- MariaDB/MySQL (primary datastore)
  - Connection: configured in git-ignored `fuel/application/config/database.php` (not present in repo; must be created locally from an undocumented `database_skeleton.php` per `README.md`)
  - Client: CodeIgniter's built-in DB layer via `mysqli` driver (per README example config: `'dbdriver' => 'mysqli'`)
  - Local dev: MariaDB 11.4.2 container (`docker-compose.yml`), seeded from ordered SQL files in `database/` (`1 fuel_schema.sql`, `2 listat_data_schema.sql`, `3 tunnukset_schema.sql`, `4 from_scratch_schema.sql`, `5 from_scratch_insert.sql`)
  - Admin UI: phpMyAdmin container exposed on port 8080 locally (`docker-compose.yml`)

**File Storage:**
- Local filesystem only — assets served from `assets/` (`assets/images`, `assets/pdf`, `assets/docs`, `assets/swf`, `assets/cache`); no object storage (e.g. S3) integration detected for file uploads

**Caching:**
- Memcached is configured as an optional CodeIgniter cache backend (`fuel/application/config/memcached.php`, default `127.0.0.1:11211`) but no Memcached service is declared in `docker-compose.yml`, so it is not active in the documented local dev setup; usage in production is unverified from this repo

## Authentication & Identity

**Auth Provider:**
- Custom / self-hosted via Ion Auth (CodeIgniter auth library)
  - Implementation: `fuel/application/libraries/Ion_auth.php`, `fuel/application/models/Ion_auth_model.php`
  - Config: `fuel/application/config/ion_auth.php` (active), `fuel/application/config/ion_auth_old.php` (legacy/reference)
  - Language strings: `fuel/application/language/{english,finnish}/ion_auth_lang.php` — app supports Finnish and English auth-flow messaging
  - No external OAuth/SSO provider (e.g. Google/Facebook login) detected in code

## Monitoring & Observability

**Error Tracking:**
- None detected — no Sentry/Bugsnag/Rollbar SDK found in `vendor/` or config

**Logs:**
- CodeIgniter's built-in file-based logging (standard CI3 `log` library); no external log aggregation service detected

## CI/CD & Deployment

**Hosting:**
- Self-managed Apache/PHP/MariaDB stack, deployable via the provided `Dockerfile` + `docker-compose.yml`; production domain `virtuaalihevoset.net` (from `fuel/application/config/environments.php`)

**CI Pipeline:**
- None detected — no `.github/workflows/`, no other CI config files found in the repository

## Environment Configuration

**Required env vars:**
- No `.env`-style environment variables detected. Configuration is done through git-ignored PHP config files:
  - `fuel/application/config/database.php` — DB host/user/password/database name
  - `fuel/application/config/config.php` — includes `encryption_key` and other CI/FuelCMS settings
- AWS SES credentials are expected to be available to the AWS SDK's default credential chain (not present in repo; presumably supplied by hosting environment)

**Secrets location:**
- Not tracked in git: `.gitignore` explicitly excludes `/fuel/application/config/config.php` and `/fuel/application/config/database.php`
- No secrets found in `docker-compose.yml` beyond development-only empty MySQL root password (`MYSQL_ALLOW_EMPTY_PASSWORD: 1`), which is local-dev-only and not representative of production secrets handling

## Webhooks & Callbacks

**Incoming:**
- None detected in explored controllers/routes

**Outgoing:**
- None detected beyond the AWS SES API calls described above

---

*Integration audit: 2026-07-19*
