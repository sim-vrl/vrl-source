<!-- refreshed: 2026-07-19 -->
# Architecture

**Analysis Date:** 2026-07-19

## System Overview

```text
┌─────────────────────────────────────────────────────────────┐
│                     HTTP Request (Apache)                    │
│              `.htaccess`, `000-default.conf`                 │
└───────────────────────────┬───────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────┐
│                   Front Controller                           │
│                   `index.php`, `fuel/index.php`               │
│  Bootstraps CodeIgniter 2.x + resolves FUEL_PATH/INSTALL_ROOT │
└───────────────────────────┬───────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────┐
│              Router / Routes                                 │
│        `fuel/application/config/routes.php`                  │
│  Maps friendly Finnish URLs to controller/method, falls      │
│  back to FUEL CMS page router (`fuel/page_router`) for CMS   │
│  pages managed through the FUEL admin.                        │
└──────────────┬───────────────────────────┬────────────────────┘
               ▼                           ▼
┌────────────────────────────┐  ┌─────────────────────────────┐
│  App Controllers            │  │  FUEL CMS Module             │
│  `fuel/application/         │  │  `fuel/modules/fuel/*`       │
│   controllers/*.php`        │  │  Admin CMS, page/block       │
│  (CI_Controller /            │  │  rendering, asset mgmt      │
│   Loggedin_Controller)      │  │                              │
└──────────────┬───────────────┘  └───────────────┬──────────────┘
               ▼                                   ▼
┌─────────────────────────────────────────────────────────────┐
│              Libraries (business logic)                      │
│  `fuel/application/libraries/*.php`                            │
│  e.g. `User_rights.php`, `Kisajarjestelma.php`,               │
│  `Color_inheritance.php`, `Porrastetut.php`, `Jaos.php`        │
└───────────────────────────┬───────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────┐
│              Models (data access)                             │
│  `fuel/application/models/*_model.php`                         │
│  Extend `Base_module_model` (FUEL) or CI_Model. Direct         │
│  `$this->db->` query-builder calls, no ORM.                    │
└───────────────────────────┬───────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────┐
│                    MySQL Database                             │
│        Schema seeded from `database/*.sql`                    │
└─────────────────────────────────────────────────────────────┘
                             ▲
                             │
┌─────────────────────────────────────────────────────────────┐
│              Views (presentation)                             │
│  `fuel/application/views/**/*.php`                              │
│  Per-feature folders (`hevoset/`, `tallit/`, `jaokset/`, ...)  │
│  + FUEL CMS layout/block partials (`_layouts/`, `_blocks/`,    │
│  `_posts/`, `_variables/`, `_admin/`, `_docs/`)                │
└─────────────────────────────────────────────────────────────┘
```

## Component Responsibilities

| Component | Responsibility | File |
|-----------|----------------|------|
| Front controller | Bootstraps CodeIgniter + FUEL paths | `index.php` |
| Route map | Maps custom URLs (mostly Finnish) to controller/method | `fuel/application/config/routes.php` |
| Controllers | HTTP request handling, permission checks, loads models/libs, assembles view data | `fuel/application/controllers/*.php` |
| FUEL CMS module | Admin backend, CMS page/block rendering, generic content management | `fuel/modules/fuel/*` |
| Libraries | Domain logic — auth/rights, competition ("kisajärjestelmä"), pedigree/color genetics, queueing | `fuel/application/libraries/*.php` |
| Models | Database access via CI query builder, extends FUEL `Base_module_model` | `fuel/application/models/*_model.php` |
| Views | PHP-templated HTML output, one folder per feature area | `fuel/application/views/*` |
| Helpers | Procedural utility functions (string, date, array, URL, HTML overrides) | `fuel/application/helpers/MY_*.php` |
| Core overrides | Extends/replaces CodeIgniter core classes (DB drivers, Loader, Router, base controllers) | `fuel/application/core/*.php` |
| Config | Environment, routing, auth, custom fields, FUEL module settings | `fuel/application/config/*.php` |
| Migrations | Numbered CI migration files for schema changes | `fuel/application/migrations/*.php` |
| Database seed | Raw SQL schema/data dumps used for fresh installs | `database/*.sql` |
| Assets | Static CSS/JS/images/PDF served directly | `assets/*` |

