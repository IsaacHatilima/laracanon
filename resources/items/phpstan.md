---
name: phpstan
description: PHPStan and Larastan with level 10 Laravel analysis.
paths:
  - 'phpstan.neon'
  - 'phpstan.neon.dist'
  - 'phpstan.dist.neon'
  - 'app/**/*.php'
dependencies:
  development:
    - phpstan/phpstan
    - larastan/larastan
minimum_versions:
  phpstan/phpstan: '2.0.0'
neon_updates:
  phpstan.neon:
    candidates:
      - phpstan.neon
      - phpstan.neon.dist
      - phpstan.dist.neon
    set:
      parameters.level: 10
---

## Examples

Install the selected development integration in an existing Laravel application:

```sh
php artisan canon:install phpstan --dry-run
php artisan canon:install phpstan
```

[PHPStan's installation guide](https://phpstan.org/user-guide/getting-started) documents the development dependency. The [Larastan installation and configuration guide](https://github.com/larastan/larastan/blob/v3.12.2/README.md) supplies the Laravel extension included below, so both `phpstan/phpstan` and `larastan/larastan` are explicit development dependencies. Laravel supplies `nesbot/carbon`; its installed version and existing requirement are preserved.

Missing packages resolve to the latest compatible stable releases. Existing requirements and installed versions are preserved. [Level 10 requires PHPStan 2](https://phpstan.org/user-guide/rule-levels); the minimum-version check reports an incompatible existing version and skips this item's configuration rather than upgrading dependencies or changing the requested level.

Laracanon uses the first existing configuration in PHPStan's discovery order: `phpstan.neon`, `phpstan.neon.dist`, then `phpstan.dist.neon`. It changes only `parameters.level` to `10`, preserving includes, paths, exclusions, comments, and other project settings. It creates no second configuration when one of those files already exists; see [PHPStan's configuration discovery](https://phpstan.org/config-reference#config-file). A malformed or unsupported configuration is reported and preserved.

If none of those configurations exists, the literal default below installs as `phpstan.neon` in the application root. Adopted existing configurations track the selected path and managed level value: unrelated edits remain permitted, while an edited managed level is reported as a conflict and is never silently reset. Configurations created by Laracanon retain whole-file ownership, so any local edits are preserved and reported as conflicts. Dry runs report the proposed change without writing files.

Run analysis on changed or affected application paths using the installed runner. Installation adds no Composer scripts and runs no analysis. This item contains no project Rules or authored Skill section; dependency-provided Boost resources are discovered when available.

## Files

### phpstan.neon

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/nesbot/carbon/extension.neon

parameters:
    level: 10
    paths:
        - app
    excludePaths:
        - tests/*
        - database/*
        - config/*
        - vendor/*
        - app/Providers/*
```
