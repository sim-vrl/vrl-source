# Codebase Concerns

**Analysis Date:** 2026-07-19

## Tech Debt

**Legacy framework stack (CodeIgniter 3 + FUEL CMS):**
- Issue: The application runs on CodeIgniter 3.1.13 (`fuel/codeigniter/core/CodeIgniter.php:59`), which reached end-of-life in the CI3 line and receives no active security patching from upstream. FUEL CMS (vendored under `fuel/modules/fuel/`) is likewise an older, lightly-maintained CMS layer bundled with the app rather than pulled via a package manager.
- Files: `fuel/codeigniter/*`, `fuel/modules/fuel/*`
- Impact: Any CVE discovered in CI3 core or FUEL CMS core has to be patched manually since there is no upstream release cadence to rely on. New PHP language features/deprecations (the app requires `php: >=8.1.0` per `fuel/application/composer.json`) may not be officially supported by CI3.
- Fix approach: Plan a longer-term migration path (CI4 or another maintained framework) or, at minimum, track CI3/FUEL security advisories manually and vendor-patch as needed.

**Ad-hoc one-off maintenance script committed as a controller:**
- Issue: `fuel/application/controllers/Update_stats.php` hardcodes a specific horse registration number (`$nro = 240230188;` at line 22) inside `update_stats()`. It is a one-time data-repair script that was seemingly left in the controller tree rather than being a reusable/parameterized tool.
- Files: `fuel/application/controllers/Update_stats.php:22`
- Impact: Running this action again does nothing useful for any other horse; if reused as a template for the next one-off fix without changing the hardcoded value, it silently reprocesses the wrong record. Recent commit history (`Update Update_stats.php`, `Laatispistelaskurin kirjoitusvirhe korjattu`) shows this file and the related scoring calculator have needed repeated hotfixes.
- Fix approach: Convert to a CLI-only artisan-style script that accepts the record number as an argument, or delete after the one-time fix is confirmed applied and keep the logic in version control history instead of live code.

**Raw SQL string concatenation instead of query bindings throughout data-migration and reporting code:**
- Issue: Several models build SQL by directly concatenating PHP variables into query strings rather than using CodeIgniter's query bindings or Active Record/Query Builder escaping.
- Files: `fuel/application/models/Kasvattajanimi_model.php:271`, `fuel/application/models/Kasvattajanimi_model.php:294`, `fuel/application/models/migraatio_model.php` (multiple `db->query("...".$var)` calls, e.g. lines 734, 737, 744, 747), `fuel/application/controllers/Update_stats.php:38-43,110-112`
- Impact: Increases risk of SQL injection if any of these values become user-controllable (see Security section) and makes future refactors error-prone since developers must manually verify escaping at every call site.
- Fix approach: Replace concatenation with `$this->db->query($sql, array($val1, $val2))` bound parameters or CI Query Builder methods (`where()`, `get()`), matching the pattern already used elsewhere in the same models (e.g. `Kasvattajanimi_model.php:280-284` uses `select()/from()/where()/get()` correctly).

**Bundled vendor libraries checked into the repo rather than managed via Composer alone:**
- Issue: Large third-party libraries such as `fuel/modules/fuel/libraries/HTMLPurifier/HTMLPurifier.standalone.php` (22,597 lines), `fuel/modules/fuel/libraries/Simplepie.php` (14,609 lines), and the AWS SDK under `fuel/application/vendor/aws/aws-sdk-php/` are committed directly into the repository tree instead of being pulled purely through Composer with a lockfile-driven install step.
- Files: `fuel/modules/fuel/libraries/*`, `fuel/application/vendor/*`
- Impact: Repository size bloat, unclear provenance/version tracking for security patching, and higher risk of accidentally hand-editing vendor code.
- Fix approach: Confirm whether these are meant to be Composer-managed (there is a `composer.json` requiring `aws/aws-sdk-php`) and, if so, ensure `vendor/` directories are excluded from version control and reproducibly installed via `composer install`.

## Known Bugs

