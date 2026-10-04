---
name: eloquent-strictness
description: 'Enable Eloquent strictness outside production and fix lazy loading, discarded attributes, and missing selected attributes at their source.'
paths:
  - 'app/Providers/**'
  - 'app/Models/**'
  - 'app/Actions/**'
  - 'app/Data/**'
  - 'app/Http/Resources/**'
  - 'tests/**'
---

## Rules

- Enable Eloquent's lazy-loading, discarded-attribute, and missing-attribute checks in every non-production environment, including local development and automated tests. Configure them in `AppServiceProvider::boot()` with `Model::shouldBeStrict()`, or retain equivalent existing calls to the three individual guards.
- Preserve unrelated provider behavior and any deliberate production strictness. Enabling the development checks must not disable guards already enabled in production. Keep one effective configuration rather than adding duplicate or contradictory calls.
- Strictness violations must surface during development and tests. Fix their cause instead of globally disabling a guard, swallowing its exception, or adding a logging-only handler to make failing checks pass.
- Resolve relationship lazy loading through explicit loading in the action responsible for the query. Load only the relationships and aggregates the caller needs before Data, Resources, accessors, or views read them. Keep database work out of those consumers.
- Resolve missing-attribute violations by correcting property names and selecting the fields the caller actually needs, including keys required for relationship matching. Do not query for missing fields from an accessor or output mapper, or default an omitted required field to a value that hides the incomplete query.
- Resolve discarded-attribute violations by matching explicitly mapped persistence keys to real database columns and the model's intended mass-assignment declaration. Use the declaration supported by the installed Laravel version. Preserve protected fields; do not broadly unguard models to silence a violation.
- The lazy-loading guard does not detect every N+1 query. Explicit queries inside loops still require review and focused query checks. For affected collection reads, verify behavior with multiple retrieved records and check that database queries do not grow once per record. A passing single-record test does not establish that the collection path avoids N+1 queries.

## Examples

Add the guard to the existing provider's `boot()` method while retaining its other statements. The conditional enables the three checks outside production without resetting an existing production policy.

```php
use Illuminate\Database\Eloquent\Model;

public function boot(): void
{
    if (! $this->app->isProduction()) {
        Model::shouldBeStrict();
    }
}
```

Laravel documents [Eloquent strictness](https://laravel.com/docs/13.x/eloquent#configuring-eloquent-strictness) and [preventing relationship lazy loading](https://laravel.com/docs/13.x/eloquent-relationships#preventing-lazy-loading). The framework's [Model implementation](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Eloquent/Model.php) defines the three checks enabled by `shouldBeStrict()`.
