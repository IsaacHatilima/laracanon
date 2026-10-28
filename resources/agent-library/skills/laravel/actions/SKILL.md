---
name: laravel-actions
description: "Use when creating, refactoring, or reviewing a Laravel action or operation service that queries, writes, or makes a business decision."
---

# Actions

Applies to: `app/Actions/**`, or `app/Services/**` in a project that uses services. An action is one business operation.

## Class shape

- Follow the project: `app/Actions/{Domain}/` with an `Action` suffix, or `app/Services/{Domain}/` with a `Service` suffix. A new project with neither uses actions. The rules are the same for both.
- `final readonly class` with `declare(strict_types=1);` and one public, fully typed `handle()`. Name writes with a domain verb (`CreateUserAction`), reads `List{Nouns}Action` or `Get{Noun}Action`, and checks `Is*` or `Has*`.
- Constructor-inject reused actions; never resolve or construct them inside `handle()`. Keep per-call state in local variables.
- Keep methods small: follow the project's complexity tool, or keep each method at a cyclomatic complexity of 5 or less. Above that, extract private methods that share the caller's transaction.

## Inputs

- Request input arrives as a Data object built by the caller (see `data`); existing records arrive as models. Never accept a Request or a request array.
- Pass the actor and parent models to `handle()`. Never read `auth()`, `request()`, or `#[CurrentUser]` inside an action.
- Small internal values may be typed scalars or PHPDoc-shaped arrays, such as `@param list<string> $userIds`. Use Data once a value needs normalization or crosses boundaries.
- Trust Data's normalization; don't trim or parse again.
- Owner, tenant, and team columns come from the actor or parent, never from input. Every read is scoped to records the caller may see (see `security`).

## Persistence

- Map every column explicitly from typed input, in create and update calls: `'phone_number' => $data->phoneNumber`. Never pass Data, `toArray()`, or an attributes array to Eloquent. The model's `#[Fillable]` must list those columns (see `models`). Storage conversions such as hashing happen here.
- Partial updates write only the supplied fields, keep an allowed explicit `null`, and skip the write when nothing was supplied.
- Related writes share one `DB::transaction()`. Read-modify-write logic locks its rows with `lockForUpdate()` inside it. A single insert needs no transaction.
- Notifications, mail, jobs, and events that depend on the write run after commit, through `DB::afterCommit()` or `ShouldQueueAfterCommit`. An inner action returning doesn't mean the outer transaction committed.

## Results and failures

- Return what the caller needs, always typed. Never return an untyped array or `mixed`.
  - The result goes into a response (API JSON or Inertia props): eager-load every relation and count the Resource reads, then return `{Noun}Resource::fromModel()` (see `data`).
  - The caller keeps working with the record, such as another action or a redirect to its route: return the model.
  - Callers need both: return the model, and the response caller passes it to a `Get{Noun}Action` that returns the Resource.
  - Deletes return `void`; checks return `bool`.
- Reads select only the needed columns and keep pagination when mapping to Resources.
- Broken business rules throw a translated `ValidationException::withMessages()` or a domain exception. Authorization, redirects, JSON, and flash messages belong to the caller.
- Fortify contracts keep their required `array $input` in the adapter, which validates, builds Data, and delegates to an action without querying.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Data\Users\CreateUserData;
use App\Data\Users\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final readonly class CreateUserAction
{
    public function handle(CreateUserData $data): UserResource
    {
        if (User::query()->where('email', $data->email)->exists()) {
            throw ValidationException::withMessages([
                'email' => __('The email has already been taken.'),
            ]);
        }

        $user = User::query()->create([
            'name' => $data->name,
            'email' => $data->email,
            'phone_number' => $data->phoneNumber,
            'password' => Hash::make($data->password),
        ]);

        return UserResource::fromModel($user);
    }
}
```

`CreateUserData` has already lower-cased the email, so the check matches stored values; the unique index still guards concurrent inserts.

## Verify

Database-backed tests cover the writes or reads, the returned type, explicit column mapping, business failures, and any rollback, locking, after-commit delivery, or pagination. HTTP tests cover authorization and response wiring (see `tests`).
