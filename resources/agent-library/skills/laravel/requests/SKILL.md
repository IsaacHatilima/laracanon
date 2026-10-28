---
name: laravel-requests
description: "Use when adding or changing a Laravel FormRequest, validation rules, request authorization, or query parameters such as filters, sorting, and pagination."
---

# Requests

Applies to: `app/Http/Requests/**`.

## Rules

- Every endpoint that accepts input, including GET filters and pagination, has a FormRequest in `app/Http/Requests/{Domain}/`.
  - Name it `{Operation}Request`, such as `CreateUserRequest`.
  - Share a `Save{Noun}Request` only when create and update have identical rules and authorization.
  - Lists use `List{Nouns}Request`.
- A FormRequest only authorizes and validates. It doesn't normalize, build Data, query, write, or build responses.
- Set request options with attributes from `Illuminate\Foundation\Http\Attributes` instead of properties: `#[StopOnFirstFailure]`, `#[ErrorBag('login')]`, `#[RedirectToRoute('users.index')]`.
- `authorize()` calls the policy through `Gate::allows()`, passing the model class for create and the route-bound model otherwise. Parent scoping comes from `scopeBindings()` on the route (see `controllers`).
- Declare explicit rules for every accepted payload and query field, including nested keys.
  - Choose `required`, `nullable`, and `sometimes` so that omitting a field and sending `null` mean what the contract says.
  - For updates, take any model ignored by `unique` from the route, never from a submitted ID.
- Validate user-chosen sort and filter fields against an allowlist with `Rule::in()`; the action maps them to real columns. Password fields use `Password::defaults()` (see `security`).
- Put checks that need another field or the database in `after()`.
  - It receives injected actions as typed parameters and returns callbacks.
  - Each callback returns early when its prerequisite fields already failed.
  - Checks that need normalized values belong in the action instead.
  - Framework `exists` and `unique` rules are fine.
- Extract rules into an `app/Concerns/*ValidationRules` trait only when two requests actually share them.
- Translate custom `messages()` and `attributes()`. Let Laravel's validation and authorization exceptions reach the web error bag or the central API handler (see `api-responses`). Don't override the failure methods.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;

final class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', User::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
```

## Verify

Tests cover:
- authorized and forbidden callers;
- each rule's failure, and omitted versus `null`;
- rejected sort values;
- translated errors;
- an `after()` lookup that doesn't run when its prerequisite fields failed.
