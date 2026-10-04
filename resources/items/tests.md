---
name: tests
description: 'Pest 5 Unit and Feature suites, focused sharded runs, and Playwright browser tests for frontend applications.'
paths:
  - 'tests/**'
  - 'phpunit.xml*'
  - 'playwright.config.*'
---

## Rules

- Projects maintain separate `tests/Unit/` and `tests/Feature/` suites. Unit tests cover isolated logic without booting Laravel or using a database. Feature tests cover Laravel integration, database-backed actions, and HTTP behavior. Add meaningful coverage at the owning boundary when behavior changes.
- Action tests cover meaningful branches, actual persistence, transactions, side effects, and return contracts. HTTP tests exercise real requests, authorization, validation, action wiring, and transport responses without repeating every action branch.
- API tests cover the `data`, `message`, `errors`, `meta`, and `links` envelope, safe Resource fields, real success/failure statuses, errors without an Accept header, pagination, protocol headers, and bodyless 204 responses. Web tests cover Inertia pages/props where installed, redirects, validation, and useful deliberate flash feedback; a write does not automatically require a toast.
- Use Pest 5 for the application's PHP testing conventions, with behavior-named `test()` cases, named datasets, and factories/states. Check the installed runner and plugin compatibility before adoption; preserve existing constraints and do not force PHP or Laravel upgrades. Keep shared setup scoped to the suites that need it.
- Confirm active `pest()->tia()` configuration in `tests/Pest.php`; add `pest()->tia()->locally()->filtered();` when missing, preserving existing setup. Use explicit affected paths when TIA cannot safely select the affected tests.
- Applications with a frontend also maintain `tests/Browser/` coverage using Playwright, through Pest's browser plugin or the existing Playwright Test runner. API-only applications do not require browser tests. Browser tests exercise changed user journeys and client behavior that HTTP assertions cannot observe.
- Run only tests for changed behavior and directly affected callers/contracts. Use a verified TIA baseline for automatic local selection, or select explicit test files before sharding; run every selected shard against the same file set. Do not rerun the whole suite after focused checks. Re-run only failed or newly affected selections after fixes.
- Exercise owned actions, models, and business decisions. Fake external services or delivery boundaries to prevent real network calls and assert side effects; do not fake the owned logic being tested. A queue or notification fake proves dispatch intent, not execution of its job or delivery logic.
- Add tests that can catch a real regression in behavior or integration. Avoid assertions that merely mirror implementation, getters, documentation wording, or framework behavior without an application contract.

Use the `laracanon-tests` skill for choosing test boundaries and validating API and web contracts.

## Skill

This is a **Laracanon-authored testing workflow** for an existing Laravel application. It installs guidance without changing runners, dependencies, scripts, or application configuration. The target convention is Pest 5 with Unit and Feature suites, plus Playwright-backed Browser tests when the application has a frontend. Preserve the working runner while reporting any compatibility gap; Inertia-specific assertions apply only where Inertia is installed.

