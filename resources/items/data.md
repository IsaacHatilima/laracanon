---
name: data
description: Readonly input Data, explicit action persistence, and a coherent user-creation workflow.
paths:
  - 'app/Data/**'
  - 'app/Actions/**'
dependencies:
  runtime:
    - spatie/laravel-data
---

## Rules

- Keep input Data in `app/Data/{Domain}/`, with operation-specific names and readonly properties. Each input contract has an explicit typed `fromRequest()` factory. FormRequests own authorization and validation; Data owns domain normalization and preserves opaque values such as passwords.
- In actions that consume input Data, map actual database column keys directly to typed Data properties inside both create and update calls, such as `'name' => $data->name` and `'phone_number' => $data->phoneNumber`. Do not pass Data, serialized Data, or a generic attributes payload to Eloquent, infer column names, or add a model adapter.
- Actions own every query, write, transaction, relationship load, existing-model decision, and storage conversion. Data and Resources never query, write, or lazy-load. Partial updates distinguish omitted values from explicit null.
- Frontend data uses output Resources in `app/Data/{Domain}/`, with an explicit typed `fromModel()` factory and camelCase properties matching frontend types. Resources read only selected attributes and already-loaded relations or counts.

Use the `laracanon-data` skill for the implementation workflow and complete example.

## Skill

This is a **Laracanon-authored implementation workflow**, not an official Spatie skill. Its `laracanon-data` name keeps dependency-provided skills available and does not override them.

1. Read the application's instructions, existing Data/Resources, requests, actions, schema, and frontend types. Inspect `composer.json`, the lockfile, and `composer show spatie/laravel-data`. When this item is missing, preview with `php artisan canon:install data --dry-run`, then install with `php artisan canon:install data`; preserve existing versions and stop on reported compatibility failures.
2. Consult the [official installation documentation](https://spatie.be/docs/laravel-data/v4/installation-setup) matching the installed major version. Spatie documents `composer require spatie/laravel-data`; prefer `php artisan canon:install data` for this toolkit's dependency and resource installation. Laravel discovers the service provider. Publish `data-config` only when custom settings are needed, and preserve an existing `config/data.php`.
3. Choose the operation and input contract. Keep the example flow's names consistent: `CreateUserRequest`, `CreateUserData`, `CreateUser`, and `CreateUserController`. The FormRequest authorizes through the application's policy and validates permitted fields; the Data factory runs after those checks.
4. Implement readonly input properties and an explicit typed `fromRequest()` using known fields and typed getters. Normalize values according to their domain and preserve passwords exactly. For nullable strings, trim the getter's value once and convert a blank result to null, as in the example. The string getter returns an empty string for missing or null input; trimming alone still returns a string. Read only known fields covered by validation. For a separate partial-update contract, use [Optional properties](https://spatie.be/docs/laravel-data/v4/as-a-data-transfer-object/optional-properties) where omission means leave unchanged. Guard each optional property individually, preserve allowed null so a field can be cleared, and skip a write with no supplied fields. See Spatie's [custom creation methods](https://spatie.be/docs/laravel-data/v4/as-a-data-transfer-object/creating-a-data-object) for factory support.
5. When an action consumes Data, map each column explicitly from its Data property directly in both create and update calls. Use `'name' => $data->name` and `'phone_number' => $data->phoneNumber`; ensure the model's `#[Fillable]` permits those columns on Laravel 13+. On older Laravel versions, preserve the existing mass-assignment declaration without upgrading the framework for this convention. Do not forward a generic attributes variable, pass Data directly, use `toArray()`/`toAttributes()` as a persistence payload, infer column names, use a generic assignment loop, or add a model adapter. Keep database checks, writes, transactions, and storage conversions such as hashing in the action. Trust Data's normalization. Write actions return models.
6. Keep the controller thin: inject its FormRequest and action, call `CreateUserData::fromRequest($request)` explicitly, invoke the typed action, and return the response. Do not substitute raw request arrays, automatic request-to-Data injection, container resolution of input Data, or `from($request)` for the explicit factory.
7. When a read endpoint needs frontend data, define its Resource with camelCase properties and an explicit `fromModel()` factory. Read only selected attributes and already-loaded relations or counts. Read actions query and eager-load before constructing Resources. Data and Resources must not execute queries or lazy-load.
8. Run focused checks for authorization, validation, normalization, nullable-text behavior, ignored extra fields, readonly input, explicit create/update column mapping, actual writes, password preservation and hashing when applicable, and the controller response. If implementing a separate partial-update or read endpoint, also check its omission/null or Resource behavior. Run the application's relevant existing checks and report what passed and what remains unverified.

### Example: create a user

One flow: `CreateUserRequest` authorizes and validates, `CreateUserData::fromRequest()` cleans input, `CreateUser` explicitly maps and persists it, and `CreateUserController` redirects after delegating to that action.

These complete classes assume an existing `App\Models\User`, a `create` policy, a unique index on `users.email`, a nullable `users.phone_number` string column, and a `users.password` string column. Import `Illuminate\Database\Eloquent\Attributes\Fillable` and declare `#[Fillable(['name', 'email', 'phone_number', 'password'])]` on the User model. Store each class in the file matching its namespace. Input Data is never sent to the frontend; this endpoint redirects rather than returning a data payload.

`app/Http/Requests/Users/CreateUserRequest.php`:

```php
<?php

namespace App\Http\Requests\Users;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', User::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ];
    }
}
```

`app/Data/Users/CreateUserData.php`:

```php
<?php

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
        $request->validated();
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

`app/Actions/Users/CreateUser.php`:

```php
<?php

namespace App\Actions\Users;

use App\Data\Users\CreateUserData;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class CreateUser
{
    public function handle(CreateUserData $data): User
    {
        if (User::query()->where('email', $data->email)->exists()) {
            throw ValidationException::withMessages([
                'email' => __('The email has already been taken.'),
            ]);
        }

        return User::query()->create([
            'name' => $data->name,
            'email' => $data->email,
            'phone_number' => $data->phoneNumber,
            'password' => Hash::make($data->password),
        ]);
    }
}
```

`app/Http/Controllers/Users/CreateUserController.php`:

```php
<?php

namespace App\Http\Controllers\Users;

use App\Actions\Users\CreateUser;
use App\Data\Users\CreateUserData;
use App\Http\Requests\Users\CreateUserRequest;
use Illuminate\Http\RedirectResponse;

final class CreateUserController
{
    public function __invoke(
        CreateUserRequest $request,
        CreateUser $create,
    ): RedirectResponse {
        $create->handle(CreateUserData::fromRequest($request));

        return back();
    }
}
```

The phone factory treats omitted, null, empty, and whitespace-only input as `null`; a nonblank phone number is trimmed. The password remains exactly as validated. The action performs its email check after Data has lower-cased the address and hashes the password while explicitly mapping the database columns. No request arrays or Data serialization become the persistence payload.
