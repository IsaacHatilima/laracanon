---
name: laravel-insights
description: "Use when adding, configuring, or running PHP Insights code-quality checks in a Laravel application, or when Insights reports a finding."
---

# PHP Insights

Applies to: `config/insights.php`, `app/**/*.php`.

## Rules

- Follow the project's `config/insights.php` and never edit it. Don't lower a `requirements` score, add `exclude` paths, or move insights to `remove`, and don't pass `--min-*` or `--disable-security-check` to get a pass. Fix the code instead.
- If the configuration forbids something, such as private methods, restructure the code to comply.
- Run Insights on the changed paths, such as `php artisan insights app/Actions/Users`. Don't run `--fix` or add Composer scripts unless asked.

## Adding Insights

Only when the project has no configuration and the task is to add the tool:

1. Require `nunomaduro/phpinsights` as a development dependency. It pulls in `dealerdirect/phpcodesniffer-composer-installer`, so add that plugin to Composer's `allow-plugins` unless an existing policy covers it.
2. Copy [insights.php](insights.php) to `config/insights.php`. It requires a score of 100 in every category.

The baseline is adapted from the [City Directory configuration](https://github.com/city-directory/citydirectory/blob/8c5cdb0093067933cb74481d7fb087ca2fb63b98/config/insights.php) at commit `8c5cdb0`, with `ForbiddenPrivateMethods` removed.
