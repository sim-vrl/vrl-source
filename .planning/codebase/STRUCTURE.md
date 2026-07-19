# Codebase Structure

**Analysis Date:** 2026-07-19

## Directory Layout

```
vrlv3-update/
├── assets/                    # Static frontend assets served directly
│   ├── css/                   # Stylesheets (main.css, vrl.css, blog.css, ...)
│   ├── js/                    # jQuery + hand-written JS (main.js, periytymisjavascript.js)
│   ├── images/                # Images, icons, flags
│   ├── docs/, pdf/, swf/      # Downloadable/legacy assets
│   └── cache/                 # Generated cache output
├── database/                  # Raw SQL schema/seed dumps for fresh installs
├── esimerkit/rajapinta/       # Example external API client scripts (PHP)
├── fuel/                      # Application root (FUEL CMS + CodeIgniter)
│   ├── application/           # Project-specific app code (the "real" codebase)
│   │   ├── config/            # CI + FUEL + Ion Auth config files
│   │   ├── controllers/       # HTTP controllers, one per feature area
│   │   ├── core/              # MY_* overrides of CodeIgniter core classes
│   │   ├── helpers/           # MY_* procedural helper function files
│   │   ├── hooks/             # CI hook definitions
│   │   ├── language/          # i18n language files
│   │   ├── libraries/         # Domain/business logic classes
│   │   ├── migrations/        # Numbered CI schema migrations
│   │   ├── models/            # *_model.php database access classes
│   │   ├── third_party/       # Vendored third-party libraries
│   │   ├── vendor/            # Composer dependencies
│   │   └── views/             # PHP view templates, per-feature folders
│   ├── codeigniter/           # Vendored CodeIgniter 2.x framework (do not edit)
│   ├── modules/fuel/          # FUEL CMS module (admin UI, page/block system)
│   ├── install/               # FUEL install/upgrade scripts
│   ├── data_backup/           # Backup output directory
│   ├── scripts/                # Shell/utility scripts
│   └── index.php              # FUEL-side front controller
├── wiki/                      # Project documentation/wiki content
├── index.php                  # Main HTTP front controller (site entry point)
├── docker-compose.yml, Dockerfile, 000-default.conf  # Container/deploy config
├── .htaccess                  # Apache rewrite rules
└── README.md
```

## Directory Purposes

**`fuel/application/controllers/`:**
- Purpose: One class per top-level feature area, handles routing targets
- Contains: `Virtuaalihevoset.php` (horse registry), `Tallit.php` (stables), `Kilpailutoiminta.php` (competitions), `Kisakeskus.php` (competition center), `Kasvatus.php` (breeding/breeder names), `Jasenyys.php` (membership), `Liitto.php` (federation info), `Alayhdistykset.php` (sub-associations), `Profiili.php` (user profile), `Auth.php` (Ion Auth wrapper), `Rajapinta.php` (external API), `Main.php` (home/misc), `Update_stats.php` (stats recompute job), `Yllapito_*.php` (admin sections: tunnukset/accounts, hevosrekisteri/horse registry, jaokset/divisions, kalenterit/calendars, puljut/sub-clubs, tiedotukset/announcements)
- Key files: `fuel/application/controllers/Virtuaalihevoset.php`, `fuel/application/controllers/Rajapinta.php`

**`fuel/application/models/`:**
- Purpose: Database access, one model per domain entity, mostly extend FUEL's `Base_module_model`
- Key files: `Hevonen_model.php` (horses), `Tallit_model.php` (stables), `Sport_model.php` (competition results), `Jaos_model.php` (divisions/events), `Tunnukset_model.php` (member accounts/applications), `Ion_auth_model.php` (auth), `Listat_model.php` (dropdown/lookup lists), `Breed_model.php`, `Color_model.php`, `Trait_model.php` (genetics reference data), `Kasvattajanimi_model.php` (breeder names), `Uutiset_model.php` (news), `migraatio_model.php`, `Oikeudet_model.php` (rights), `Misc_model.php`

