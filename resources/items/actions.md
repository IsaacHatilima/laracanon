---
name: actions
description: 'Implement or refactor typed Laravel domain actions with explicit persistence, transactions, and transport-independent results.'
paths:
  - 'app/Actions/**'
---

## Rules

- Actions live in `app/Actions/{Domain}/` with one public, fully typed `handle()` method. Use domain verbs for writes, `List{Nouns}` or `Get{Noun}` for reads, and `Is*` or `Has*` for boolean checks. Request input arrives through a typed Data contract; existing entities are models. Internal values may use typed scalars or small, well-defined arrays with PHPDoc element types or array shapes. Domain actions never accept Request objects or request payload arrays.
- Actions trust Data's normalization. Both create and update calls map actual database column keys explicitly from their typed inputs, using Data properties when the action consumes Data. An accepted internal array is never forwarded as the persistence payload. Do not pass Data, `toArray()`, `toAttributes()`, or a generic attributes payload to Eloquent, infer column names, or add a model adapter. Storage conversions belong in the action.
- Actions own queries, writes, transactions, relationship loads, and business decisions. Callers authorize before invocation. Actions remain independent of HTTP, authentication globals, sessions, Inertia, and responses; failures use validation or domain exceptions.
- Write actions return the created or updated model, or `void` for deletes. Read actions return Resources built through explicit `fromModel()` factories and load every relation or count those Resources read. Checks return `bool`.
- Related writes share a transaction; read-modify-write operations lock the relevant records within it. Notifications, mail, jobs, and events happen after commit. Reused actions are constructor-injected as private readonly dependencies.
- Fortify adapters retain their framework-required array input, validate it, construct typed Data, and delegate to a domain action. The adapter itself never queries or writes.

Use the `laracanon-actions` skill for the implementation workflow and example.

## Skill

Use this **Laracanon-authored workflow** when creating or refactoring a domain action. Follow the application's rules and existing contracts. This item supplies guidance without adding package dependencies. Use the application's Data contracts for request input and Resources for output; small internal arrays are allowed when their structure is clear.

1. Identify the operation, callers, schema, and existing actions before choosing a name and return contract. Keep one public `handle()` method; private helpers may support it. Accept the relevant models and a typed Data object for request input. For small internal values, use typed scalars or arrays with explicit PHPDoc contracts, such as `@param list<string> $userIds` or `@param array{notify: bool} $options`. Do not create a DTO for every tiny internal array; prefer Data when a business contract needs normalization, grows richer, or is reused across boundaries. These arrays do not replace Data for request payloads. Pass actor or parent models explicitly rather than looking them up through `auth()` or `request()`. Constructor-inject reused actions as `private readonly` properties instead of constructing or resolving them inside `handle()`.
2. Keep authorization and request validation at the caller boundary. A web caller uses its FormRequest and the explicit `fromRequest()` factory. For operations with a Data contract, jobs and commands construct that contract through an appropriate factory; other internal values can remain scalars or documented arrays. Trust the resulting Data values. Do not repeat trimming, case conversion, or request parsing in the action. Use translated `ValidationException::withMessages()` errors or the application's domain exceptions for broken business rules; leave redirects, JSON, Inertia, flash messages, and exception-to-response handling to the caller.
3. Implement database checks and persistence inside the action. In both create and update calls, map each column directly from the typed input, such as `'name' => $data->name` and `'phone_number' => $data->phoneNumber` for Data. If an internal array contributes a stored value, read only its documented keys and map those values explicitly; never pass the array itself to Eloquent. Check the schema, casts, and `#[Fillable]` for those columns on Laravel 13+. On older Laravel versions, preserve the existing mass-assignment declaration without upgrading the framework for this convention. Apply storage conversions, such as password hashing, here. Do not forward a generic attributes variable, serialized Data, or an assignment loop. For a partial-update contract, guard each known property's omitted state individually, preserve an allowed explicit null, and skip a write when no fields were supplied; retain explicit column names at the Eloquent write.
4. Group related writes in `DB::transaction()` and return the result from its closure. For a read-modify-write decision, retrieve and lock the relevant records with `lockForUpdate()` inside that transaction before inspecting their state. Preserve the connection and transaction boundary when composing actions. A single insert does not need a transaction solely to satisfy the example. Consult Laravel's [transaction documentation](https://laravel.com/docs/13.x/database#database-transactions) and [pessimistic locking documentation](https://laravel.com/docs/13.x/queries#pessimistic-locking) for the installed framework version.
5. Register notifications, mail, job dispatch, and events for after commit when they depend on the write. Account for an enclosing transaction: returning from an inner action does not mean its caller's transaction committed. Use the application's existing after-commit mechanisms, `DB::afterCommit()` for callbacks, or the queue's documented `afterCommit()` behavior. Do not change unrelated queue configuration. See Laravel's [jobs and database transactions documentation](https://laravel.com/docs/13.x/queues#jobs-and-database-transactions).
6. Return the documented result. Writes return their model; deletes return `void`; checks return `bool`. For reads, select the required fields and eager-load all relations and counts before applying each Resource's explicit `fromModel()` factory. Preserve collection or pagination metadata while mapping results. Keep queries and lazy-loading out of Resources, and leave transport response construction to controllers or other callers.
7. When the entry point is a Fortify contract, keep its required `array $input` in the adapter. Validate the contract fields there, construct the typed Data through the application's factory so normalization remains in Data, and delegate to an injected domain action. Keep queries and writes out of the adapter. This framework exception does not change the domain action's typed input contract.
8. Run focused database-backed action tests for the resulting writes or reads, ignored input fields, explicit column mapping, and business failures. For the implemented operation, cover applicable rollback, locking, deferred delivery, composition, or pagination behavior, and fake external delivery. Verify caller authorization and response wiring in the relevant HTTP tests. Run the application's required checks and report what passed and what remains unverified.

### Example: create a user

This is the action from the same `CreateUserRequest` → `CreateUserData::fromRequest()` → `CreateUser` → `CreateUserController` flow in the Data skill. It assumes the existing `App\Data\Users\CreateUserData` exposes readonly `name`, `email`, `phoneNumber`, and `password` properties, with input already authorized, validated, and normalized. The User model imports `Illuminate\Database\Eloquent\Attributes\Fillable` and declares `#[Fillable(['name', 'email', 'phone_number', 'password'])]`; its table has a unique email index and a nullable phone column.

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

The action checks the normalized email, chooses each database column explicitly, hashes the untouched password, and returns a User. The unique index remains the database guarantee against concurrent duplicate inserts. Input cleaning stays in `CreateUserData`; the controller decides the response. An update action follows the same explicit column-to-property mapping in its update call.
