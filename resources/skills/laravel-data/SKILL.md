---
name: laravel-data
description: "Use when creating or changing Laravel input Data classes, output Resources, or the frontend-facing representation of a model, including partial updates."
---

# Data

Applies to: `app/Data/**`. Uses `spatie/laravel-data`; if it's missing, install it with Composer. Publish `data-config` only for custom settings.

## Input Data

- Lives in `app/Data/{Domain}/` as `{Operation}Data`, with readonly properties.
- Built only through an explicit, typed `fromRequest()` that the controller calls after the FormRequest has authorized and validated. Don't use automatic request injection, container resolution, or `from($request)`.
- The factory reads only validated, known fields through typed getters such as `$request->string('name')`. It owns domain normalization: trimming, letter case, and turning blank strings into null. Passwords and other opaque values pass through untouched.
- Jobs and commands build the same Data through their own factory.
- Partial-update contracts use [`Optional`](https://spatie.be/docs/laravel-data/v4/as-a-data-transfer-object/optional-properties) properties, so an omitted field differs from an explicit `null`.
- Data is never sent to the frontend and never passed to Eloquent (see `actions`).

## Output Resources

- Also live in `app/Data/{Domain}/`, named `{Noun}Resource`, with camelCase properties matching the frontend type and an explicit, typed `fromModel()`.
- A Resource is the outbound shape for API JSON and Inertia props. Formatting and derived values, such as labels, full names, and money from minor units, are computed in `fromModel()`, so controllers and pages receive them ready.
- Read only selected attributes and already-loaded relations or counts. The action that returns the Resource loads them first (see `actions`); Resources never query or lazy-load.

## Example

`app/Data/Users/CreateUserData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data\Users;

use App\Http\Requests\Users\CreateUserRequest;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

final class CreateUserData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $phoneNumber,
        public readonly string $password,
    ) {}

    public static function fromRequest(CreateUserRequest $request): self
    {
        $phoneNumber = trim($request->string('phone_number')->value());

        return new self(
            name: Str::title(trim($request->string('name')->value())),
            email: Str::lower(trim($request->string('email')->value())),
            phoneNumber: $phoneNumber === '' ? null : $phoneNumber,
            password: $request->string('password')->value(),
        );
    }
}
```

`string()` returns an empty string for missing or null input, so a blank phone number becomes `null` after trimming.

`app/Data/Users/UserResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data\Users;

use App\Models\User;
use Spatie\LaravelData\Resource;

final class UserResource extends Resource
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $phoneNumber,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: (string) $user->getKey(),
            name: $user->name,
            email: $user->email,
            phoneNumber: $user->phone_number,
        );
    }
}
```

## Verify

Tests cover:
- normalization, including blank-to-null;
- ignored extra fields;
- passwords passed through untouched;
- omitted versus `null` on partial updates;
- building a Resource runs no queries.
