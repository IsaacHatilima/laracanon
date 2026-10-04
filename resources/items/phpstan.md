---
name: phpstan
description: PHPStan and Larastan with level 10 Laravel analysis.
paths:
  - 'phpstan.neon.dist'
  - 'app/**/*.php'
dependencies:
  development:
    - phpstan/phpstan
    - larastan/larastan
minimum_versions:
  phpstan/phpstan: '2.0.0'
---

## Examples

Install the selected development integration in an existing Laravel application:

```sh
php artisan canon:install phpstan --dry-run
php artisan canon:install phpstan
```

[PHPStan's installation guide](https://phpstan.org/user-guide/getting-started) documents the development dependency. The [Larastan installation and configuration guide](https://github.com/larastan/larastan/blob/v3.12.2/README.md) supplies the Laravel extension included below, so both `phpstan/phpstan` and `larastan/larastan` are explicit development dependencies. Laravel supplies `nesbot/carbon`; its installed version and existing requirement are preserved.

Missing packages resolve to the latest compatible stable releases. Existing requirements and installed versions are preserved. [Level 10 requires PHPStan 2](https://phpstan.org/user-guide/rule-levels); the minimum-version check reports an incompatible existing version and skips this item's configuration rather than upgrading dependencies or changing the requested level.

The literal file below installs as `phpstan.neon.dist` in the application root. Repeat installs leave matching content unchanged, update unchanged managed content, and report conflicts when project edits differ. PHPStan uses a local `phpstan.neon` before `phpstan.neon.dist`, so an existing local configuration retains precedence; see [PHPStan's configuration discovery](https://phpstan.org/config-reference#config-file).

Run analysis on changed or affected application paths using the installed runner. Installation adds no Composer scripts and runs no analysis. This item contains no project Rules or authored Skill section; dependency-provided Boost resources are discovered when available.

## Files

### phpstan.neon.dist

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