## Pattern Overview

**Overall:** MVC on top of CodeIgniter 2.x, extended by the FUEL CMS module (`fuel/modules/fuel`). This is a classic "fat controller, some fat model" PHP application — no service layer, no dependency injection container, no ORM.

**Key Characteristics:**
- Two parallel content systems coexist: hand-written app controllers/views (feature routes like `virtuaalihevoset`, `tallit`, `kilpailutoiminta`) and FUEL CMS-managed pages (rendered via `fuel/page_router` and `_layouts`/`_blocks` views).
- Controllers directly call `$this->load->model(...)`/`$this->load->library(...)` in constructors — no central service container.
- Domain-specific "business logic" lives in `libraries/` (e.g. `Color_inheritance.php` for horse-color genetics, `Kisajarjestelma.php` for competition scoring, `Porrastetut.php` for tiered results).
- Access control is enforced per-controller via `$allowed_user_groups` + `User_rights` library, not centrally in routing.
- All domain naming is Finnish (hevonen=horse, talli=stable, kilpailu=competition, jaos=division/event, kasvattaja=breeder).

## Layers

**Controllers (`fuel/application/controllers/`):**
- Purpose: HTTP entry points, request validation, permission gating, view assembly
- Location: `fuel/application/controllers/*.php`
- Contains: One class per feature area (`Virtuaalihevoset.php`=horses, `Tallit.php`=stables, `Kilpailutoiminta.php`=competitions, `Yllapito_*.php`=admin sections, `Rajapinta.php`=external API)
- Depends on: models, libraries, FUEL page renderer (`$this->fuel->pages->render(...)`)
- Used by: routed HTTP requests

**Libraries (`fuel/application/libraries/`):**
- Purpose: Reusable domain logic not tied to a single controller/model
- Location: `fuel/application/libraries/*.php`
- Contains: `User_rights.php` (permission checks), `Ion_auth.php` (auth library, third-party), `Kisajarjestelma.php`/`Porrastetut.php` (competition scoring engines), `Color_inheritance.php`/`Pedigree_printer.php` (breeding/genetics), `Vrl_email.php`/`Vrl_helper.php` (project-wide helpers), `Queue_manager.php`, `Bcrypt.php`
- Depends on: models (some), CI core services
- Used by: controllers, models

**Models (`fuel/application/models/`):**
- Purpose: Database access, one model roughly per domain entity
- Location: `fuel/application/models/*_model.php`
- Contains: `Hevonen_model.php` (horses), `Tallit_model.php` (stables), `Sport_model.php` (competitions/results), `Jaos_model.php` (event divisions), `Tunnukset_model.php` (member accounts), `Ion_auth_model.php` (auth), `Listat_model.php` (lookup lists)
- Depends on: `Base_module_model` (FUEL) and CI query builder (`$this->db`)
- Used by: controllers, some libraries

**Views (`fuel/application/views/`):**
- Purpose: PHP-templated HTML rendering
- Location: `fuel/application/views/<feature>/*.php`
- Contains: One directory per feature (`hevoset/`, `tallit/`, `jaokset/`, `kilpailutoiminta/`, `kisakeskus/`, `liitto/`, `puljut/`, `tiedotukset/`, `yllapito/`, `profiili/`, `jasenyys/`, `kasvattajanimet/`, `email/`, `auth/`) plus FUEL CMS convention folders prefixed with `_` (`_layouts/`, `_blocks/`, `_posts/`, `_variables/`, `_admin/`, `_docs/`)
- Depends on: data arrays passed from controllers
- Used by: `$this->load->view(...)` and FUEL's page renderer

