---
name: controllers
description: 'Implement thin Laravel API and web controllers with shared actions, typed input, Resources, and transport-appropriate responses.'
paths:
  - 'app/Http/Controllers/**'
---

## Rules

- Controllers handle authorization, typed action invocation, and HTTP responses. Queries, writes, relationship loads, business decisions, and model-to-output mapping belong in actions. Inject FormRequests, route-bound models, and actions as method parameters; single-action controllers use `__invoke`.
- Request payloads and user-supplied query parameters reach actions through explicit Data `fromRequest()` factories. Never pass request arrays or pluck fields into action arguments. Pass actor and parent models explicitly rather than accessing their relationships. Small internal arrays remain valid action inputs when they are documented application values rather than request payloads.
- A write endpoint invokes one write action; related writes are composed inside it. A read action may prepare the response representation after that write. Read endpoints may call several read actions. API and web controllers reuse the same transport-independent actions.
- FormRequests authorize before invocation. Without a FormRequest, authorize through Gate before calling actions. Scope nested route bindings to their parent. Session and authentication mechanics belong at the appropriate HTTP boundary for the configured authentication mode.
- Response data comes from read-action Resources. Controllers may arrange those Resources into the established response envelope or page props, with enum options, constants, and transport metadata. Do not return models directly or map model attributes into arrays in controllers.
- Body-bearing JSON API responses use the shared `ApiResponse` contract: `data`, `message`, `errors`, `meta`, and `links`. Resources supply `data`; success has `errors: null`, failures have `data: null`, and pagination supplies `meta` and `links`. Preserve real HTTP status codes. A 204 response has no body. Central exception handling uses the same envelope.
- Inertia pages render Resource-backed props; web writes redirect to a named route or back to the current page. Flash translated feedback for deliberate web changes only when useful. API responses do not assume Inertia, session flash, or redirects.

Use the `laracanon-controllers` skill for the implementation workflow and shared web/API example.

## Skill

Use this **Laracanon-authored workflow** when implementing or refactoring API or web controllers. It adds no package dependencies. Use the application's existing Data and Resource implementations and the shared API contract from the `api-responses` item; apply Inertia-specific steps only where Inertia is installed. Install both sets of guidance with `php artisan canon:install controllers api-responses`. The API helper lives in the application; installing guidance does not create PHP classes or change exception configuration.

1. Inspect the route, callers, authorization policy, shared `ApiResponse` helper, authentication mode, and installed framework/resource adapters. Use the application's helper when it implements the `api-responses` contract, or implement that item's authored helper and exception workflow once; avoid independent per-controller envelopes. Decide whether the endpoint serves an API, an Inertia page, or another web response. Keep route namespaces and response types explicit. Use separate web and API controllers where their contracts differ, sharing the same actions rather than duplicating business work or choosing a response by guessing from headers.
2. Inject the FormRequest, route-bound models, and actions into the controller method. Let the FormRequest authorize and validate, then construct request input with its explicit Data `fromRequest()` factory. Use a validated Data contract for API filters and pagination input too. If there is no FormRequest, call `Gate::authorize()` before any action. Configure scoped nested bindings on the route so a child cannot resolve outside its parent; consult Laravel's [scoped binding documentation](https://laravel.com/docs/13.x/routing#implicit-model-binding-scoping).
3. Invoke one write action for a mutation. Pass existing actor/parent models and the typed input contract; compose multiple writes and their transaction inside that action. If the response needs the stored representation, call a read action with the returned model to prepare its Resource. For reads, call the relevant read actions and pass their results into the response. Do not query, load relations, normalize values, authorize again inside actions, or map model fields in the controller.
4. For APIs, serialize the Resource through its supported response API with an explicit outer `data` key, then pass that response to `ApiResponse::success()` with a translated message and the intended HTTP status. This produces exactly one envelope containing `data`, `message`, `errors`, `meta`, and `links`, while preserving Resource field selection and pagination. Use 200 for a successful read/update representation, 201 for synchronous creation, or the documented status for asynchronous work. A successful operation without a representation may use an enveloped 200 with `data: null`; an existing 204 contract must remain bodyless. Keep input Data and Eloquent models out of JSON. Do not wrap a Resource twice or infer wrapping from an arbitrary model field. Laravel's [response documentation](https://laravel.com/docs/13.x/responses#json-responses) and the installed Resource implementation define the available serialization methods.
5. Apply the `api-responses` exception workflow centrally so API failures use the same five-key envelope, even without an `Accept: application/json` header. Preserve 401 authentication, 403 authorization, 404 missing-resource, 422 validation, and other real error statuses. Validation `errors` retains field names and message lists; non-field failures use the safe `message` with `errors: null`. Preserve authentication and rate-limit headers. Merge this into existing exception handling, leave web/Inertia behavior intact, and keep controller-level catch blocks and exception details out of responses; consult Laravel's [JSON exception rendering documentation](https://laravel.com/docs/13.x/errors#rendering-exceptions-as-json).
6. For Inertia reads, render the existing page with read-action Resources, enum options, constants, and page metadata. For web writes, use `to_route()` for a named destination or `back()` for an in-place change; use the installed adapter's [redirect handling](https://inertiajs.com/docs/v2/the-basics/redirects). Flash translated feedback only for deliberate changes whose success would otherwise be unclear, using the project's supported flash mechanism. Side-effect writes such as recording a view or marking a notice read need no automatic toast. Keep these web behaviors out of API response construction. Session-backed authentication controllers perform their required session mechanics at this boundary; bearer endpoints use their own authentication contract.
7. Test the endpoint's HTTP contract with its actual caller type. For APIs, cover the five envelope keys, authorized success, HTTP status, excluded private fields, validation and authorization failures, preserved headers, and pagination metadata/links where relevant. Test error behavior with and without JSON headers, and ensure 204 responses remain empty. For Inertia, assert the page and Resource-backed props on reads and redirect/flash behavior on writes. Verify scoped nested bindings where used. Action tests cover database behavior; controller tests cover wiring and response behavior. Run the application's required checks and report what passed and what remains unverified.

