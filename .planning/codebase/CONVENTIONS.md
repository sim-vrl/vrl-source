# Coding Conventions

**Analysis Date:** 2026-07-19

## Naming Patterns

**Files:**
- Controllers: PascalCase, singular/plural Finnish domain words, e.g. `fuel/application/controllers/Kilpailutoiminta.php`, `fuel/application/controllers/Yllapito_tunnukset.php` (admin controllers prefixed `Yllapito_`)
- Models: PascalCase + `_model` suffix, e.g. `fuel/application/models/Hevonen_model.php`, `fuel/application/models/Jaos_model.php`
- Helpers: `MY_<name>_helper.php` (CodeIgniter override convention), e.g. `fuel/application/helpers/MY_string_helper.php`; one non-conforming file `fuel/application/helpers/my_helper.php` (lowercase)
- Libraries: PascalCase, e.g. `fuel/application/libraries/Vrl_helper.php`, `fuel/application/libraries/Vrl_email.php`
- Views organized by domain in subdirectories matching controller names, e.g. `fuel/application/views/kilpailutoiminta/`, `fuel/application/views/yllapito/`

**Functions:**
- snake_case throughout (CodeIgniter/FUEL convention), e.g. `get_just_registered()`, `mass_insert_available()`, `delete_hevonen()` in `fuel/application/models/Hevonen_model.php`

**Variables:**
- snake_case, frequently in Finnish domain terminology (`$reknro`, `$hevonen`, `$jaos_id`, `$rekisteroity`) mixed with English CodeIgniter idioms (`$query`, `$date`)

**Types:**
- No type hints/strict typing used on function signatures despite PHP 8.1 requirement in `fuel/application/composer.json`. Functions declared as plain `function name($arg)` with no return types.

## Code Style

**Formatting:**
- No formatter or linter config found (no `.php-cs-fixer`, `.editorconfig`, `phpcs.xml`, or similar in repo root or `fuel/application/`)
- Indentation is inconsistent between files — 4 spaces in some (`Hevonen_model.php`), no indentation / column-0 statements in others (`fuel/application/controllers/Update_stats.php` lines 12-18, 79-102)
- Brace style mixed: `function foo(){` (no space) is common; some files use `function foo() {`

**Linting:**
- Not detected. No ESLint/PHP_CodeSniffer/PHPStan config present in the application tree.

## Import Organization

**Style:**
- CodeIgniter autoloading (`fuel/application/config/autoload.php`) plus explicit `$this->load->library(...)`, `$this->load->model(...)`, `require_once(...)` calls at top of controller/model constructors
- No namespaces/`use` statements — this is a pre-PSR-4, global-class-name CodeIgniter 2/3-style codebase (FUEL CMS)
- Models requiring FUEL base classes use `require_once(FUEL_PATH.'models/Base_module_model.php');` at top of file (see `fuel/application/models/Hevonen_model.php:3`)

## Error Handling

**Patterns:**
- Dominant pattern is boolean return + by-reference error message parameter, e.g. `delete_hevonen($reknro, &$msg, $admin = false)` in `fuel/application/models/Hevonen_model.php:77` — caller checks return value and reads `$msg` for the Finnish-language error string
- `try/catch` is rare; only 3 occurrences outside vendor code: `fuel/application/controllers/Virtuaalihevoset.php:882`, `fuel/application/libraries/Vrl_email.php:97`, `fuel/application/libraries/Vrl_helper.php:132`
- CLI/admin scripts call CodeIgniter's `show_error('message', 403)` for access control failures, e.g. `fuel/application/controllers/Update_stats.php:16`
- No centralized exception hierarchy or error-handling middleware; error messages are hardcoded Finnish strings returned inline to views/controllers

**Caution — SQL construction:**
- Most models use CodeIgniter Query Builder (`$this->db->select()/where()/get()`), which parameterizes values safely (see `fuel/application/models/Hevonen_model.php:25-37`)
- At least one controller builds raw SQL via string interpolation of unescaped input: `fuel/application/controllers/Update_stats.php:38-43,110-112` interpolates `$nro` directly into `LIKE`/`INSERT` SQL strings passed to `$this->db->query()`. Do not replicate this pattern in new code — use Query Builder or `$this->db->query($sql, array($bindings))` with bound parameters instead.

## Logging

**Framework:** CodeIgniter's built-in `log_message()` / `logs/` directory (`fuel/application/logs/`)

**Patterns:**
- Debug output in admin/CLI scripts is done via inline `echo` statements rather than structured logging (see `fuel/application/controllers/Update_stats.php:32-33,109,114,116`)

## Comments

**When to Comment:**
- Inline comments in Finnish explain business rules and magic numbers, e.g. VRL placement-scoring thresholds in `fuel/application/controllers/Update_stats.php:85-95`
- No consistent PHPDoc/DocBlock usage across models/controllers; comments are sparse and localized to non-obvious domain logic

**JSDoc/TSDoc:** Not applicable (PHP codebase)

## Function Design

**Size:** Functions vary widely; controller action methods (e.g. `update_stats()`) mix data access, business logic, and output/echo in a single method rather than being split into layers

**Parameters:** Optional parameters use default values (`$admin = false`); output parameters passed by reference (`&$msg`) are used for returning secondary error info alongside a boolean success/failure result

**Return Values:** Models typically return `array()` (empty array as a "not found" sentinel) or associative/result arrays from `$query->result_array()`; boolean returns are common for mutation methods (insert/update/delete)

## Module Design

**Exports:** Not applicable (PHP class-based, one class per file matching CodeIgniter loader conventions)

**Structure:** Built on FUEL CMS (a CodeIgniter admin/CMS framework) — models extend `Base_module_model` (`fuel/modules/fuel/...`) for FUEL admin integration; application code lives in `fuel/application/`, with `fuel/modules/fuel/` and `fuel/codeigniter/` as vendored/framework code that should not be modified for feature work

---

*Convention analysis: 2026-07-19*
