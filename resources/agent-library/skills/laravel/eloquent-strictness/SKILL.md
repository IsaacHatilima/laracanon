---
name: laravel-eloquent-strictness
description: "Use when enabling Eloquent strict mode, or when fixing a lazy-loading, missing-attribute, or discarded-attribute violation or an N+1 query."
---

# Eloquent strictness

Applies to: `app/Providers/AppServiceProvider.php`, `app/Actions/**` or `app/Services/**`, `app/Data/**`, `app/Models/**`.

## Rules

- Enable strictness outside production in `AppServiceProvider::boot()`. Keep the provider's other code and any guards already enabled in production.

```php
if (! $this->app->isProduction()) {
    Model::shouldBeStrict();
}
```

- Fix violations at their source. Never disable a guard, swallow its exception, or log it instead of throwing.
- **Lazy loading:** the action that builds the Resource eager-loads exactly the relations and counts it reads (see `actions`).
- **Missing attribute:**
  - Fix the property name, or select the column, including keys needed to match relationships.
  - Never fetch the column from an accessor or Resource, or default it to hide an incomplete query.
- **Discarded attribute:** make the action's column names match real columns and the model's `#[Fillable]` list. Never unguard the model.
- Strict mode doesn't catch queries written inside loops. For collection reads, test with several records and assert that the query count doesn't grow per record.