**FUEL CMS module (`fuel/modules/fuel/`):**
- Purpose: Third-party CMS layer providing admin UI, page/block management, asset handling
- Location: `fuel/modules/fuel/*`
- Contains: its own controllers, models, views, libraries mirroring the app structure
- Depends on: CodeIgniter core (`fuel/codeigniter/`)
- Used by: `fuel/page_router` route fallback, admin URLs

**CodeIgniter core (`fuel/codeigniter/`):**
- Purpose: Underlying framework (v2.x) — routing, DB drivers, loader, base libraries
- Location: `fuel/codeigniter/*`
- Overridden/extended by: `fuel/application/core/MY_*.php` (custom DB drivers, loader, router, controllers)

## Data Flow

### Primary Request Path

1. Apache routes request to `index.php` (front controller) which sets up FUEL/CI paths and includes CodeIgniter's bootstrap (`fuel/codeigniter/core/CodeIgniter.php`).
2. CI router matches the URI against `fuel/application/config/routes.php`; unmatched URIs fall through to `fuel/page_router` (FUEL CMS page lookup).
3. Matched controller constructor runs (`fuel/application/controllers/<Feature>.php`), loading required models/libraries and often gating access via `User_rights` (`fuel/application/libraries/User_rights.php`) checked against a `$allowed_user_groups` array.
4. Controller method builds a `$data`/`$vars` array, calling model methods (`fuel/application/models/*_model.php`) that run CI query-builder calls against MySQL.
5. Controller renders a view via `$this->load->view('feature/view_name', $data)` or, for CMS pages, `$this->fuel->pages->render('misc/pagename')`.
6. View (`fuel/application/views/<feature>/*.php`) emits HTML, often embedding JSON-encoded data (`json_encode(...)`) for client-side JS consumption (see `assets/js/main.js`, `assets/js/periytymisjavascript.js`).

### Admin ("yllapito") Flow

1. Requests under `/yllapito/*` route to `Yllapito_*` controllers (`Yllapito_tunnukset.php`, `Yllapito_hevosrekisteri.php`, `Yllapito_jaokset.php`, `Yllapito_kalenterit.php`, `Yllapito_puljut.php`, `Yllapito_tiedotukset.php`).
2. Each admin controller enforces its own permission group check, then performs CRUD against the relevant model.
3. Views live in `fuel/application/views/yllapito/`.

**State Management:**
- Session/auth state handled by Ion Auth (`fuel/application/libraries/Ion_auth.php`, `fuel/application/models/Ion_auth_model.php`), backed by CI's native session library.
- No client-side app state framework; state is server-rendered per request, with jQuery (`assets/js/jquery.js`, `assets/js/main.js`) handling in-page interactivity.

## Key Abstractions

**`Base_module_model` (FUEL):**
- Purpose: Base class most domain models extend, providing CRUD scaffolding compatible with FUEL's admin CRUD generator
- Examples: `fuel/application/models/Hevonen_model.php`, `fuel/application/models/Tallit_model.php`
- Pattern: Subclass sets `$this->db->select/from/join/where` chains and returns `$query->result_array()`

**`User_rights` library:**
- Purpose: Central permission gate for controllers
- Examples: constructed in controller `__construct()` via `$this->load->library('user_rights', array('groups' => $this->allowed_user_groups))`, checked with `$this->user_rights->is_allowed()`
- Pattern: Each controller declares a private `$allowed_user_groups` array of Ion Auth group names

**MY_* Core Overrides:**
- Purpose: Extend CodeIgniter internals project-wide (custom DB result handling, loader, router, controller base classes)
- Examples: `fuel/application/core/MY_Loader.php`, `fuel/application/core/MY_Router.php`, `fuel/application/core/Loggedin_Controller.php`, `fuel/application/core/MY_Controller.php`
- Pattern: CodeIgniter's `MY_` prefix convention for auto-loaded class extension/replacement

## Entry Points

**Public web front controller:**
- Location: `index.php`
- Triggers: Every HTTP request (via Apache rewrite, see `.htaccess`)
- Responsibilities: Resolves FUEL/CI install paths, environment detection, bootstraps CodeIgniter