1. Read the application's instructions, Composer scripts and lockfile, `phpunit.xml`, `tests/Pest.php`, base TestCase, database configuration, factories, and nearby tests. Identify the changed behavior and directly affected callers before choosing test files. Inspect the installed Pest and plugins: Pest 5 requires PHP 8.4, and particular releases/transitive dependencies can require a newer patch; the installed Laravel plugin must also support the existing framework. Use Composer's platform/lock compatibility as authority. Report an incompatible Pest 5 adoption instead of forcing PHP/Laravel upgrades or rewriting unrelated existing tests. Consult the matching [Pest configuration](https://pestphp.com/docs/configuring-tests), [Pest 5 package requirements](https://github.com/pestphp/pest/blob/v5.3.0/composer.json), and [Laravel testing guidance](https://laravel.com/docs/13.x/testing).
2. Inspect `tests/Pest.php` for active Test Impact Analysis. If absent, add the configuration below; extend a bare `pest()->tia();` call instead of duplicating it. Preserve existing suite bindings, watch patterns, storage choices, and explicit activation preferences. Check PCOV/Xdebug and a usable baseline before relying on automatic selection. Without them, run explicit affected paths with `--no-tia` rather than starting an unfiltered baseline. Follow the installed [TIA documentation](https://pestphp.com/docs/tia).
3. Keep both Unit and Feature suites explicit in the runner configuration. Place isolated domain decisions, value objects, and utilities under `tests/Unit/`. Put database-backed action tests under `tests/Feature/Actions/{Domain}/` and HTTP tests under the corresponding Feature domain. Call `handle()` directly in action tests with realistic typed input and models. Scope the application TestCase and database-reset strategy, such as `RefreshDatabase`, to the Feature tests that need them; pure Unit tests remain independent. Preserve a working legacy grouping while handling any reorganization as its own change. Do not add empty or trivial tests merely to populate a suite.
4. Arrange the smallest realistic state with factories and named states, invoke the real action, and assert externally meaningful results: selected columns, returned model/Resource, normalization at the Data boundary, omitted versus explicit null, rollback, and required side effects. For the shared CreateUser flow, useful checks include known-column persistence, unchanged password bytes before hashing, excluded extra input, duplicate email rejection, and no extra creation on failure. Add only cases relevant to the actual change. A read Resource factory should use prepared attributes/relations; detect output queries where lazy loading would violate that contract.
5. Fake the external boundary before execution, such as the HTTP client, mail, notifications, storage, or queue dispatch, using the installed framework's supported fake. Keep application actions, models, authorization, and business decisions real. If the behavior under test lives inside a job, listener, or notification, exercise that owned code instead of faking it away; use separate tests for dispatch and execution when both matter. Verify intended calls or dispatches and prohibited side effects without depending on a real remote service.
6. Exercise API routes through Laravel's HTTP test helpers with real middleware and actions. Assert authorized success, Resource field exclusion, the exact five envelope keys, meaningful values, and the actual 200/201/202 contract. Check native validation 422 and its field-to-message-list `errors`, authentication 401, authorization 403, missing resources 404, and other relevant failures. Send error requests both with JSON negotiation and without an Accept header so the application's API predicate and exception callback are exercised; `postJson()` alone cannot prove the latter. Verify failures do not write and safe 500 messages exclude internal details. For collection endpoints, assert Resource pagination `meta`/`links` and list shape without `data.data`; check relevant `WWW-Authenticate`, `Retry-After`, or `Location` headers. Assert an actual 204 body is empty rather than expecting an envelope. Use the installed [Laravel HTTP assertions](https://laravel.com/docs/13.x/http-tests).
7. Exercise web routes using their real caller mode. Assert guest/unauthorized behavior, FormRequest validation and session errors, intended redirect destinations, and Inertia component/Resource props where applicable. Use the installed adapter's [Inertia testing APIs](https://inertiajs.com/docs/v3/advanced/testing), such as `assertInertia`, rather than interpreting an API envelope as page props. Test the application's existing feedback mechanism: deliberate useful flash when intended, and its absence for background/side-effect writes. Inertia 3 supports `hasFlash`/`missingFlash` on page assertions; older shared-session implementations require their own session/prop assertions. Preserve working web redirects and middleware behavior when adding API coverage.
8. When the project has a frontend, add or update Playwright-backed tests under `tests/Browser/` for the changed user journey. Reuse an existing compatible Pest Browser or Playwright Test setup; do not run PHP browser tests through the Node runner or TypeScript specs through Pest. Verify browser binaries, frontend assets, the test server/base URL, authentication fixtures, and isolated test state. Cover relevant form errors, navigation, pending states, accessible controls, and JavaScript errors. Scope Laravel TestCase/database setup to PHP Browser tests that need it; preserve the Node runner's existing projects and setup dependencies. API-only changes in an API-only project use Unit/Feature coverage without adding a browser stack. See [Pest Browser](https://pestphp.com/docs/browser-testing) and [Playwright Test](https://playwright.dev/docs/intro).
9. Name tests for behavior, use named datasets for variations that share one contract, and keep fixtures/helpers local until multiple tests need them. HTTP coverage proves wiring and transport behavior while action tests own deeper branches and Browser tests own real client interaction. Add a regression case for the changed contract, avoiding a second implementation inside the test or snapshots of documentation prose.
10. Choose verified local TIA selection or explicit changed/impacted files for sharding. Pest 5.3.0 disables TIA on partial runs, including explicit paths and `--shard`; sharding therefore requires its own affected-file selection. Split that selected set with `--shard=i/n` as shown below. Keep the same paths and shard total for every index, and run all indexes before claiming the selected set passed. Use one shard for a single file; keep the shard count within the number of selected test classes/files. Avoid combining a title `--filter` with `--shard`: the shard plugin supplies its own filter, and an empty shard falls back to the original selection. Do not rely on `--dirty` alone to identify tests impacted by production changes. Preserve isolated databases/state for concurrent shards; use `--parallel` only when the project supports it. Verify the installed [CLI options](https://pestphp.com/docs/cli-api-reference), [TIA partial-run behavior](https://github.com/pestphp/pest/blob/v5.3.0/tests/Features/Tia/PartialRunWriteTier.php), and [shard implementation](https://github.com/pestphp/pest/blob/v5.3.0/src/Plugins/Shard.php).
11. Run relevant existing static/frontend checks for the changed code, inspecting scripts before invoking them so a focused check does not silently rerun the entire test suite. Re-run only failing shards and selections affected by further changes. Report selected files, shard indexes/results, failures, and limits, including unavailable browser execution or production-database behavior that SQLite cannot prove. Do not repeat an unfiltered whole-suite run after the affected checks pass.

### Test impact analysis configuration

In `tests/Pest.php`, confirm TIA is enabled; add this chain when missing:

```php
pest()->tia()->locally()->filtered();
```

A bare `tia()` call returns configuration without activating it. `locally()` activates local TIA; `filtered()` narrows discovery to affected files. See the [configuration source](https://github.com/pestphp/pest/blob/v5.3.0/src/Plugins/Tia/Configuration.php). TIA needs a coverage driver and records a baseline before subsequent impact selection. Avoid an automatic broad baseline run when that state is missing or stale; use the explicit selections below with `--no-tia` instead.

### Focused shard commands

Replace these paths with the actual changed/impacted files. Explicit selections and shards do not use TIA impact selection in Pest 5.3.0. This two-shard example selects two files; it does not discover the whole suite or generate new timing data:

```sh
./vendor/bin/pest tests/Unit/Users/UserNameTest.php tests/Feature/Users/CreateUserTest.php --no-tia --shard=1/2
./vendor/bin/pest tests/Unit/Users/UserNameTest.php tests/Feature/Users/CreateUserTest.php --no-tia --shard=2/2
```

For one changed PHP Browser file, use the installed Pest Browser plugin:

```sh
./vendor/bin/pest tests/Browser/Users/CreateUserTest.php --no-tia --shard=1/1
```

For an existing Node Playwright Test project, select its actual spec instead. Playwright path filters are regular expressions; preserve the configured browser projects and required setup dependencies. Larger selected sets can use every index of the same `--shard=i/n` total, following the installed [Playwright sharding behavior](https://playwright.dev/docs/test-sharding).

```sh
npx playwright test 'tests/Browser/users/create-user\.spec\.ts$' --shard=1/1
```

### Browser example: the User creation page

This PHP test uses Pest Browser's Playwright integration and the same User flow as the Data/controller skills. It assumes the application already has `/users/create`, that the factory-created actor is authorized to view it, and that the Browser suite has the needed Laravel/database setup. Adjust the existing factory state, route, and visible text to the actual project. It checks the client-rendered form; Feature/action tests own persistence and HTTP branch coverage.

`tests/Browser/Users/CreateUserTest.php`:

```php
<?php

use App\Models\User;

test('shows the user creation form to an authorized actor', function () {
    $this->actingAs(User::factory()->create());

    visit('/users/create')
        ->assertSee('Create user')
        ->assertPresent('input[name="name"]')
        ->assertPresent('input[name="email"]')
        ->assertNoJavaScriptErrors();
});
```