**`fuel/application/libraries/`:**
- Purpose: Domain/business logic reused across controllers and models
- Key files: `User_rights.php` (permission gate), `Ion_auth.php` (auth library, third-party), `Kisajarjestelma.php` and `Porrastetut.php` (competition/tiered scoring engines), `Color_inheritance.php` (horse color genetics calculator), `Pedigree_printer.php` (pedigree rendering), `Jaos.php` (division helpers), `Vrl_helper.php`/`Vrl_email.php` (project-wide utilities/mail), `Age_calc.php`, `Bcrypt.php`, `Ownership.php`, `Queue_manager.php`, `Events.php`, `Form_collection.php`

**`fuel/application/views/`:**
- Purpose: PHP view templates, one directory per feature, matching controller names
- Key subfolders: `hevoset/` (horse pages), `tallit/` (stables), `kilpailutoiminta/` (competitions), `kisakeskus/`, `jaokset/` (divisions), `jasenyys/` (membership), `kasvattajanimet/` (breeder names), `puljut/`, `liitto/`, `profiili/`, `tiedotukset/`, `yllapito/` (admin), `auth/` (Ion Auth views), `email/` (email templates), `misc/`, `errors/`
- FUEL CMS convention folders (underscore-prefixed): `_layouts/`, `_blocks/`, `_posts/`, `_variables/`, `_admin/`, `_docs/`

**`fuel/application/core/`:**
- Purpose: `MY_*`-prefixed overrides/extensions of CodeIgniter framework internals, auto-loaded by CI's extension convention
- Key files: `MY_Controller.php`, `Loggedin_Controller.php` (base controller requiring auth), `MY_Loader.php`, `MY_Router.php`, `MY_DB_mysqli_driver.php`, `MY_DB_mysql_driver.php`, `MY_Exceptions.php`, `MY_Hooks.php`, `MY_Model.php`

**`fuel/application/helpers/`:**
- Purpose: Procedural CI helper functions, auto/manually loaded
- Key files: `MY_string_helper.php`, `MY_date_helper.php`, `MY_array_helper.php`, `MY_url_helper.php`, `MY_html_helper.php`, `MY_file_helper.php`, `MY_directory_helper.php`, `MY_language_helper.php`, `my_helper.php`

**`fuel/application/config/`:**
- Purpose: Application configuration — routing, environments, auth, custom fields, module settings
- Key files: `routes.php` (URL routing table), `environments.php` (per-host env selection), `ion_auth.php` (auth settings), `MY_fuel.php`/`MY_fuel_layouts.php`/`MY_fuel_modules.php` (FUEL CMS config), `custom_fields.php`, `autoload.php`, `constants.php`

**`fuel/application/migrations/`:**
- Purpose: Numbered CI schema migrations
- Key files: `001_install.php`

**`fuel/modules/fuel/`:**
- Purpose: The vendored/extended FUEL CMS module itself — provides the admin backend, page rendering, block/variable system
- Contains: mirrors app structure (`controllers/`, `models/`, `views/`, `libraries/`, `config/`, `helpers/`)

**`fuel/codeigniter/`:**
- Purpose: Vendored CodeIgniter 2.x framework core — not project-specific, should not be modified directly (extend via `application/core/MY_*` instead)

**`database/`:**
- Purpose: Raw SQL dumps for schema/data used to bootstrap a fresh database (`1 fuel_schema.sql` through `5 from_scratch_insert.sql`)

**`esimerkit/rajapinta/`:**
- Purpose: Example scripts showing how external consumers call the API surface exposed by `Rajapinta.php` (`porrastetut.php`, `varsat.php`)

**`assets/`:**
- Purpose: Static files served directly by the web server, referenced from views via `site_url('assets/...')`

**`wiki/`:**
- Purpose: Project documentation/wiki content, not application code

## Key File Locations

**Entry Points:**
- `index.php`: Main HTTP front controller
- `fuel/index.php`: FUEL install-side front controller

**Configuration:**
- `fuel/application/config/routes.php`: URL-to-controller routing table
- `fuel/application/config/environments.php`: environment detection by host
- `fuel/application/config/ion_auth.php`: authentication configuration
- `fuel/application/config/MY_fuel.php`: FUEL CMS core settings

