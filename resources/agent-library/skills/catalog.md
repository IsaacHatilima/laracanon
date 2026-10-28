# Installed skill catalog

Generated from IsaacHatilima/skills. Only the skills delivered to this consumer are listed.

## Resolve skill files

A skill id is its grouped source path. In project-native roots it uses a group-prefixed folder, such as `laravel-actions/SKILL.md`.

1. Prefer a copy in the target worktree's `.agents/skills/`, `.claude/skills/`, or `.ai/skills/`.
2. Then use the supplied library roots in their stated order: a grouped canonical path, or the group-prefixed folder in a flat root.

Read the selected file and record its absolute path. Conflicting copies at the same priority require an explicit selection. Library roots outside the target worktree are read-only. Missing required skills block the relevant stage; missing unused groups do not. Project guidance takes precedence. Skills do not expand write ownership or authorize external effects.

## Available skills

| Skill id | Canonical path | Use when |
|---|---|---|
| laravel/actions | `laravel/actions/SKILL.md` | Use when creating, refactoring, or reviewing a Laravel action or operation service that queries, writes, or makes a business decision. |
| laravel/api-responses | `laravel/api-responses/SKILL.md` | Use when adding or changing a Laravel JSON API endpoint, its response envelope or pagination output, or how API errors such as 401, 403, 404, 422, 429, and 500 are rendered. |
| laravel/architecture | `laravel/architecture/SKILL.md` | Use when placing new Laravel behaviour, naming a new class, planning a feature or refactor, or reviewing where queries, validation, authorization, and responses belong. |
| laravel/controllers | `laravel/controllers/SKILL.md` | Use when adding or changing Laravel controllers or routes, wiring a FormRequest and action to an endpoint, or choosing between an API response, Inertia page, and redirect. |
| laravel/data | `laravel/data/SKILL.md` | Use when creating or changing Laravel input Data classes, output Resources, or the frontend-facing representation of a model, including partial updates. |
| laravel/eloquent-strictness | `laravel/eloquent-strictness/SKILL.md` | Use when enabling Eloquent strict mode, or when fixing a lazy-loading, missing-attribute, or discarded-attribute violation or an N+1 query. |
| laravel/enums | `laravel/enums/SKILL.md` | Use when adding or changing a PHP enum used by a Laravel model, or its stored values, labels, validation, or select options. |
| laravel/frontend | `laravel/frontend/SKILL.md` | Use when adding or changing React or Inertia pages, page props, Wayfinder routes, forms, or shared UI in a Laravel application's resources/js. |
| laravel/insights | `laravel/insights/SKILL.md` | Use when adding, configuring, or running PHP Insights code-quality checks in a Laravel application, or when Insights reports a finding. |
| laravel/jobs | `laravel/jobs/SKILL.md` | Use when adding or reviewing Laravel queued jobs, listeners, or background work such as exports, reports, webhooks, imports, and notifications, or when a request is slow because it does work the user doesn't need to wait for. |
| laravel/migrations | `laravel/migrations/SKILL.md` | Use when adding or changing a Laravel migration, table, column, index, foreign key, or schema backfill. |
| laravel/models | `laravel/models/SKILL.md` | Use when creating or changing an Eloquent model, or its casts, relationships, scopes, fillable columns, or PHPDoc properties. |
| laravel/phpstan | `laravel/phpstan/SKILL.md` | Use when adding, configuring, or running PHPStan or Larastan static analysis in a Laravel application, or when PHPStan reports an error. |
| laravel/requests | `laravel/requests/SKILL.md` | Use when adding or changing a Laravel FormRequest, validation rules, request authorization, or query parameters such as filters, sorting, and pagination. |
| laravel/security | `laravel/security/SKILL.md` | Use when adding or reviewing Laravel authorization, policies, authentication, API tokens, CORS, CSRF exceptions, file uploads, raw queries, HTML output, rate limits, or production configuration, or before deploying a Laravel application. |
| laravel/tests | `laravel/tests/SKILL.md` | Use when writing, choosing, or running tests for a Laravel application, including Pest unit, feature, action, HTTP, and browser tests, or when deciding which tests a change needs. |