**FUEL install front controller:**
- Location: `fuel/index.php`
- Triggers: Requests under FUEL install/upgrade tooling
- Responsibilities: FUEL-specific bootstrap variant

**Admin/API controller:**
- Location: `fuel/application/controllers/Rajapinta.php`
- Triggers: `/liitto/rajapinta` route and external API example clients (`esimerkit/rajapinta/*.php`)
- Responsibilities: Serves data to external consumers (e.g. federation systems)

**Stats/cron entry point:**
- Location: `fuel/application/controllers/Update_stats.php`
- Triggers: Scheduled/CLI invocation to recompute cached statistics
- Responsibilities: Batch recalculation job, not a typical user-facing controller

## Architectural Constraints

- **Threading:** Standard synchronous PHP-per-request execution under Apache/mod_php (or PHP-FPM); no async runtime, no queue workers beyond the in-process `Queue_manager.php` library.
- **Global state:** CodeIgniter's `$this->CI =& get_instance()` super-object pattern is used throughout models/libraries to access loaded services, creating implicit global coupling (e.g. `Hevonen_model.php` calls `$this->CI->ion_auth`).
- **Circular imports:** Controllers load models which sometimes load libraries which reference `$this->CI` back into controller-loaded services; not a strict layering, tightly coupled via the CI super-object.
- **Dual routing systems:** Custom `routes.php` entries can conflict with FUEL CMS's own page-based routing (`404_override` → `fuel/page_router`); adding a new user-facing URL requires checking both systems.
- **No package/build step:** No JS/CSS bundler; assets in `assets/js` and `assets/css` are hand-authored and referenced directly.

## Anti-Patterns

### Fat controllers with inline SQL prep and JSON-in-HTML

**What happens:** Controllers (e.g. `fuel/application/controllers/Virtuaalihevoset.php::haku()`) build `$vars['headers']` arrays, JSON-encode them, and pass to views that embed them as inline `<script>` data for jQuery to consume.
**Why it's wrong:** Mixes presentation formatting (table headers, links) with controller logic; makes it hard to reuse the same data via a real API endpoint.
**Do this instead:** New JSON-facing endpoints should go through `fuel/application/controllers/Rajapinta.php` (the existing API controller) rather than embedding JSON in server-rendered pages.

### Direct `$this->CI` super-object access in models

**What happens:** Models like `Hevonen_model.php` reach into `$this->CI->ion_auth->user()` for the current user rather than receiving it as a parameter.
**Why it's wrong:** Makes models hard to test in isolation and hides dependencies.
**Do this instead:** When adding new model methods, prefer passing the needed user/session data explicitly as method arguments, matching the pattern already used elsewhere (e.g. `search_horse($reknro, $nimi, ...)` in `Hevonen_model.php`).

## Error Handling

**Strategy:** Standard CodeIgniter error views plus manual `&$msg` by-reference error message parameters on model methods (e.g. `Hevonen_model::delete_hevonen($reknro, &$msg, $admin = false)`).

**Patterns:**
- Error pages rendered via `fuel/application/views/errors/html` and `errors/cli`.
- Model methods commonly return `true`/`false` or empty arrays and populate an `&$msg` reference parameter rather than throwing exceptions.
- `fuel/application/core/MY_Exceptions.php` customizes CI's exception/error display.

## Cross-Cutting Concerns

**Logging:** CodeIgniter's native logging to `fuel/application/logs/`; no external log aggregation detected.
**Validation:** CI's `form_validation` library loaded per-controller (e.g. `$this->load->library('form_validation')` in `Virtuaalihevoset.php`), plus custom validators in individual controllers (`validate_horse_search_form()`).
**Authentication:** Ion Auth (`fuel/application/libraries/Ion_auth.php`, `fuel/application/config/ion_auth.php`, `fuel/application/models/Ion_auth_model.php`), group-based (`admin`, `hevosrekisteri`, etc.), enforced per-controller via `User_rights`.

---

*Architecture analysis: 2026-07-19*
