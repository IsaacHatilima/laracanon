---
name: laravel-phpstan
description: "Use when adding, configuring, or running PHPStan or Larastan static analysis in a Laravel application, or when PHPStan reports an error."
---

# PHPStan

Applies to: `phpstan.neon`, `phpstan.neon.dist`, `phpstan.dist.neon`, `app/**/*.php`.

## Rules

- Follow the project's PHPStan configuration and never edit it. Don't lower `level`, and add no `ignoreErrors` entries, baseline files, `excludePaths`, or `@phpstan-ignore` comments. Don't pass `--level` or `--generate-baseline` to get a pass. Fix the code instead.
- Run analysis on the changed paths and their callers, such as `./vendor/bin/phpstan analyse app/Actions/Users`. Don't add Composer scripts unless asked.

## Adding PHPStan

Only when the project has no configuration and the task is to add the tool:

1. Require `phpstan/phpstan` and `larastan/larastan` as development dependencies. The baseline runs at level 10, which needs PHPStan 2.0 or newer.
2. Copy [phpstan.neon](phpstan.neon) to the application root.