**Recent scoring calculator ("laatispistelaskuri") fixes suggest fragile logic:**
- Symptoms: Recent commit history (`02e53c2 Laatispistelaskurin kirjoitusvirhe korjattu`, `bad209f Päivitetty laatispistelaskuri #97`, `12e8fe7 Päivitetty laatispistelaskuri #97`) shows repeated typo/logic fixes to the quality-point scoring calculation, and `51e1d9f Update Update_stats.php` shows a related stats-repair patch on the same day as this analysis.
- Files: `fuel/application/controllers/Update_stats.php`, likely also `fuel/application/libraries/Kisajarjestelma.php` and `fuel/application/libraries/Jaos.php` (both contain scoring/competition-class logic with unresolved `//TODO: Katso kisaluokat` notes at `Jaos.php:493,564` and `//TODO_ Takaaja` at `Kisajarjestelma.php:476`)
- Trigger: Competition results entry or periodic stats recalculation; the exact trigger for the July 19 hotfix is unclear from commit messages alone.
- Workaround: None documented; fixes have been applied directly to production-facing controllers/models without an accompanying test suite (see Test Coverage Gaps).

## Security Considerations

**SQL injection risk via unescaped string concatenation with route-derived input:**
- Risk: `Kasvattajanimi_model.php::update_breeds($id, ...)` concatenates `$id` directly into a raw `db->query()` call at lines 271 and 294. This method is called from `fuel/application/controllers/Kasvatus.php:703` and `:723` as `update_breeds($nimi, ...)`, where `$nimi` is passed into `_kasvattajanimet_muokkaa($nimi, $sivu, $tapa, $id)` (`Kasvatus.php:656`) — a value that originates from a URI segment (route parameter), not a validated/bound query parameter.
- Files: `fuel/application/models/Kasvattajanimi_model.php:271,294`, `fuel/application/controllers/Kasvatus.php:656,703,723`
- Current mitigation: None visible at these specific call sites (no `(int)` cast or bound parameter). Other nearby methods in the same model (e.g., lines 280-284) correctly use CI Query Builder methods which auto-escape.
- Recommendations: Cast `$id`/`$nimi` to `(int)` before use if it is expected to always be numeric, or switch to bound query parameters (`$this->db->query($sql, array($id))`), consistent with CodeIgniter's built-in escaping mechanisms.

**Legacy SHA1 password hashing still supported as a fallback:**
- Risk: `fuel/application/models/Ion_auth_model.php:339` retains a `_password_verify_sha1_legacy()` fallback path (marked `// Handle legacy SHA1 @TODO to delete in later revision`) alongside modern `password_verify()`. Accounts that have not logged in since the migration to `password_hash` remain protected only by SHA1+salt (`Ion_auth_model.php:2759,2774`), which is a weak, fast hash unsuitable for password storage against offline brute force.
- Files: `fuel/application/models/Ion_auth_model.php:321-341,2731,2759,2774`
- Current mitigation: `rehash_password_if_needed()` (`Ion_auth_model.php:353`) exists to upgrade hashes on successful login, so accounts self-heal over time as users log in.
- Recommendations: Track and force-migrate (e.g., forced password reset) for any accounts that still carry a legacy SHA1 hash after a reasonable grace period, then remove the legacy path entirely per the existing TODO.

**No automated dependency vulnerability scanning detected:**
- Risk: `fuel/application/composer.json` only declares `aws/aws-sdk-php`, but the vendored tree also contains Guzzle, Symfony polyfills, etc. under `fuel/application/vendor/`. There is no CI configuration found in the repo to run `composer audit` or equivalent.
- Files: `fuel/application/composer.json`, `fuel/application/vendor/*`
- Current mitigation: None detected.
- Recommendations: Add `composer audit` (or a Dependabot/Renovate equivalent) to catch known CVEs in AWS SDK, Guzzle, and other vendored packages.

## Performance Bottlenecks

