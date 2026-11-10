---
name: laravel-models
description: "Use when creating or changing an Eloquent model, or its casts, relationships, scopes, fillable columns, or PHPDoc properties."
---

# Models

Applies to: `app/Models/**`.

## Rules

- Models are declarative: attributes, relationships, scopes, `casts()`, constants, and property types.
  - Relationships and scopes build queries but never run them: no `get()`, `first()`, `exists()`, `count()`, or writes.
  - Accessors compute only from loaded attributes.
- Queries, writes, and business behaviour live in actions. Never put them in model helpers, `app/Concerns` traits, events, boot hooks, or observers. Framework lifecycle features stay: UUID generation, timestamps, casts, and authentication traits.
- Configure the model with class attributes from `Illuminate\Database\Eloquent\Attributes`, never the matching property or method: `#[Table]`, `#[Fillable]`, `#[Hidden]`, `#[RouteKey]`, `#[UseFactory]`, `#[UsePolicy]`, `#[ObservedBy]`, `#[ScopedBy]`. Add only the ones the model needs.
- `#[Fillable]` lists only the columns that actions mass-assign. Privileged columns stay out, and secrets go in `#[Hidden]` (see `security`). Never use `#[Unguarded]` or `$guarded = []`.
- Entity models use `HasUuids`; pivot and lookup models keep integer keys. Match the real schema, and don't convert existing keys or routes without a planned migration (see `migrations`).
- Cast string enum columns to their backed enum in `casts()` (see `enums`). Use a custom cast or `Attribute` for value objects, such as money stored in minor units.
- Local scopes are protected `#[Scope]` methods, called from actions. Reserve a global scope, registered with `#[ScopedBy]`, for a filter that every query must have, such as tenant isolation. Never define the same filter both ways.
- Recoverable records use `SoftDeletes`.
- Every model has a class-level PHPDoc block. Use `@property` for writable attributes and `@property-read` for computed attributes and relations. Types match the casts and nullability; to-many relations use collection generics.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $phone_number
 * @property string $password
 * @property UserStatus $status
 */
#[Fillable(['name', 'email', 'phone_number', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['password' => 'hashed', 'status' => UserStatus::class];
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', UserStatus::Active);
    }
}
```

`status` is privileged, so it stays out of `#[Fillable]` and actions assign it directly.

## Workflow

1. For an existing model such as `User`, keep its base class, authentication and notification traits, hidden fields, casts, and route binding. Change only the requested concern.
2. When moving logic out of a helper, observer, or boot hook, put it in an action, update its callers, and keep its transaction and after-commit timing.
3. Verify:
   - key generation;
   - fillable versus protected assignment;
   - casts, relationships, and scopes;
   - reading accessors or Resources runs no extra queries.
