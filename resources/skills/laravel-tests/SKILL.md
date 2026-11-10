---
name: laravel-tests
description: "Use when writing, choosing, or running tests for a Laravel application, including Pest unit, feature, action, HTTP, and browser tests, or when deciding which tests a change needs."
---

# Tests

Applies to: `tests/**`, `phpunit.xml*`, `playwright.config.*`.

## Suites

- **`tests/Unit/`:** isolated logic, with no Laravel boot and no database.
- **`tests/Feature/`:**
  - Action tests in `tests/Feature/Actions/{Domain}/` call `handle()` with real typed input and models.
  - HTTP tests live in `tests/Feature/Http/{Domain}/`.
  - Scope `RefreshDatabase` to the Feature tests that need it.
- **`tests/Browser/`:** changed user journeys in applications with a frontend, using Pest Browser or the existing Playwright runner. API-only applications don't need browser tests.
- Test files mirror the class they cover: `CreateUserAction` is tested in `CreateUserActionTest.php`.
- Use Pest 5 conventions: behaviour-named `test()` cases, named datasets, factories, and states. Pest 5 needs PHP 8.4; if the project can't run it, keep the working runner and report the gap.

## What to test

- Action tests own the deep branches: persisted columns, the returned model or Resource, rollback, side effects, and failures.
- HTTP tests own the wiring: authorization, validation, the action call, and the response.
  - API tests assert the `api-responses` contract.
  - Web tests assert the Inertia component and props, redirects, session errors, and intended flash.
- Browser tests cover what HTTP assertions can't see: client rendering, form errors, navigation, pending states, and JavaScript errors.
- Fake external boundaries: the HTTP client, mail, notifications, storage, and the queue. Keep owned actions, models, and policies real. Queued work is tested as `jobs` describes.
- Every test must be able to catch a real regression. Don't test getters, framework behaviour, or documentation wording.

## Running tests

Run only the tests for the files and flows you changed. Never run the whole suite; CI does that.

- Select the tests of each changed class, the endpoints and pages that use it, and the browser journey it changes. Pass them as explicit paths, and use `--filter` to narrow to one case.
- After a fix, rerun only the failing tests and the tests of what the fix touched.
- Report the files you ran and what you couldn't verify, such as browser runs or engine behaviour that SQLite can't prove.

Adding a comments section to the post page changes `CreateCommentAction`, `CreateCommentController`, and the post page. Run their tests, not the change-password tests:

```sh
./vendor/bin/pest tests/Feature/Actions/Comments tests/Feature/Http/Comments tests/Feature/Http/Posts/ShowPostControllerTest.php tests/Browser/Posts
```