**Unbounded LIKE-based full text scans in stats recovery script:**
- Problem: `Update_stats.php:38-43` performs a `LIKE '%...%'` scan across `vrlv3_kisat_tulokset.tulokset` joined with `vrlv3_kisat_kisakalenteri`, with a comment stating "PLAN B: Haetaan suoraan tekstistä LIKE-haulla, koska osallistujataulu on tyhjä" (searching directly in free text because the participant table is empty).
- Files: `fuel/application/controllers/Update_stats.php:37-45`
- Cause: Leading-wildcard `LIKE` patterns cannot use an index, forcing a full table scan; the comment indicates this is a workaround for missing/incomplete data in a proper participants table (`osallistujat`).
- Improvement path: Populate/maintain the intended `osallistujat` table so lookups can use indexed joins instead of text scanning, which would also remove the fragile hardcoded-value pattern noted above.

## Fragile Areas

**Competition scoring/class libraries with unresolved TODOs:**
- Files: `fuel/application/libraries/Jaos.php:493,564` (`//TODO: Katso kisaluokat` — "check competition classes"), `fuel/application/libraries/Kisajarjestelma.php:476` (`//TODO_ Takaaja` — guarantor/backup logic)
- Why fragile: These TODOs mark known-incomplete logic in files that directly compute competition classes and results (`Kisajarjestelma.php` is 1,017 lines and central to the competition system), consistent with the repeated scoring-calculator bugfixes seen in recent commits.
- Safe modification: Any change to class/placement logic in `Jaos.php` or `Kisajarjestelma.php` should be manually cross-checked against real competition data before deploying, since there is no automated test suite to catch regressions.
- Test coverage: None (see below).

**Data migration model mixing legacy and new schema logic:**
- Files: `fuel/application/models/migraatio_model.php` (753 lines)
- Why fragile: Contains large blocks of raw, multi-line concatenated SQL migrating from legacy tables (`tunnukset`, `tallirekisteri`, `hevosrekisteri_perustiedot`) into `vrlv3_`-prefixed tables, with hardcoded values (e.g., `WHERE kasvattajanimi = 'Karkurannan'` at line 507) that only make sense for a specific one-time migration run.
- Safe modification: Treat this file as historical/one-shot migration tooling, not reusable application logic; avoid calling its methods outside of the original migration context.
- Test coverage: None.

## Scaling Limits

Not assessed — no infrastructure/deployment configuration (e.g., queue workers, cache layer sizing, DB connection pool limits) was found in the repository to evaluate against. `fuel/application/config/memcached.php` indicates memcached is available for caching, but usage patterns were not audited in this pass.

## Dependencies at Risk

**AWS SDK for PHP (`aws/aws-sdk-php`):**
- Risk: Declared as `^3.321` in `fuel/application/composer.json`; large, frequently-updated SDK. Without CI dependency scanning (see Security section), point-release vulnerabilities could go unnoticed.
- Impact: Any S3/EC2 credential-handling code (`fuel/application/vendor/aws/aws-sdk-php/src/S3/S3Client.php`, `.../Credentials/CredentialProvider.php`) is security-sensitive; an unpatched SDK could expose stored file access or credentials.
- Migration plan: No migration needed; just ensure the SDK is kept current and add automated advisories.

## Missing Critical Features

Not assessed in this pass — no product requirements or roadmap documents were reviewed to identify functional gaps.

## Test Coverage Gaps

**No automated test suite exists anywhere in the application code:**
- What's not tested: The entire `fuel/application/` tree (controllers, models, libraries) has zero PHPUnit or other test files. Searches for `test*.php`, `*Test.php`, and `phpunit*` config outside vendor directories returned no results.
- Files: N/A — absence is repo-wide for `fuel/application/*`
- Risk: Changes to competition scoring (`Kisajarjestelma.php`, `Jaos.php`), authentication (`Ion_auth_model.php`), and data migration (`migraatio_model.php`) rely entirely on manual verification. This directly correlates with the repeated scoring-calculator hotfixes visible in recent git history.
- Priority: High — start with unit tests around the VRL scoring formula in `Update_stats.php`/`Kisajarjestelma.php` and the SQL-injection-risk methods in `Kasvattajanimi_model.php`, since these are the areas with the most recent bug churn and highest security sensitivity.

---

*Concerns audit: 2026-07-19*