### Example: create a user through web or API

Both entry points use the same `CreateUserRequest`, `CreateUserData::fromRequest()`, and `CreateUser` from the Data skill. The web controller redirects; the API controller returns the created user's representation in the shared response envelope, using the `App\Http\Responses\ApiResponse` helper from the `api-responses` item. This example assumes those classes and the User model already exist, with authorization and normalization handled by the established request/Data boundary. It uses the Spatie Resource implementation already present in that example; another application's existing Resource implementation can provide the same boundary without changing its packages.

The API response includes only the declared public fields. The read action prepares that representation; no password or arbitrary model attributes reach the controller response. No relationship is needed in this example. If the representation grows to include relationships or counts, the read action loads them before building the Resource.

`app/Data/Users/UserResource.php`:

```php
<?php

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

`app/Actions/Users/GetUser.php`:

```php
<?php

namespace App\Actions\Users;

use App\Data\Users\UserResource;
use App\Models\User;

final class GetUser
{
    public function handle(User $user): UserResource
    {
        return UserResource::fromModel($user);
    }
}
```

`app/Http/Controllers/Users/CreateUserController.php` (web):

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

`app/Http/Controllers/Api/Users/CreateUserController.php` (API):

```php
<?php

namespace App\Http\Controllers\Api\Users;

use App\Actions\Users\CreateUser;
use App\Actions\Users\GetUser;
use App\Data\Users\CreateUserData;
use App\Http\Requests\Users\CreateUserRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class CreateUserController
{
    public function __invoke(
        CreateUserRequest $request,
        CreateUser $create,
        GetUser $getUser,
    ): JsonResponse {
        $user = $create->handle(CreateUserData::fromRequest($request));

        return ApiResponse::success(
            resourceResponse: $getUser->handle($user)->wrap('data')->toResponse($request),
            message: __('User created successfully.'),
            status: Response::HTTP_CREATED,
        );
    }
}
```

Register the web controller on the application's web route and the API controller on its API route, using the existing authentication and exception handling for each. They are alternate transports for one operation, not a requirement to add both routes to every application. For this Spatie implementation, `wrap('data')->toResponse()` serializes the Resource under the required key and preserves allowed request field selection before `ApiResponse` adds the envelope; see [Spatie's Resource response documentation](https://spatie.be/docs/laravel-data/v4/as-a-resource/from-data-to-resource).
