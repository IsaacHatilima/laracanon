# Laracanon

`isaachatilima/laracanon` is a Composer development package for existing Laravel applications. It installs selected pieces of a reusable toolkit: architecture conventions, package integrations, development practices, and authored workflows.

The repository is [IsaacHatilima/laracanon](https://github.com/IsaacHatilima/laracanon). The package has not been published to Packagist.

## Installation

Requires PHP 8.2+ and Laravel 11, 12, or 13. Composer checks compatibility with the application's PHP and framework constraints. Boost integration requires the `RuleRepository`, `RuleComposer`, and agent-skill APIs verified against **Laravel Boost v2.10.1**. An older installed Boost version without these APIs produces a clear failure; Laracanon does not force a framework, PHP, or existing Boost upgrade.

Install from GitHub by configuring a [VCS repository](https://getcomposer.org/doc/05-repositories.md#vcs) in the **target Laravel application**:

```sh
composer config repositories.laracanon vcs git@github.com:IsaacHatilima/laracanon.git
composer require --dev isaachatilima/laracanon:dev-main
```

For local development, configure a path repository instead. Adjust the path to this checkout:

```sh
composer config repositories.laracanon '{"type":"path","url":"../Laracanon","options":{"versions":{"isaachatilima/laracanon":"dev-main"}}}'
composer require --dev isaachatilima/laracanon:@dev
```

Laravel discovers the service provider automatically. Installing Laracanon alone installs no item dependencies, rules, skills, or configuration files. Boost is installed as an application development dependency when `canon:install` first needs it.

After a future registry release, installation will be `composer require --dev isaachatilima/laracanon`. This package has not been published.

## Commands

```sh
php artisan canon:list
php artisan canon:install
php artisan canon:install <item>
php artisan canon:install <item-a> <item-b>
php artisan canon:install --dry-run
php artisan canon:install <item-a> <item-b> --dry-run
```

`canon:list` shows each item's description, dependencies, and sample label. Duplicate names in one install are deduplicated. Unknown items and malformed item definitions fail before dependency changes.

`canon:install` with no item names installs every item in the configured catalog, including newly added Markdown files. With item names, it installs only those items. `canon:install --dry-run` previews the full catalog. An empty catalog is reported before any dependency or file changes.

`--dry-run` reads the application and ownership state, reports planned dependencies and files, and describes the Boost refresh. It runs no Composer or application subprocess and changes no files. Compatibility is checked by Composer during an actual installation; a dry run does not claim that a dependency can resolve.

Installation displays the current step and elapsed time while Composer or Boost runs. Composer progress identifies package metadata loading, dependency resolution, downloads, installation, autoload generation, and project scripts. A finalizing step covers Composer's remaining work after Boost, including its security audit when enabled. Redirected output and `--no-ansi` use readable step lines and periodic elapsed-time updates during silent work; `--quiet` suppresses progress. Dry runs label their progress as a preview. The indicator finishes before the summary and reports issues when failures or conflicts remain.

Installation exits nonzero if any failure or conflict remains. The summary lists completed items, dependency changes, rules, authored skills, configuration files, package resources, conflicts, and failures. Independent items can complete when another item's dependency fails. Completed work remains available for a retry.

## Available items

The library adapts the conventions from `Pamo/pamo-dashboard/.ai/rules/`, each in its own item, and adds a separately authored API response contract and selectable package integrations. Both groups use `resources/items/<item-name>.md` and the same commands; there is no second installer or manifest.

### Conventions and workflows

| Item | Scope |
| --- | --- |
| `architecture` | Request flow, actions owning database work, translation, and legacy-code boundaries |
| `actions` | Typed inputs, explicit persistence, transactions, composition, Fortify adapters, and an authored implementation workflow |
| `controllers` | Thin API/web controllers, shared actions, standard API envelopes, and an authored implementation workflow |
| `api-responses` | Shared API response envelopes, pagination, and centralized error formatting |
| `requests` | FormRequest authorization, validation, and action-backed cross-field checks |
| `models` | Relationships, casts, scopes, UUID keys, and no query or business work in model methods |
| `eloquent-strictness` | Rules for non-production lazy-loading, discarded-attribute, and missing-attribute guards |
| `enums` | String-backed enums with translated labels and select options |
| `migrations` | UUID entities, explicit foreign-key delete behavior, string enum columns, and reversibility |
| `frontend` | Domain folders, Inertia/React pages, Resource-shaped types, Wayfinder, and shadcn |
| `tests` | Pest 5 Unit/Feature suites, focused sharded runs, and Playwright browser coverage for frontend applications |
| `plans` | Concise contracts and acceptance checks in implementation plans |

### Packages

| Item | Dependency | Type | Scope |
| --- | --- | --- | --- |
| `data` | `spatie/laravel-data` | Runtime | Input factories, output Resources, normalization, and an authored project workflow |
| `insights` | `nunomaduro/phpinsights` | Development | PHP Insights with the exact City Directory configuration |
| `phpstan` | `phpstan/phpstan`, `larastan/larastan` | Development | Level 10 Laravel analysis with `phpstan.neon.dist` |

Convention items contain concise rules and may include a separately authored implementation skill. The rules define lasting conventions; an authored skill explains how to inspect the application, implement that concern, and verify the result. The skills stay in their own source items, with links to related workflows rather than mixed concerns. `eloquent-strictness` is a rules-only item: installation adds guidance without modifying the application provider or generating a custom skill. Package items can install configuration and rely on dependency-provided skills without adding project rules or generating a custom skill.

`insights` installs a missing compatible stable release of [PHP Insights](https://github.com/nunomaduro/phpinsights) into the application's `require-dev`, preserving an existing dependency's constraint and version. It writes `config/insights.php` exactly as captured from [City Directory](https://github.com/city-directory/citydirectory/blob/8c5cdb0093067933cb74481d7fb087ca2fb63b98/config/insights.php), including its exclusions, removed insights, metric configuration, and four minimum scores of 100. The literal configuration lives in the same [item source](resources/items/insights.md); no separate stub or manifest is required. A different existing configuration is reported as a conflict and preserved. Installation adds no Composer scripts and runs no analysis or fixes. The item authors no rules or custom skill; Boost discovers dependency resources when available.

```sh
php artisan canon:install insights --dry-run
php artisan canon:install insights
```

`phpstan` installs compatible stable releases of PHPStan and Larastan into `require-dev` and writes `phpstan.neon.dist` in the application root. Its exact configuration includes Larastan and Laravel's existing Carbon extension, sets level 10, analyzes `app`, and excludes `tests/*`, `database/*`, `config/*`, `vendor/*`, and `app/Providers/*`. Carbon's installed version is preserved. [Level 10 requires PHPStan 2](https://phpstan.org/user-guide/rule-levels); an incompatible existing PHPStan version is reported and the item's configuration is skipped. Existing constraints, local edits, and Composer scripts are preserved. A local `phpstan.neon` takes precedence over the distributed configuration. The [item source](resources/items/phpstan.md) contains the dependencies and literal configuration together, with no generated rule or custom skill.

```sh
php artisan canon:install phpstan --dry-run
php artisan canon:install phpstan
```

`tests` requires separate Unit and Feature suites, plus Playwright-backed Browser coverage for applications with a frontend. Its Pest 5 workflow confirms active `pest()->tia()` configuration in `tests/Pest.php`, adding `pest()->tia()->locally()->filtered();` when missing. It verifies TIA prerequisites or selects changed/impacted test files explicitly before sharding, then runs every shard of that selection without a routine whole-suite rerun. It checks runner/plugin compatibility and preserves existing PHP/Laravel constraints. Installing the item does not switch runners or install browser dependencies.

These adapt Pamo's conventions and path scopes, including its Inertia/React/Wayfinder/shadcn frontend, Pest testing, UUID entities, and `docs/superpowers/plans/` planning directory. Select the items appropriate to an application's stack. The frontend workflow applies to applications using that stack; API-only applications use the API/controller workflows. Importing the guidance does not install frontend packages or change application code. The generated Pamo `index.md` is not an item; Boost generates the target application's index from its installed rules.

```sh
php artisan canon:install architecture actions controllers api-responses requests data
php artisan canon:install data --dry-run
```

`actions` installs concise architecture conventions into `.ai/rules/laracanon-actions.md` and its explicitly authored workflow into `.ai/skills/laracanon-actions/SKILL.md`. The skill covers typed inputs, explicit create/update column mapping, transactions, locking, delivery after commit, action composition, read Resources using `fromModel()`, and Fortify adapters. Its single example is the same `CreateUser` action used in the Data flow below. Request payloads use Data; small internal arrays, such as ID lists or option maps, are allowed with documented element types or array shapes. Every create/update call still maps columns explicitly, including values taken from an internal array. The item adds no package dependencies; it uses the application's existing typed contracts. Boost publishes its rule and skill for the configured agents.

`controllers` installs transport-neutral rules into `.ai/rules/laracanon-controllers.md` and an explicitly authored workflow into `.ai/skills/laracanon-controllers/SKILL.md`. API guidance uses the shared `api-responses` contract for Resource JSON, status codes, centralized errors, and pagination; Inertia guidance covers Resource-backed props, redirects, and deliberate flash feedback. One shared CreateUser flow demonstrates both transports calling the same write action, with a read action preparing the API Resource. The item adds no dependencies and uses the application's existing response adapters.

`api-responses` installs its rules and an authored `laracanon-api-responses` skill. Body-bearing JSON responses use `data`, `message`, `errors`, `meta`, and `links`, with actual HTTP status codes. Success has `errors: null`; failures have `data: null`, a safe message, and validation field errors when applicable. Paginated responses retain their metadata and links. A 204 response remains bodyless. The skill supplies a reusable application `ApiResponse` helper and an API-only exception workflow; `canon:install` installs guidance rather than writing PHP helpers or changing application configuration. This is a Laracanon convention, not a claim about Laravel's default response format or the JSON:API specification.

`data` declares **`spatie/laravel-data` as a runtime dependency**, following [Spatie's official installation documentation](https://spatie.be/docs/laravel-data/v4/installation-setup). Laracanon installs a missing compatible stable release through Composer and preserves existing constraints and versions. The source file separates concise architecture rules from an explicitly authored implementation skill. The lasting conventions install into `.ai/rules/laracanon-data.md`; the workflow and complete user-creation example install into `.ai/skills/laracanon-data/SKILL.md`. Boost publishes both for the configured agents.

The Data conventions extend the Pamo rules with operation-specific input names and readonly input properties. The skill contains one complete user-creation example following `CreateUserRequest` → `CreateUserData::fromRequest()` → `CreateUser` → `CreateUserController`. The Data factory reads typed getters, normalizes names and emails, trims nullable phone input once and converts a blank result to null, and preserves the password exactly. The action checks normalized email uniqueness, explicitly maps database columns to Data properties, and hashes the password before persistence. The controller only delegates and redirects. Form keys and Data properties need not match database column names; both create and update actions map each column explicitly, such as `'phone_number' => $data->phoneNumber`. Resource and partial-update conventions remain documented without separate example flows.

Agents must use explicit field mapping directly in both create and update calls. Direct Data arguments, generic `$attributes` payloads, `toArray()`/`toAttributes()` persistence payloads, automatic column inference, and model-update adapters are not the convention. On Laravel 13+, the model's `#[Fillable]` must allow the intended columns. The attribute is documented in [Laravel's mass-assignment guide](https://laravel.com/docs/13.x/eloquent#mass-assignment). On Laravel 11/12, preserve the existing mass-assignment declaration; Laracanon does not require an upgrade for this convention. `canon:install data` installs guidance and the authored implementation skill; it does not rewrite PHP classes.

The `laracanon-data` skill is authored for these project conventions, rather than presented as an official Spatie skill. It keeps authorization and validation in FormRequests, normalization in `fromRequest()`, output shaping in `fromModel()`, and database work in actions. It uses [Spatie's documented creation APIs](https://spatie.be/docs/laravel-data/v4/as-a-data-transfer-object/creating-a-data-object) while enforcing the project's explicit factories. Any dependency-provided guidance or skills remain available under their own names. Other convention items may also contain explicitly authored workflows; the installer never synthesizes skills from rules or examples. The `api-responses` item supplies the shared transport response contract.

## Catalog and test fixtures

The installable catalog contains the real items above. Sample items are not shipped in `resources/items/`. Isolated Markdown files under `tests/Fixtures/Items/` demonstrate rules-only items, authored skills, and runtime/development dependency types for package tests; they are not selected by application installs.

Installing Laracanon itself selects no items. Running `canon:install` with no names selects the configured catalog. Item dependencies are installed only when their respective items are selected, including through install-all; they are not dependencies of Laracanon itself. General usage guidance and skills come from dependencies when those dependencies provide Boost resources.

## One Markdown file per item

Add `resources/items/<item-name>.md`. The filename must match the frontmatter `name`. No manifest, registration, PHP class, or installer change is needed.

```markdown
---
name: example-convention
description: A concise description of this selectable convention.
paths:
  - app/Example/**/*.php
  - tests/Feature/Example/**/*.php
dependencies:
  runtime:
    - vendor/runtime-package
  development:
    - vendor/development-tool
composer_plugins:
  vendor/development-tool:
    - vendor/composer-plugin
---

## Rules

Define conventions agents must follow in the applicable files.

## Examples

Illustrate those conventions. Explicit project preferences take precedence
over differing package examples.

## Skill

1. Inspect the existing implementation and its callers.
2. Apply this explicitly authored workflow.
3. Run the relevant checks and report their results.
```

Required frontmatter:

- `name`: lowercase kebab-case identifier matching the filename.
- `description`: a nonempty description, also used for generated skills.
- `paths`: a list of relative project paths or glob patterns. Use `[]` for a project-wide convention; Laracanon renders this as `['**']` so Boost indexes it. Absolute paths, parent traversal, and line breaks are rejected.

Optional frontmatter:

- `dependencies.runtime`: package names to add to the application's `require`.
- `dependencies.development`: package names to add to `require-dev`.
- `minimum_versions`: a mapping of declared dependency names to quoted stable `major.minor.patch` versions required by the item's resources. Incompatible or unverifiable installed or locked versions are reported without upgrades; that item's files are skipped. Actual installed versions are checked again after Composer completes.
- `composer_plugins`: a mapping of declared dependency names to lists of exact lowercase Composer plugin package names needed by those dependencies, including transitive plugins. Laracanon configures missing project permissions only for selected item dependencies; existing explicit allow/deny, pattern, and boolean policies take precedence. Version constraints, wildcard entries, malformed values, and duplicate plugins within one dependency list are rejected. Different dependencies may declare the same plugin.
- `sample: true`: label a demonstration in the list command.
- `skill_name`: a skill identifier, only with a nonempty authored Skill section. The default is `laracanon-<item-name>`.
- `overrides_skill: true`: explicitly allow this skill to replace a dependency-provided skill of the same name. This never authorizes overwriting locally edited files.

Dependency entries are Composer package names without version constraints. Laracanon asks Composer for the latest compatible **stable** release of missing packages. Existing application constraints and versions take precedence. If several selected items require a package, runtime placement wins over development placement. A dependency already in `require-dev` cannot satisfy a runtime requirement; Laracanon reports this so the project can explicitly move it while preserving its constraint.

Use `composer_plugins` when a dependency installs a Composer plugin that requires project permission. For example, the `insights` item declares `nunomaduro/phpinsights: [dealerdirect/phpcodesniffer-composer-installer]`. Selecting that item authorizes its declared integration; before the dependency installation, Laracanon adds an exact project-local `allow-plugins` entry only when no existing policy covers the plugin. It preserves unrelated Composer configuration and scripts, never enables a blanket or global permission, and reports permissions without writing them during a dry run.

The recognized sections are exactly `## Rules`, `## Examples`, `## Skill`, and `## Files`. Each is optional and may appear once, in any order. Empty sections are treated as absent. Lower-level headings and fenced examples are retained. Unknown metadata or top-level sections fail clearly rather than being silently ignored.

Rules describe lasting conventions. Skills describe implementation workflows and can include their own examples under lower-level headings. Put a workflow example inside `## Skill` when it should accompany the skill rather than the rule. An item with rules or examples alone creates **no custom skill**. An examples-only item is valid source material but installs no custom rule or skill; examples are attached to an item's rules when a Rules section exists. Dependencies declared in frontmatter are installed independently of those sections, and Boost discovers any resources supplied by those packages. This supports package-only items. Keep separate concerns in separate items, such as DTO factories and action conventions.

Use `## Files` for explicitly authored application configuration. Each destination uses a `###` heading followed by a literal fenced block, for example:

````markdown
## Files

### config/example.php

```php
<?php

return ['enabled' => true];
```
````

Supported destinations are canonical relative paths inside `config/` and root configuration filenames ending in `.neon` or `.neon.dist`, such as `phpstan.neon.dist`. Root filenames must start with an ASCII letter or digit and contain only letters, digits, dots, underscores, or hyphens. Duplicate destinations, parent traversal, symlinked paths, and malformed blocks are rejected. Multiple files can appear in the section; no other prose belongs there. Backtick or tilde fences are supported with an optional language. Payload content, including leading/trailing blank lines and the newline before the closing fence, is preserved; item line endings are normalized to LF. Files are written after the item's dependencies and minimum-version checks succeed; Laracanon copies literal content without templating it. Files have the same ownership, update, retirement, conflict, and dry-run protections as rules and skills. They create no custom skill.

Applications can point `config('laracanon.items_path')` at an alternative item directory, for example via a small `config/laracanon.php` returning `['items_path' => resource_path('canon/items')]`. This replaces the bundled catalog and uses the same format and discovery.

## Rules, skills, and Boost

Laracanon writes selected rule content to `.ai/rules/laracanon-<item-name>.md`, including description and applicable paths. Examples accompany rules as illustrations. Authored skills are written to `.ai/skills/<skill-name>/SKILL.md` with valid skill frontmatter.

Boost's native rule repository scans top-level `.ai/rules/*.md` and its managed `boost/` rules. Laracanon explicitly invokes the rule-index mechanism, producing `.ai/rules/index.md`; its agent guidance includes the index entry point. It does not assume copying Markdown or invoking `boost:update` is sufficient.

After dependency installation, a fresh application process discovers package guidance and skills using Boost's native composers and renders resources for the configured agents. Package resources are not reimplemented as custom Laracanon skills. Custom skills with package names require explicit `overrides_skill`. Project rules can express preferences that differ from dependency examples.

For a new Boost configuration, Laracanon enables guidelines, skills, and MCP with Codex as the default agent. Existing nonempty agent selections, guideline/MCP feature preferences, and unrelated configuration keys are preserved. An absent or empty agent selection uses Codex. Selected dependencies with Boost guidance or skills are added to the existing package selection; unrelated package opt-outs remain in place. The saved skill-name list is extended as selected workflows are discovered. Disabled guidelines are reported as a limitation. No Composer script is added or replaced.

Derived agent files, MCP configuration, skill files, and rules are rendered before being written through ownership checks. Existing guideline content outside Boost's marker is retained. Unowned or edited output that cannot be verified is reported as a conflict. See [the verified integration notes](docs/boost-integration.md) for the exact source APIs, compatibility boundaries, and preservation behavior.

## Ownership and retries

Commit `.ai/laracanon/state.json` and `.ai/laracanon/boost-state.json` with the corresponding managed files if you want repeat installs to retain ownership across checkouts. The former records item source hashes and managed-file hashes; the latter records derived Boost outputs. The transient installation lock is `.ai/laracanon/install.lock`.

Repeat installation leaves identical content unchanged. A new source version updates a managed file only if its current content still matches the recorded hash. Locally edited or unrelated files are preserved and reported. An unowned file with exactly the requested content can be adopted as managed; future updates then follow its recorded hash. Removed rules, skills, or configuration files retire only unchanged owned files. Shared output names between items are rejected.

To resolve a conflict, compare the installed file with the item source, then either preserve the project edit outside the managed file or explicitly remove the conflicted output and reinstall. There is no automatic force-overwrite option. Deleting ownership state deliberately makes differing existing files unowned and therefore protected.

Dependencies are never removed on item updates. New direct requirements are batched by runtime/development section using [Composer's multiple-package require command](https://getcomposer.org/doc/03-cli.md#require-r). This avoids repeating dependency resolution, autoload generation, project scripts, and audits for each new package. Existing declared dependencies use their current installation or scoped-update recovery path. Composer scripts and security audits keep their existing behavior; slow repository requests can still take time.

Composer runs scoped operations with no all-dependency update or platform bypass. Transitive dependencies already installed are pinned to their current version when promoted to a direct requirement. Installed versions must agree with the lockfile before dependency mutation; restore a missing or inconsistent lockfile before retrying. Package compatibility failures include Composer's diagnostic output. A batch that fails dependency resolution can retry packages individually only when Composer left its manifest, lockfile, and installed-package state unchanged. Plugin, script, and other failures do not trigger that fallback. If Composer scripts fail after packages have been installed, the summary reports that partial state; fix the script and rerun `composer install` before retrying the item.

Files are written atomically where possible. New configuration files use normal file permissions subject to the project's umask; configuration updates preserve existing permission bits. Symlinked managed paths are rejected. An installation lock prevents concurrent Laracanon writes in the same application. Composer changes and completed files are retained on partial failure, so subsequent installs can reconcile the remaining work.

### Composer plugin policy and recovery

Composer requires a project permission before loading a new plugin in a noninteractive installation. Item authors declare required plugins with `composer_plugins`, and selecting the item lets Laracanon configure its missing named project permissions before Composer runs. The `insights` item already declares `dealerdirect/phpcodesniffer-composer-installer`, received through Slevomat to register external PHP_CodeSniffer standards, so a fresh project needs no manual permission step. See [Composer's plugin permissions](https://getcomposer.org/doc/06-config.md#allow-plugins) and the [plugin's installation instructions](https://github.com/PHPCSStandards/composer-installer#usage).

Existing explicit `true` and `false` entries, matching patterns, and boolean `allow-plugins` policies remain project decisions. A denial keeps plugin execution disabled; Laracanon does not replace it, insert an exception, or broaden permission. Dry runs describe planned named permissions without writing them. Permissions are project-local and apply only to plugins declared for selected dependencies; no global or blanket permissions are added.

An unexpected, undeclared plugin can still make Composer fail. Laracanon preserves the diagnostic, reports the named plugin, and skips further Composer operations after a recognized block; already installed dependencies and independent item files can still complete. Manual recovery is a fallback for that unexpected case: review the plugin and set its project permission explicitly, run `composer install --no-interaction` to complete any partial operation, then retry the original selected items. Add a reviewed plugin to the item's metadata when it is part of the intended integration. A failed Composer operation may already have changed the manifest, lockfile, or installed packages.

## Development and validation

Install the package's development dependencies, then run only checks affected by the change. For example, catalog changes use:

```sh
composer install
./vendor/bin/phpunit tests/Unit/Items/ItemCatalogTest.php tests/Integration/Items/CatalogInstallationTest.php
./vendor/bin/pint --test tests/Unit/Items/ItemCatalogTest.php tests/Integration/Items/CatalogInstallationTest.php
```

Add other directly affected files to the selection, and re-run only failing or newly affected checks after fixes. This package currently uses PHPUnit; the `tests` item describes Pest 5 conventions for target applications. `composer check` runs all package checks and remains available for CI or explicitly requested broad validation; do not use it as a routine follow-up to passing focused tests.

The suite tests parsing, optional sections, dependency placement and existing constraints, multiple item selection, repeat installs, configuration files, conflicts, dry runs, partial failures, and Boost rule/skill integration. It also executes the authored API helper and native exception configuration to verify response envelopes, pagination, statuses, safe errors, and preserved headers. External Composer installations are mocked. Native Boost integration tests use the installed Boost classes without downloading application dependencies or contacting an MCP service.

The current local validation uses PHP 8.5.10, Laravel 13.34.0, and Boost 2.10.1. Fresh-process CLI smoke tests exercise the actual Artisan commands and Boost helper, with Composer mutations blocked. Other supported PHP/Laravel combinations are configured in the CI matrix, which has not run locally.

Laravel 11 compatibility tests run in isolation: [Laravel 11 security support ended March 12, 2026](https://laravel.com/docs/11.x/releases#support-policy). That CI row permits only four reviewed framework advisory IDs during dependency resolution, with reasons and audit reporting retained. Laravel 12/13 jobs use normal security blocking. These CI settings do not change the package's Composer configuration or the application's dependency installation policy.
