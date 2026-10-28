---
name: laravel-controllers
description: "Use when adding or changing Laravel controllers or routes, wiring a FormRequest and action to an endpoint, or choosing between an API response, Inertia page, and redirect."
---

# Controllers

Applies to: `app/Http/Controllers/**`, `routes/**`.

## Rules

- A controller method:
  - injects the FormRequest, route-bound models, the actor (`#[CurrentUser] User $user`), and actions as parameters;
  - builds input with `Data::fromRequest()`;
  - calls actions;
  - returns a response.

  Single-action controllers use `__invoke`. Queries, relation loads, and formatting belong to actions and Resources.
- Declare controller middleware with `#[Middleware('auth')]` instead of a `middleware()` method. Without a FormRequest, authorize with `#[Authorize('view', 'user')]`: the ability, then the route parameter or model class. Both come from `Illuminate\Routing\Attributes\Controllers`.
- A write endpoint calls one write action, which composes any related writes and returns what the response needs (see `actions`). Read endpoints may call several read actions.
- Pass actor and parent models explicitly. Never pass request arrays or plucked fields to actions.
- Use separate web and API controllers when their responses differ; both reuse the same actions. Don't choose the response type by inspecting headers.
- Routes:
  - Name route parameters after the bound model, such as `{conversation}` for `Conversation`.
  - Use consistent plural prefixes, and name every route.
  - Group nested routes in `scopeBindings()`.
  - Use `Route::model()` or `resolveRouteBinding()` only when a parameter must resolve differently.
- Never return models or input Data.

## Responses

- **API:** pass the action's Resource, wrapped under `data`, to `ApiResponse::success()` with a translated message. Use 200 for reads and updates, 201 for synchronous creation, and 202 for queued work. A 204 stays bodyless. Errors are formatted centrally (see `api-responses`).
- **Inertia:** pass the actions' Resources as props, plus any enum options and page metadata.
- **Web writes:**
  - Redirect with `to_route()` or `back()`.
  - Flash translated feedback only when a deliberate change would otherwise be unclear. Background writes, such as marking a notice read, need none.
- **Authentication:** session-based controllers handle session mechanics here. Token-based APIs follow their own guard.

## Example

This API controller reuses `CreateUserRequest` (see `requests`), `CreateUserData` (see `data`), and `CreateUserAction` (see `actions`), which returns a `UserResource`. The web controller calls the same action and returns `back()`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Users;

use App\Actions\Users\CreateUserAction;
use App\Data\Users\CreateUserData;
use App\Http\Requests\Users\CreateUserRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class CreateUserController
{
    public function __invoke(CreateUserRequest $request, CreateUserAction $createUser): JsonResponse
    {
        $user = $createUser->handle(CreateUserData::fromRequest($request));

        return ApiResponse::success(
            resourceResponse: $user->wrap('data')->toResponse($request),
            message: __('User created successfully.'),
            status: Response::HTTP_CREATED,
        );
    }
}
```

`wrap('data')->toResponse()` is the Spatie Resource response API.

## Verify

- **API:** the five envelope keys; status codes; private fields excluded; 401, 403, 404, and 422 with and without a JSON `Accept` header; pagination; empty 204 bodies.
- **Web:** the Inertia component and props, redirects, and flash only where intended.
- **Routes:** scoped bindings reject a child of another parent.
