---
name: laravel-architecture
description: "Use when placing new Laravel behaviour, naming a new class, planning a feature or refactor, or reviewing where queries, validation, authorization, and responses belong."
---

# Architecture

Applies to: `app/**`. The Laravel skills target Laravel 13. Project guidance (`AGENTS.md`, `CLAUDE.md`) and existing conventions win over their defaults.

## Boundaries

| Concern | Lives in | Skill |
|---|---|---|
| Authorization and validation | FormRequest, calling a policy | `requests`, `security` |
| Input normalization | Data `fromRequest()` | `data` |
| Queries, writes, transactions, relation loads, business decisions | Action in `app/Actions/{Domain}/`, or service in `app/Services/{Domain}/` | `actions` |
| Output representation and formatting | Resource `fromModel()`, returned by the action | `data` |
| HTTP response: API envelope, Inertia page, redirect | Controller | `controllers`, `api-responses` |
| Background work | Job calling an action | `jobs` |

The flow is FormRequest → `Data::fromRequest()` → action → Resource or model → controller response. Web, API, job, and command callers reuse the same actions.

## Naming

Without a project convention, a class name ends with its type: `CreateUserAction` (or `CreateUserService`), `CreateUserController`, `CreateUserRequest`, `CreateUserData`, `UserResource`, `UserPolicy`, `DetectCommitLanguageJob`, `UserCreatedEvent`, `SendWelcomeEmailListener`, `InvoicePaidNotification`, `WelcomeMail`. Models and enums are plain nouns: `User`, `UserStatus`. Test files mirror the class: `CreateUserActionTest.php`.

## Attributes

Where Laravel 13 offers an attribute instead of a property or method, use it. `models`, `jobs`, `requests`, and `controllers` list theirs. Console commands use `#[Signature]` and `#[Description]` from `Illuminate\Console\Attributes`. Injected values use `Illuminate\Container\Attributes`, such as `#[Config('services.mailer.key')]` and, in controllers, `#[CurrentUser]`.

## Rules

- Only framework lookups may run outside actions: route-model binding, `exists`/`unique` rules, the current user, Gate, policy and `can()` checks, and Spatie's `hasRole()`/`hasPermissionTo()`. Any other lookup, including one inside a policy, calls an action.
- Data and Resources never query or lazy-load.
- Translate user-facing strings with `__()`.
- New work follows these boundaries. Fix unrelated legacy violations in separate changes.

## Workflow

1. Trace the entry points, authorization, input, database work, output, and response. Find other callers, such as API routes, jobs, and commands, that should share the operation.
2. Assign each responsibility using the table, and open only the skills for the concerns that change.
3. Before coding, check that request fields match Data properties, the action's signature and return type fit its callers, Resource fields match the frontend types, and the response shape matches what the caller expects.
4. Verify through the real callers' tests (see `tests`) and report what remains unverified.