**Core Logic:**
- `fuel/application/controllers/`: request handling per feature
- `fuel/application/models/`: data access per entity
- `fuel/application/libraries/`: domain logic (scoring, genetics, permissions, email)

**Testing:**
- `fuel/modules/fuel/tests/`: FUEL module's own test scaffolding (third-party, not project tests)
- No project-level `tests/` directory was found for `fuel/application/`

## Naming Conventions

**Files:**
- Controllers: PascalCase matching class name, e.g. `Virtuaalihevoset.php`, `Yllapito_hevosrekisteri.php`
- Models: PascalCase with `_model` suffix, e.g. `Hevonen_model.php`, `Tallit_model.php`
- Libraries: PascalCase, e.g. `Color_inheritance.php`, `User_rights.php`
- Helpers: lowercase with `MY_` prefix and `_helper` suffix, e.g. `MY_string_helper.php`
- Core overrides: `MY_` prefix matching the CI class being extended, e.g. `MY_Router.php`
- Views: lowercase snake_case, grouped in a directory matching the owning controller/feature, e.g. `views/hevoset/hevonen_muokkaa.php`

**Directories:**
- Domain/feature name in Finnish, lowercase (`hevoset`=horses, `tallit`=stables, `jaokset`=divisions, `kasvattajanimet`=breeder names, `jalostus`=breeding)
- Admin-only areas prefixed `yllapito_` at controller level and grouped under `views/yllapito/`
- FUEL CMS convention directories use a leading underscore (`_layouts`, `_blocks`, `_posts`, `_variables`, `_admin`, `_docs`)

## Where to Add New Code

**New Feature (new top-level area):**
- Controller: `fuel/application/controllers/<FeatureName>.php` (PascalCase, extends `CI_Controller` or `Loggedin_Controller`)
- Model: `fuel/application/models/<featurename>_model.php` (extends `Base_module_model` if it needs FUEL CRUD support)
- Views: new folder `fuel/application/views/<featurename>/`
- Routes: add entries to `fuel/application/config/routes.php` if URLs need to differ from the default `class/method/id` pattern

**New Admin Section:**
- Controller: `fuel/application/controllers/Yllapito_<name>.php`, gated with `$allowed_user_groups`
- Views: `fuel/application/views/yllapito/<name>/`
- Routes: add `yllapito/<name>` entries in `routes.php`

**New Domain/Business Logic:**
- Add to `fuel/application/libraries/` as a new class, loaded via `$this->load->library('new_lib')`

**Shared Utilities:**
- Procedural helpers: `fuel/application/helpers/MY_<type>_helper.php`
- Reusable class-based helpers: `fuel/application/libraries/Vrl_helper.php`

**External API Endpoint:**
- Extend `fuel/application/controllers/Rajapinta.php`; add example usage under `esimerkit/rajapinta/` if the endpoint is meant for external federation consumers

**Database Changes:**
- Add a new numbered migration in `fuel/application/migrations/` following the `NNN_description.php` pattern established by `001_install.php`

## Special Directories

**`fuel/application/cache/`:**
- Purpose: Runtime CI cache output
- Generated: Yes
- Committed: Not typically (check `.gitignore`)

**`fuel/application/logs/`:**
- Purpose: CodeIgniter application logs
- Generated: Yes
- Committed: No

**`fuel/data_backup/`:**
- Purpose: Backup output location referenced by FUEL/backup tooling
- Generated: Yes
- Committed: No (contains only `.htaccess`/`index.html` placeholders in repo)

**`assets/cache/`:**
- Purpose: Generated frontend cache artifacts
- Generated: Yes
- Committed: Partially (an `index.html` placeholder is tracked)

**`fuel/application/vendor/`:**
- Purpose: Composer-installed PHP dependencies
- Generated: Yes (via `composer install`)
- Committed: Check repo state; `composer.json`/`composer.lock` present at `fuel/application/`

---

*Structure analysis: 2026-07-19*
